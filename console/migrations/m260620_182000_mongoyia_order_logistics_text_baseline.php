<?php

use yii\db\Migration;

class m260620_182000_mongoyia_order_logistics_text_baseline extends Migration
{
    public function safeUp()
    {
        $table = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($table === null) {
            return true;
        }

        if (!isset($table->columns['wlgs'])) {
            $this->addColumn(
                '{{%mall_order}}',
                'wlgs',
                $this->string(255)->notNull()->defaultValue('')->comment('物流公司')->after('logistics_review_remark')
            );
        }

        $table = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if (!isset($table->columns['wldh'])) {
            $this->addColumn(
                '{{%mall_order}}',
                'wldh',
                $this->string(255)->notNull()->defaultValue('')->comment('物流单号')->after('wlgs')
            );
        }

        return true;
    }

    public function safeDown()
    {
        $table = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($table === null) {
            return true;
        }

        if (isset($table->columns['wldh'])) {
            $this->dropColumn('{{%mall_order}}', 'wldh');
        }

        $table = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if (isset($table->columns['wlgs'])) {
            $this->dropColumn('{{%mall_order}}', 'wlgs');
        }

        return true;
    }
}
