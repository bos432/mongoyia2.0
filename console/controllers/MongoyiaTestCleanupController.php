<?php

namespace console\controllers;

use common\models\BaseModel;
use common\models\mall\Order;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaTestCleanupController extends Controller
{
    public $apply = false;
    public $olderThanHours = 0;
    public $includeChat = true;
    public $failOnPending = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'apply',
            'olderThanHours',
            'includeChat',
            'failOnPending',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia generated test-data cleanup\n");
        $this->stdout($this->apply ? "Mode: apply\n" : "Mode: dry-run\n");

        $cutoff = $this->cutoffTimestamp();
        if ($cutoff > 0) {
            $this->stdout("Only records older than " . date('Y-m-d H:i:s', $cutoff) . " will be considered.\n");
        }

        $db = Yii::$app->db;
        $transaction = $this->apply ? $db->beginTransaction() : null;
        try {
            $orderIds = $this->testOrderIds($cutoff);
            $counts = [
                'orders' => count($orderIds),
                'order_products' => $this->countOrderProducts($orderIds),
                'payment_attempts' => $this->countPaymentAttempts($orderIds),
                'stock_refunds' => $this->countStockRefundRows($orderIds),
                'chat_messages' => $this->includeChat ? $this->countChatMessages($cutoff) : 0,
                'chat_files' => $this->includeChat ? count($this->chatSmokeFiles($cutoff)) : 0,
            ];

            foreach ($counts as $label => $count) {
                $this->stdout(str_pad($label, 18) . $count . "\n");
            }

            if (!$this->apply) {
                if ($this->failOnPending && array_sum($counts) > 0) {
                    $this->stderr("\nGenerated test data is still pending cleanup.\n");
                    return ExitCode::UNSPECIFIED_ERROR;
                }
                $this->stdout("\nDry-run only. Re-run with --apply=1 to clean these generated test records.\n");
                return ExitCode::OK;
            }

            if ($orderIds) {
                $this->refundGeneratedOrderStock($orderIds);
                $db->createCommand()->update('{{%mall_order_product}}', [
                    'status' => BaseModel::STATUS_DELETED,
                    'updated_at' => time(),
                ], ['order_id' => $orderIds])->execute();
                $db->createCommand()->update('{{%mall_payment_attempt}}', [
                    'status' => BaseModel::STATUS_DELETED,
                    'updated_at' => time(),
                ], ['order_id' => $orderIds])->execute();
                $db->createCommand()->update('{{%mall_order}}', [
                    'status' => BaseModel::STATUS_DELETED,
                    'updated_at' => time(),
                ], ['id' => $orderIds])->execute();
            }

            if ($this->includeChat) {
                $this->deleteChatMessages($cutoff);
                $this->deleteChatSmokeFiles($cutoff);
            }

            $transaction->commit();
            $this->stdout("\nCleanup applied.\n");
            return ExitCode::OK;
        } catch (\Throwable $e) {
            if ($transaction) {
                $transaction->rollBack();
            }
            $this->stderr("Cleanup failed: " . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    private function cutoffTimestamp()
    {
        $hours = (int)$this->olderThanHours;
        return $hours > 0 ? time() - ($hours * 3600) : 0;
    }

    private function testOrderIds(int $cutoff)
    {
        $query = (new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_order}}')
            ->where(['or',
                ['like', 'sn', 'REGPAY-%', false],
                ['like', 'sn', 'WEBFIX-%', false],
            ])
            ->andWhere(['<>', 'status', BaseModel::STATUS_DELETED]);

        if ($cutoff > 0) {
            $query->andWhere(['and', ['>', 'created_at', 0], ['<', 'created_at', $cutoff]]);
        }

        return array_map('intval', $query->column(Yii::$app->db));
    }

    private function countOrderProducts(array $orderIds)
    {
        if (!$orderIds) {
            return 0;
        }

        return (int)(new \yii\db\Query())
            ->from('{{%mall_order_product}}')
            ->where(['order_id' => $orderIds])
            ->andWhere(['<>', 'status', BaseModel::STATUS_DELETED])
            ->count('*', Yii::$app->db);
    }

    private function countPaymentAttempts(array $orderIds)
    {
        if (!$orderIds) {
            return 0;
        }

        return (int)(new \yii\db\Query())
            ->from('{{%mall_payment_attempt}}')
            ->where(['order_id' => $orderIds])
            ->andWhere(['<>', 'status', BaseModel::STATUS_DELETED])
            ->count('*', Yii::$app->db);
    }

    private function countStockRefundRows(array $orderIds)
    {
        if (!$orderIds) {
            return 0;
        }

        return (int)(new \yii\db\Query())
            ->from('{{%mall_order_product}} op')
            ->innerJoin('{{%mall_order}} o', 'o.id = op.order_id')
            ->where(['op.order_id' => $orderIds])
            ->andWhere(['>', 'o.stock_deducted_at', 0])
            ->andWhere(['o.stock_refunded_at' => 0])
            ->andWhere(['<>', 'o.status', BaseModel::STATUS_DELETED])
            ->sum('op.number', Yii::$app->db);
    }

    private function refundGeneratedOrderStock(array $orderIds)
    {
        $parents = Order::find()
            ->where(['id' => $orderIds, 'parent_id' => 0])
            ->andWhere(['>', 'stock_deducted_at', 0])
            ->andWhere(['stock_refunded_at' => 0])
            ->all();

        $refundedAt = time();
        foreach ($parents as $parent) {
            $parent->refundStockIfNeeded($refundedAt);
        }

        $children = Order::find()
            ->where(['id' => $orderIds])
            ->andWhere(['>', 'parent_id', 0])
            ->andWhere(['>', 'stock_deducted_at', 0])
            ->andWhere(['stock_refunded_at' => 0])
            ->all();

        foreach ($children as $child) {
            $child->refundStockIfNeeded($refundedAt);
        }
    }

    private function countChatMessages(int $cutoff)
    {
        return (int)$this->chatQuery($cutoff)->count('*', Yii::$app->db);
    }

    private function deleteChatMessages(int $cutoff)
    {
        $condition = ['or',
            ['like', 'uuid', 'im_regression_%', false],
            ['like', 'uuid', 'im_concurrency_%', false],
            ['like', 'uuid', 'healthcheck_%', false],
            ['like', 'content', 'im_regression_browser_%', false],
        ];
        if ($cutoff > 0) {
            $condition = ['and', $condition, ['<', 'time', date('Y-m-d H:i:s', $cutoff)]];
        }

        Yii::$app->db->createCommand()->delete('{{%chat}}', $condition)->execute();
    }

    private function chatQuery(int $cutoff)
    {
        $query = (new \yii\db\Query())
            ->from('{{%chat}}')
            ->where(['or',
                ['like', 'uuid', 'im_regression_%', false],
                ['like', 'uuid', 'im_concurrency_%', false],
                ['like', 'uuid', 'healthcheck_%', false],
                ['like', 'content', 'im_regression_browser_%', false],
            ]);

        if ($cutoff > 0) {
            $query->andWhere(['<', 'time', date('Y-m-d H:i:s', $cutoff)]);
        }

        return $query;
    }

    private function chatSmokeFiles(int $cutoff)
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'attachment' . DIRECTORY_SEPARATOR . 'chat';
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (!preg_match('#[\\\\/]chat[\\\\/]\d{4}[\\\\/]\d{2}[\\\\/]\d{2}[\\\\/]chat_smoke_[^\\\\/]+\.png$#', $file->getPathname())) {
                continue;
            }
            if ($cutoff > 0 && $file->getMTime() >= $cutoff) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        return $files;
    }

    private function deleteChatSmokeFiles(int $cutoff)
    {
        foreach ($this->chatSmokeFiles($cutoff) as $file) {
            @unlink($file);
        }
    }
}
