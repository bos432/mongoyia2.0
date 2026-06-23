<?php

namespace common\services\mall;

use common\models\base\Message;
use common\models\BaseModel;
use common\models\User;
use Yii;
use yii\db\Expression;
use yii\db\Query;

class OperationalNotificationService
{
    public const VERSION = 'MONGOYIA_OPERATIONAL_NOTIFICATION_V1';
    public const TABLE = '{{%mall_notification_send_log}}';

    public const EVENT_ORDER_STATUS = 'order_status';
    public const EVENT_LOGISTICS_STATUS = 'logistics_status';
    public const EVENT_PAYMENT_RESULT = 'payment_result';
    public const EVENT_CUSTOMER_SERVICE_REPLY = 'customer_service_reply';
    public const EVENT_COMPLAINT_RESULT = 'complaint_result';

    public const CHANNEL_SITE = 'site';
    public const CHANNEL_APP_RESERVED = 'app_reserved';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_DRY_RUN = 'dry_run';

    public function eventDefinitions(): array
    {
        return [
            self::EVENT_ORDER_STATUS => [
                'label' => '订单状态通知',
                'default_channels' => [self::CHANNEL_SITE, self::CHANNEL_APP_RESERVED],
                'required_payload' => ['order_id', 'order_sn', 'status_label'],
            ],
            self::EVENT_LOGISTICS_STATUS => [
                'label' => '物流状态通知',
                'default_channels' => [self::CHANNEL_SITE, self::CHANNEL_APP_RESERVED],
                'required_payload' => ['order_id', 'order_sn', 'logistics_status'],
            ],
            self::EVENT_PAYMENT_RESULT => [
                'label' => '支付结果通知',
                'default_channels' => [self::CHANNEL_SITE, self::CHANNEL_APP_RESERVED],
                'required_payload' => ['order_id', 'order_sn', 'payment_status'],
            ],
            self::EVENT_CUSTOMER_SERVICE_REPLY => [
                'label' => '客服回复通知',
                'default_channels' => [self::CHANNEL_SITE, self::CHANNEL_APP_RESERVED],
                'required_payload' => ['chat_uuid'],
            ],
            self::EVENT_COMPLAINT_RESULT => [
                'label' => '投诉处理结果通知',
                'default_channels' => [self::CHANNEL_SITE, self::CHANNEL_APP_RESERVED],
                'required_payload' => ['ticket_id', 'result_label'],
            ],
        ];
    }

    public function channelLabels(): array
    {
        return [
            self::CHANNEL_SITE => '站内消息',
            self::CHANNEL_APP_RESERVED => 'APP 推送预留',
        ];
    }

    public function statusLabels(): array
    {
        return [
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAILED => '失败',
            self::STATUS_PENDING => '待处理',
            self::STATUS_RESERVED => '预留',
            self::STATUS_DRY_RUN => '演练',
        ];
    }

    public function notifyOrderStatus(int $userId, array $payload = [], array $options = []): array
    {
        $orderSn = $this->payloadValue($payload, 'order_sn', 'unknown');
        $status = $this->payloadValue($payload, 'status_label', 'updated');
        return $this->dispatch(
            self::EVENT_ORDER_STATUS,
            $userId,
            '订单状态更新',
            "订单 {$orderSn} 状态已更新为 {$status}",
            $payload,
            $options['channels'] ?? null,
            $options
        );
    }

    public function notifyLogisticsStatus(int $userId, array $payload = [], array $options = []): array
    {
        $orderSn = $this->payloadValue($payload, 'order_sn', 'unknown');
        $status = $this->payloadValue($payload, 'logistics_status', 'updated');
        return $this->dispatch(
            self::EVENT_LOGISTICS_STATUS,
            $userId,
            '物流状态更新',
            "订单 {$orderSn} 物流状态：{$status}",
            $payload,
            $options['channels'] ?? null,
            $options
        );
    }

