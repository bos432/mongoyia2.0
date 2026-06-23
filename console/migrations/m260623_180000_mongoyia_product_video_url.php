<?php

use yii\db\Migration;

class m260623_180000_mongoyia_product_video_url extends Migration
{
    public function safeUp()
    {
        $productSchema = $this->db->schema->getTableSchema('{{%mall_product}}', true);
        if ($productSchema === null) {
            echo "Table {{%mall_product}} not found, skip product video URL field.\n";
            return true;
        }

        if (!isset($productSchema->columns['video_url'])) {
            $this->addColumn('{{%mall_product}}', 'video_url', $this->string(1024)->notNull()->defaultValue('')->comment('商品视频URL')->after('images'));
        }

        return true;
    }

    public function safeDown()
    {
        $productSchema = $this->db->schema->getTableSchema('{{%mall_product}}', true);
        if ($productSchema !== null && isset($productSchema->columns['video_url'])) {
            $this->dropColumn('{{%mall_product}}', 'video_url');
        }

        return true;
    }
}
