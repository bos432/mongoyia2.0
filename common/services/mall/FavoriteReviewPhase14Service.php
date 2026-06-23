<?php

namespace common\services\mall;

class FavoriteReviewPhase14Service
{
    public const VERSION = 'MONGOYIA_FAVORITE_REVIEW_PHASE14_V1';

    public function planStoreFavoriteToggle(array $currentFavorites, int $userId, int $storeId, string $storeName): array
    {
        $matched = null;
        foreach ($currentFavorites as $favorite) {
            if ((int)($favorite['user_id'] ?? 0) === $userId &&
                (int)($favorite['store_id'] ?? 0) === $storeId &&
                (int)($favorite['status'] ?? 0) > 0) {
                $matched = $favorite;
                break;
            }
        }

        if ($matched) {
            return [
                'version' => self::VERSION,
                'action' => 'cancel_store_favorite',
                'store_favorite' => false,
                'store_id' => $storeId,
                'user_id' => $userId,
                'name' => $storeName,
                'mutates_order' => false,
                'mutates_fund' => false,
                'mutates_stock' => false,
            ];
        }

        return [
            'version' => self::VERSION,
            'action' => 'create_store_favorite',
            'store_favorite' => true,
            'store_id' => $storeId,
            'user_id' => $userId,
            'name' => $storeName,
            'mutates_order' => false,
            'mutates_fund' => false,
            'mutates_stock' => false,
        ];
    }

    public function reviewModerationTransition(string $action): array
    {
        $action = strtolower(trim($action));
        $map = [
            'approve' => [
                'moderation_status' => 'approved',
                'status' => 1,
                'visible_to_users' => true,
            ],
            'reject' => [
                'moderation_status' => 'rejected',
                'status' => 0,
                'visible_to_users' => false,
            ],
            'violation' => [
                'moderation_status' => 'violation',
                'status' => 0,
                'visible_to_users' => false,
            ],
            'pending' => [
                'moderation_status' => 'pending',
                'status' => 0,
                'visible_to_users' => false,
            ],
        ];

        return [
            'version' => self::VERSION,
            'action' => $action,
            'transition' => $map[$action] ?? $map['pending'],
            'review_apply_requires_backend_permission' => true,
            'mutates_order' => false,
            'mutates_fund' => false,
            'mutates_stock' => false,
        ];
    }

    public function fixtureFavorites(): array
    {
        return [
            ['id' => 1, 'user_id' => 701, 'store_id' => 3, 'name' => 'Store A', 'status' => 1],
            ['id' => 2, 'user_id' => 701, 'store_id' => 4, 'name' => 'Store B', 'status' => -1],
        ];
    }
}
