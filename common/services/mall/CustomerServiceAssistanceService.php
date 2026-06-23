<?php

namespace common\services\mall;

use Yii;
use yii\db\Query;

class CustomerServiceAssistanceService
{
    public const VERSION = 'MONGOYIA_CUSTOMER_SERVICE_ASSISTANCE_V1';

    public function assistanceTypes(): array
    {
        return [
            'payment_guidance' => [
                'label' => '支付指导',
                'approval_required' => false,
                'risk_action' => 'none',
            ],
            'logistics_query' => [
                'label' => '物流查询',
                'approval_required' => false,
                'risk_action' => 'none',
            ],
            'merchant_material_request' => [
                'label' => '商家补充资料',
                'approval_required' => false,
                'risk_action' => 'none',
            ],
            'exchange_suggestion' => [
                'label' => '退换货建议',
                'approval_required' => true,
                'risk_action' => 'after_sale_approval',
            ],
            'refund_suggestion' => [
                'label' => '退款建议',
                'approval_required' => true,
                'risk_action' => 'refund_approval',
            ],
            'compensation_suggestion' => [
                'label' => '赔付建议',
                'approval_required' => true,
                'risk_action' => 'compensation_approval',
            ],
        ];
    }

    public function boundaries(): array
    {
        return [
            'read_only_search' => true,
            'assistance_creates_ticket_only' => true,
            'order_mutation_allowed' => false,
            'payment_mutation_allowed' => false,
            'fund_mutation_allowed' => false,
            'stock_mutation_allowed' => false,
            'refund_mutation_allowed' => false,
            'approval_required_for_high_risk' => true,
        ];
    }

    public function searchOrders(array $input, int $scopeStoreId = 0, int $limit = 20): array
    {
        if (!$this->tableExists('{{%mall_order}}')) {
            return [];
        }

        $limit = $this->limit($limit);
        $keyword = $this->keyword((string)($input['q'] ?? ''));
        $storeId = max(0, (int)($input['store_id'] ?? 0));
        $productId = max(0, (int)($input['product_id'] ?? 0));
        $userId = max(0, (int)($input['user_id'] ?? 0));

        $query = (new Query())
            ->select($this->selectColumns('{{%mall_order}}', 'o', [
                'id',
                'store_id',
                'user_id',
                'sn',
                'name',
                'amount',
                'product_amount',
                'payment_status',
                'shipment_status',
                'shipment_name',
                'wlgs',
                'wldh',
                'mobile',
                'email',
                'paid_at',
                'shipped_at',
                'created_at',
                'updated_at',
                'status',
            ]))
            ->from(['o' => '{{%mall_order}}'])
            ->orderBy(['o.id' => SORT_DESC])
            ->limit($limit);

        if ($scopeStoreId > 0) {
            $query->andWhere(['o.store_id' => $scopeStoreId]);
        } elseif ($storeId > 0) {
            $query->andWhere(['o.store_id' => $storeId]);
        }
        if ($userId > 0) {
            $query->andWhere(['o.user_id' => $userId]);
        }
        if ($productId > 0) {
            $orderIds = $this->orderIdsByProduct($productId, $scopeStoreId, $limit);
            $query->andWhere($orderIds ? ['o.id' => $orderIds] : '0=1');
        }
        if ($keyword !== '') {
            $or = ['or'];
            foreach (['sn', 'name', 'mobile', 'email'] as $column) {
                if ($this->hasColumn('{{%mall_order}}', $column)) {
                    $or[] = ['like', 'o.' . $column, $keyword];
                }
            }
            if (ctype_digit($keyword)) {
                $or[] = ['o.id' => (int)$keyword];
            }
            $orderIds = $this->orderIdsByOrderProductKeyword($keyword, $scopeStoreId, $limit);
            if ($orderIds) {
                $or[] = ['o.id' => $orderIds];
            }
            if (count($or) > 1) {
                $query->andWhere($or);
            }
        }

        return array_map([$this, 'normalizeOrderRow'], $query->all(Yii::$app->db));
    }

