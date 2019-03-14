<?php

namespace Crm\PaymentsModule\Repository;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Repository;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\PaymentItem\PaymentItemInterface;
use Crm\ProductsModule\Repository\ProductsRepository;
use League\Event\Emitter;
use Nette\Caching\IStorage;
use Nette\Database\Context;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class PaymentItemsRepository extends Repository
{
    protected $tableName = 'payment_items';

    private $productsRepository;

    private $applicationConfig;

    private $emitter;

    public function __construct(
        Context $database,
        ProductsRepository $productsRepository,
        ApplicationConfig $applicationConfig,
        Emitter $emitter,
        IStorage $cacheStorage = null
    ) {
        parent::__construct($database, $cacheStorage);
        $this->productsRepository = $productsRepository;
        $this->applicationConfig = $applicationConfig;
        $this->emitter = $emitter;
    }

    public function add(IRow $payment, PaymentItemContainer $container): array
    {
        $rows = [];
        /** @var PaymentItemInterface $item */
        foreach ($container->items() as $item) {
            $data = [
                'payment_id' => $payment->id,
                'type' => $item->type(),
                'count' => $item->count(),
                'name' => $item->name(),
                'amount' => $item->price(),
                'vat' => $item->vat(),
                'created_at' => new DateTime(),
                'updated_at' => new DateTime(),
            ];
            foreach ($item->data() as $key => $value) {
                $data[$key] = $value;
            }
            $rows[] = $this->insert($data);
        }
        return $rows;
    }

    public function deleteByPayment(IRow $payment)
    {
        return $this->getTable()
            ->where('payment_id', $payment->id)
            ->delete();
    }
}
