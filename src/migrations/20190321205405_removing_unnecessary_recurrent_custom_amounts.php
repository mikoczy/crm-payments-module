<?php


use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

class RemovingUnnecessaryRecurrentCustomAmounts extends AbstractMigration
{
    public function up()
    {
        $sql = <<<SQL
UPDATE recurrent_payments
SET custom_amount = null
WHERE id in (

  SELECT * FROM (
    SELECT recurrent_payments.id FROM recurrent_payments
    JOIN subscription_types on subscription_type_id = subscription_types.id
    WHERE state = 'active'
    AND recurrent_payments.next_subscription_type_id IS NULL
    AND custom_amount IS NOT NULL
    AND CAST(custom_amount AS DECIMAL) = CAST(subscription_types.price AS DECIMAL)
  ) t1
)
SQL;

        $this->execute($sql);
    }

    public function down()
    {
        throw new IrreversibleMigrationException();
    }
}