    public function searchProducts(array $input, int $scopeStoreId = 0, int $limit = 20): array
    {
        if (!$this->tableExists('{{%mall_product}}')) {
            return [];
        }

        $limit = $this->limit($limit);
        $keyword = $this->keyword((string)($input['q'] ?? ''));
        $storeId = max(0, (int)($input['store_id'] ?? 0));

        $query = (new Query())
            ->select($this->selectColumns('{{%mall_product}}', 'p', [
                'id',
                'store_id',
                'category_id',
                'name',
                'sku',
                'stock_code',
                'stock',
                'price',
                'market_price',
                'thumb',
                'image',
                'images',
                'brief',
                'content',
                'seo_keywords',
                'status',
                'audit_status',
                'created_at',
                'updated_at',
            ]))
            ->from(['p' => '{{%mall_product}}'])
            ->orderBy(['p.id' => SORT_DESC])
            ->limit($limit);

        if ($scopeStoreId > 0) {
            $query->andWhere(['p.store_id' => $scopeStoreId]);
        } elseif ($storeId > 0) {
            $query->andWhere(['p.store_id' => $storeId]);
        }
        if ($keyword !== '') {
            $or = ['or'];
            foreach (['name', 'sku', 'stock_code', 'brief', 'seo_keywords'] as $column) {
                if ($this->hasColumn('{{%mall_product}}', $column)) {
                    $or[] = ['like', 'p.' . $column, $keyword];
                }
            }
            if (ctype_digit($keyword)) {
                $or[] = ['p.id' => (int)$keyword];
            }
            if (count($or) > 1) {
                $query->andWhere($or);
            }
        }

        return array_map([$this, 'normalizeProductRow'], $query->all(Yii::$app->db));
    }

    public function orderDetail(int $orderId, int $scopeStoreId = 0): array
    {
        $order = $this->orderRow($orderId, $scopeStoreId);
        if (!$order) {
            return [];
        }

        return [
            'version' => self::VERSION,
            'order' => $order,
            'items' => $this->orderItems($orderId, (int)$order['store_id']),
            'payment_attempts' => $this->paymentAttempts($orderId),
            'logistics' => $this->logisticsSummary($order),
            'boundaries' => $this->boundaries(),
        ];
    }

    public function productDetail(int $productId, int $scopeStoreId = 0): array
    {
        $product = $this->productRow($productId, $scopeStoreId);
        if (!$product) {
            return [];
        }

        return [
            'version' => self::VERSION,
            'product' => $product,
            'boundaries' => $this->boundaries(),
        ];
    }

    public function createAssistanceRequest(
        array $context,
        string $assistanceType,
        bool $apply = false,
        int $operatorId = 1,
        string $operatorType = CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
        int $scopeStoreId = 0
    ): array {
        $assistanceType = $this->normalizeAssistanceType($assistanceType);
        $definition = $this->assistanceTypes()[$assistanceType];
        $context = $this->enrichAssistanceContext($context);
        $context['assistance_type'] = $assistanceType;
        $context['risk_action'] = $definition['risk_action'];
        $context['approval_required'] = !empty($definition['approval_required']) ? 1 : 0;
        if (trim((string)($context['title'] ?? '')) === '') {
            $context['title'] = '客服协助：' . $definition['label'];
        }
        $prefix = '协助类型：' . $definition['label'];
        $content = trim((string)($context['content'] ?? ''));
        $context['content'] = $content === '' ? $prefix : ($prefix . "\n" . $content);
        $context['source'] = 'assistance-workbench';

        $result = (new CustomerServiceTicketCreateService())->run(
            $context,
            CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST,
            $apply,
            $operatorId,
            $operatorType,
            $scopeStoreId
        );
        $result['assistanceType'] = $assistanceType;
        $result['approvalRequired'] = !empty($definition['approval_required']);
        $result['riskAction'] = (string)$definition['risk_action'];
        $result['boundaries'] = $this->boundaries();

        return $result;
    }

