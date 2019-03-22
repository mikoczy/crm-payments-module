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
        VariableSymbol $variableSymbol,
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

    public function add(
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
        $invoiceable = true
    ) {
        $data = [
            'user_id' => $user->id,
            'payment_gateway_id' => $paymentGateway->id,
            'status' => self::STATUS_FORM,
            'created_at' => new DateTime(),
            'modified_at' => new DateTime(),
            'variable_symbol' => $variableSymbol ? $variableSymbol : $this->variableSymbol->getNew(),
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
            'invoiceable' => $invoiceable,
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

        $this->emitter->emit(new NewPaymentEvent($payment));
        return $payment;
    }

    public function addMeta($payment, $data)
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

    public function copyPayment(ActiveRow $payment)
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
            'invoiceable' => $payment->invoiceable,
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

    public function getPaymentItems(ActiveRow $payment): array
    {
        $items = [];
        foreach ($payment->related('payment_items') as $paymentItem) {
            $items[] = [
                'name' => $paymentItem->name,
                'amount' => $paymentItem->amount,
                'vat' => $paymentItem->vat,
                'count' => $paymentItem->count,
            ];
        }
        return $items;
    }

    public function update(IRow &$row, $data, PaymentItemContainer $paymentItemContainer = null)
    {
        if ($paymentItemContainer) {
            $this->paymentItemsRepository->deleteByPayment($row);
            $this->paymentItemsRepository->add($row, $paymentItemContainer);
        }

        $values['modified_at'] = new DateTime();
        return parent::update($row, $data);
    }

    public function updateStatus(ActiveRow $payment, $status, $sendEmail = false, $note = null, $errorMessage = null, $salesFunnelId = null)
    {
        $data = [
            'status' => $status,
            'modified_at' => new DateTime()
        ];
        if ($status == self::STATUS_PAID && !$payment->paid_at) {
            $data['paid_at'] = new DateTime();
        }
        if ($note) {
            $data['note'] = $note;
        }
        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }

        if ($payment->status == static::STATUS_PAID && $data['status'] == static::STATUS_FAIL) {
            Debugger::log("attempt to make change payment status from [paid] to [fail]");
            return false;
        }

        parent::update($payment, $data);

        $this->emitter->emit(new PaymentChangeStatusEvent($payment, $sendEmail));
        $this->hermesEmitter->emit(new HermesMessage('payment-status-change', [
            'payment_id' => $payment->id,
            'sales_funnel_id' => $payment->sales_funnel_id ?? $salesFunnelId, // pass explicit sales_funnel_id if payment doesn't contain one
        ]));

        return $payment;
    }

    /**
     * @param $variableSymbol
     * @return ActiveRow
     */
    public function findByVs($variableSymbol)
    {
        return $this->findBy('variable_symbol', $this->variableSymbolVariants($variableSymbol));
    }

    private function variableSymbolVariants($variableSymbol)
    {
        $variableSymbolVariant = new VariableSymbolVariant();
        return $variableSymbolVariant->variableSymbolVariants($variableSymbol);
    }

    public function findLastByVS(string $variableSymbol)
    {
        return $this->findAllByVS($variableSymbol)->order('created_at DESC')->limit(1)->fetch();
    }

    public function findAllByVS(string $variableSymbol)
    {
        return $this->getTable()->where(
            'variable_symbol',
            $this->variableSymbolVariants($variableSymbol)
        );
    }

    public function addSubscriptionToPayment(IRow $subscription, IRow $payment)
    {
        return parent::update($payment, ['subscription_id' => $subscription->id]);
    }

    public function subscriptionPayment(IRow $subscription)
    {
        return $this->getTable()->where(['subscription_id' => $subscription->id])->select('*')->limit(1)->fetch();
    }

    /**
     * @param int $userId
     * @return \Nette\Database\Table\Selection
     */
    public function userPayments($userId)
    {
        return $this->getTable()->where(['user_id' => $userId])->order('created_at DESC');
    }

    public function userPaymentsWithRecurrent($userId)
    {
        return $this->getTable()->where(['payments.user_id' => $userId])->order('created_at DESC');
    }

    /**
     * @param string $text
     * @param int $payment_gateway
     * @param int $subscription_type
     * @param string $status
     * @param string|\DateTime $start
     * @param string|\DateTime $end
     * @param int $sales_funnel
     * @param bool $donation
     * @param bool $recurrentCharge
     * @return Selection
     */
    public function all($text = '', $payment_gateway = null, $subscription_type = null, $status = null, $start = null, $end = null, $sales_funnel = null, $donation = null, $recurrentCharge = null)
    {
        $where = [];
        if ($text != '') {
            $where['variable_symbol LIKE ? OR note LIKE ?'] = ["%{$text}%", "%{$text}%"];
        }
        if ($payment_gateway) {
            $where['payment_gateway_id'] = $payment_gateway;
        }
        if ($subscription_type) {
            $where['subscription_type_id'] = $subscription_type;
        }
        if ($status) {
            $where['status'] = $status;
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
        return $this->getTable()->where($where);
    }

    public function allWithoutOrder()
    {
        return $this->getTable();
    }

    public function totalAmountSum($allowCached = false, $forceCacheUpdate = false)
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

    public function totalUserAmountSum($userId)
    {
        return $this->getTable()->where(['user_id' => $userId, 'status' => self::STATUS_PAID])->sum('amount');
    }

    public function totalUserAmounts()
    {
        return $this->getDatabase()->query("SELECT user_id,email,SUM(amount) AS total FROM payments INNER JOIN users ON users.id=payments.user_id WHERE payments.status='paid' GROUP BY user_id ORDER BY total DESC");
    }

    public function getStatusPairs()
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

    public function getPaymentsWithNotes()
    {
        return $this->getTable()->where(['NOT note' => null])->order('created_at DESC');
    }

    public function totalCount($allowCached = false, $forceCacheUpdate = false)
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

    public function paidSubscribersCount($allowCached = false, $forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->paidSubscribers()
                ->select('COUNT(DISTINCT(subscriptions.user_id)) AS total')
                ->fetch()->total;
        };

        if ($allowCached) {
            return $this->cacheRepository->loadAndUpdate(
                'paid_subscribers_count',
                $callable,
                \Nette\Utils\DateTime::from('-1 hour'),
                $forceCacheUpdate
            );
        }

        return $callable();
    }

    public function paidSubscribers()
    {
        return $this->database->table('subscriptions')
            ->where('start_time < ?', $this->database::literal('NOW()'))
            ->where('end_time > ?', $this->database::literal('NOW()'))
            ->where('user.active = 1')
            ->where(':payments.id IS NOT NULL OR type IN (?)', ['upgrade', 'prepaid', 'gift']);
    }

    public function freeSubscribersCount($allowCached = false, $forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->freeSubscribers()
                ->select('COUNT(DISTINCT(subscriptions.user_id)) AS total')
                ->fetch()->total;
        };

        if ($allowCached) {
            return $this->cacheRepository->loadAndUpdate(
                'free_subscribers_count',
                $callable,
                \Nette\Utils\DateTime::from('-1 hour'),
                $forceCacheUpdate
            );
        }

        return $callable();
    }

    public function freeSubscribers()
    {
        $freeSubscribers = $this->database->table('subscriptions')
            ->where('start_time < ?', $this->database::literal('NOW()'))
            ->where('end_time > ?', $this->database::literal('NOW()'))
            ->where('user.active = 1')
            ->where(':payments.id IS NULL AND type NOT IN (?)', ['upgrade', 'prepaid', 'gift']);

        $paidSubscribers = $this->paidSubscribers()->select('subscriptions.user_id');
        if ($paidSubscribers->fetchAll()) {
            $freeSubscribers->where('subscriptions.user_id NOT IN (?)', $paidSubscribers);
        }

        return $freeSubscribers;
    }

    /**
     * @param DateTime $from
     * @param DateTime $to
     * @return \Crm\ApplicationModule\Selection
     */
    public function paidBetween(DateTime $from, DateTime $to)
    {
        return $this->getTable()->where([
            'status' => self::STATUS_PAID,
            'paid_at > ?' => $from,
            'paid_at < ?' => $to,
        ]);
    }

    public function subscriptionsWithActiveUnchargedRecurrentEndingNextTwoWeeksCount($forceCacheUpdate = false)
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

    public function subscriptionsWithActiveUnchargedRecurrentEndingNextMonthCount($forceCacheUpdate = false)
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
    public function subscriptionsWithActiveUnchargedRecurrentEndingBetween(DateTime $startTime, DateTime $endTime)
    {
        return $this->database->table('subscriptions')
            ->where(':payments.id IS NOT NULL')
            ->where(':payments:recurrent_payments(parent_payment_id).status IS NULL')
            ->where(':payments:recurrent_payments(parent_payment_id).retries > ?', 3)
            ->where(':payments:recurrent_payments(parent_payment_id).state = ?', 'active')
            ->where('next_subscription_id IS NULL')
            ->where('end_time >= ?', $startTime)
            ->where('end_time <= ?', $endTime);
    }


    /**
     * Cached value since computation of the value for next two weeks interval may be slow
     *
     * @param bool $forceCacheUpdate
     *
     * @return int
     */
    public function subscriptionsWithoutExtensionEndingNextTwoWeeksCount($forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->subscriptionsWithoutExtensionEndingBetweenCount(
                \Nette\Utils\DateTime::from('today 00:00'),
                \Nette\Utils\DateTime::from('+14 days 23:59:59')
            );
        };
        return $this->cacheRepository->loadAndUpdate(
            'subscriptions_without_extension_ending_next_two_weeks_count',
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
    public function subscriptionsWithoutExtensionEndingNextMonthCount($forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->subscriptionsWithoutExtensionEndingBetweenCount(
                \Nette\Utils\DateTime::from('today 00:00'),
                \Nette\Utils\DateTime::from('+31 days 23:59:59')
            );
        };
        return $this->cacheRepository->loadAndUpdate(
            'subscriptions_without_extension_ending_next_month_count',
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::REFRESH_TIME_5_MINUTES),
            $forceCacheUpdate
        );
    }

    public function subscriptionsWithoutExtensionEndingBetweenCount(DateTime $startTime, DateTime $endTime)
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
recurrent_payments.retries > 3 AND 
recurrent_payments.state = 'active' AND
next_subscription_id IS NULL AND 
end_time >= ? AND 
end_time <= ?
SQL;

        $q = <<<SQL
        
SELECT COUNT(id) as total FROM subscriptions 
WHERE id IS NOT NULL AND end_time >= ? AND end_time <= ? AND id NOT IN (
  SELECT id FROM subscriptions WHERE end_time >= ? AND end_time <= ? AND next_subscription_id IS NOT NULL
  UNION 
  ($renewedSubscriptionsEndingBetweenSql)
)
SQL;
        return $this->getDatabase()->fetch($q, $s, $e, $s, $e, $s, $e)->total;
    }

    /**
     * WARNING: slow for wide intervals
     * @param DateTime $startTime
     * @param DateTime $endTime
     * @return Selection
     */
    public function subscriptionsWithoutExtensionEndingBetween(DateTime $startTime, DateTime $endTime)
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
    public function unconfirmedPayments(DateTime $from)
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
    public function findBySalesFunnelUrlKey(string $urlKey)
    {
        return $this->getTable()
            ->where('sales_funnel.url_key', $urlKey);
    }
}
