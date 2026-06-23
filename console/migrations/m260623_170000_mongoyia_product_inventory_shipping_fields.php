<?php

use yii\db\Migration;

class m260623_170000_mongoyia_product_inventory_shipping_fields extends Migration
{
    public function safeUp()
    {
        $productSchema = $this->db->schema->getTableSchema('{{%mall_product}}', true);
        if ($productSchema !== null) {
            if (!isset($productSchema->columns['shipment_timeout_hours'])) {
                $this->addColumn('{{%mall_product}}', 'shipment_timeout_hours', $this->integer()->notNull()->defaultValue(72)->comment('发货时效小时')->after('stock_warning'));
            }
            $productSchema = $this->db->schema->getTableSchema('{{%mall_product}}', true);
            if (!isset($productSchema->columns['shipment_timeout_deduct_fee'])) {
                $this->addColumn('{{%mall_product}}', 'shipment_timeout_deduct_fee', $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('发货超时预存金扣费')->after('shipment_timeout_hours'));
            }
        } else {
            echo "Table {{%mall_product}} not found, skip product inventory shipping fields.\n";
        }

        $skuSchema = $this->db->schema->getTableSchema('{{%mall_product_sku}}', true);
        if ($skuSchema !== null) {
            if (!isset($skuSchema->columns['inventory_location'])) {
                $this->addColumn('{{%mall_product_sku}}', 'inventory_location', $this->string(128)->notNull()->defaultValue('')->comment('库存地点')->after('stock_code'));
            }
        } else {
            echo "Table {{%mall_product_sku}} not found, skip SKU inventory location field.\n";
        }

        return true;
    }

    public function safeDown()
    {
        $skuSchema = $this->db->schema->getTableSchema('{{%mall_product_sku}}', true);
        if ($skuSchema !== null && isset($skuSchema->columns['inventory_location'])) {
            $this->dropColumn('{{%mall_product_sku}}', 'inventory_location');
        }

        $productSchema = $this->db->schema->getTableSchema('{{%mall_product}}', true);
        if ($productSchema !== null && isset($productSchema->columns['shipment_timeout_deduct_fee'])) {
            $this->dropColumn('{{%mall_product}}', 'shipment_timeout_deduct_fee');
        }
        $productSchema = $this->db->schema->getTableSchema('{{%mall_product}}', true);
        if ($productSchema !== null && isset($productSchema->columns['shipment_timeout_hours'])) {
            $this->dropColumn('{{%mall_product}}', 'shipment_timeout_hours');
        }

        return true;
    }
}