    public function notifyPaymentResult(int $userId, array $payload = [], array $options = []): array
    {
        $orderSn = $this->payloadValue($payload, 'order_sn', 'unknown');
        $status = $this->payloadValue($payload, 'payment_status', 'updated');
        return $this->dispatch(
            self::EVENT_PAYMENT_RESULT,
            $userId,
            '支付结果通知',
            "订单 {$orderSn} 支付状态：{$status}",
            $payload,
            $options['channels'] ?? null,
            $options
        );
    }

    public function notifyCustomerServiceReply(int $userId, array $payload = [], array $options = []): array
    {
        return $this->dispatch(
            self::EVENT_CUSTOMER_SERVICE_REPLY,
            $userId,
            '客服已回复',
            '客服已回复你的咨询，请进入聊天查看。',
            $payload,
            $options['channels'] ?? null,
            $options
        );
    }

    public function notifyComplaintResult(int $userId, array $payload = [], array $options = []): array
    {
        $result = $this->payloadValue($payload, 'result_label', 'updated');
        return $this->dispatch(
            self::EVENT_COMPLAINT_RESULT,
            $userId,
            '投诉处理结果',
            "你的投诉处理结果已更新：{$result}",
            $payload,
            $options['channels'] ?? null,
            $options
        );
    }

    public function dispatch(
        string $eventKey,
        int $userId,
        string $title,
        string $content,
        array $payload = [],
        ?array $channels = null,
        array $options = []
    ): array {
        $definitions = $this->eventDefinitions();
        if (!isset($definitions[$eventKey])) {
            return [
                'success' => false,
                'event_key' => $eventKey,
                'results' => [[
                    'channel' => '',
                    'status' => self::STATUS_FAILED,
                    'message' => 'Unsupported notification event.',
                ]],
            ];
        }

        $dryRun = !empty($options['dry_run']);
        $channels = $this->normalizeChannels($channels ?: $definitions[$eventKey]['default_channels']);
        $results = [];
        foreach ($channels as $channel) {
            if ($dryRun) {
                $results[] = [
                    'channel' => $channel,
                    'status' => self::STATUS_DRY_RUN,
                    'message' => 'Dry-run notification hook validated.',
                ];
                continue;
            }

            if ($channel === self::CHANNEL_SITE) {
                $results[] = $this->sendSiteMessage($eventKey, $userId, $title, $content, $payload, $options);
                continue;
            }

            $results[] = $this->recordLog($eventKey, $channel, $userId, $title, $content, $payload, [
                'delivery_status' => self::STATUS_RESERVED,
                'error_summary' => 'APP push provider is reserved until external provider evidence is accepted.',
                'source' => $options['source'] ?? 'notification-service',
                'trace_id' => $options['trace_id'] ?? '',
                'store_id' => (int)($options['store_id'] ?? 0),
                'operator_user_id' => (int)($options['operator_user_id'] ?? 1),
            ]);
        }

        return [
            'success' => $this->hasSuccessfulOrReservedResult($results),
            'event_key' => $eventKey,
            'results' => $results,
        ];
    }

    public function snapshot(array $filter = []): array
    {
        $filter = $this->normalizeFilter($filter);

        return [
            'version' => self::VERSION,
            'filter' => $filter,
            'events' => $this->eventDefinitions(),
            'channels' => $this->channelLabels(),
            'statuses' => $this->statusLabels(),
            'table_exists' => $this->tableExists(self::TABLE),
            'summary' => $this->summary($filter),
            'event_rows' => $this->groupRows($filter, 'event_key'),
            'channel_rows' => $this->groupRows($filter, 'channel'),
            'status_rows' => $this->groupRows($filter, 'delivery_status'),
            'recent_rows' => $this->recentRows($filter),
        ];
    }

