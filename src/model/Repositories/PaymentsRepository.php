<?php

namespace Crm\PaymentsModule\Repository;

use Crm\ApplicationModule\Cache\CacheRepository;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Repository;
use Crm\ApplicationModule\Repository\AuditLogRepository;
use Crm\ApplicationModule\Request;
use Crm\PaymentsModule\Events\NewPaymentEvent;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\VariableSymbolInterface;
use Crm\PaymentsModule\VariableSymbolVariant;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use DateTime;
use League\Event\Emitter;
use Nette\Database\Context;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\IRow;
use Nette\Database\Table\Selection;
use Nette\Localization\ITranslator;
use Tracy\Debugger;

class PaymentsRepository extends Repository
{
    const STATUS_FORM = 'form';
    const STATUS_PAID = 'paid';
    const STATUS_FAIL = 'fail';
    const STATUS_TIMEOUT = 'timeout';
    const STATUS_REFUND = 'refund';
    const STATUS_IMPORTED = 'imported';
    const STATUS_PREPAID = 'prepaid';

    protected $tableName = 'payments';

    private $variableSymbol;

    private $subscriptionTypesRepository;

    private $subscriptionsRepository;

    private $paymentGatewaysRepository;

    private $recurrentPaymentsRepository;

    private $paymentItemsRepository;

    private $emitter;

    private $hermesEmitter;

    private $paymentMetaRepository;

    private $translator;

    private $cacheRepository;

    public function __construct(
        Context $database,
        VariableSymbolInterface $variableSymbol,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        SubscriptionsRepository $subscriptionsRepository,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        PaymentItemsRepository $paymentItemsRepository,
        Emitter $emitter,
        AuditLogRepository $auditLogRepository,
        \Tomaj\Hermes\Emitter $hermesEmitter,
        PaymentMetaRepository $paymentMetaRepository,
        ITranslator $translator,
        CacheRepository $cacheRepository
    ) {
        parent::__construct($database);
        $this->variableSymbol = $variableSymbol;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->paymentItemsRepository = $paymentItemsRepository;
        $this->emitter = $emitter;
        $this->auditLogRepository = $auditLogRepository;
        $this->hermesEmitter = $hermesEmitter;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->translator = $translator;
        $this->cacheRepository = $cacheRepository;
    }

    final public function add(
        ActiveRow $subscriptionType = null,
        ActiveRow $paymentGateway,
        ActiveRow $user,
        PaymentItemContainer $paymentItemContainer,
        $referer = null,
        $amount = null,
        DateTime $subscriptionStartAt = null,
        DateTime $subscriptionEndAt = null,
        $note = null,
        $additionalAmount = 0,
        $additionalType = null,
        $variableSymbol = null,
        IRow $address = null,
        $recurrentCharge = false,
        array $metaData = []
    ) {
        $data = [
            'user_id' => $user->id,
            'payment_gateway_id' => $paymentGateway->id,
            'status' => self::STATUS_FORM,
            'created_at' => new DateTime(),
            'modified_at' => new DateTime(),
            'variable_symbol' => $variableSymbol ? $variableSymbol : $this->variableSymbol->getNew($paymentGateway),
            'ip' => Request::getIp(),
            'user_agent' => Request::getUserAgent(),
            'referer' => $referer,
            'subscription_start_at' => $subscriptionStartAt,
            'subscription_end_at' => $subscriptionEndAt,
            'note' => $note,
            'additional_type' => $additionalType,
            'additional_amount' => $additionalAmount == null ? 0 : $additionalAmount,
            'address_id' => $address ? $address->id : null,
            'recurrent_charge' => $recurrentCharge,
        ];

        // TODO: Additional type/amount fields are only informative and should be replaced with single/recurrent flag
        // directly on payment_items and be removed from here. additional_amount should not affect total amount anymore.

        // If amount is not provided, it's calculated based on payment items in container.
        if ($amount) {
            $data['amount'] = $amount;
        } else {
            $data['amount'] = $paymentItemContainer->totalPrice();
        }

        // It's not possible to generate payment amount based on payment items as postal fees of product module were
        // not refactored yet to separate payment item. Therefore custom "$amount" is still allowed.

        if ($data['amount'] <= 0) {
            throw new \Exception('attempt to create payment with zero or negative amount: ' . $data['amount']);
        }

        if ($subscriptionType) {
            $data['subscription_type_id'] = $subscriptionType->id;
        }

        /** @var ActiveRow $payment */
        $payment = $this->insert($data);

        $this->paymentItemsRepository->add($payment, $paymentItemContainer);

        if (!empty($metaData)) {
            $this->addMeta($payment, $metaData);
        }

        $this->emitter->emit(new NewPaymentEvent($payment));
        $this->hermesEmitter->emit(new HermesMessage('new-payment', [
            'payment_id' => $payment->id
        ]));
        return $payment;
    }

