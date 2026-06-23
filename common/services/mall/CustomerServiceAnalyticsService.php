<?php

namespace common\services\mall;

use Yii;
use yii\db\Query;

class CustomerServiceAnalyticsService
{
    public const VERSION = 'MONGOYIA_CUSTOMER_SERVICE_ANALYTICS_V1';

    public function dimensions(): array
    {
        return [
            'staff' => '客服人员',
            'store' => '店铺',
            'language' => '语言',
            'channel' => '渠道',
            'hour' => '时段',
            'media' => '消息类型',
            'ticket' => '工单类型',
            'complaint' => '投诉类型',
        ];
    }

    public function run(int $storeId = 0, array $filters = [], int $limit = 1000): array
    {
        $filters = $this->normalizeFilters($filters);
        $storeId = max(0, $storeId);
        $limit = max(1, min(5000, $limit));
        $issues = [];

        $statRows = $this->statRows($storeId, $filters, $limit, $issues);
        $ticketRows = $this->ticketRows($storeId, $filters, $limit, $issues);
        $chatRows = $this->chatRows($storeId, $filters, $limit, $issues);
        $ratingRows = $this->ratingRows($storeId, $filters, $limit, $issues);

        $statTotals = $this->statTotals($statRows);
        $ticketSummary = $this->ticketSummary($ticketRows, $filters);
        $chatSummary = $this->chatSummary($chatRows);
        $ratingSummary = $this->ratingSummary($ratingRows);
        $messageCount = (int)$chatSummary['message_count'];
        $translationFailureRate = $messageCount > 0 ? round(((int)$chatSummary['translation_failed_count'] / $messageCount) * 100, 2) : 0.0;
        $mediaSendFailureRate = 0.0;
        if ($messageCount > 0 && (int)$chatSummary['media_count'] > 0) {
            $issues[] = 'media send failure rate is based on persisted messages only; failed client uploads need a later error-log source.';
        }

        return [
            'version' => self::VERSION,
            'storeId' => $storeId,
            'filters' => $filters,
            'limit' => $limit,
            'rowsScanned' => [
                'stat_daily' => count($statRows),
                'tickets' => count($ticketRows),
                'chat' => count($chatRows),
                'ratings' => count($ratingRows),
            ],
            'totals' => [
                'session_count' => (int)$statTotals['session_count'],
                'consultation_count' => max((int)$statTotals['session_count'], (int)$chatSummary['session_count']),
                'message_count' => $messageCount,
                'media_count' => (int)$chatSummary['media_count'],
                'ticket_count' => max((int)$statTotals['ticket_count'], (int)$ticketSummary['ticket_count']),
                'order_assist_count' => max((int)$statTotals['order_assist_count'], (int)$ticketSummary['order_assist_count']),
                'complaint_count' => max((int)$statTotals['complaint_count'], (int)$ticketSummary['complaint_count']),
                'resolved_count' => max((int)$statTotals['resolved_count'], (int)$ticketSummary['resolved_count']),
                'unresolved_count' => max((int)$statTotals['unresolved_count'], (int)$ticketSummary['unresolved_count']),
                'translation_failed_count' => (int)$chatSummary['translation_failed_count'],
                'media_send_failed_count' => 0,
                'rating_count' => (int)$ratingSummary['rating_count'],
            ],
            'kpis' => [
                'average_first_response_seconds' => $this->averageSeconds((int)$statTotals['first_response_seconds_total'], max(1, (int)$statTotals['ticket_count'])),
                'average_resolution_seconds' => $this->averageSeconds((int)$statTotals['resolved_seconds_total'], max(1, (int)$statTotals['resolved_count'])),
                'timeout_rate' => $ticketSummary['timeout_rate'],
                'satisfaction_score' => $ratingSummary['average_score'],
                'translation_failure_rate' => $translationFailureRate,
                'media_send_failure_rate' => $mediaSendFailureRate,
                'peak_hour' => $chatSummary['peak_hour'],
            ],
            'trendRows' => $this->trendRows($statRows),
            'staffRankRows' => $this->staffRankRows($statRows),
            'storeRankRows' => $this->storeRankRows($statRows),
            'languageRows' => $chatSummary['language_rows'],
            'channelRows' => $chatSummary['channel_rows'],
            'mediaRows' => $chatSummary['media_rows'],
            'hourRows' => $chatSummary['hour_rows'],
            'ticketTypeRows' => $ticketSummary['ticket_type_rows'],
            'ticketStatusRows' => $ticketSummary['ticket_status_rows'],
            'complaintTypeRows' => $ticketSummary['complaint_type_rows'],
            'ratingRows' => $ratingSummary['rating_rows'],
            'aggregationPlan' => $this->aggregationPlan($storeId, $filters),
            'alertSignals' => $this->alertSignals($translationFailureRate, $ticketSummary['timeout_rate'], (int)$ratingSummary['dissatisfied_count']),
            'boundaries' => $this->boundaries(),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    public function aggregationPlan(int $storeId, array $filters): array
    {
        return [
            'source' => self::VERSION,
            'mode' => 'dry-run',
            'store_id' => max(0, $storeId),
            'date_from' => (string)($filters['date_from'] ?? ''),
            'date_to' => (string)($filters['date_to'] ?? ''),
            'recommended_command' => 'php yii customer-service-analytics-test/run --fixture=1 --interactive=0',
            'writes_business_rows' => false,
            'writes_stat_rows_without_audit' => false,
            'alert_linkage' => 'Phase 7 email alert channel can consume timeout/translation/rating signals after DB acceptance.',
        ];
    }

    public function csvLines(array $report): array
    {
        $lines = ['section,key,label,value,extra'];
        foreach (($report['totals'] ?? []) as $key => $value) {
            $lines[] = $this->csvRow('total', $key, $key, (string)$value, '');
        }
        foreach (($report['kpis'] ?? []) as $key => $value) {
            $lines[] = $this->csvRow('kpi', $key, $key, (string)$value, '');
        }
        foreach ([
            'trend' => 'trendRows',
            'staff' => 'staffRankRows',
            'store' => 'storeRankRows',
            'language' => 'languageRows',
            'channel' => 'channelRows',
            'media' => 'mediaRows',
            'hour' => 'hourRows',
            'ticket_type' => 'ticketTypeRows',
            'ticket_status' => 'ticketStatusRows',
            'complaint_type' => 'complaintTypeRows',
            'rating' => 'ratingRows',
        ] as $section => $key) {
            foreach (($report[$key] ?? []) as $row) {
                $lines[] = $this->csvRow(
                    $section,
                    (string)($row['key'] ?? ''),
                    (string)($row['label'] ?? ''),
                    (string)($row['count'] ?? $row['value'] ?? 0),
                    json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
                );
            }
        }

        return $lines;
    }

    public function boundaries(): array
    {
        return [
            'analytics_read_only' => true,
            'csv_export_read_only' => true,
            'scheduled_aggregation_requires_audited_cli' => true,
            'order_mutation_allowed' => false,
            'payment_mutation_allowed' => false,
            'fund_mutation_allowed' => false,
            'stock_mutation_allowed' => false,
            'stat_overwrite_from_backend_allowed' => false,
        ];
    }

    private function statRows(int $storeId, array $filters, int $limit, array &$issues): array
    {
        if (!$this->tableExists('{{%mall_customer_service_stat_daily}}')) {
            $issues[] = 'customer-service stat table missing';
            return [];
        }

        $query = (new Query())
            ->from('{{%mall_customer_service_stat_daily}}')
            ->where(['status' => 1])
            ->orderBy(['stat_date' => SORT_DESC, 'store_id' => SORT_ASC, 'service_user_id' => SORT_ASC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ((int)$filters['service_user_id'] > 0) {
            $query->andWhere(['service_user_id' => (int)$filters['service_user_id']]);
        }
        if ($filters['date_from'] !== '') {
            $query->andWhere(['>=', 'stat_date', (int)$filters['date_from']]);
        }
        if ($filters['date_to'] !== '') {
            $query->andWhere(['<=', 'stat_date', (int)$filters['date_to']]);
        }

        return $query->all(Yii::$app->db);
    }

    private function ticketRows(int $storeId, array $filters, int $limit, array &$issues): array
    {
        if (!$this->tableExists('{{%mall_customer_service_ticket}}')) {
            $issues[] = 'customer-service ticket table missing';
            return [];
        }

        $query = (new Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['status' => 1])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ((int)$filters['service_user_id'] > 0) {
            $userId = (int)$filters['service_user_id'];
            $query->andWhere(['or', ['merchant_user_id' => $userId], ['platform_user_id' => $userId]]);
        }
        if ($filters['ticket_type'] !== '') {
            $query->andWhere(['ticket_type' => $filters['ticket_type']]);
        }
        if ($filters['date_from_ts'] > 0) {
            $query->andWhere(['>=', 'created_at', $filters['date_from_ts']]);
        }
        if ($filters['date_to_ts'] > 0) {
            $query->andWhere(['<=', 'created_at', $filters['date_to_ts']]);
        }

        $rows = $query->all(Yii::$app->db);
        if ($filters['complaint_category'] !== '') {
            $rows = array_values(array_filter($rows, function ($row) use ($filters) {
                return $this->complaintCategory($row) === $filters['complaint_category'];
            }));
        }

        return $rows;
    }

    private function chatRows(int $storeId, array $filters, int $limit, array &$issues): array
    {
        if (!$this->tableExists('{{%chat}}')) {
            $issues[] = 'chat table missing';
            return [];
        }

        $columns = $this->availableColumns('{{%chat}}', [
            'id',
            'from',
            'uid',
            'uuid',
            'type',
            'time',
            'store_id',
            'product_id',
            'source_language',
            'target_language',
            'translation_status',
            'translation_provider',
            'status',
        ]);
        $query = (new Query())
            ->select($columns ?: ['id'])
            ->from('{{%chat}}')
            ->orderBy($this->hasColumn('{{%chat}}', 'time') ? ['time' => SORT_DESC] : ['id' => SORT_DESC])
            ->limit($limit);
        if ($this->hasColumn('{{%chat}}', 'status')) {
            $query->andWhere(['>=', 'status', 0]);
        }
        if ($storeId > 0 && $this->hasColumn('{{%chat}}', 'store_id')) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ((int)$filters['msg_type'] > 0 && $this->hasColumn('{{%chat}}', 'type')) {
            $query->andWhere(['type' => (int)$filters['msg_type']]);
        }
        if ($filters['language'] !== '' && $this->hasColumn('{{%chat}}', 'source_language') && $this->hasColumn('{{%chat}}', 'target_language')) {
            $query->andWhere(['or', ['source_language' => $filters['language']], ['target_language' => $filters['language']]]);
        }
        if ($filters['date_from_ts'] > 0 && $this->hasColumn('{{%chat}}', 'time')) {
            $query->andWhere(['>=', 'time', $filters['date_from_ts']]);
        }
        if ($filters['date_to_ts'] > 0 && $this->hasColumn('{{%chat}}', 'time')) {
            $query->andWhere(['<=', 'time', $filters['date_to_ts']]);
        }

        $rows = $query->all(Yii::$app->db);
        if ($filters['channel'] !== '') {
            $rows = array_values(array_filter($rows, function ($row) use ($filters) {
                return $this->chatChannel($row) === $filters['channel'];
            }));
        }

        return $rows;
    }

    private function ratingRows(int $storeId, array $filters, int $limit, array &$issues): array
    {
        if (!$this->tableExists('{{%mall_customer_service_rating}}')) {
            $issues[] = 'customer-service rating table missing';
            return [];
        }

        $query = (new Query())
            ->from('{{%mall_customer_service_rating}}')
            ->where(['status' => 1])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ($filters['date_from_ts'] > 0) {
            $query->andWhere(['>=', 'created_at', $filters['date_from_ts']]);
        }
        if ($filters['date_to_ts'] > 0) {
            $query->andWhere(['<=', 'created_at', $filters['date_to_ts']]);
        }

        return $query->all(Yii::$app->db);
    }

    private function statTotals(array $rows): array
    {
        $totals = [
            'session_count' => 0,
            'ticket_count' => 0,
            'order_assist_count' => 0,
            'complaint_count' => 0,
            'resolved_count' => 0,
            'unresolved_count' => 0,
            'first_response_seconds_total' => 0,
            'resolved_seconds_total' => 0,
        ];
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int)($row[$key] ?? 0);
            }
        }

        return $totals;
    }

    private function ticketSummary(array $rows, array $filters): array
    {
        $types = [];
        $statuses = [];
        $complaints = [];
        $timeouts = 0;
        $resolved = 0;
        $unresolved = 0;
        foreach ($rows as $row) {
            $type = (string)($row['ticket_type'] ?? '');
            $status = (string)($row['ticket_status'] ?? '');
            $types[$type] = ($types[$type] ?? 0) + 1;
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            if ($type === CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT) {
                $category = $this->complaintCategory($row);
                $complaints[$category] = ($complaints[$category] ?? 0) + 1;
            }
            if (in_array($status, [CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED], true)) {
                $resolved++;
            } else {
                $unresolved++;
            }
            if ($this->isTicketTimedOut($row, $filters)) {
                $timeouts++;
            }
        }
        $total = count($rows);

        return [
            'ticket_count' => $total,
            'order_assist_count' => (int)($types[CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST] ?? 0),
            'complaint_count' => (int)($types[CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT] ?? 0),
            'resolved_count' => $resolved,
            'unresolved_count' => $unresolved,
            'timeout_rate' => $total > 0 ? round(($timeouts / $total) * 100, 2) : 0.0,
            'ticket_type_rows' => $this->countRows($types, [
                CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST => '订单协助',
                CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT => '投诉',
            ]),
            'ticket_status_rows' => $this->countRows($statuses, (new CustomerServiceComplaintLoopService())->statusLabels()),
            'complaint_type_rows' => $this->countRows($complaints, (new CustomerServiceComplaintLoopService())->categories() + ['uncategorized' => '未分类']),
        ];
    }

    private function chatSummary(array $rows): array
    {
        $languages = [];
        $channels = [];
        $media = [];
        $hours = [];
        $sessions = [];
        $translationFailed = 0;
        $mediaCount = 0;
        foreach ($rows as $row) {
            $msgType = (int)($row['type'] ?? 1);
            $media[$msgType] = ($media[$msgType] ?? 0) + 1;
            if ($msgType > 1) {
                $mediaCount++;
            }
            $sourceLanguage = trim((string)($row['source_language'] ?? ''));
            $targetLanguage = trim((string)($row['target_language'] ?? ''));
            foreach ([$sourceLanguage, $targetLanguage] as $language) {
                $language = $language !== '' ? $language : 'unknown';
                $languages[$language] = ($languages[$language] ?? 0) + 1;
            }
            $channel = $this->chatChannel($row);
            $channels[$channel] = ($channels[$channel] ?? 0) + 1;
            $hour = $this->hourLabel((int)($row['time'] ?? 0));
            $hours[$hour] = ($hours[$hour] ?? 0) + 1;
            $sessionKey = (string)($row['uuid'] ?? '') !== '' ? (string)$row['uuid'] : ('uid:' . (int)($row['uid'] ?? 0));
            $sessions[$sessionKey] = true;
            if ((string)($row['translation_status'] ?? '') === CustomerServiceTranslationService::STATUS_FAILED) {
                $translationFailed++;
            }
        }

        return [
            'message_count' => count($rows),
            'session_count' => count($sessions),
            'media_count' => $mediaCount,
            'translation_failed_count' => $translationFailed,
            'peak_hour' => $this->peakKey($hours),
            'language_rows' => $this->countRows($languages, ['zh-CN' => '中文', 'en' => '英语', 'mn' => '蒙古语', 'unknown' => '未知']),
            'channel_rows' => $this->countRows($channels, ['buyer' => '买家', 'merchant' => '商家客服', 'platform' => '平台客服', 'im' => 'IM']),
            'media_rows' => $this->countRows($media, $this->mediaLabels()),
            'hour_rows' => $this->countRows($hours, []),
        ];
    }

    private function ratingSummary(array $rows): array
    {
        $ratings = [];
        $scoreTotal = 0;
        $dissatisfied = 0;
        foreach ($rows as $row) {
            $rating = (string)($row['rating'] ?? 'unknown');
            $ratings[$rating] = ($ratings[$rating] ?? 0) + 1;
            $scoreTotal += (int)($row['rating_score'] ?? 0);
            if ($rating === 'dissatisfied') {
                $dissatisfied++;
            }
        }
        $total = count($rows);

        return [
            'rating_count' => $total,
            'average_score' => $total > 0 ? round($scoreTotal / $total, 2) : 0.0,
            'dissatisfied_count' => $dissatisfied,
            'rating_rows' => $this->countRows($ratings, (new CustomerServiceRatingService())->labels() + ['unknown' => '未知']),
        ];
    }

    private function trendRows(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $date = (string)(int)($row['stat_date'] ?? 0);
            if (!isset($grouped[$date])) {
                $grouped[$date] = ['key' => $date, 'label' => $date, 'session_count' => 0, 'ticket_count' => 0, 'complaint_count' => 0, 'resolved_count' => 0, 'count' => 0];
            }
            foreach (['session_count', 'ticket_count', 'complaint_count', 'resolved_count'] as $key) {
                $grouped[$date][$key] += (int)($row[$key] ?? 0);
            }
            $grouped[$date]['count']++;
        }

        return array_values($grouped);
    }

