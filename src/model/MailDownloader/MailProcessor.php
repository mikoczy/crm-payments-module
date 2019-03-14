<?php

namespace Crm\PaymentsModule\MailConfirmation;

use Crm\PaymentsModule\Builder\ParsedMailLogsBuilder;
use Crm\PaymentsModule\model\MailDownloader\MailProcessorException;
use Crm\PaymentsModule\PaymentProcessor;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use DateInterval;
use Nette\DI\Container;
use Nette\Utils\DateTime;
use Symfony\Component\Console\Output\OutputInterface;
use Tomaj\BankMailsParser\MailContent;

class MailProcessor
{
    /** @var PaymentsRepository */
    private $paymentsRepository;

    /** @var  RecurrentPaymentsRepository */
    private $recurrentPaymentsRepository;

    /** @var PaymentProcessor */
    private $paymentProcessor;

    /** @var ParsedMailLogsBuilder */
    private $parsedMailLogsBuilder;

    /** @var  ParsedMailLogsBuilder */
    private $log;

    /** @var  MailContent */
    private $mailContent;

    /** @var  OutputInterface */
    private $output;

    /** @var  Container */
    private $context;

    public function __construct(
        PaymentsRepository $paymentsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        PaymentProcessor $paymentProcessor,
        ParsedMailLogsBuilder $parsedMailLogsBuilder,
        Container $context
    ) {
        $this->context = $context;
        $this->paymentsRepository = $paymentsRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->paymentProcessor = $paymentProcessor;
        $this->parsedMailLogsBuilder = $parsedMailLogsBuilder;
    }

    public function processMail(MailContent $mailContent, OutputInterface $output, $skipCheck = false)
    {
        $this->mailContent = $mailContent;
        $this->output = $output;

        try {
            if ($this->mailContent->getSign() == null) {
                $transactionDatetime = new DateTime('@' . $this->mailContent->getTransactionDate());
                $this->log = $this->parsedMailLogsBuilder->createNew()
                    ->setDeliveredAt($transactionDatetime);
                $this->processBankAccountMovements();
            } else {
                $transactionDatetime = DateTime::createFromFormat('dmYHis', $this->mailContent->getTransactionDate());
                $this->log = $this->parsedMailLogsBuilder->createNew()
                    ->setDeliveredAt($transactionDatetime);
                $this->processCardMovements($skipCheck);
            }
            return true;
        } catch (MailProcessorException $e) {
            return false;
        }
    }

