<?php

namespace Crm\PaymentsModule\Upgrade;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\GatewayFactory;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentLogsRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Exception;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class PaidRecurrentUpgrade extends Upgrader
{
    private $recurrentPaymentsRepository;

    private $paymentsRepository;

    private $hermesEmitter;

    private $paymentLogsRepository;

    private $gatewayFactory;

    private $dailyFix;

    private $trackingParams;

    private $salesFunnelId;

    public function __construct(
        ActiveRow $subscriptionTypeUpgrade,
        PaymentsRepository $paymentsRepository,
        PaymentLogsRepository $paymentLogsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        Emitter $emitter,
        \Tomaj\Hermes\Emitter $hermesEmitter,
        GatewayFactory $gatewayFactory,
        $dailyFix,
        $trackingParams,
        $salesFunnelId
    ) {
        parent::__construct($subscriptionTypeUpgrade, $subscriptionsRepository, $emitter);
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->paymentLogsRepository = $paymentLogsRepository;
        $this->gatewayFactory = $gatewayFactory;
        $this->hermesEmitter = $hermesEmitter;
        $this->dailyFix = $dailyFix;
        $this->trackingParams = $trackingParams;
        $this->salesFunnelId = $salesFunnelId;
    }

    public function getType()
    {
        return Expander::UPGRADE_RECURRENT;
    }

    public function upgrade(SubscriptionUpgrade $subscriptionUpgrade, $gatewayId = null)
    {
        $payment = $subscriptionUpgrade->getPayment();
        $actualUserSubscription = $subscriptionUpgrade->getSubscription();

        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        if (!$recurrentPayment) {
            throw new Exception('Nemalo by nikdy nastat - pokus upgradnut nerecurentne zaplatenu subscription - paymentID:' . $payment->id . ' subscriptionID:' . $actualUserSubscription->id);
        }

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

        // spravit novu platbu a rovno ju chargnut
        $newPayment = $this->paymentsRepository->add(
            $toSubscriptionType,
            $payment->payment_gateway,
            $payment->user,
            $paymentItemContainer,
            '',
            null,
            null,
            null,
            "Payment for upgrade from {$actualUserSubscription->subscription_type->name} to {$toSubscriptionType->name}"
        );

        $this->paymentsRepository->update($newPayment, [
            'upgrade_type' => $this->getType(),
        ]);
        $this->paymentsRepository->addMeta($newPayment, $this->trackingParams);

        $newPayment = $this->paymentsRepository->find($newPayment->id);

        // todo - toto by bolo fajn spravit nejak inak
        // idealne cez paymentRepository update status a potom cez eventy vyrobit subscription atd...
        // pretoze aktualne ak neprebehne platba a niekto ju odklikne v admine
        // tak sa nestane to co by sa malo stat
        $gateway = $this->gatewayFactory->getGateway($newPayment->payment_gateway->code);

        $this->hermesEmitter->emit(new HermesMessage('sales-funnel', [
            'type' => 'payment',
            'user_id' => $newPayment->user_id,
            'browser_id' => $this->browserId,
            'source' => $this->trackingParams,
            'sales_funnel_id' => $this->salesFunnelId,
            'payment_id' => $newPayment->id,
        ]));

        try {
            $gateway->charge($newPayment, $recurrentPayment->cid);
        } catch (Exception $e) {
            $this->paymentsRepository->updateStatus(
                $newPayment,
                PaymentsRepository::STATUS_FAIL,
                false,
                $newPayment->note . '; failed: ' . $gateway->getResultCode()
            );
        }

        $this->paymentLogsRepository->add(
            $gateway->isSuccessful() ? 'OK' : 'ERROR',
            json_encode($gateway->getResponseData()),
            'recurring-payment-manual-charge',
            $newPayment->id
        );
        if (!$gateway->isSuccessful()) {
            return false;
        }

        $this->paymentsRepository->updateStatus($newPayment, PaymentsRepository::STATUS_PAID);

        // updatnutneme recurrent na novy subscription type
        $this->recurrentPaymentsRepository->update($recurrentPayment, [
            'subscription_type_id' => $toSubscriptionType->id,
            'custom_amount' => $this->getFutureChargePrice(),
            'parent_payment_id' => $newPayment->id,
            'note' => "Upgradnuty recurrentu z {$actualUserSubscription->subscription_type->name} na {$toSubscriptionType->name}\n(" . time() . ')',
        ]);

        return true;
    }

    public function calculateChargePrice($payment, $actualUserSubscription)
    {
        if ($this->subscriptionTypeUpgrade->type == 'action') {
            return 1.0;
        }

        // zistime kolko penazi usetril
        $subscriptionDays = $actualUserSubscription->start_time->diff($actualUserSubscription->end_time)->days;
        $dayPrice = ($payment->amount - intval($payment->additional_amount)) / $subscriptionDays;
        $saveFromActual = (new DateTime())->diff($actualUserSubscription->end_time)->days * $dayPrice;
        $saveFromActual = round($saveFromActual, 2);

        $toSubscriptionType = $this->getToSubscriptionType();

        // vypocitame kolko stoji do konca stareho predplatneho novy typ predplatneho
        if ($this->dailyFix) {
            $subscriptionType = $actualUserSubscription->subscription_type;
            if ($subscriptionType->next_subscription_type_id) {
                $subscriptionType = $subscriptionType->next_subscription_type;
            }
            $newDayPrice = ($subscriptionType->price / $toSubscriptionType->length) + $this->dailyFix;
            $futureChargePrice = round($newDayPrice * $toSubscriptionType->length, 2);
        } else {
            $newDayPrice = $toSubscriptionType->price / $toSubscriptionType->length;
            if ($toSubscriptionType->next_subscription_type_id) {
                $futureChargePrice = $toSubscriptionType->next_subscription_type->price;
            } else {
                $futureChargePrice = $toSubscriptionType->price;
            }
        }

        $newPrice = (new DateTime())->diff($actualUserSubscription->end_time)->days * $newDayPrice;
        $newPrice = round($newPrice, 2);

        $chargePrice = $newPrice - $saveFromActual;
        if (getenv('CRM_ENV') == 'dennikn_cz') {
            // there's a bug with CSOB library rounding: https://github.com/ondrakoupil/csob/issues/21
            $chargePrice = round($chargePrice);
        }

        if ($chargePrice <= 0) {
            $chargePrice = 0.01;
        }

        $this->chargePrice = $chargePrice;
        $this->futureChargePrice = $futureChargePrice;

        return $chargePrice;
    }
}