    private function staffRankRows(array $rows): array
    {
        $rank = [];
        foreach ($rows as $row) {
            $key = (string)(int)($row['service_user_id'] ?? 0);
            if (!isset($rank[$key])) {
                $rank[$key] = ['key' => $key, 'label' => '客服 #' . $key, 'ticket_count' => 0, 'resolved_count' => 0, 'complaint_count' => 0, 'count' => 0];
            }
            foreach (['ticket_count', 'resolved_count', 'complaint_count'] as $field) {
                $rank[$key][$field] += (int)($row[$field] ?? 0);
            }
            $rank[$key]['count'] += (int)($row['ticket_count'] ?? 0);
        }

        return $this->sortRows(array_values($rank), 'count');
    }

    private function storeRankRows(array $rows): array
    {
        $rank = [];
        foreach ($rows as $row) {
            $key = (string)(int)($row['store_id'] ?? 0);
            if (!isset($rank[$key])) {
                $rank[$key] = ['key' => $key, 'label' => '店铺 #' . $key, 'ticket_count' => 0, 'complaint_count' => 0, 'count' => 0];
            }
            foreach (['ticket_count', 'complaint_count'] as $field) {
                $rank[$key][$field] += (int)($row[$field] ?? 0);
            }
            $rank[$key]['count'] += (int)($row['ticket_count'] ?? 0);
        }

        return $this->sortRows(array_values($rank), 'count');
    }