    private function sendSiteMessage(string $eventKey, int $userId, string $title, string $content, array $payload, array $options): array
    {
        if ($userId <= 0) {
            return $this->recordLog($eventKey, self::CHANNEL_SITE, $userId, $title, $content, $payload, [
                'delivery_status' => self::STATUS_FAILED,
                'error_summary' => 'User ID is required for site notification.',
                'source' => $options['source'] ?? 'notification-service',
                'trace_id' => $options['trace_id'] ?? '',
                'store_id' => (int)($options['store_id'] ?? 0),
                'operator_user_id' => (int)($options['operator_user_id'] ?? 1),
            ]);
        }

        $message = Message::create($title, [
            'event_key' => $eventKey,
            'content' => $content,
            'payload' => $payload,
            'source' => $options['source'] ?? 'notification-service',
        ], $userId, (int)($options['from_id'] ?? 1), $options['message_type_id'] ?? null);

        if (!$message) {
            return $this->recordLog($eventKey, self::CHANNEL_SITE, $userId, $title, $content, $payload, [
                'delivery_status' => self::STATUS_FAILED,
                'error_summary' => 'Failed to create base_message row.',
                'source' => $options['source'] ?? 'notification-service',
                'trace_id' => $options['trace_id'] ?? '',
                'store_id' => (int)($options['store_id'] ?? 0),
                'operator_user_id' => (int)($options['operator_user_id'] ?? 1),
            ]);
        }

        return $this->recordLog($eventKey, self::CHANNEL_SITE, $userId, $title, $content, $payload, [
            'delivery_status' => self::STATUS_SUCCESS,
            'message_id' => (int)$message->id,
            'source' => $options['source'] ?? 'notification-service',
            'trace_id' => $options['trace_id'] ?? '',
            'store_id' => (int)($options['store_id'] ?? (int)$message->store_id),
            'operator_user_id' => (int)($options['operator_user_id'] ?? 1),
        ]);
    }

    private function recordLog(string $eventKey, string $channel, int $userId, string $title, string $content, array $payload, array $data): array
    {
        if (!$this->tableExists(self::TABLE)) {
            return [
                'channel' => $channel,
                'status' => self::STATUS_FAILED,
                'message' => 'Notification send-log table is missing.',
            ];
        }

        $now = time();
        $storeId = (int)($data['store_id'] ?? 0);
        if ($storeId <= 0) {
            $storeId = $this->storeIdForUser($userId);
        }

        Yii::$app->db->createCommand()->insert(self::TABLE, [
            'store_id' => $storeId,
            'user_id' => max(0, $userId),
            'event_key' => $eventKey,
            'channel' => $channel,
            'title' => $title,
            'content' => $content,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'delivery_status' => (string)($data['delivery_status'] ?? self::STATUS_PENDING),
            'error_summary' => (string)($data['error_summary'] ?? ''),
            'message_id' => (int)($data['message_id'] ?? 0),
            'source' => (string)($data['source'] ?? 'notification-service'),
            'trace_id' => (string)($data['trace_id'] ?? ''),
            'sent_at' => $now,
            'sort' => 50,
            'status' => BaseModel::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => (int)($data['operator_user_id'] ?? 1),
            'updated_by' => (int)($data['operator_user_id'] ?? 1),
        ])->execute();

        return [
            'channel' => $channel,
            'status' => (string)($data['delivery_status'] ?? self::STATUS_PENDING),
            'message' => (string)($data['error_summary'] ?? 'Notification log recorded.'),
            'message_id' => (int)($data['message_id'] ?? 0),
        ];
    }

    private function normalizeChannels(array $channels): array
    {
        $allowed = array_keys($this->channelLabels());
        $result = [];
        foreach ($channels as $channel) {
            $channel = strtolower(trim((string)$channel));
            if (in_array($channel, $allowed, true) && !in_array($channel, $result, true)) {
                $result[] = $channel;
            }
        }

        return $result ?: [self::CHANNEL_SITE];
    }

    private function hasSuccessfulOrReservedResult(array $results): bool
    {
        foreach ($results as $row) {
            if (in_array($row['status'] ?? '', [self::STATUS_SUCCESS, self::STATUS_RESERVED, self::STATUS_DRY_RUN], true)) {
                return true;
            }
        }

        return false;
    }

