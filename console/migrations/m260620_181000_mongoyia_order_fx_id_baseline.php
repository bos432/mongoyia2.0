<?php

use yii\db\Migration;

class m260620_181000_mongoyia_order_fx_id_baseline extends Migration
{
    public function safeUp()
    {
        $table = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($table === null || isset($table->columns['fx_id'])) {
            return true;
        }

        $this->addColumn(
            '{{%mall_order}}',
            'fx_id',
            $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('分销用户id')->after('user_id')
        );

        return true;
    }

    public function safeDown()
    {
        $table = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($table === null || !isset($table->columns['fx_id'])) {
            return true;
        }

        $this->dropColumn('{{%mall_order}}', 'fx_id');

        return true;
    }
}