    private function alertSignals(float $translationFailureRate, float $timeoutRate, int $dissatisfiedCount): array
    {
        return [
            [
                'key' => 'translation_failure_rate',
                'status' => $translationFailureRate > 5 ? 'warn' : 'ok',
                'value' => $translationFailureRate,
                'phase7_email_alert_ready' => true,
            ],
            [
                'key' => 'timeout_rate',
                'status' => $timeoutRate > 10 ? 'warn' : 'ok',
                'value' => $timeoutRate,
                'phase7_email_alert_ready' => true,
            ],
            [
                'key' => 'dissatisfied_count',
                'status' => $dissatisfiedCount > 0 ? 'watch' : 'ok',
                'value' => $dissatisfiedCount,
                'phase7_email_alert_ready' => true,
            ],
        ];
    }

    private function isTicketTimedOut(array $row, array $filters): bool
    {
        $createdAt = (int)($row['created_at'] ?? 0);
        if ($createdAt <= 0) {
            return false;
        }
        $firstResponseAt = (int)($row['first_response_at'] ?? 0);
        $resolvedAt = (int)($row['resolved_at'] ?? 0);
        $firstLimit = (int)$filters['first_response_seconds'];
        $resolutionLimit = (int)$filters['resolution_seconds'];
        $now = time();
        $firstElapsed = $firstResponseAt > 0 ? $firstResponseAt - $createdAt : $now - $createdAt;
        $resolutionElapsed = $resolvedAt > 0 ? $resolvedAt - $createdAt : $now - $createdAt;

        return $firstElapsed > $firstLimit || $resolutionElapsed > $resolutionLimit;
    }

