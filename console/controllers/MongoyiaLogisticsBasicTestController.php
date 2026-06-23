<?php

namespace console\controllers;

use common\models\mall\Order;
use common\models\mall\LogisticsMethod;
use common\models\mall\StoreLogisticsMethod;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaLogisticsBasicTestController extends Controller
{
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia logistics basic closure test\n");

        $this->checkOrderSchema();
        $this->checkLogisticsMethodSchema();
        $this->checkOrderMethods();
        $this->checkBackendEntrances();
        $this->checkFrontendEntrances();
        $this->checkLogisticsMethodFixture();
        $this->checkShipmentData();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkOrderSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'parent_id', 'payment_status', 'shipment_id', 'shipment_name', 'shipment_fee', 'shipment_fee_deducted_at', 'shipment_status', 'shipped_at', 'wlgs', 'wldh']);
    }

    private function checkLogisticsMethodSchema()
    {
        $this->requireColumns('{{%logistics_method}}', ['id', 'name', 'code', 'provider', 'base_fee', 'fee_per_kg', 'fee_per_volume', 'tracking_url', 'status']);
        $this->requireColumns('{{%store_logistics_method}}', ['id', 'store_id', 'logistics_method_id', 'selection_status', 'selected_at', 'status']);
        foreach ([LogisticsMethod::class, StoreLogisticsMethod::class] as $class) {
            if (!class_exists($class)) {
                $this->fail("Missing model {$class}.");
                return;
            }
        }
        $this->ok('Logistics method models exist.');
    }

    private function checkOrderMethods()
    {
        $this->section('Order methods');
        foreach (['markShipped', 'markReceived', 'syncParentShipmentStatus', 'deductStockIfNeeded', 'refundStockIfNeeded', 'deductShipmentFeeIfNeeded'] as $method) {
            if (!method_exists(Order::class, $method)) {
                $this->fail("Order missing {$method}().");
                return;
            }
        }
        foreach (['SHIPMENT_STATUS_UNSHIPPED', 'SHIPMENT_STATUS_PREPARING', 'SHIPMENT_STATUS_SHIPPING', 'SHIPMENT_STATUS_RECEIVED'] as $constant) {
            if (!defined(Order::class . '::' . $constant)) {
                $this->fail("Order missing constant {$constant}.");
                return;
            }
        }
        $this->ok('Order shipment methods and status constants exist.');
    }

    private function checkBackendEntrances()
    {
        $this->section('Backend entrances');
        $this->requireFileContains('@app/../backend/modules/mall/controllers/OrderController.php', [
            'MONGOYIA_BACKEND_ORDER_SHIPMENT_POST_ID_GUARD_V1',
            '$request->isPost ? $request->post(\'id\', 0) : $request->get(\'id\')',
            'markShipped',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/order/fh-ajax.php', [
            'shipment_id',
            'shipment_name',
            'data-mongoyia-order-shipment-post-id-guard',
            "Html::hiddenInput('id'",
            "'action' => Url::to(['fh-ajax'])",
            "'validationUrl' => Url::to(['fh-ajax'])",
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/order/index.php', ['shipment_status']);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/LogisticsMethodController.php', ['actionIndex', 'actionSelect', 'actionUnselect']);
        $this->requireFileContains('@app/../backend/modules/mall/views/logistics-method/index.php', ['物流方式', '店铺选择']);
        $this->requireFileContains('@app/../backend/modules/mall/views/logistics-method/edit.php', ['编辑物流方式', 'fee_per_kg']);
        $this->checkPermission('/mall/logistics-method/index');
    }

    private function checkFrontendEntrances()
    {
        $this->section('Frontend entrances');
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/OrderController.php', [
            'MONGOYIA_BUYER_ORDER_RECEIVED_POST_ID_GUARD_V1',
            'markReceived',
            "post('id', 0)",
        ]);
        $this->requireFileContains('@app/../common/models/mall/Order.php', ['Only shipped orders can be received']);
        $this->requireFileContains('@app/../web/resources/mall/default/views/order/view.php', ['shipment_name', 'shipment_id', 'Awaiting shipment', 'Write a review', '/mall/order/review', 'data-mongoyia-buyer-received-post-guard', "hiddenInput('id'"]);
        $this->requireFileContains('@app/../web/resources/mall/default/views/user/order_.php', ['shipment_status', '/mall/order/review', 'Received', 'data-mongoyia-buyer-received-post-guard', "hiddenInput('id'"]);
    }

    private function checkShipmentData()
    {
        $this->section('Shipment data');
        $total = (int)(new \yii\db\Query())->from('{{%mall_order}}')->count('*', Yii::$app->db);
        $paid = (int)(new \yii\db\Query())->from('{{%mall_order}}')->where(['payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])->count('*', Yii::$app->db);
        $shipped = (int)(new \yii\db\Query())->from('{{%mall_order}}')->where(['>=', 'shipment_status', Order::SHIPMENT_STATUS_SHIPPING])->count('*', Yii::$app->db);
        $received = (int)(new \yii\db\Query())->from('{{%mall_order}}')->where(['>=', 'shipment_status', Order::SHIPMENT_STATUS_RECEIVED])->count('*', Yii::$app->db);

        $this->ok("Order rows: {$total}; paid/COD: {$paid}; shipped+: {$shipped}; received: {$received}.");
        if ($paid === 0) {
            $this->warn('No paid/COD order data found for shipment manual verification.');
        }
        if ($shipped === 0) {
            $this->warn('No shipped order data found for tracking page verification.');
        }
    }

    private function checkLogisticsMethodFixture()
    {
        $this->section('Logistics method fixture');
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $now = time();
            $method = new LogisticsMethod();
            $method->name = 'LOGFIX Express ' . date('YmdHis');
            $method->code = 'LOGFIX';
            $method->provider = 'LOGFIX Provider';
            $method->base_fee = 10;
            $method->fee_per_kg = 2;
            $method->fee_per_volume = 3;
            $method->tracking_url = 'https://tracking.example.invalid/{sn}';
            $method->status = LogisticsMethod::STATUS_ACTIVE;
            if (!$method->save()) {
                throw new \RuntimeException(json_encode($method->errors, JSON_UNESCAPED_UNICODE));
            }
            $this->ok('Platform logistics method is writable.');

            $selection = new StoreLogisticsMethod();
            $selection->store_id = $this->firstSellerStoreId();
            $selection->logistics_method_id = $method->id;
            $selection->selection_status = StoreLogisticsMethod::SELECTION_ENABLED;
            $selection->selected_at = $now;
            $selection->status = StoreLogisticsMethod::STATUS_ACTIVE;
            if (!$selection->save()) {
                throw new \RuntimeException(json_encode($selection->errors, JSON_UNESCAPED_UNICODE));
            }
            $this->ok('Seller store can select a logistics method.');

            $selection->selection_status = StoreLogisticsMethod::SELECTION_DISABLED;
            $selection->status = StoreLogisticsMethod::STATUS_INACTIVE;
            if (!$selection->save()) {
                throw new \RuntimeException(json_encode($selection->errors, JSON_UNESCAPED_UNICODE));
            }
            $this->ok('Seller store can unselect a logistics method.');

            $transaction->rollBack();
            $this->ok('Logistics method fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Logistics method fixture failed: ' . $e->getMessage());
        }
    }

    private function checkPermission(string $path)
    {
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => $path, 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail("Missing active backend permission {$path}.");
            return;
        }

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail("Seller role 50 is missing {$path} permission.");
            return;
        }

        $this->ok("Permission exists and seller role can access {$path}.");
    }

    private function firstSellerStoreId(): int
    {
        $storeId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['>', 'id', 0])
            ->andWhere(['>', 'status', 0])
            ->andWhere(['not in', 'id', [5]])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
        if ($storeId <= 0) {
            throw new \RuntimeException('No seller store is available for logistics fixture.');
        }

        return $storeId;
    }

    private function requireColumns(string $table, array $columns)
    {
        $schema = Yii::$app->db->schema->getTableSchema($table);
        if (!$schema) {
            $this->fail("Missing table {$table}.");
            return;
        }
        foreach ($columns as $column) {
            if (!isset($schema->columns[$column])) {
                $this->fail("Table {$table} missing column {$column}.");
                return;
            }
        }
        $this->ok("Table {$table} has required columns.");
    }

    private function requireFileContains(string $alias, array $needles)
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function section(string $name)
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message)
    {
        $this->stdout("OK   {$message}\n");
    }

    private function warn(string $message)
    {
        $this->warnings++;
        $this->stdout("WARN {$message}\n");
    }

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
