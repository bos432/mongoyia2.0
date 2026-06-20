<?php

namespace console\controllers;

use common\models\BaseModel;
use common\models\mall\Order;
use common\models\mall\PaymentAttempt;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaPaymentAuditBackfillController extends Controller
{
    public $apply = false;
    public $limit = 100;
    public $includeLegacyPaidOrders = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'apply',
            'limit',
            'includeLegacyPaidOrders',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia payment audit backfill\n");
        $this->stdout($this->apply ? "Mode: apply\n" : "Mode: dry-run\n");

        $orders = $this->findMissingPaidOrders();
        if (!$orders) {
            $this->stdout("No paid parent orders need payment audit backfill.\n");
            return ExitCode::OK;
        }

        foreach ($orders as $order) {
            $this->stdout(sprintf(
                "ORDER %d sn=%s store=%d user=%d amount=%0.2f paid_at=%d\n",
                $order['id'],
                $order['sn'],
                $order['store_id'],
                $order['user_id'],
                $order['amount'],
                $order['paid_at']
            ));
        }

        if (!$this->apply) {
            $this->stdout("\nDry-run only. Re-run with --apply=1 after business approval to insert legacy success audit rows.\n");
            return ExitCode::OK;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $created = 0;
            foreach ($orders as $order) {
                if ($this->hasSuccessAttempt((int)$order['id'])) {
                    continue;
                }
                $attempt = PaymentAttempt::createForOrder($this->orderFromRow($order), 'legacy', 'backfill', [
                    'merchant_transaction_id' => 'legacy-paid-' . $order['id'],
                    'business_key' => 'legacy:backfill:legacy-paid-' . $order['id'],
                    'amount' => (float)$order['amount'],
                    'payload' => [
                        'source' => 'mongoyia-payment-audit-backfill/run',
                        'reason' => 'Historical paid parent order missing successful payment audit row.',
                        'order_id' => (int)$order['id'],
                        'sn' => (string)$order['sn'],
                    ],
                    'result' => PaymentAttempt::RESULT_SUCCESS,
                    'processed_at' => (int)$order['paid_at'] ?: time(),
                ]);
                if (!$attempt) {
                    throw new \RuntimeException('Create payment audit backfill row failed for order ' . $order['id']);
                }
                $created++;
            }

            $transaction->commit();
            $this->stdout("\nBackfill applied. Created {$created} payment audit row(s).\n");
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->stderr("Backfill failed: " . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    private function findMissingPaidOrders()
    {
        $query = (new \yii\db\Query())
            ->select([
                'o.id',
                'o.store_id',
                'o.user_id',
                'o.sn',
                'o.amount',
                'o.paid_at',
            ])
            ->from(['o' => '{{%mall_order}}'])
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

        return $query->all(Yii::$app->db);
    }

    private function hasSuccessAttempt($orderId)
    {
        return PaymentAttempt::find()
            ->where(['order_id' => $orderId, 'result' => PaymentAttempt::RESULT_SUCCESS])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->exists();
    }

    private function orderFromRow(array $row)
    {
        $order = new Order();
        $order->id = (int)$row['id'];
        $order->store_id = (int)$row['store_id'];
        $order->user_id = (int)$row['user_id'];
        $order->amount = (float)$row['amount'];

        return $order;
    }
}
