<?php

namespace common\services\mall;

use common\models\BaseModel;
use common\models\mall\Order;
use common\models\mall\PaymentAttempt;
use Yii;
use yii\db\Expression;
use yii\db\Query;

class PaymentStatisticsService
{
    public const VERSION = 'MONGOYIA_PAYMENT_STATISTICS_V1';

    public function snapshot(array $filter = []): array
    {
        $range = $this->dateRange($filter);
        $storeId = max(0, (int)($filter['store_id'] ?? 0));

        return [
            'version' => self::VERSION,
            'filter' => [
                'start_date' => $range['start_date'],
                'end_date' => $range['end_date'],
                'store_id' => $storeId,
            ],
            'summary' => $this->summary($range, $storeId),
            'daily_rows' => $this->dailyRows($range, $storeId),
            'provider_rows' => $this->providerRows($range, $storeId),
            'failure_rows' => $this->failureRows($range, $storeId),
            'anomaly_rows' => $this->anomalyRows($range, $storeId),
            'reconciliation_rows' => $this->reconciliationRows($range, $storeId),
        ];
    }

    private function summary(array $range, int $storeId): array
    {
        $row = $this->baseQuery($range, $storeId)
            ->select([
                'attempt_count' => new Expression('COUNT(*)'),
                'success_count' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_SUCCESS . "' THEN 1 ELSE 0 END)"),
                'failed_count' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_FAILED . "' THEN 1 ELSE 0 END)"),
                'ignored_count' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_IGNORED . "' THEN 1 ELSE 0 END)"),
                'pending_count' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_PENDING . "' THEN 1 ELSE 0 END)"),
                'success_amount' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_SUCCESS . "' THEN pa.amount ELSE 0 END)"),
                'failed_amount' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_FAILED . "' THEN pa.amount ELSE 0 END)"),
                'provider_count' => new Expression('COUNT(DISTINCT pa.provider)'),
                'order_count' => new Expression('COUNT(DISTINCT pa.order_id)'),
            ])
            ->one(Yii::$app->db);

        return [
            'attempt_count' => (int)($row['attempt_count'] ?? 0),
            'success_count' => (int)($row['success_count'] ?? 0),
            'failed_count' => (int)($row['failed_count'] ?? 0),
            'ignored_count' => (int)($row['ignored_count'] ?? 0),
            'pending_count' => (int)($row['pending_count'] ?? 0),
            'success_amount' => round((float)($row['success_amount'] ?? 0), 2),
            'failed_amount' => round((float)($row['failed_amount'] ?? 0), 2),
            'provider_count' => (int)($row['provider_count'] ?? 0),
            'order_count' => (int)($row['order_count'] ?? 0),
        ];
    }

    private function dailyRows(array $range, int $storeId): array
    {
        return $this->baseQuery($range, $storeId)
            ->select([
                'day' => new Expression("FROM_UNIXTIME(pa.processed_at, '%Y-%m-%d')"),
                'attempt_count' => new Expression('COUNT(*)'),
                'success_count' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_SUCCESS . "' THEN 1 ELSE 0 END)"),
                'failed_count' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_FAILED . "' THEN 1 ELSE 0 END)"),
                'success_amount' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_SUCCESS . "' THEN pa.amount ELSE 0 END)"),
            ])
            ->groupBy(new Expression("FROM_UNIXTIME(pa.processed_at, '%Y-%m-%d')"))
            ->orderBy(['day' => SORT_DESC])
            ->all(Yii::$app->db);
    }

    private function providerRows(array $range, int $storeId): array
    {
        return $this->baseQuery($range, $storeId)
            ->select([
                'provider' => 'pa.provider',
                'event' => 'pa.event',
                'attempt_count' => new Expression('COUNT(*)'),
                'success_count' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_SUCCESS . "' THEN 1 ELSE 0 END)"),
                'failed_count' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_FAILED . "' THEN 1 ELSE 0 END)"),
                'ignored_count' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_IGNORED . "' THEN 1 ELSE 0 END)"),
                'success_amount' => new Expression("SUM(CASE WHEN pa.result = '" . PaymentAttempt::RESULT_SUCCESS . "' THEN pa.amount ELSE 0 END)"),
            ])
            ->groupBy(['pa.provider', 'pa.event'])
            ->orderBy(['attempt_count' => SORT_DESC])
            ->limit(50)
            ->all(Yii::$app->db);
    }

    private function failureRows(array $range, int $storeId): array
    {
        return $this->baseQuery($range, $storeId)
            ->select([
                'provider' => 'pa.provider',
                'event' => 'pa.event',
                'error_message' => new Expression("COALESCE(NULLIF(pa.error_message, ''), 'NO_ERROR_MESSAGE')"),
                'failed_count' => new Expression('COUNT(*)'),
                'latest_at' => new Expression('MAX(pa.processed_at)'),
            ])
            ->andWhere(['pa.result' => PaymentAttempt::RESULT_FAILED])
            ->groupBy(['pa.provider', 'pa.event', new Expression("COALESCE(NULLIF(pa.error_message, ''), 'NO_ERROR_MESSAGE')")])
            ->orderBy(['failed_count' => SORT_DESC, 'latest_at' => SORT_DESC])
            ->limit(50)
            ->all(Yii::$app->db);
    }

    private function anomalyRows(array $range, int $storeId): array
    {
        $duplicateCount = (int)$this->baseQuery($range, $storeId)
            ->andWhere(['pa.result' => PaymentAttempt::RESULT_IGNORED])
            ->count('*', Yii::$app->db);

        $failedCallbackCount = (int)$this->baseQuery($range, $storeId)
            ->andWhere(['pa.result' => PaymentAttempt::RESULT_FAILED])
            ->andWhere(['pa.event' => ['callback', 'webhook', 'return']])
            ->count('*', Yii::$app->db);

        $amountMismatchCount = (int)$this->baseQuery($range, $storeId)
            ->andWhere(['like', 'pa.error_message', 'amount', false])
            ->count('*', Yii::$app->db);

        $signatureFailureCount = (int)$this->baseQuery($range, $storeId)
            ->andWhere(['or',
                ['like', 'pa.error_message', 'signature', false],
                ['like', 'pa.error_message', 'secret', false],
                ['like', 'pa.error_message', 'hmac', false],
            ])
            ->count('*', Yii::$app->db);

        return [
            [
                'code' => 'duplicate_callback',
                'label' => '重复回调/重复 Webhook',
                'count' => $duplicateCount,
                'severity' => $duplicateCount > 0 ? 'WARN' : 'PASS',
            ],
            [
                'code' => 'failed_callback',
                'label' => '失败回调/返回',
                'count' => $failedCallbackCount,
                'severity' => $failedCallbackCount > 0 ? 'WARN' : 'PASS',
            ],
            [
                'code' => 'amount_mismatch',
                'label' => '金额不符',
                'count' => $amountMismatchCount,
                'severity' => $amountMismatchCount > 0 ? 'FAIL' : 'PASS',
            ],
            [
                'code' => 'signature_failure',
                'label' => '签名/密钥校验失败',
                'count' => $signatureFailureCount,
                'severity' => $signatureFailureCount > 0 ? 'WARN' : 'PASS',
            ],
        ];
    }

    private function reconciliationRows(array $range, int $storeId): array
    {
        return $this->baseQuery($range, $storeId)
            ->select([
                'attempt_id' => 'pa.id',
                'order_id' => 'pa.order_id',
                'store_id' => 'pa.store_id',
                'provider' => 'pa.provider',
                'event' => 'pa.event',
                'attempt_amount' => 'pa.amount',
                'order_amount' => 'o.amount',
                'payment_status' => 'o.payment_status',
                'processed_at' => 'pa.processed_at',
                'reason' => new Expression("CASE WHEN ABS(pa.amount - o.amount) > 0.01 THEN 'amount_difference' WHEN o.payment_status <> " . (int)Order::PAYMENT_STATUS_PAID . " THEN 'order_not_paid' ELSE 'ok' END"),
            ])
            ->innerJoin(['o' => '{{%mall_order}}'], 'o.id = pa.order_id')
            ->andWhere(['pa.result' => PaymentAttempt::RESULT_SUCCESS])
            ->andWhere(['or',
                new Expression('ABS(pa.amount - o.amount) > 0.01'),
                ['<>', 'o.payment_status', Order::PAYMENT_STATUS_PAID],
            ])
            ->orderBy(['pa.processed_at' => SORT_DESC, 'pa.id' => SORT_DESC])
            ->limit(50)
            ->all(Yii::$app->db);
    }

    private function baseQuery(array $range, int $storeId): Query
    {
        $query = (new Query())
            ->from(['pa' => '{{%mall_payment_attempt}}'])
            ->where(['>', 'pa.status', BaseModel::STATUS_DELETED])
            ->andWhere(['between', 'pa.processed_at', $range['start_ts'], $range['end_ts']]);

        if ($storeId > 0) {
            $query->andWhere(['pa.store_id' => $storeId]);
        }

        return $query;
    }

    private function dateRange(array $filter): array
    {
        $end = trim((string)($filter['end_date'] ?? date('Y-m-d')));
        $start = trim((string)($filter['start_date'] ?? date('Y-m-d', strtotime('-6 days'))));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start = date('Y-m-d', strtotime('-6 days'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end = date('Y-m-d');
        }
        if (strtotime($start) > strtotime($end)) {
            [$start, $end] = [$end, $start];
        }

        return [
            'start_date' => $start,
            'end_date' => $end,
            'start_ts' => strtotime($start . ' 00:00:00'),
            'end_ts' => strtotime($end . ' 23:59:59'),
        ];
    }
}
