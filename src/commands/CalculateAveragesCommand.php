<?php

namespace Crm\PaymentsModule\Commands;

// TODO: [payments_module] refactor or move from payments module (dependencies on ProductsModule and SubscriptionsModule)
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\ProductsModule\PaymentItem\ProductPaymentItem;
use Nette\Database\Context;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CalculateAveragesCommand extends \Symfony\Component\Console\Command\Command
{
    private $database;

    public function __construct(Context $database)
    {
        parent::__construct();
        $this->database = $database;
    }

    protected function configure()
    {
        $this->setName('payments:calculate_averages')
            ->setDescription('Calculate averages');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $paidStatus = PaymentsRepository::STATUS_PAID;
        $this->database->query(<<<SQL
            INSERT INTO user_meta (`user_id`,`key`,`value`,`created_at`,`updated_at`)
            SELECT
                id,
                'subscription_payments',
                (
                    SELECT COUNT(*)
                    FROM payments
                    WHERE
                        status='$paidStatus'
                        AND payments.user_id = users.id
                        AND payments.subscription_id IS NOT NULL
                ),
                NOW(),
                NOW()
            FROM users
            ON DUPLICATE KEY UPDATE `updated_at`=NOW(), `value`=VALUES(value);
SQL
        );

        $productType = ProductPaymentItem::TYPE;
        $this->database->query(<<<SQL
            INSERT INTO user_meta (`user_id`,`key`,`value`,`created_at`,`updated_at`)
            SELECT
                id,
                'product_payments',
                (
                    SELECT COUNT(DISTINCT(payments.id))
                    FROM payments
                    INNER JOIN payment_items ON payment_items.payment_id = payments.id AND payment_items.type = '$productType'
                    WHERE
                        payments.status='$paidStatus'
                        AND payments.user_id = users.id
                ),
                NOW(),
                NOW()
            FROM users
            ON DUPLICATE KEY UPDATE `updated_at`=NOW(), `value`=VALUES(value);
SQL
        );

        $this->database->query(<<<SQL
            INSERT INTO user_meta (`user_id`,`key`,`value`,`created_at`,`updated_at`)
            SELECT
                id,
                'avg_month_payment',
                (
                    SELECT COALESCE(AVG(payments.amount / subscription_types.length * 31), 0)
                    FROM payments
                    INNER JOIN subscription_types ON subscription_types.id = payments.subscription_type_id AND subscription_types.length > 0
                    WHERE
                        payments.status='$paidStatus'
                        AND payments.user_id = users.id
                ),
                NOW(),
                NOW()
            FROM users
            ON DUPLICATE KEY UPDATE `updated_at`=NOW(), `value`=VALUES(value);
SQL
        );
    }
}