    final public function addMeta($payment, $data)
    {
        if (empty($data)) {
            return null;
        }
        $added = [];
        foreach ($data as $key => $value) {
            if (!$this->paymentMetaRepository->add($payment, $key, $value)) {
                return false;
            }
        }
        return $added;
    }

    final public function copyPayment(ActiveRow $payment)
    {
        $newPayment = $this->insert([
            'amount' => $payment->amount,
            'user_id' => $payment->user_id,
            'subscription_type_id' => $payment->subscription_type_id,
            'payment_gateway_id' => $payment->payment_gateway_id,
            'status' => self::STATUS_FORM,
            'created_at' => new DateTime(),
            'modified_at' => new DateTime(),
            'variable_symbol' => $payment->variable_symbol,
            'ip' => '',
            'user_agent' => '',
            'referer' => '',
        ]);

        foreach ($payment->related('payment_items') as $paymentItem) {
            $paymentItemArray = $paymentItem->toArray();
            $paymentItemArray['payment_id'] = $newPayment->id;
            unset($paymentItemArray['id']);
            $this->paymentItemsRepository->getTable()->insert($paymentItemArray);
        }

        return $newPayment;
    }

    final public function getPaymentItems(ActiveRow $payment): array
    {
        $items = [];
        foreach ($payment->related('payment_items') as $paymentItem) {
            $items[] = [
                'name' => $paymentItem->name,
                'amount' => $paymentItem->amount,
                'vat' => $paymentItem->vat,
                'count' => $paymentItem->count,
                'type' => $paymentItem->type,
            ];
        }
        return $items;
    }

    /**
     * @param ActiveRow $payment
     * @param string $paymentItemType
     * @return array|IRow[]
     */
    final public function getPaymentItemsByType(ActiveRow $payment, string $paymentItemType): array
    {
        return $this->paymentItemsRepository->getByType($payment, $paymentItemType);
    }

    final public function update(IRow &$row, $data, PaymentItemContainer $paymentItemContainer = null)
    {
        if ($paymentItemContainer) {
            $this->paymentItemsRepository->deleteByPayment($row);
            $this->paymentItemsRepository->add($row, $paymentItemContainer);
        }

        $data['modified_at'] = new DateTime();
        return parent::update($row, $data);
    }

    final public function updateStatus(ActiveRow $payment, $status, $sendEmail = false, $note = null, $errorMessage = null, $salesFunnelId = null)
    {
        $data = [
            'status' => $status,
            'modified_at' => new DateTime()
        ];
        if (in_array($status, [self::STATUS_PAID, self::STATUS_PREPAID]) && !$payment->paid_at) {
            $data['paid_at'] = new DateTime();
        }
        if ($note) {
            $data['note'] = $note;
        }
        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }

        if (in_array($payment->status, [self::STATUS_PAID, self::STATUS_PREPAID]) && $data['status'] == static::STATUS_FAIL) {
            Debugger::log("attempt to make change payment status from [{$payment->status}] to [fail]");
            return false;
        }

        parent::update($payment, $data);

        $this->emitter->emit(new PaymentChangeStatusEvent($payment, $sendEmail));
        $this->hermesEmitter->emit(new HermesMessage('payment-status-change', [
            'payment_id' => $payment->id,
            'sales_funnel_id' => $payment->sales_funnel_id ?? $salesFunnelId, // pass explicit sales_funnel_id if payment doesn't contain one
            'send_email' => $sendEmail,
        ]));

