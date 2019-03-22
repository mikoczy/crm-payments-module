<?php

namespace Crm\PaymentsModule\Upgrade;

use Crm\SubscriptionsModule\Events\NewSubscriptionEvent;
use Crm\SubscriptionsModule\Events\SubscriptionEndsEvent;
use Crm\SubscriptionsModule\Events\SubscriptionStartsEvent;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

abstract class Upgrader
{
    protected $subscriptionTypeUpgrade;

    protected $subscriptionsRepository;

    protected $subscriptionTypesRepository;

    protected $emitter;

    protected $chargePrice;

    /**
     * @var float custom charge price
     *
     * Variable should be set only when the future charge price differs from standard subscription price
     * we're upgrading to. It's meant to be used as custom_amount field of the recurrent payment instance.
     */
    protected $customAmount;

    protected $alteredEndTime;

    protected $browserId;

    public function __construct(
        ActiveRow $subscriptionTypeUpgrade,
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        Emitter $emitter
    ) {
        $this->subscriptionTypeUpgrade = $subscriptionTypeUpgrade;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->emitter = $emitter;

        $this->browserId = (isset($_COOKIE['browser_id']) ? $_COOKIE['browser_id'] : null);
    }

    abstract public function upgrade(SubscriptionUpgrade $subscriptionUpgrade, $gatewayId = null);

    abstract public function calculateChargePrice($payment, $actualUserSubscription);

    abstract public function getType();

    public function isRecurrent()
    {
        return in_array($this->getType(), ['recurrent', 'recurrent_free']);
    }

    public function canUpgradeSubscriptionType($toSubscriptionTypeId)
    {
        return $this->subscriptionTypeUpgrade->to_subscription_type_id == $toSubscriptionTypeId;
    }

    public function getToSubscriptionType()
    {
        return $this->subscriptionTypeUpgrade->to_subscription_type;
    }

    // getToSubscriptionTypeItem returns subscription type item to be used within upgrade (for payment items and later
    // invoicing). This feature expects that upgrade always adds just club to the existing subscription, not print.
    // Worst case scenario is that we pay more VAT than we should if something is misconfigured
    public function getToSubscriptionTypeItem()
    {
        $upgradedItem = null;
        foreach ($this->getToSubscriptionType()->related('subscription_type_items') as $item) {
            if (!$upgradedItem || $upgradedItem->vat < $item->vat) {
                $upgradedItem = $item;
            }
        }
        return $upgradedItem;
    }

    public function getChargePrice()
    {
        if (!isset($this->chargePrice)) {
            throw new \Exception('calculateChargePrice() was not called for this instance of Upgrader or chargePrice was just not set.');
        }
        return $this->chargePrice;
    }

    public function getAlteredEndTime()
    {
        if (!isset($this->alteredEndTime)) {
            throw new \Exception('alteredEndTime was not set for current upgrader.');
        }
        return $this->alteredEndTime;
    }

    /**
     * @return float getFutureChargePrice returns amount of money to be charged in the next recurring payment.
     *
     * If the $customAmount is set by upgrader implementation, this amount is returned and used by recurrent payment.
     * Otherwise the future charge price is deducted from subscription type we're upgrading to.
     */
    public function getFutureChargePrice(): float
    {
        if (isset($this->customAmount)) {
            return $this->customAmount;
        }

        $subscriptionType = $this->getToSubscriptionType();
        if ($subscriptionType->next_subscription_type_id) {
            $subscriptionType = $this->subscriptionTypesRepository->find($subscriptionType->next_subscription_type_id);
        }
        return $subscriptionType->price;
    }

    protected function splitSubscription(
        SubscriptionsRepository $subscriptionsRepository,
        Emitter $emitter,
        $actualUserSubscription,
        $toSubscriptionType,
        ActiveRow $payment,
        DateTime $endTime = null
    ) {
        $changeTime = new DateTime();
        if ($endTime === null) {
            $endTime = $actualUserSubscription->end_time;
        }

        // zastavime aktualnu subscription
        $subscriptionsRepository->update($actualUserSubscription, [
            'end_time' => $changeTime,
            'internal_status' => SubscriptionsRepository::INTERNAL_STATUS_AFTER_END,
            'note' => '[upgrade] Original end_time ' . $actualUserSubscription->end_time,
            'modified_at' => new DateTime(),
        ]);
        $actualUserSubscription = $subscriptionsRepository->find(($actualUserSubscription->id));

        // spravime novu subscription do konca aktualnej
        $newSubscription = $subscriptionsRepository->add(
            $toSubscriptionType,
            $payment->payment_gateway->is_recurrent,
            $payment->user,
            SubscriptionsRepository::TYPE_UPGRADE,
            $changeTime,
            $endTime,
            "Upgrade z {$actualUserSubscription->subscription_type->name} na {$toSubscriptionType->name}",
            $actualUserSubscription->address
        );
        $subscriptionsRepository->update($newSubscription, [
            'internal_status' => SubscriptionsRepository::INTERNAL_STATUS_ACTIVE,
        ]);

        $emitter->emit(new SubscriptionEndsEvent($actualUserSubscription));
        $emitter->emit(new NewSubscriptionEvent($newSubscription, false));
        $emitter->emit(new SubscriptionStartsEvent($newSubscription));

        return $newSubscription;
    }
}
