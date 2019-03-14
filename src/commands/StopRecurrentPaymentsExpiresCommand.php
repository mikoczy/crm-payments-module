<?php

namespace Crm\PaymentsModule\Commands;

use Crm\ApplicationModule\DataRow;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\UsersModule\Events\NotificationEvent;
use League\Event\Emitter;
use Nette\Utils\DateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StopRecurrentPaymentsExpiresCommand extends Command
{
    private $recurrentPaymentsRepository;

    private $emitter;

    public function __construct(
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        Emitter $emitter
    ) {
        parent::__construct();
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->emitter = $emitter;
    }

    protected function configure()
    {
        $this->setName('payments:stop_expired_recurrent_payments')
            ->setDescription('Stop expired recurrent payments')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $start = microtime(true);

        $output->writeln('');
        $output->writeln('<info>***** Stopping Expired Recurrent Payments *****</info>');
        $output->writeln('');

        $date = DateTime::from('first day of this month 00:00:00');
        $nextMonth = DateTime::from('last day of this month 23:59:59');

        $output->writeln("Stopping recurrents expired before <info>{$date->format('c')}</info> and charged between <info>{$date->format('c')}</info> - <info>{$nextMonth->format('c')}</info>");

        $recurrentPayments = $this->recurrentPaymentsRepository->all()->where([
            'expires_at <= ?' => $date,
            'state' => RecurrentPaymentsRepository::STATE_ACTIVE,
            'charge_at >= ?' => $date,
            'charge_at <= ?' => $nextMonth,
        ]);

        $output->writeln('Total: <info>' . count($recurrentPayments) . '</info>');

        foreach ($recurrentPayments as $recurrentPayment) {
            $output->writeln("Processing user #[{$recurrentPayment->user->id}] <info>{$recurrentPayment->user->email}</info>");

            // v buducnosti by bolo fajn tu len emitovat event
            // a posielanie emailu dat do email modulu
            // a zrusenie recurentu bude nechat tu alebo dat inde

            $output->writeln("  * Stopping recurrent <info>{$recurrentPayment->id}</info>");
            $note = 'AutoStop on expired card';
            $this->recurrentPaymentsRepository->update($recurrentPayment, [
                'state' => RecurrentPaymentsRepository::STATE_SYSTEM_STOP,
                'note' => $recurrentPayment->note ? $recurrentPayment->note . ' ' . $note : $note,
            ]);

            $hasNewRecurrent = $this->recurrentPaymentsRepository->all()->where([
                'user_id' => $recurrentPayment->user_id,
                'cid != ?' => $recurrentPayment->cid,
                'charge_at > ?' => $recurrentPayment->charge_at,
                'status' => RecurrentPaymentsRepository::STATE_ACTIVE,
            ])->count('*') > 0;

            if ($hasNewRecurrent) {
                $output->writeln("  * has new recurrent <info>{$recurrentPayment->user->email}</info>");
            } else {
                $output->writeln("  * Sending email <info>{$recurrentPayment->user->email}</info>");
                $userRow = new DataRow([
                    'email' => $recurrentPayment->user->email,
                ]);
                $this->emitter->emit(new NotificationEvent($userRow, 'card_expires_this_month'));
            }
        }

        $end = microtime(true);
        $duration = $end - $start;

        $output->writeln('Stopped: <info>' . count($recurrentPayments) . '</info>');

        $output->writeln('');
        $output->writeln('<info>All done. Took ' . round($duration, 2) . ' sec.</info>');
        $output->writeln('');
    }
}
