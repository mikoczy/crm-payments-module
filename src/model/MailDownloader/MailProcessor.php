<?php

namespace Crm\PaymentsModule\MailConfirmation;

use Crm\PaymentsModule\Builder\ParsedMailLogsBuilder;
use Crm\PaymentsModule\Gateways\GatewayAbstract;
use Crm\PaymentsModule\PaymentProcessor;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use DateInterval;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Symfony\Component\Console\Output\OutputInterface;
use Tomaj\BankMailsParser\MailContent;

class MailProcessor
{
    private $paymentsRepository;

    private $paymentProcessor;

    private $parsedMailLogsBuilder;

    private $logBuilder;

    private $parsedMailLogsRepository;

    /** @var  MailContent */
    private $mailContent;

    /** @var  OutputInterface */
    private $output;

    public function __construct(
        PaymentsRepository $paymentsRepository,
        PaymentProcessor $paymentProcessor,
        ParsedMailLogsBuilder $parsedMailLogsBuilder,
        ParsedMailLogsRepository $parsedMailLogsRepository
    ) {
        $this->paymentsRepository = $paymentsRepository;
        $this->paymentProcessor = $paymentProcessor;
        $this->parsedMailLogsBuilder = $parsedMailLogsBuilder;
        $this->parsedMailLogsRepository = $parsedMailLogsRepository;
    }

    public function processMail(MailContent $mailContent, OutputInterface $output, $skipCheck = false)
    {
        $this->mailContent = $mailContent;
        $this->output = $output;

        if ($this->mailContent->getSign() == null) {
            $transactionDatetime = new DateTime('@' . $this->mailContent->getTransactionDate());
            $this->logBuilder = $this->parsedMailLogsBuilder->createNew()
                ->setDeliveredAt($transactionDatetime);
            return $this->processBankAccountMovements();
        }

        $transactionDatetime = DateTime::createFromFormat('dmYHis', $this->mailContent->getTransactionDate());
        $this->logBuilder = $this->parsedMailLogsBuilder->createNew()
            ->setDeliveredAt($transactionDatetime);
        return $this->processCardMovements($skipCheck);
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

        $this->logBuilder
            ->setMessage($this->mailContent->getReceiverMessage())
            ->setAmount($this->mailContent->getAmount());

        $vs = $this->getVs();
        if (!$vs) {
            return false;
        }
        $payment = $this->getPayment($vs);
        if (!$payment) {
            return false;
        }

        if ($payment->amount != $this->mailContent->getAmount()) {
            $this->logBuilder
                ->setState(ParsedMailLogsRepository::STATE_DIFFERENT_AMOUNT)
                ->save();
        }

        // we will not approve payment when amount in email (real payment) is lower than payment (in db)
        if ($payment->amount > $this->mailContent->getAmount()) {
            return false;
        }

        $newPaymentThreshold = (clone $transactionDate)->sub(new DateInterval('P10D'));

        $createdNewPayment = false;

        if ($payment->status == PaymentsRepository::STATUS_PAID && $payment->created_at < $newPaymentThreshold) {
            $newPayment = $this->paymentsRepository->copyPayment($payment);
            $payment = $newPayment;
            $this->logBuilder->setPayment($payment);
            $createdNewPayment = true;
        }

        $duplicatedPaymentCheck = $this->parsedMailLogsRepository
            ->findByVariableSymbols([$payment->variable_symbol])
            ->where('state = ?', ParsedMailLogsRepository::STATE_CHANGED_TO_PAID)
            ->where('created_at >= ?', $newPaymentThreshold)
            ->count('*');
        if ($duplicatedPaymentCheck > 0) {
            $this->logBuilder
                ->setState(ParsedMailLogsRepository::STATE_DUPLICATED_PAYMENT)
                ->save();
            return false;
        }

        if ($payment->status == PaymentsRepository::STATUS_PAID) {
            $this->logBuilder
                ->setState(ParsedMailLogsRepository::STATE_ALREADY_PAID)
                ->save();
            return false;
        }

        if (in_array($payment->status, [PaymentsRepository::STATUS_FORM, PaymentsRepository::STATUS_FAIL, PaymentsRepository::STATUS_TIMEOUT])) {
            $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PAID, true);

            $state = ParsedMailLogsRepository::STATE_CHANGED_TO_PAID;
            if ($createdNewPayment) {
                $state = ParsedMailLogsRepository::STATE_AUTO_NEW_PAYMENT;
            }
            $this->logBuilder->setState($state)->save();
        }

