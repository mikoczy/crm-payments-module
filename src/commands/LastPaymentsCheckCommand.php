<?php

namespace Crm\PaymentsModule\Commands;

use Crm\ApplicationModule\DataRow;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\UsersModule\Events\NotificationEvent;
use League\Event\Emitter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LastPaymentsCheckCommand extends Command
{
    private $paymentGatewaysRepository;

    private $paymentsRepository;

    private $emitter;

    private $emailTempate = 'problems_with_payments_notification';

    public function __construct(
        PaymentGatewaysRepository $paymentGatewaysRepository,
        PaymentsRepository $paymentsRepository,
        Emitter $emitter
    ) {
        parent::__construct();
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->emitter = $emitter;
    }

    protected function configure()
    {
        $this->setName('payments:last_payments_check')
            ->addOption(
                'notify',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                "E-mail addresses of people to notify about the issue."
            )
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                "Codes of gateways to exclude from checking"
            )
            ->setDescription('Check last payments if there is some errors');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('');
        $output->writeln('<info>***** CHECK LAST PAYMENTS *****</info>');
        $output->writeln('');

        $checkCount = 10;

        $emails = $input->getOption('notify');
        $exclude = $input->getOption('exclude');

        foreach ($this->paymentGatewaysRepository->getAllActive() as $gateway) {
            if (in_array($gateway->code, $exclude)) {
                continue;
            }

            $output->writeln("Checking gateway <info>{$gateway->name}</info>");

            $lastPayments = $this->paymentsRepository->all('', $gateway)->order('created_at DESC')->limit($checkCount);
            $form = 0;
            $paid = 0;
            $error = 0;
            foreach ($lastPayments as $payment) {
                if ($payment->status == PaymentsRepository::STATUS_PAID) {
                    $paid++;
                }
                if ($payment->status == PaymentsRepository::STATUS_FORM) {
                    $form++;
                }
            }

            if ($form == $checkCount) {
                $output->writeln('<error>Notification</error> form');
                foreach ($emails as $email) {
                    $userRow = new DataRow([
                        'email' => $email,
                    ]);
                    $this->emitter->emit(new NotificationEvent($userRow, $this->emailTempate, [
                        'gateway' => $gateway,
                        'error' => 'form',
                    ]));
                }
            } elseif ($error == $checkCount) {
                $output->writeln('<error>Notification</error> error');
                foreach ($emails as $email) {
                    $userRow = new DataRow([
                        'email' => $email,
                    ]);
                    $this->emitter->emit(new NotificationEvent($userRow, $this->emailTempate, [
                        'gateway' => $gateway,
                        'error' => 'error',
                    ]));
                }
            } else {
                $output->writeln('OK');
            }
        }
    }
}
