<?php

namespace console\controllers;

use common\models\BaseModel;
use common\models\mall\Order;
use common\models\mall\PaymentAttempt;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaPaymentAuditController extends Controller
{
    public $limit = 100;
    public $amountTolerance = 0.01;
    public $strict = false;
    public $includeLegacyPaidOrders = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'limit',
            'amountTolerance',
            'strict',
            'includeLegacyPaidOrders',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia payment audit check\n");

        $this->checkSuccessfulAttempts();
        $this->checkDuplicateGatewaySuccess();
        $this->checkPaidOrdersHaveAudit();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSuccessfulAttempts()
    {
        $this->section('Successful attempts');
        $rows = (new \yii\db\Query())
            ->select([
                'pa.id',
                'pa.order_id',
                'pa.store_id',
                'pa.provider',
                'pa.event',
                'pa.merchant_transaction_id',
                'pa.gateway_transaction_id',
                'pa.amount',
                'o.amount AS order_amount',
                'o.payment_status',
                'o.parent_id',
                'o.store_id AS order_store_id',
                'o.sn',
            ])
            ->from('{{%mall_payment_attempt}} pa')
            ->innerJoin('{{%mall_order}} o', 'o.id = pa.order_id')
            ->where(['pa.result' => PaymentAttempt::RESULT_SUCCESS])
            ->andWhere(['>', 'pa.status', BaseModel::STATUS_DELETED])
            ->andWhere(['not like', 'o.sn', 'REGPAY-%', false])
            ->orderBy(['pa.id' => SORT_DESC])
            ->limit((int)$this->limit)
            ->all(Yii::$app->db);

        foreach ($rows as $row) {
            if ((int)$row['parent_id'] !== 0) {
                $this->fail("Success payment attempt {$row['id']} points to child order {$row['order_id']}.");
            }
            if ((int)$row['store_id'] !== (int)$row['order_store_id']) {
                $this->fail("Success payment attempt {$row['id']} store {$row['store_id']} does not match order {$row['order_id']} store {$row['order_store_id']}.");
            }
            if ((int)$row['payment_status'] !== Order::PAYMENT_STATUS_PAID) {
                $this->fail("Success payment attempt {$row['id']} points to unpaid/non-paid order {$row['order_id']} status {$row['payment_status']}.");
            }
            if (abs((float)$row['amount'] - (float)$row['order_amount']) > (float)$this->amountTolerance) {
                $this->fail("Success payment attempt {$row['id']} amount {$row['amount']} does not match order {$row['order_id']} amount {$row['order_amount']}.");
            }
            if (trim((string)$row['merchant_transaction_id']) === '') {
                $this->fail("Success payment attempt {$row['id']} has empty merchant_transaction_id.");
            }
        }

        $this->ok('Checked ' . count($rows) . ' successful payment attempt(s).');
    }

    private function checkDuplicateGatewaySuccess()
    {
        $this->section('Duplicate gateway success');
        $rows = (new \yii\db\Query())
            ->select([
                'pa.provider',
                'pa.gateway_transaction_id',
                'cnt' => 'COUNT(*)',
                'orders' => 'COUNT(DISTINCT pa.order_id)',
            ])
            ->from(['pa' => '{{%mall_payment_attempt}}'])
            ->innerJoin('{{%mall_order}} o', 'o.id = pa.order_id')
            ->where(['pa.result' => PaymentAttempt::RESULT_SUCCESS])
            ->andWhere(['>', 'pa.status', BaseModel::STATUS_DELETED])
            ->andWhere(['not like', 'o.sn', 'REGPAY-%', false])
            ->andWhere(['<>', 'pa.gateway_transaction_id', ''])
            ->groupBy(['pa.provider', 'pa.gateway_transaction_id'])
            ->having(['>', 'COUNT(DISTINCT pa.order_id)', 1])
            ->limit(10)
            ->all(Yii::$app->db);

        if ($rows) {
            foreach ($rows as $row) {
                $this->fail("Gateway transaction {$row['provider']}:{$row['gateway_transaction_id']} succeeded for {$row['orders']} order(s).");
            }
            return;
        }

        $this->ok('No successful gateway transaction is shared across multiple orders.');
    }

    private function checkPaidOrdersHaveAudit()
    {
        $this->section('Paid order audit coverage');
        $query = (new \yii\db\Query())
            ->select(['o.id'])
            ->from('{{%mall_order}} o')
            ->leftJoin('{{%mall_payment_attempt}} pa', 'pa.order_id = o.id AND pa.result = :success AND pa.status > :deleted', [
                ':success' => PaymentAttempt::RESULT_SUCCESS,
                ':deleted' => BaseModel::STATUS_DELETED,
            ])
            ->where(['o.parent_id' => 0, 'o.payment_status' => Order::PAYMENT_STATUS_PAID])
            ->andWhere(['>', 'o.status', BaseModel::STATUS_DELETED])
            ->andWhere(['not like', 'o.sn', 'REGPAY-%', false])
            ->andWhere(['pa.id' => null])
            ->orderBy(['o.id' => SORT_DESC])
            ->limit((int)$this->limit);

        if (!$this->includeLegacyPaidOrders) {
            $query->andWhere(['exists', (new \yii\db\Query())
                ->select(new \yii\db\Expression('1'))
                ->from('{{%mall_order}} child')
                ->where('child.parent_id = o.id')
                ->andWhere(['>', 'child.status', BaseModel::STATUS_DELETED])
            ]);
        }

        $missing = array_map('intval', $query->column(Yii::$app->db));
        if ($missing) {
            $message = 'Paid parent orders missing successful payment audit rows: ' . implode(',', array_slice($missing, 0, 20));
            $this->includeLegacyPaidOrders ? $this->fail($message) : $this->warn($message);
            return;
        }

        $this->ok('Paid parent orders in scope have successful payment audit rows.');
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
