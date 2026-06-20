<?php

use yii\db\Migration;

class m260618_141000_mongoyia_merchant_onboarding_phase2 extends Migration
{
    public function safeUp()
    {
        $this->createMerchantApplicationTable();
        $this->createStoreCategoryAuthTable();
        $this->addProductAuditColumns();
        $this->addStoreProfileColumns();

        return true;
    }

    public function safeDown()
    {
        $this->dropStoreProfileColumns();
        $this->dropProductAuditColumns();

        if ($this->tableExists('{{%store_category_auth}}')) {
            $this->dropTable('{{%store_category_auth}}');
        }

        if ($this->tableExists('{{%merchant_application}}')) {
            $this->dropTable('{{%merchant_application}}');
        }

        return true;
    }

    private function createMerchantApplicationTable()
    {
        if ($this->tableExists('{{%merchant_application}}')) {
            echo "Table {{%merchant_application}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%merchant_application}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('审核通过后的店铺'),
            'user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('申请用户'),
            'applicant_name' => $this->string(120)->notNull()->defaultValue('')->comment('申请人'),
            'mobile' => $this->string(64)->notNull()->defaultValue('')->comment('手机号'),
            'email' => $this->string(160)->notNull()->defaultValue('')->comment('邮箱'),
            'company_name' => $this->string(180)->notNull()->defaultValue('')->comment('公司/商家名称'),
            'business_license' => $this->string(255)->notNull()->defaultValue('')->comment('营业执照'),
            'requested_category_ids' => $this->text()->null()->comment('申请经营分类'),
            'audit_status' => $this->string(32)->notNull()->defaultValue('submitted')->comment('审核状态'),
            'audit_remark' => $this->string(255)->notNull()->defaultValue('')->comment('审核备注'),
            'submitted_at' => $this->integer()->notNull()->defaultValue(0)->comment('提交时间'),
            'reviewed_at' => $this->integer()->notNull()->defaultValue(0)->comment('审核时间'),
            'reviewer_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('审核人'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="商家入驻申请"');

        $this->createIndex('merchant_application_k0', '{{%merchant_application}}', 'store_id');
        $this->createIndex('merchant_application_k1', '{{%merchant_application}}', 'user_id');
        $this->createIndex('merchant_application_k2', '{{%merchant_application}}', 'audit_status');
        $this->createIndex('merchant_application_k3', '{{%merchant_application}}', 'created_at');
    }

    private function createStoreCategoryAuthTable()
    {
        if ($this->tableExists('{{%store_category_auth}}')) {
            echo "Table {{%store_category_auth}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%store_category_auth}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->comment('店铺'),
            'category_id' => $this->bigInteger()->unsigned()->notNull()->comment('授权分类'),
            'source_application_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('来源入驻申请'),
            'audit_status' => $this->string(32)->notNull()->defaultValue('approved')->comment('授权状态'),
            'audit_remark' => $this->string(255)->notNull()->defaultValue('')->comment('审核备注'),
            'authorized_at' => $this->integer()->notNull()->defaultValue(0)->comment('授权时间'),
            'expires_at' => $this->integer()->notNull()->defaultValue(0)->comment('到期时间'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="店铺经营分类授权"');

        $this->createIndex('store_category_auth_u0', '{{%store_category_auth}}', ['store_id', 'category_id'], true);
        $this->createIndex('store_category_auth_k0', '{{%store_category_auth}}', 'category_id');
        $this->createIndex('store_category_auth_k1', '{{%store_category_auth}}', 'source_application_id');
    }

    private function addProductAuditColumns()
    {
        $table = '{{%mall_product}}';
        if (!$this->columnExists($table, 'audit_status')) {
            $this->addColumn($table, 'audit_status', $this->string(32)->notNull()->defaultValue('approved')->comment('商品审核状态')->after('sales'));
            $this->createIndex('mall_product_audit_status_k0', $table, 'audit_status');
        }
        if (!$this->columnExists($table, 'audit_remark')) {
            $this->addColumn($table, 'audit_remark', $this->string(255)->notNull()->defaultValue('')->comment('商品审核备注')->after('audit_status'));
        }
        if (!$this->columnExists($table, 'reviewed_at')) {
            $this->addColumn($table, 'reviewed_at', $this->integer()->notNull()->defaultValue(0)->comment('商品审核时间')->after('audit_remark'));
        }
        if (!$this->columnExists($table, 'reviewer_id')) {
            $this->addColumn($table, 'reviewer_id', $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('商品审核人')->after('reviewed_at'));
        }
    }

    private function addStoreProfileColumns()
    {
        $table = '{{%store}}';
        $columns = [
            'name_en' => $this->string(255)->notNull()->defaultValue('')->comment('英文店铺名')->after('name'),
            'name_mn' => $this->string(255)->notNull()->defaultValue('')->comment('蒙文店铺名')->after('name_en'),
            'brief_en' => $this->string(255)->notNull()->defaultValue('')->comment('英文简介')->after('brief'),
            'brief_mn' => $this->string(255)->notNull()->defaultValue('')->comment('蒙文简介')->after('brief_en'),
            'main_products' => $this->string(255)->notNull()->defaultValue('')->comment('主营产品')->after('brief_mn'),
            'logo' => $this->string(255)->notNull()->defaultValue('')->comment('店铺Logo')->after('qrcode'),
            'contact_name' => $this->string(120)->notNull()->defaultValue('')->comment('联系人')->after('logo'),
            'contact_phone' => $this->string(64)->notNull()->defaultValue('')->comment('联系电话')->after('contact_name'),
            'business_hours' => $this->string(120)->notNull()->defaultValue('')->comment('营业时间')->after('contact_phone'),
        ];

        foreach ($columns as $name => $definition) {
            if (!$this->columnExists($table, $name)) {
                $this->addColumn($table, $name, $definition);
            }
        }
    }

    private function dropProductAuditColumns()
    {
        $table = '{{%mall_product}}';
        if ($this->columnExists($table, 'audit_status')) {
            $this->dropIndex('mall_product_audit_status_k0', $table);
        }
        foreach (['reviewer_id', 'reviewed_at', 'audit_remark', 'audit_status'] as $column) {
            if ($this->columnExists($table, $column)) {
                $this->dropColumn($table, $column);
            }
        }
    }

    private function dropStoreProfileColumns()
    {
        foreach (['business_hours', 'contact_phone', 'contact_name', 'logo', 'main_products', 'brief_mn', 'brief_en', 'name_mn', 'name_en'] as $column) {
            if ($this->columnExists('{{%store}}', $column)) {
                $this->dropColumn('{{%store}}', $column);
            }
        }
    }

    private function tableExists(string $table): bool
    {
        return $this->db->schema->getTableSchema($table, true) !== null;
    }

    private function columnExists(string $table, string $column): bool
    {
        $schema = $this->db->schema->getTableSchema($table, true);
        return $schema !== null && isset($schema->columns[$column]);
    }
}
