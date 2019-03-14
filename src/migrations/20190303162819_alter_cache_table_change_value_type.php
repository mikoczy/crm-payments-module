<?php


use Phinx\Migration\AbstractMigration;

class AlterCacheTableChangeValueType extends AbstractMigration
{
    public function change()
    {
        $this->table('cache')
            ->changeColumn('value', 'text')
            ->update();
    }
}