        return $this->find($payment->id);
    }

    /**
     * @param $variableSymbol
     * @return ActiveRow
     */
    final public function findByVs($variableSymbol)
    {
        return $this->findBy('variable_symbol', $this->variableSymbolVariants($variableSymbol));
    }

    private function variableSymbolVariants($variableSymbol)
    {
        $variableSymbolVariant = new VariableSymbolVariant();
        return $variableSymbolVariant->variableSymbolVariants($variableSymbol);
    }

    final public function findLastByVS(string $variableSymbol)
    {
        return $this->findAllByVS($variableSymbol)->order('created_at DESC')->limit(1)->fetch();
    }

    final public function findAllByVS(string $variableSymbol)
    {
        return $this->getTable()->where(
            'variable_symbol',
            $this->variableSymbolVariants($variableSymbol)
        );
    }

    final public function addSubscriptionToPayment(IRow $subscription, IRow $payment)
    {
        return parent::update($payment, ['subscription_id' => $subscription->id]);
    }

    final public function subscriptionPayment(IRow $subscription)
    {
        return $this->getTable()->where(['subscription_id' => $subscription->id])->select('*')->limit(1)->fetch();
    }

    /**
     * @param int $userId
     * @return \Nette\Database\Table\Selection
     */
    final public function userPayments($userId)
    {
        return $this->getTable()->where(['payments.user_id' => $userId])->order('created_at DESC');
    }

    /**
     * @param int $userId
     * @return \Nette\Database\Table\Selection
     */
    final public function userRefundPayments($userId)
    {
        return $this->userPayments($userId)->where('status', self::STATUS_REFUND);
    }

    /**
     * @param string $text
     * @param null $payment_gateway
     * @param null $subscription_type
     * @param null $status
     * @param null $start
     * @param null $end
     * @param null $sales_funnel
     * @param null $donation
     * @param null $recurrentCharge
     * @param null $referer
     * @return Selection
     */
    final public function all($text = '', $payment_gateway = null, $subscription_type = null, $status = null, $start = null, $end = null, $sales_funnel = null, $donation = null, $recurrentCharge = null, $referer = null)
    {
        $where = [];
        if ($text !== null && $text !== '') {
            $where['variable_symbol LIKE ? OR note LIKE ?'] = ["%{$text}%", "%{$text}%"];
        }
        if ($payment_gateway) {
            $where['payment_gateway_id'] = $payment_gateway;
        }
        if ($subscription_type) {
            $where['subscription_type_id'] = $subscription_type;
        }
        if ($status) {
            $where['payments.status'] = $status;
        }
        if ($sales_funnel) {
            $where['sales_funnel_id'] = $sales_funnel;
        }
        if ($donation !== null) {
            if ($donation) {
                $where[] = 'additional_amount > 0';
            } else {
                $where[] = 'additional_amount = 0';
            }
        }
        if ($start) {
            $where['(paid_at IS NOT NULL AND paid_at >= ?) OR (paid_at IS NULL AND modified_at >= ?)'] = [$start, $start];
        }
        if ($end) {
            $where['(paid_at IS NOT NULL AND paid_at < ?) OR (paid_at IS NULL AND modified_at < ?)'] = [$end, $end];
        }
        if ($recurrentCharge !== null) {
            $where['recurrent_charge'] = $recurrentCharge;
        }
        if ($referer) {
            $where['referer LIKE ?'] = "%{$referer}%";
        }

        return $this->getTable()->where($where);
    }

    final public function allWithoutOrder()
    {
        return $this->getTable();
    }

    final public function totalAmountSum($allowCached = false, $forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->getTable()->where(['status' => self::STATUS_PAID])->sum('amount');
        };

        if ($allowCached) {
            return $this->cacheRepository->loadAndUpdate(
                'payments_paid_sum',
                $callable,
                \Nette\Utils\DateTime::from(CacheRepository::REFRESH_TIME_5_MINUTES),
                $forceCacheUpdate
            );
        }

        return $callable();
    }

    final public function totalUserAmountSum($userId)
    {
        return $this->getTable()->where(['user_id' => $userId, 'status' => self::STATUS_PAID])->sum('amount');
    }

    final public function getStatusPairs()
    {
        return [
            self::STATUS_FORM => self::STATUS_FORM,
            self::STATUS_FAIL => self::STATUS_FAIL,
            self::STATUS_PAID => self::STATUS_PAID,
            self::STATUS_TIMEOUT => self::STATUS_TIMEOUT,
            self::STATUS_REFUND => self::STATUS_REFUND,
            self::STATUS_IMPORTED => self::STATUS_IMPORTED,
            self::STATUS_PREPAID => self::STATUS_PREPAID,
        ];
    }

    final public function getPaymentsWithNotes()
    {
        return $this->getTable()->where(['NOT note' => null])->order('created_at DESC');
    }

    final public function totalCount($allowCached = false, $forceCacheUpdate = false)
    {
        $callable = function () {
            return parent::totalCount();
        };
        if ($allowCached) {
            return $this->cacheRepository->loadAndUpdate(
                'payments_count',
                $callable,
                \Nette\Utils\DateTime::from(CacheRepository::REFRESH_TIME_5_MINUTES),
                $forceCacheUpdate
            );
        }
        return $callable();
    }

    final public function paidSubscribers()
    {
        return $this->database->table('subscriptions')
            ->where('start_time <= ?', $this->database::literal('NOW()'))
            ->where('end_time > ?', $this->database::literal('NOW()'))
            ->where('is_paid = 1')
            ->where('user.active = 1');
    }


    /**
     * @param DateTime $from
     * @param DateTime $to
     * @param array    $paidStatus
     *
     * @return \Crm\ApplicationModule\Selection
     */
    final public function paidBetween(DateTime $from, DateTime $to, array $paidStatus = [self::STATUS_PAID])
    {
        return $this->getTable()->where([
            'status IN (?)' => $paidStatus,
            'paid_at > ?' => $from,
            'paid_at < ?' => $to,
        ]);
    }

    final public function subscriptionsWithActiveUnchargedRecurrentEndingNextTwoWeeksCount($forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->subscriptionsWithActiveUnchargedRecurrentEndingBetween(
                \Nette\Utils\DateTime::from('today 00:00'),
                \Nette\Utils\DateTime::from('+14 days 23:59:59')
            )->count('*');
        };

        return $this->cacheRepository->loadAndUpdate(
            'subscriptions_with_active_uncharged_recurrent_ending_next_two_weeks_count',
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::REFRESH_TIME_5_MINUTES),
            $forceCacheUpdate
        );
    }

    final public function subscriptionsWithActiveUnchargedRecurrentEndingNextMonthCount($forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->subscriptionsWithActiveUnchargedRecurrentEndingBetween(
                \Nette\Utils\DateTime::from('today 00:00'),
                \Nette\Utils\DateTime::from('+31 days 23:59:59')
            )->count('*');
        };

        return $this->cacheRepository->loadAndUpdate(
            'subscriptions_with_active_uncharged_recurrent_ending_next_month_count',
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::REFRESH_TIME_5_MINUTES),
            $forceCacheUpdate
        );
    }

    /**
     * @param DateTime $startTime
     * @param DateTime $endTime
     * @return mixed|Selection
     */
    final public function subscriptionsWithActiveUnchargedRecurrentEndingBetween(DateTime $startTime, DateTime $endTime)
    {
        return $this->database->table('subscriptions')
            ->where(':payments.id IS NOT NULL')
            ->where(':payments:recurrent_payments(parent_payment_id).status IS NULL')
            ->where(':payments:recurrent_payments(parent_payment_id).retries > ?', 0)
            ->where(':payments:recurrent_payments(parent_payment_id).state = ?', 'active')
            ->where('next_subscription_id IS NULL')
            ->where('end_time >= ?', $startTime)
            ->where('end_time <= ?', $endTime);
    }


    /**
     * Cached value since computation of the value for next two weeks interval may be slow
     *
     * @param bool $forceCacheUpdate
     * @param bool $onlyPaid
     * @return int
     */
    final public function subscriptionsWithoutExtensionEndingNextTwoWeeksCount($forceCacheUpdate = false, $onlyPaid = false)
    {
        $cacheKey = 'subscriptions_without_extension_ending_next_two_weeks_count';
        if ($onlyPaid) {
            $cacheKey = 'paid_' . $cacheKey;
        }

        $callable = function () use ($onlyPaid) {
            return $this->subscriptionsWithoutExtensionEndingBetweenCount(
                \Nette\Utils\DateTime::from('today 00:00'),
                \Nette\Utils\DateTime::from('+14 days 23:59:59'),
                $onlyPaid
            );
        };
        return $this->cacheRepository->loadAndUpdate(
            $cacheKey,
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::REFRESH_TIME_5_MINUTES),
            $forceCacheUpdate
        );
    }

    /**
     * Cached value since computation of the value for next month interval may be slow
     *
     * @param bool $forceCacheUpdate
     *
     * @return int
     */
    final public function subscriptionsWithoutExtensionEndingNextMonthCount($forceCacheUpdate = false, $onlyPaid = false)
    {
        $cacheKey = 'subscriptions_without_extension_ending_next_month_count';
        if ($onlyPaid) {
            $cacheKey = 'paid_' . $cacheKey;
        }

        $callable = function () use ($onlyPaid) {
            return $this->subscriptionsWithoutExtensionEndingBetweenCount(
                \Nette\Utils\DateTime::from('today 00:00'),
                \Nette\Utils\DateTime::from('+31 days 23:59:59'),
                $onlyPaid
            );
        };
        return $this->cacheRepository->loadAndUpdate(
            $cacheKey,
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::REFRESH_TIME_5_MINUTES),
            $forceCacheUpdate
        );
    }

    final public function subscriptionsWithoutExtensionEndingBetweenCount(DateTime $startTime, DateTime $endTime, $onlyPaid = false)
    {
        $s = $startTime;
        $e = $endTime;

        $renewedSubscriptionsEndingBetweenSql = <<<SQL
SELECT subscriptions.id 
FROM subscriptions 
LEFT JOIN payments ON subscriptions.id = payments.subscription_id 
LEFT JOIN recurrent_payments ON payments.id = recurrent_payments.parent_payment_id 
WHERE payments.id IS NOT NULL AND 
recurrent_payments.status IS NULL AND
recurrent_payments.retries > 0 AND 
recurrent_payments.state = 'active' AND
next_subscription_id IS NULL AND 
end_time >= ? AND 
end_time <= ?
SQL;

        $q = <<<SQL
        
SELECT COUNT(subscriptions.id) as total FROM subscriptions 
LEFT JOIN subscription_types ON subscription_types.id = subscriptions.subscription_type_id
WHERE subscriptions.id IS NOT NULL AND end_time >= ? AND end_time <= ? AND subscriptions.id NOT IN (
  SELECT id FROM subscriptions WHERE end_time >= ? AND end_time <= ? AND next_subscription_id IS NOT NULL
  UNION 
  ($renewedSubscriptionsEndingBetweenSql)
)
SQL;

        if ($onlyPaid) {
            $q .= " AND subscription_types.price > 0 AND subscriptions.type NOT IN ('free')";
        }

        return $this->getDatabase()->fetch($q, $s, $e, $s, $e, $s, $e)->total;
    }

    /**
     * WARNING: slow for wide intervals
     * @param DateTime $startTime
     * @param DateTime $endTime
     * @return Selection
     */
    final public function subscriptionsWithoutExtensionEndingBetween(DateTime $startTime, DateTime $endTime)
    {
        $endingSubscriptions = $this->subscriptionsRepository->subscriptionsEndingBetween($startTime, $endTime)->select('subscriptions.id')->fetchAll();
        $renewedSubscriptions = $this->subscriptionsRepository->renewedSubscriptionsEndingBetween($startTime, $endTime)->select('subscriptions.id')->fetchAll();
        $activeUnchargedSubscriptions = $this->subscriptionsWithActiveUnchargedRecurrentEndingBetween($startTime, $endTime)->select('subscriptions.id')->fetchAll();

        $ids = array_diff($endingSubscriptions, $renewedSubscriptions, $activeUnchargedSubscriptions);

        return $this->database
            ->table('subscriptions')
            ->where('id IN (?)', $ids);
    }

    /**
     * @param DateTime $from
     * @return Selection
     */
    final public function unconfirmedPayments(DateTime $from)
    {
        return $this->getTable()
            ->where('payments.status = ?', self::STATUS_FORM)
            ->where('payments.created_at >= ?', $from)
            ->order('payments.created_at DESC');
    }

    /**
     * @param string $urlKey
     * @return \Crm\ApplicationModule\Selection
     */
    final public function findBySalesFunnelUrlKey(string $urlKey)
    {
        return $this->getTable()
            ->where('sales_funnel.url_key', $urlKey);
    }
}