        return true;
    }

    private function processCardMovements($skipCheck)
    {
        $transactionDate = DateTime::createFromFormat('dmYHis', $this->mailContent->getTransactionDate());
        $date = $transactionDate->format('d.m.Y H:i');
        $this->output->writeln(" * Parsed email <info>{$date}</info>");
        $this->output->writeln("    -> VS - <info>{$this->mailContent->getVS()}</info> {$this->mailContent->getAmount()} {$this->mailContent->getCurrency()}");
        $fields = [''];

        $this->logBuilder
            ->setMessage($this->mailContent->getReceiverMessage())
            ->setAmount($this->mailContent->getAmount());

        $vs = $this->getVs();
        if (!$vs) {
            return false;
        }

        $sign = $this->mailContent->getSign();

        if (!$sign) {
            $this->output->writeln("    -> missing sign");
            $this->logBuilder->setState(ParsedMailLogsRepository::STATE_NO_SIGN);
            $this->logBuilder->save();
            return false;
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
        $fields['RC'] = $this->mailContent->getRc();

        $payment = $this->getPayment($vs);
        if (!$payment) {
            return false;
        }
        if (!$skipCheck && $payment->status == PaymentsRepository::STATUS_PAID) {
            $this->logBuilder->setState(ParsedMailLogsRepository::STATE_ALREADY_PAID)->save();
            return false;
        }

        if ($this->mailContent->getRes() !== 'OK') {
            $this->paymentsRepository->updateStatus(
                $payment,
                PaymentsRepository::STATUS_FAIL,
                false,
                "non-OK RES mail param: {$this->mailContent->getRes()}"
            );
            $this->output->writeln("    -> Payment has non-OK result, setting failed");
            return true;
        }

        $cid = $this->mailContent->getCid();

        if ($payment->payment_gateway->is_recurrent && !$cid) {
            // halt the processing, not a comfortpay confirmation email
            // we receive both cardpay and comfortpay notifications for payment at the same time
            $this->output->writeln("    -> Payment's [{$payment->id}] gateway is recurrent but CID was not received [{$cid}], halting");
            return false;
        }

        if ($cid) {
            $fields['CID'] = $cid;
            $fields['TRES'] = 'OK';
        }

        // TODO toto je ultra mega hack
        foreach ($fields as $k => $v) {
            $_GET[$k] = $v;
        }

        $this->paymentProcessor->complete($payment, function ($payment, GatewayAbstract $gateway) {
            if ($payment->status === PaymentsRepository::STATUS_PAID) {
                $this->logBuilder->setState(ParsedMailLogsRepository::STATE_CHANGED_TO_PAID)->save();
            }
        });

        return true;
    }

    private function getVs(): ?string
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
            $this->logBuilder->setState(ParsedMailLogsRepository::STATE_WITHOUT_VS);
            $this->logBuilder->save();
            return null;
        }
        $this->logBuilder->setVariableSymbol($vs);
        return $vs;
    }

    private function getPayment($vs): ?ActiveRow
    {
        $payment = $this->paymentsRepository->findLastByVS($vs);
        if (!$payment) {
            $this->logBuilder
                ->setState(ParsedMailLogsRepository::STATE_PAYMENT_NOT_FOUND)
                ->save();
            return null;
        }
        $this->logBuilder->setPayment($payment);
        return $payment;
    }
}