    private function enrichAssistanceContext(array $context): array
    {
        $context['order_id'] = max(0, (int)($context['order_id'] ?? 0));
        $context['product_id'] = max(0, (int)($context['product_id'] ?? 0));
        $context['store_id'] = max(0, (int)($context['store_id'] ?? 0));
        $context['customer_user_id'] = max(0, (int)($context['customer_user_id'] ?? 0));
        $context['order_sn'] = (string)($context['order_sn'] ?? '');

        if ($context['order_id'] > 0) {
            $order = $this->orderRow($context['order_id'], 0);
            if ($order) {
                $context['store_id'] = $context['store_id'] ?: (int)$order['store_id'];
                $context['customer_user_id'] = $context['customer_user_id'] ?: (int)$order['user_id'];
                $context['order_sn'] = $context['order_sn'] !== '' ? $context['order_sn'] : (string)$order['sn'];
            }
        }
        if ($context['product_id'] > 0 && $context['store_id'] <= 0) {
            $product = $this->productRow($context['product_id'], 0);
            $context['store_id'] = $product ? (int)$product['store_id'] : 0;
        }

        return $context;
    }

    private function orderRow(int $orderId, int $scopeStoreId = 0): array
    {
        if ($orderId <= 0 || !$this->tableExists('{{%mall_order}}')) {
            return [];
        }

        $query = (new Query())
            ->select($this->selectColumns('{{%mall_order}}', 'o', [
                'id',
                'store_id',
                'user_id',
                'sn',
                'name',
                'first_name',
                'last_name',
                'mobile',
                'email',
                'country',
                'province',
                'city',
                'district',
                'address',
                'address2',
                'postcode',
                'amount',
                'product_amount',
                'payment_method',
                'payment_status',
                'paid_at',
                'shipment_id',
                'shipment_name',
                'shipment_fee',
                'shipment_status',
                'wlgs',
                'wldh',
                'shipped_at',
                'remark',
                'created_at',
                'updated_at',
                'status',
            ]))
            ->from(['o' => '{{%mall_order}}'])
            ->where(['o.id' => $orderId]);
        if ($scopeStoreId > 0) {
            $query->andWhere(['o.store_id' => $scopeStoreId]);
        }

        $row = $query->one(Yii::$app->db);
        return $row ? $this->normalizeOrderRow($row) : [];
    }

    private function productRow(int $productId, int $scopeStoreId = 0): array
    {
        if ($productId <= 0 || !$this->tableExists('{{%mall_product}}')) {
            return [];
        }

        $query = (new Query())
            ->select($this->selectColumns('{{%mall_product}}', 'p', [
                'id',
                'store_id',
                'category_id',
                'name',
                'sku',
                'stock_code',
                'stock',
                'stock_warning',
                'price',
                'market_price',
                'thumb',
                'image',
                'images',
                'brief',
                'content',
                'status',
                'audit_status',
                'audit_remark',
                'created_at',
                'updated_at',
            ]))
            ->from(['p' => '{{%mall_product}}'])
            ->where(['p.id' => $productId]);
        if ($scopeStoreId > 0) {
            $query->andWhere(['p.store_id' => $scopeStoreId]);
        }

        $row = $query->one(Yii::$app->db);
        return $row ? $this->normalizeProductRow($row) : [];
    }

