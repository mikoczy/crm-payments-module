<?php

namespace Crm\PaymentsModule\Populator;

use Crm\ApplicationModule\Populator\AbstractPopulator;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;

class PaymentsPopulator extends AbstractPopulator
{
    /**
     * @param \Symfony\Component\Console\Helper\ProgressBar $progressBar
     */
    public function seed($progressBar)
    {
        $payments = $this->database->table('payments');
        $recurrentPayments = $this->database->table('recurrent_payments');

        for ($i = 0; $i < $this->count; $i++) {
            $user = $this->getRecord('users');
            $data = [
                'variable_symbol' => $this->faker->randomNumber(10),
                'user_id' => $user->id,
                'created_at' => $this->faker->dateTimeBetween('-1 years'),
                'modified_at' => $this->faker->dateTimeBetween('-1 years'),
                'ip' => $this->faker->ipv4,
                'user_agent' => $this->faker->userAgent,
                'referer' => $this->faker->url
            ];

            $subscription = false;
            if (rand(1, 4) == 2) {
                $subscription = $this->getRecord('subscriptions');
            }
            $paymentGateway = $this->getRecord('payment_gateways');

            if ($subscription) {
                $data['status'] = 'paid';
                $data['amount'] = $subscription->subscription_type->price;
                $data['payment_gateway_id'] = $paymentGateway->id;
                $data['subscription_type_id'] = $subscription->subscription_type_id;
                $data['subscription_id'] = $subscription;
                $data['paid_at'] = $this->faker->dateTimeBetween('-1 years');
            } else {
                $subscriptionType = $this->getRecord('subscription_types');
                $data['status'] = $this->getErrorPaymentStatus();
                $data['amount'] = $subscriptionType->price;
                $data['payment_gateway_id'] = $paymentGateway->id;
                $data['subscription_type_id'] = $subscriptionType->id;
            }

            $payment = $payments->insert($data);
            if ($payment->payment_gateway->is_recurrent) {
                $recurrentPaymentData = [
                    'cid' => $this->faker->randomNumber(10),
                    'created_at' => $this->faker->dateTimeBetween('-1 years'),
                    'payment_gateway_id' => 2,
                    'charge_at' => $this->faker->dateTimeBetween('-1 months'),
                    'expires_at' => $this->faker->dateTimeBetween('-1 years'),
                    'payment_id' => $payment->id,
                    'retries' => $this->faker->randomNumber(3),
                    'status' => 'ok',
                    'approval' => '',
                    'user_id' => $user->id,
                    'subscription_type_id' => $data['subscription_type_id'],
                    'state' => RecurrentPaymentsRepository::STATE_ACTIVE,
                ];
                $recurrentPayments->insert($recurrentPaymentData);
            }

            $progressBar->advance();
        }
    }

    private function getErrorPaymentStatus()
    {
        $statuses = [
            PaymentsRepository::STATUS_FORM,
            PaymentsRepository::STATUS_FAIL,
            PaymentsRepository::STATUS_TIMEOUT,
        ];
        return $statuses[rand(0, count($statuses)-1)];
    }
}
