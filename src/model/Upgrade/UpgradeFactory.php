<?php

namespace Crm\PaymentsModule\Upgrade;

use Crm\PaymentsModule\GatewayFactory;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentLogsRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Tomaj\Hermes\Emitter as HermesEmitter;

class UpgradeFactory
{
    protected $paymentsRepository;

    protected $paymentLogsRepository;

    protected $recurrentPaymentsRepository;

    protected $subscriptionsRepository;

    protected $subscriptionTypesRepository;

    protected $paymentGatewaysRepository;

    protected $emitter;

    protected $hermesEmitter;

    protected $gatewayFactory;

    protected $trackingParams = [];

    protected $dailyFix = false;

    protected $salesFunnelId;

    public function __construct(
        PaymentsRepository $paymentsRepository,
        PaymentLogsRepository $paymentLogsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        Emitter $emitter,
        HermesEmitter $hermesEmitter,
        GatewayFactory $gatewayFactory
    ) {
        $this->paymentsRepository = $paymentsRepository;
        $this->paymentLogsRepository = $paymentLogsRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->emitter = $emitter;
        $this->hermesEmitter = $hermesEmitter;
        $this->gatewayFactory = $gatewayFactory;
    }

    public function build($upgradeType, ActiveRow $subscriptionTypeUpgrade)
    {
        switch ($upgradeType) {
            case Expander::UPGRADE_RECURRENT:
                return new PaidRecurrentUpgrade(
                    $subscriptionTypeUpgrade,
                    $this->paymentsRepository,
                    $this->paymentLogsRepository,
                    $this->recurrentPaymentsRepository,
                    $this->subscriptionsRepository,
                    $this->subscriptionTypesRepository,
                    $this->emitter,
                    $this->hermesEmitter,
                    $this->gatewayFactory,
                    $this->dailyFix,
                    $this->trackingParams,
                    $this->salesFunnelId
                );
            case Expander::UPGRADE_RECURRENT_FREE:
                return new FreeRecurrentUpgrade(
                    $subscriptionTypeUpgrade,
                    $this->paymentsRepository,
                    $this->recurrentPaymentsRepository,
                    $this->subscriptionsRepository,
                    $this->subscriptionTypesRepository,
                    $this->emitter,
                    $this->hermesEmitter,
                    $this->dailyFix,
                    $this->trackingParams,
                    $this->salesFunnelId
                );
            case Expander::UPGRADE_PAID_EXTEND:
                return new PaidExtendUpgrade(
                    $subscriptionTypeUpgrade,
                    $this->paymentsRepository,
                    $this->paymentGatewaysRepository,
                    $this->subscriptionsRepository,
                    $this->subscriptionTypesRepository,
                    $this->emitter,
                    $this->hermesEmitter,
                    $this->dailyFix,
                    $this->trackingParams,
                    $this->salesFunnelId
                );
            case Expander::UPGRADE_SHORT:
                return new ShortUpgrade(
                    $subscriptionTypeUpgrade,
                    $this->paymentsRepository,
                    $this->subscriptionsRepository,
                    $this->subscriptionTypesRepository,
                    $this->emitter,
                    $this->hermesEmitter,
                    $this->dailyFix,
                    $this->trackingParams,
                    $this->salesFunnelId
                );
            default:
                throw new \Exception('unsupported upgrade type for factory build');
        }
    }

    public function setTrackingParams($trackingParams)
    {
        $this->trackingParams = $trackingParams;
    }

    public function setSalesFunnelId($salesFunnelId)
    {
        $this->salesFunnelId = $salesFunnelId;
    }

    public function setDailyFix($fix)
    {
        $this->dailyFix = $fix;
    }
}