    private function complaintCategory(array $row): string
    {
        $evidence = trim((string)($row['evidence_json'] ?? ''));
        $decoded = $evidence !== '' ? json_decode($evidence, true) : [];
        $category = is_array($decoded) ? (string)($decoded['complaint_loop']['category'] ?? '') : '';

        return $category !== '' ? $category : 'uncategorized';
    }

    private function chatChannel(array $row): string
    {
        $from = strtolower(trim((string)($row['from'] ?? '')));
        if (strpos($from, 'merchant') !== false || strpos($from, 'store') !== false) {
            return 'merchant';
        }
        if (strpos($from, 'platform') !== false || strpos($from, 'service') !== false || strpos($from, 'kf') !== false) {
            return 'platform';
        }
        if (strpos($from, 'user') !== false || strpos($from, 'buyer') !== false) {
            return 'buyer';
        }

        return 'im';
    }

    private function countRows(array $counts, array $labels): array
    {
        ksort($counts);
        $total = array_sum($counts);
        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = [
                'key' => (string)$key,
                'label' => (string)($labels[$key] ?? $key),
                'count' => (int)$count,
                'ratio' => $total > 0 ? round(((int)$count / $total) * 100, 2) : 0.0,
            ];
        }

        return $this->sortRows($rows, 'count');
    }

    private function sortRows(array $rows, string $key): array
    {
        usort($rows, function ($a, $b) use ($key) {
            return (int)($b[$key] ?? 0) <=> (int)($a[$key] ?? 0);
        });

        return $rows;
    }

    private function peakKey(array $counts): string
    {
        if (!$counts) {
            return '';
        }
        arsort($counts);

        return (string)array_key_first($counts);
    }

    private function hourLabel(int $timestamp): string
    {
        return $timestamp > 0 ? date('H:00', $timestamp) : 'unknown';
    }

    private function mediaLabels(): array
    {
        return [
            1 => '文字',
            2 => '图片',
            3 => '文件',
            4 => '视频',
            5 => '语音',
        ];
    }

    private function averageSeconds(int $total, int $count): int
    {
        return $count > 0 ? (int)round($total / $count) : 0;
    }

    private function normalizeFilters(array $filters): array
    {
        $dateFrom = $this->normalizeDate((string)($filters['date_from'] ?? ''));
        $dateTo = $this->normalizeDate((string)($filters['date_to'] ?? ''));
        if ($dateFrom !== '' && $dateTo !== '' && (int)$dateFrom > (int)$dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        $language = (string)($filters['language'] ?? '');
        if (!in_array($language, ['zh-CN', 'en', 'mn'], true)) {
            $language = '';
        }
        $channel = strtolower(trim((string)($filters['channel'] ?? '')));
        if (!in_array($channel, ['buyer', 'merchant', 'platform', 'im'], true)) {
            $channel = '';
        }
        $ticketType = strtolower(trim((string)($filters['ticket_type'] ?? '')));
        if (!in_array($ticketType, (new CustomerServiceAdvancedService())->supportedTicketTypes(), true)) {
            $ticketType = '';
        }
        $complaintCategory = strtolower(trim((string)($filters['complaint_category'] ?? '')));
        if (!isset((new CustomerServiceComplaintLoopService())->categories()[$complaintCategory])) {
            $complaintCategory = '';
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'date_from_ts' => $this->dateToTimestamp($dateFrom, false),
            'date_to_ts' => $this->dateToTimestamp($dateTo, true),
            'service_user_id' => max(0, (int)($filters['service_user_id'] ?? 0)),
            'language' => $language,
            'channel' => $channel,
            'msg_type' => max(0, min(5, (int)($filters['msg_type'] ?? 0))),
            'ticket_type' => $ticketType,
            'complaint_category' => $complaintCategory,
            'first_response_seconds' => max(60, min(86400, (int)($filters['first_response_seconds'] ?? 1800))),
            'resolution_seconds' => max(300, min(2592000, (int)($filters['resolution_seconds'] ?? 86400))),
        ];
    }

    private function normalizeDate(string $date): string
    {
        $date = str_replace(['-', '/', '.'], '', trim($date));

        return preg_match('/^\d{8}$/', $date) ? $date : '';
    }

    private function dateToTimestamp(string $date, bool $endOfDay): int
    {
        if ($date === '') {
            return 0;
        }
        $text = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2) . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        $timestamp = strtotime($text);

        return $timestamp !== false ? (int)$timestamp : 0;
    }

    private function availableColumns(string $table, array $columns): array
    {
        $select = [];
        foreach ($columns as $column) {
            if ($this->hasColumn($table, $column)) {
                $select[] = $column;
            }
        }

        return $select;
    }

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $schema = Yii::$app->db->schema->getTableSchema($table, true);
        return $schema !== null && isset($schema->columns[$column]);
    }

    private function csvRow(string $section, string $key, string $label, string $value, string $extra): string
    {
        return implode(',', [
            $this->csvCell($section),
            $this->csvCell($key),
            $this->csvCell($label),
            $this->csvCell($value),
            $this->csvCell($extra),
        ]);
    }

    private function csvCell(string $value): string
    {
        if (strpbrk($value, "\",\n\r") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
