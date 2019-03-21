<?php

namespace Crm\PaymentsModule\Upgrade;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class ShortUpgrade extends Upgrader
{
    private $paymentsRepository;

    private $hermesEmitter;

    private $dailyFix;

    private $trackingParams;

    private $salesFunnelId;

    public function __construct(
        ActiveRow $subscriptionTypeUpgrade,
        PaymentsRepository $paymentsRepository,
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
        $this->paymentsRepository = $paymentsRepository;
        $this->hermesEmitter = $hermesEmitter;
        $this->dailyFix = $dailyFix;
        $this->trackingParams = $trackingParams;
        $this->salesFunnelId = $salesFunnelId;
    }

    public function getType()
    {
        return Expander::UPGRADE_SHORT;
    }

    public function upgrade(SubscriptionUpgrade $subscriptionUpgrade, $gatewayId = null)
    {
        $payment = $subscriptionUpgrade->getPayment();
        $actualUserSubscription = $subscriptionUpgrade->getSubscription();
        $toSubscriptionType = $this->getToSubscriptionType();

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

        $endTime = $this->getNewEndTime($payment, $actualUserSubscription, $toSubscriptionType);
        $this->splitSubscription(
            $this->subscriptionsRepository,
            $this->emitter,
            $actualUserSubscription,
            $toSubscriptionType,
            $payment,
            $endTime
        );

        $this->paymentsRepository->update($payment, [
            'upgrade_type' => Expander::UPGRADE_SHORT,
            'modified_at' => new DateTime(),
        ]);

        $this->hermesEmitter->emit(new HermesMessage('subscription-split', $eventParams));
        return true;
    }

    public function canUpgradeSubscriptionType($toSubscriptionTypeId)
    {
        return $this->subscriptionTypeUpgrade->to_subscription_type_id == $toSubscriptionTypeId;
    }

    /**
     * getEndTimeForShortUpgradedSubscription calculates remaining number of days for $toSubscriptionType based on
     * $actualUserSubscription's remaining days and its price.
     *
     * @param ActiveRow $payment
     * @param ActiveRow $actualUserSubscription
     * @param ActiveRow $toSubscriptionType
     *
     * @return DateTime
     */
    public function getNewEndTime(ActiveRow $payment, ActiveRow $actualUserSubscription, ActiveRow $toSubscriptionType)
    {
        $subscriptionDays = $actualUserSubscription->start_time->diff($actualUserSubscription->end_time)->days;
        $dayPrice = $payment->amount / $subscriptionDays;
        $saveFromActual = (new DateTime())->diff($actualUserSubscription->end_time)->days * $dayPrice;
        $saveFromActual = round($saveFromActual, 2);

        // vypocitame kolko stoji do konca stareho predplatneho novy typ predplatneho
        if ($this->dailyFix) {
            $toSubscriptionPrice = $actualUserSubscription->subscription_type->price;
            $newDayPrice = $toSubscriptionPrice / $toSubscriptionType->length + $this->dailyFix;
        } else {
            $toSubscriptionPrice = $toSubscriptionType->price;
            $newDayPrice = $toSubscriptionPrice / $toSubscriptionType->length;
        }

        $length = ceil($saveFromActual / $newDayPrice);

        $newEndTime = DateTime::from(strtotime('+' . $length . ' days ' . $actualUserSubscription->end_time->format('H:i:s')));
        $this->alteredEndTime = $newEndTime;
        return $newEndTime;
    }

    public function calculateChargePrice($payment, $actualUserSubscription)
    {
        $this->chargePrice = 0.0;
        return $this->chargePrice;
    }
}
