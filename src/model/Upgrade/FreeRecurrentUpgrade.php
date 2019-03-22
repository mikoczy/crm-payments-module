<?php

namespace Crm\PaymentsModule\Upgrade;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class FreeRecurrentUpgrade extends Upgrader
{
    private $recurrentPaymentsRepository;

    private $paymentsRepository;

    private $dailyFix;

    private $trackingParams;

    private $salesFunnelId;

    private $hermesEmitter;

    public function __construct(
        ActiveRow $subscriptionTypeUpgrade,
        PaymentsRepository $paymentsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        Emitter $emitter,
        \Tomaj\Hermes\Emitter $hermesEmitter,
        $dailyFix,
        $trackingParams,
        $salesFunnelId
    ) {
        parent::__construct(
            $subscriptionTypeUpgrade,
            $subscriptionsRepository,
            $subscriptionTypesRepository,
            $emitter
        );
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->hermesEmitter = $hermesEmitter;
        $this->dailyFix = $dailyFix;
        $this->trackingParams = $trackingParams;
        $this->salesFunnelId = $salesFunnelId;
    }

    public function getType()
    {
        return Expander::UPGRADE_RECURRENT_FREE;
    }

    public function upgrade(SubscriptionUpgrade $subscriptionUpgrade, $gatewayId = null)
    {
        $payment = $subscriptionUpgrade->getPayment();
        $actualUserSubscription = $subscriptionUpgrade->getSubscription();

        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        if (!$recurrentPayment) {
            throw new \Exception('Nemalo by nikdy nastat - pokus upgradnut nerecurentne zaplatenu subscription - paymentID:' . $payment->id . ' subscriptionID:' . $actualUserSubscription->id);
        }

        $toSubscriptionType = $this->getToSubscriptionType();
        $note = "Free recurrent upgraded from subscription type {$recurrentPayment->subscription_type->name} to {$toSubscriptionType->name}";

        $eventParams = [
            'user_id' => $payment->user_id,
            'browser_id' => $this->browserId,
            'source' => $this->trackingParams,
            'sales_funnel_id' => $this->salesFunnelId,
            'transaction_id' => 'upgrade',
            'product_ids' => [strval($payment->subscription_type_id)],
            'revenue' => 0,
        ];
        $this->hermesEmitter->emit(new HermesMessage('sales-funnel', array_merge(['type' => 'payment'], $eventParams)));

        $this->paymentsRepository->update($payment, [
            'note' => $payment->note ? $payment->note . "\n" . $note : $note,
            'modified_at' => new DateTime(),
            'upgrade_type' => Expander::UPGRADE_RECURRENT_FREE,
        ]);
        $this->recurrentPaymentsRepository->update($recurrentPayment, [
            'next_subscription_type_id' => $toSubscriptionType->id,
            'custom_amount' => $this->customAmount,
            'note' => $note . "\n(" . time() . ')',
        ]);

        $this->splitSubscription(
            $this->subscriptionsRepository,
            $this->emitter,
            $actualUserSubscription,
            $toSubscriptionType,
            $payment
        );

        $this->hermesEmitter->emit(new HermesMessage('subscription-split', $eventParams));
        return true;
    }

    public function calculateChargePrice($payment, $actualUserSubscription)
    {
        $toSubscriptionType = $this->getToSubscriptionType();

        // vypocitame kolko stoji do konca stareho predplatneho novy typ predpaltneho
        if ($this->dailyFix) {
            $subscriptionType = $actualUserSubscription->subscription_type;
            if ($subscriptionType->next_subscription_type_id) {
                $subscriptionType = $subscriptionType->next_subscription_type;
            }
            $newDayPrice = ($subscriptionType->price / $toSubscriptionType->length) + $this->dailyFix;
            $this->customAmount = round($newDayPrice * $toSubscriptionType->length, 2);
        }

        $this->chargePrice = 0.0;
        return $this->chargePrice;
    }
}