    private function orderItems(int $orderId, int $storeId): array
    {
        if ($orderId <= 0 || !$this->tableExists('{{%mall_order_product}}')) {
            return [];
        }

        $query = (new Query())
            ->select($this->selectColumns('{{%mall_order_product}}', 'op', [
                'id',
                'store_id',
                'order_id',
                'product_id',
                'name',
                'sku',
                'product_attribute_value',
                'number',
                'price',
                'thumb',
                'status',
            ]))
            ->from(['op' => '{{%mall_order_product}}'])
            ->where(['op.order_id' => $orderId])
            ->orderBy(['op.id' => SORT_ASC])
            ->limit(50);
        if ($storeId > 0 && $this->hasColumn('{{%mall_order_product}}', 'store_id')) {
            $query->andWhere(['op.store_id' => $storeId]);
        }

        return array_map(function ($row) {
            return [
                'id' => (int)($row['id'] ?? 0),
                'store_id' => (int)($row['store_id'] ?? 0),
                'order_id' => (int)($row['order_id'] ?? 0),
                'product_id' => (int)($row['product_id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'sku' => (string)($row['sku'] ?? ''),
                'product_attribute_value' => (string)($row['product_attribute_value'] ?? ''),
                'number' => (int)($row['number'] ?? 0),
                'price' => (float)($row['price'] ?? 0),
                'thumb' => (string)($row['thumb'] ?? ''),
                'status' => (int)($row['status'] ?? 0),
            ];
        }, $query->all(Yii::$app->db));
    }

    private function paymentAttempts(int $orderId): array
    {
        if ($orderId <= 0 || !$this->tableExists('{{%mall_payment_attempt}}')) {
            return [];
        }

        $query = (new Query())
            ->select($this->selectColumns('{{%mall_payment_attempt}}', 'pa', [
                'id',
                'order_id',
                'provider',
                'event',
                'merchant_transaction_id',
                'gateway_transaction_id',
                'amount',
                'currency',
                'result',
                'error_message',
                'processed_at',
                'created_at',
            ]))
            ->from(['pa' => '{{%mall_payment_attempt}}'])
            ->where(['pa.order_id' => $orderId])
            ->orderBy(['pa.id' => SORT_DESC])
            ->limit(10);

        return array_map(function ($row) {
            return [
                'id' => (int)($row['id'] ?? 0),
                'provider' => (string)($row['provider'] ?? ''),
                'event' => (string)($row['event'] ?? ''),
                'merchant_transaction_id' => (string)($row['merchant_transaction_id'] ?? ''),
                'gateway_transaction_id' => (string)($row['gateway_transaction_id'] ?? ''),
                'amount' => (float)($row['amount'] ?? 0),
                'currency' => (string)($row['currency'] ?? ''),
                'result' => (string)($row['result'] ?? ''),
                'error_message' => (string)($row['error_message'] ?? ''),
                'processed_at' => (int)($row['processed_at'] ?? 0),
                'created_at' => (int)($row['created_at'] ?? 0),
            ];
        }, $query->all(Yii::$app->db));
    }

    private function logisticsSummary(array $order): array
    {
        return [
            'shipment_id' => (int)($order['shipment_id'] ?? 0),
            'shipment_name' => (string)($order['shipment_name'] ?? ''),
            'shipment_status' => (int)($order['shipment_status'] ?? 0),
            'wlgs' => (string)($order['wlgs'] ?? ''),
            'wldh' => (string)($order['wldh'] ?? ''),
            'shipped_at' => (int)($order['shipped_at'] ?? 0),
            'note' => 'Read-only logistics summary. Customer service may suggest handling only.',
        ];
    }

    private function orderIdsByProduct(int $productId, int $scopeStoreId, int $limit): array
    {
        if ($productId <= 0 || !$this->tableExists('{{%mall_order_product}}')) {
            return [];
        }

        $query = (new Query())
            ->select('order_id')
            ->from('{{%mall_order_product}}')
            ->where(['product_id' => $productId])
            ->limit($limit);
        if ($scopeStoreId > 0 && $this->hasColumn('{{%mall_order_product}}', 'store_id')) {
            $query->andWhere(['store_id' => $scopeStoreId]);
        }

        return array_map('intval', $query->column(Yii::$app->db));
    }

    private function orderIdsByOrderProductKeyword(string $keyword, int $scopeStoreId, int $limit): array
    {
        if ($keyword === '' || !$this->tableExists('{{%mall_order_product}}')) {
            return [];
        }

        $or = ['or'];
        foreach (['name', 'sku'] as $column) {
            if ($this->hasColumn('{{%mall_order_product}}', $column)) {
                $or[] = ['like', $column, $keyword];
            }
        }
        if (count($or) <= 1) {
            return [];
        }

        $query = (new Query())
            ->select('order_id')
            ->from('{{%mall_order_product}}')
            ->where($or)
            ->limit($limit);
        if ($scopeStoreId > 0 && $this->hasColumn('{{%mall_order_product}}', 'store_id')) {
            $query->andWhere(['store_id' => $scopeStoreId]);
        }

        return array_map('intval', $query->column(Yii::$app->db));
    }

    private function normalizeOrderRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'sn' => (string)($row['sn'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'mobile' => (string)($row['mobile'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'amount' => (float)($row['amount'] ?? 0),
            'product_amount' => (float)($row['product_amount'] ?? 0),
            'payment_method' => (int)($row['payment_method'] ?? 0),
            'payment_status' => (int)($row['payment_status'] ?? 0),
            'paid_at' => (int)($row['paid_at'] ?? 0),
            'shipment_id' => (int)($row['shipment_id'] ?? 0),
            'shipment_name' => (string)($row['shipment_name'] ?? ''),
            'shipment_status' => (int)($row['shipment_status'] ?? 0),
            'wlgs' => (string)($row['wlgs'] ?? ''),
            'wldh' => (string)($row['wldh'] ?? ''),
            'shipped_at' => (int)($row['shipped_at'] ?? 0),
            'address' => trim((string)($row['country'] ?? '') . ' ' . (string)($row['province'] ?? '') . ' ' . (string)($row['city'] ?? '') . ' ' . (string)($row['district'] ?? '') . ' ' . (string)($row['address'] ?? '') . ' ' . (string)($row['address2'] ?? '')),
            'postcode' => (string)($row['postcode'] ?? ''),
            'remark' => (string)($row['remark'] ?? ''),
            'created_at' => (int)($row['created_at'] ?? 0),
            'updated_at' => (int)($row['updated_at'] ?? 0),
            'status' => (int)($row['status'] ?? 0),
        ];
    }

    private function normalizeProductRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'category_id' => (int)($row['category_id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'sku' => (string)($row['sku'] ?? ''),
            'stock_code' => (string)($row['stock_code'] ?? ''),
            'stock' => (int)($row['stock'] ?? 0),
            'stock_warning' => (int)($row['stock_warning'] ?? 0),
            'price' => (float)($row['price'] ?? 0),
            'market_price' => (float)($row['market_price'] ?? 0),
            'thumb' => (string)($row['thumb'] ?? ''),
            'image' => (string)($row['image'] ?? ''),
            'images' => $this->normalizeImages($row['images'] ?? ''),
            'brief' => (string)($row['brief'] ?? ''),
            'content' => strip_tags((string)($row['content'] ?? '')),
            'seo_keywords' => (string)($row['seo_keywords'] ?? ''),
            'status' => (int)($row['status'] ?? 0),
            'audit_status' => (string)($row['audit_status'] ?? ''),
            'audit_remark' => (string)($row['audit_remark'] ?? ''),
            'created_at' => (int)($row['created_at'] ?? 0),
            'updated_at' => (int)($row['updated_at'] ?? 0),
        ];
    }

    private function normalizeImages($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        $value = trim((string)$value);
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function normalizeAssistanceType(string $assistanceType): string
    {
        $assistanceType = strtolower(trim($assistanceType));
        $types = $this->assistanceTypes();
        if (!isset($types[$assistanceType])) {
            throw new \InvalidArgumentException('Unsupported assistance type.');
        }

        return $assistanceType;
    }

    private function selectColumns(string $table, string $alias, array $columns): array
    {
        $select = [];
        foreach ($columns as $column) {
            if ($this->hasColumn($table, $column)) {
                $select[$column] = $alias . '.' . $column;
            }
        }

        return $select ?: ['id' => $alias . '.id'];
    }

    private function keyword(string $keyword): string
    {
        return function_exists('mb_substr')
            ? mb_substr(trim($keyword), 0, 80, 'UTF-8')
            : substr(trim($keyword), 0, 80);
    }

    private function limit(int $limit): int
    {
        return max(1, min(50, $limit));
    }

    private function hasColumn(string $table, string $column): bool
    {
        $schema = Yii::$app->db->schema->getTableSchema($table, true);
        return $schema !== null && isset($schema->columns[$column]);
    }

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }
}
