<?php

namespace common\services\mall;

class ProductSearchVideoPhase14Service
{
    public const VERSION = 'MONGOYIA_PRODUCT_SEARCH_VIDEO_PHASE14_V1';

    public function sortOrder(string $sort): array
    {
        $sort = strtolower(trim($sort));
        $map = [
            'sales_desc' => ['sales' => SORT_DESC, 'id' => SORT_DESC],
            '-sales' => ['sales' => SORT_DESC, 'id' => SORT_DESC],
            'best_selling' => ['sales' => SORT_DESC, 'id' => SORT_DESC],
            'price_asc' => ['price' => SORT_ASC, 'id' => SORT_DESC],
            'price' => ['price' => SORT_ASC, 'id' => SORT_DESC],
            'price_desc' => ['price' => SORT_DESC, 'id' => SORT_DESC],
            '-price' => ['price' => SORT_DESC, 'id' => SORT_DESC],
            'newest' => ['id' => SORT_DESC],
            '-id' => ['id' => SORT_DESC],
            'oldest' => ['id' => SORT_ASC],
            'id' => ['id' => SORT_ASC],
        ];

        return $map[$sort] ?? ['sort' => SORT_ASC, 'id' => SORT_DESC];
    }

    public function buildSuggestions(array $products, string $keyword, int $limit = 8): array
    {
        $keyword = mb_strtolower(trim($keyword));
        $limit = max(1, min(20, $limit));
        $items = [];
        $seen = [];

        foreach ($products as $product) {
            foreach ([
                'keyword' => (string)($product['name'] ?? ''),
                'sku' => (string)($product['sku'] ?? ''),
            ] as $type => $value) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                if ($keyword !== '' && mb_strpos(mb_strtolower($value), $keyword) === false) {
                    continue;
                }

                $key = $type . ':' . mb_strtolower($value);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $items[] = [
                    'type' => $type,
                    'value' => $value,
                    'label' => $type === 'sku' ? 'SKU: ' . $value : $value,
                    'product_id' => (int)($product['id'] ?? 0),
                ];
                if (count($items) >= $limit) {
                    return $items;
                }
            }
        }

        return $items;
    }

    public function filterFixtureProducts(array $products, array $filters): array
    {
        $keyword = mb_strtolower(trim((string)($filters['keyword'] ?? '')));
        $brandId = (int)($filters['brand_id'] ?? ($filters['brand'] ?? 0));
        $minPrice = $this->optionalFloat($filters['min_price'] ?? null);
        $maxPrice = $this->optionalFloat($filters['max_price'] ?? null);

        $rows = array_values(array_filter($products, function (array $product) use ($keyword, $brandId, $minPrice, $maxPrice): bool {
            if ($keyword !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    (string)($product['name'] ?? ''),
                    (string)($product['sku'] ?? ''),
                    (string)($product['brief'] ?? ''),
                ]));
                if (mb_strpos($haystack, $keyword) === false) {
                    return false;
                }
            }
            if ($brandId > 0 && (int)($product['brand_id'] ?? 0) !== $brandId) {
                return false;
            }
            if ($minPrice !== null && (float)($product['price'] ?? 0) < $minPrice) {
                return false;
            }
            if ($maxPrice !== null && (float)($product['price'] ?? 0) > $maxPrice) {
                return false;
            }

            return true;
        }));

        $sort = strtolower(trim((string)($filters['sort'] ?? '')));
        usort($rows, function (array $a, array $b) use ($sort): int {
            if (in_array($sort, ['sales_desc', '-sales', 'best_selling'], true)) {
                return ((int)($b['sales'] ?? 0) <=> (int)($a['sales'] ?? 0)) ?: ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            }
            if (in_array($sort, ['price_asc', 'price'], true)) {
                return ((float)($a['price'] ?? 0) <=> (float)($b['price'] ?? 0)) ?: ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            }
            if (in_array($sort, ['price_desc', '-price'], true)) {
                return ((float)($b['price'] ?? 0) <=> (float)($a['price'] ?? 0)) ?: ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
            }

            return ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
        });

        return $rows;
    }

    public function videoPayload(string $videoUrl): array
    {
        $videoUrl = $this->normalizeVideoUrl($videoUrl);
        return [
            'video_url' => $videoUrl,
            'has_video' => $videoUrl !== '',
            'media_type' => $videoUrl === '' ? '' : 'video',
        ];
    }

    public function normalizeVideoUrl(string $videoUrl): string
    {
        $videoUrl = trim($videoUrl);
        if ($videoUrl === '') {
            return '';
        }
        if (preg_match('/[\r\n]/', $videoUrl)) {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $videoUrl) || strpos($videoUrl, '/') === 0) {
            return $videoUrl;
        }

        return '';
    }

    public function fixtureProducts(): array
    {
        return [
            ['id' => 101, 'name' => 'Cashmere Scarf', 'sku' => 'SCARF-RED-001', 'brief' => 'Mongolian wool scarf', 'brand_id' => 7, 'price' => 29.90, 'sales' => 12, 'video_url' => 'https://cdn.example.test/products/scarf.mp4'],
            ['id' => 102, 'name' => 'Leather Wallet', 'sku' => 'WALLET-BLK-002', 'brief' => 'Small black wallet', 'brand_id' => 8, 'price' => 19.50, 'sales' => 38, 'video_url' => ''],
            ['id' => 103, 'name' => 'Cashmere Hat', 'sku' => 'HAT-GRY-003', 'brief' => 'Warm winter hat', 'brand_id' => 7, 'price' => 15.00, 'sales' => 8, 'video_url' => '/uploads/product/hat.webm'],
        ];
    }

    private function optionalFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float)$value : null;
    }
}
