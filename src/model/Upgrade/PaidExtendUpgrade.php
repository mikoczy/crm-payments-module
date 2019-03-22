<?php

namespace Crm\PaymentsModule\Upgrade;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class PaidExtendUpgrade extends Upgrader
{
    private $paymentGatewaysRepository;

    private $paymentsRepository;

    private $dailyFix;

    private $utmParams;

    private $salesFunnelId;

    private $hermesEmitter;

    public function __construct(
        ActiveRow $subscriptionTypeUpgrade,
        PaymentsRepository $paymentsRepository,
        PaymentGatewaysRepository $paymentGatewaysRepository,
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
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->hermesEmitter = $hermesEmitter;
        $this->dailyFix = $dailyFix;
        $this->utmParams = $trackingParams;
        $this->salesFunnelId = $salesFunnelId;
    }

    public function getType()
    {
        return Expander::UPGRADE_PAID_EXTEND;
    }

    public function upgrade(SubscriptionUpgrade $subscriptionUpgrade, $gatewayId = null)
    {
        if (!$gatewayId) {
            return false;
        }

        $gateway = $this->paymentGatewaysRepository->find($gatewayId);
        if (!$gateway) {
            return false;
        }

        $payment = $subscriptionUpgrade->getPayment();
        $actualUserSubscription = $subscriptionUpgrade->getSubscription();

        $toSubscriptionType = $this->getToSubscriptionType();
        $upgradedItem = $this->getToSubscriptionTypeItem();

        $chargePrice = $this->getChargePrice();
        $item = new SubscriptionTypePaymentItem(
            $toSubscriptionType->id,
            $upgradedItem->name,
            $chargePrice,
            $upgradedItem->vat
        );
        $paymentItemContainer = (new PaymentItemContainer())->addItem($item);

        // vytvorime novu platu
        $payment = $this->paymentsRepository->add(
            $toSubscriptionType,
            $gateway,
            $payment->user,
            $paymentItemContainer,
            ''
        );

        $upgradeType = Expander::UPGRADE_PAID_EXTEND;
        if ($this->subscriptionTypeUpgrade->type == 'action') {
            $upgradeType = Expander::UPGRADE_SPECIAL;
        }

        $this->paymentsRepository->update($payment, [
            'upgrade_type' => $upgradeType,
            'note' => "Upgrade from '{$actualUserSubscription->subscription_type->name}' to '{$toSubscriptionType->name}'",
            'modified_at' => new DateTime(),
        ]);
        $this->paymentsRepository->addMeta($payment, $this->utmParams);

        $this->hermesEmitter->emit(new HermesMessage('sales-funnel', [
            'type' => 'payment',
            'user_id' => $payment->user_id,
            'browser_id' => $this->browserId,
            'source' => $this->utmParams,
            'sales_funnel_id' => $this->salesFunnelId,
            'payment_id' => $payment->id,
        ]));

        return $payment;
    }

    /**
     * calculateChargePrice calculates price based on $toSubscriptionType's price discounted by
     * $actualUserSubscription's remaining amount of days.
     *
     * @param ActiveRow $payment
     * @param ActiveRow $actualUserSubscription
     *
     * @return float
     */
    public function calculateChargePrice($payment, $actualUserSubscription)
    {
        if ($this->subscriptionTypeUpgrade->type == 'action') {
            return 1.0;
        }

        // zistime kolko penazi usetril
        $subscriptionDays = $actualUserSubscription->start_time->diff($actualUserSubscription->end_time)->days;
        $dayPrice = $payment->amount / $subscriptionDays;
        $saveFromActual = (new DateTime())->diff($actualUserSubscription->end_time)->days * $dayPrice;
        $saveFromActual = round($saveFromActual, 2);

        $toSubscriptionType = $this->getToSubscriptionType();

        // vypocitame kolko stoji do konca stareho predplatneho novy typ predpaltneho
        if ($this->dailyFix) {
            $newDayPrice = ($actualUserSubscription->subscription_type->price / $actualUserSubscription->subscription_type->length) + $this->dailyFix;
            $newPrice = $toSubscriptionType->length * $newDayPrice;
            $newPrice = round($newPrice, 2);
        } else {
            $newPrice = $toSubscriptionType->price;
        }

        $chargePrice = $newPrice - $saveFromActual;
        if (getenv('CRM_ENV') == 'dennikn_cz') {
            // there's a bug with CSOB library rounding: https://github.com/ondrakoupil/csob/issues/21
            $chargePrice = round($chargePrice);
        }

        if ($chargePrice <= 0) {
            $chargePrice = 0.01;
        }

        $this->alteredEndTime = DateTime::from(strtotime('+' . $this->getToSubscriptionType()->length . ' days '));
        $this->chargePrice = $chargePrice;

        return $chargePrice;
    }
}