    private function summary(array $filter): array
    {
        if (!$this->tableExists(self::TABLE)) {
            return ['total' => 0, 'success' => 0, 'failed' => 0, 'reserved' => 0, 'pending' => 0];
        }

        $row = $this->baseQuery($filter)
            ->select([
                'total' => new Expression('COUNT(*)'),
                'success' => new Expression("SUM(CASE WHEN delivery_status = '" . self::STATUS_SUCCESS . "' THEN 1 ELSE 0 END)"),
                'failed' => new Expression("SUM(CASE WHEN delivery_status = '" . self::STATUS_FAILED . "' THEN 1 ELSE 0 END)"),
                'reserved' => new Expression("SUM(CASE WHEN delivery_status = '" . self::STATUS_RESERVED . "' THEN 1 ELSE 0 END)"),
                'pending' => new Expression("SUM(CASE WHEN delivery_status = '" . self::STATUS_PENDING . "' THEN 1 ELSE 0 END)"),
            ])
            ->one(Yii::$app->db);

        return [
            'total' => (int)($row['total'] ?? 0),
            'success' => (int)($row['success'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'reserved' => (int)($row['reserved'] ?? 0),
            'pending' => (int)($row['pending'] ?? 0),
        ];
    }

    private function groupRows(array $filter, string $field): array
    {
        if (!$this->tableExists(self::TABLE)) {
            return [];
        }

        return $this->baseQuery($filter)
            ->select([
                'key' => $field,
                'count' => new Expression('COUNT(*)'),
                'latest_at' => new Expression('MAX(sent_at)'),
            ])
            ->groupBy($field)
            ->orderBy(['count' => SORT_DESC, 'latest_at' => SORT_DESC])
            ->all(Yii::$app->db);
    }

    private function recentRows(array $filter): array
    {
        if (!$this->tableExists(self::TABLE)) {
            return [];
        }

        return $this->baseQuery($filter)
            ->select(['id', 'store_id', 'user_id', 'event_key', 'channel', 'title', 'delivery_status', 'error_summary', 'message_id', 'source', 'trace_id', 'sent_at'])
            ->orderBy(['sent_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(80)
            ->all(Yii::$app->db);
    }

    private function baseQuery(array $filter): Query
    {
        $query = (new Query())
            ->from(self::TABLE)
            ->where(['>', 'status', BaseModel::STATUS_DELETED])
            ->andWhere(['between', 'sent_at', $filter['start_ts'], $filter['end_ts']]);

        foreach (['store_id', 'event_key', 'channel', 'delivery_status'] as $field) {
            if (($filter[$field] ?? '') !== '' && $filter[$field] !== 0) {
                $query->andWhere([$field => $filter[$field]]);
            }
        }

        return $query;
    }

    private function normalizeFilter(array $filter): array
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

        $eventKey = strtolower(trim((string)($filter['event_key'] ?? '')));
        if (!isset($this->eventDefinitions()[$eventKey])) {
            $eventKey = '';
        }
        $channel = strtolower(trim((string)($filter['channel'] ?? '')));
        if (!isset($this->channelLabels()[$channel])) {
            $channel = '';
        }
        $deliveryStatus = strtolower(trim((string)($filter['delivery_status'] ?? '')));
        if (!isset($this->statusLabels()[$deliveryStatus])) {
            $deliveryStatus = '';
        }

        return [
            'start_date' => $start,
            'end_date' => $end,
            'start_ts' => strtotime($start . ' 00:00:00'),
            'end_ts' => strtotime($end . ' 23:59:59'),
            'store_id' => max(0, (int)($filter['store_id'] ?? 0)),
            'event_key' => $eventKey,
            'channel' => $channel,
            'delivery_status' => $deliveryStatus,
        ];
    }

    private function storeIdForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $row = User::find()->select(['store_id'])->where(['id' => $userId])->asArray()->one();
        return (int)($row['store_id'] ?? 0);
    }

    private function payloadValue(array $payload, string $key, string $default): string
    {
        $value = trim((string)($payload[$key] ?? ''));
        return $value !== '' ? $value : $default;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Yii::$app->db->schema->getTableSchema($table, true) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