    private function processBankAccountMovements()
    {
        if ($this->mailContent->getTransactionDate()) {
            $transactionDate = DateTime::from($this->mailContent->getTransactionDate());
        } else {
            $transactionDate = new DateTime();
        }
        $this->output->writeln(" * Parsed email <info>{$transactionDate->format('d.m.Y H:i')}</info>");
        $this->output->writeln("    -> VS - <info>{$this->mailContent->getVS()}</info> {$this->mailContent->getAmount()} {$this->mailContent->getCurrency()}");

        $this->log
            ->setMessage($this->mailContent->getReceiverMessage())
            ->setAmount($this->mailContent->getAmount());

        $vs = $this->getVs();

        $payment = $this->getPayment($vs);

        if ($payment->amount != $this->mailContent->getAmount()) {
            $this->log->setState(ParsedMailLogsRepository::STATE_DIFFERENT_AMOUNT)
                ->save();
        }

        $olderPaymentThan = (clone $transactionDate)->sub(new DateInterval('P5D'));

        $createdNewPayment = false;

        if ($payment->status == PaymentsRepository::STATUS_PAID && $payment->created_at < $olderPaymentThan) {
            $newPayment = $this->paymentsRepository->copyPayment($payment);
            $payment = $newPayment;
            $createdNewPayment = true;
        }

        $this->checkPaymentStatus($payment);

        if (in_array($payment->status, [PaymentsRepository::STATUS_FORM, PaymentsRepository::STATUS_FAIL, PaymentsRepository::STATUS_TIMEOUT])) {
            $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PAID, true);

            $state = ParsedMailLogsRepository::STATE_CHANGED_TO_PAID;
            if ($createdNewPayment) {
                $state = ParsedMailLogsRepository::STATE_AUTO_NEW_PAYMENT;
            }
            $this->log->setState($state)->save();
        }
    }

    private function processCardMovements($skipCheck)
    {
        $transactionDate = DateTime::createFromFormat('dmYHis', $this->mailContent->getTransactionDate());
        $date = $transactionDate->format('d.m.Y H:i');
        $this->output->writeln(" * Parsed email <info>{$date}</info>");
        $this->output->writeln("    -> VS - <info>{$this->mailContent->getVS()}</info> {$this->mailContent->getAmount()} {$this->mailContent->getCurrency()}");
        $fields = [''];

        $this->log
            ->setMessage($this->mailContent->getReceiverMessage())
            ->setAmount($this->mailContent->getAmount());

        $vs = $this->getVs();

        $sign = $this->mailContent->getSign();

        if (!$sign) {
            $this->output->writeln("    -> missing sign");
            $this->log->setState(ParsedMailLogsRepository::STATE_NO_SIGN);
            $this->log->save();
            throw new MailProcessorException(ParsedMailLogsRepository::STATE_NO_SIGN);
        }
        $fields['SIGN'] = $sign;
        $fields['HMAC'] = $sign;
        $fields['AMT'] = $this->mailContent->getAmount();
        $fields['CURR'] = $this->mailContent->getCurrency();
        $fields['TIMESTAMP'] = $this->mailContent->getTransactionDate();
        $fields['CC'] = $this->mailContent->getCc();
        $fields['TID'] = $this->mailContent->getTid();
        $fields['VS'] = $vs;
        $fields['AC'] = $this->mailContent->getAc();
        $fields['RES'] = $this->mailContent->getRes();

        $payment = $this->getPayment($vs);
        if (!$skipCheck) {
            $this->checkPaymentStatus($payment);
        }

        if ($this->mailContent->getRes() != 'OK') {
            $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_FAIL, false, "non-OK RES mail param: {$this->mailContent->getRes()}");
            $this->output->writeln("    -> Payment has non-OK result, setting failed");
            return;
        }

        $cid = $this->mailContent->getCid();

        if ($payment->payment_gateway->is_recurrent && !$cid) {
            // halt the processing, not a comfortpay confirmation email
            // we receive both cardpay and comfortpay notifications for payment at the same time
            $this->output->writeln("    -> Payment's [{$payment->id}] gateway is recurrent but CID was not received [{$cid}], halting");
            return;
        }

        if ($cid) {
            $fields['CID'] = $cid;
            $fields['TRES'] = 'OK';
        }

        // TODO toto je ultra mega hack
        foreach ($fields as $k => $v) {
            $_GET[$k] = $v;
        }

        $this->paymentProcessor->complete($payment, function () {
        });
    }

    private function getVs()
    {
        $vs = $this->mailContent->getVs();
        if (!$vs) {
            $pattern = '/vs[:\.\-_ ]??(\d{1,10})/i';
            if (preg_match($pattern, $this->mailContent->getReceiverMessage(), $result)) {
                $vs = $result[1];
                $this->mailContent->setVs($vs);
            }
        }
        if (!$vs) {
            $this->log->setState(ParsedMailLogsRepository::STATE_WITHOUT_VS);
            $this->log->save();
            throw new MailProcessorException(ParsedMailLogsRepository::STATE_WITHOUT_VS);
        }
        $this->log->setVariableSymbol($vs);
        return $vs;
    }

    private function getPayment($vs)
    {
        $payment = $this->paymentsRepository->findLastByVS($vs);
        if (!$payment) {
            $this->log->setState(ParsedMailLogsRepository::STATE_PAYMENT_NOT_FOUND)
                ->save();
            throw new MailProcessorException(ParsedMailLogsRepository::STATE_PAYMENT_NOT_FOUND);
        }
        $this->log->setPayment($payment);
        return $payment;
    }

    private function checkPaymentStatus($payment)
    {
        if ($payment->status == PaymentsRepository::STATUS_PAID) {
            $this->log->setState(ParsedMailLogsRepository::STATE_ALREADY_PAID)
                ->save();
            throw new MailProcessorException(ParsedMailLogsRepository::STATE_ALREADY_PAID);
        }
    }
}
