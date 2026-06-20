<?php

namespace common\services\mall;

use common\models\mall\Order;
use Yii;

class DistributionInviteService
{
    const INVITE_STATUS_ACTIVE = 'active';
    const INVITE_STATUS_BLOCKED = 'blocked';

    const REWARD_STATUS_PENDING = 'pending';
    const REWARD_STATUS_APPROVED = 'approved';
    const REWARD_STATUS_REJECTED = 'rejected';
    const REWARD_STATUS_WITHDRAWN = 'withdrawn';

    public function recordInvite(int $distributorId, int $invitedUserId, bool $apply = true, string $source = 'fxid'): array
    {
        $result = [
            'apply' => $apply,
            'distributorUserId' => $distributorId,
            'invitedUserId' => $invitedUserId,
            'created' => 0,
            'updated' => 0,
            'skippedReason' => '',
        ];

        if ($distributorId <= 0 || $invitedUserId <= 0) {
            $result['skippedReason'] = 'missing distributor or invited user';
            return $result;
        }
        if ($distributorId === $invitedUserId) {
            $result['skippedReason'] = 'self invite is not allowed';
            return $result;
        }

        $existing = $this->inviteByInvitedUser($invitedUserId);
        if ($existing && (int)$existing['distributor_user_id'] !== $distributorId) {
            $result['skippedReason'] = 'invited user already belongs to another distributor';
            return $result;
        }
        if (!$apply) {
            return $result;
        }

        $now = time();
        if ($existing) {
            Yii::$app->db->createCommand()->update('{{%mall_distribution_invite}}', [
                'invite_status' => self::INVITE_STATUS_ACTIVE,
                'source' => $this->clean($source, 32),
                'updated_at' => $now,
                'updated_by' => $distributorId,
            ], ['id' => (int)$existing['id']])->execute();
            $result['updated'] = 1;
            return $result;
        }

        Yii::$app->db->createCommand()->insert('{{%mall_distribution_invite}}', [
            'distributor_user_id' => $distributorId,
            'invited_user_id' => $invitedUserId,
            'source' => $this->clean($source, 32),
            'invite_status' => self::INVITE_STATUS_ACTIVE,
            'first_order_id' => 0,
            'first_order_at' => 0,
            'remark' => 'Created by distribution invite service',
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $distributorId,
            'updated_by' => $distributorId,
        ])->execute();
        $result['created'] = 1;
        return $result;
    }

    public function run(int $storeId = 0, int $limit = 100, bool $apply = false, int $distributorId = 0): array
    {
        $orders = $this->candidateOrders($storeId, max(1, $limit), $distributorId);
        $result = $this->emptyResult($apply);

        foreach ($orders as $order) {
            $result['scanned']++;
            $blockedReason = $this->blockedReason($order);
            if ($blockedReason !== '') {
                $result['blockedOrders']++;
                $result['blockedRows'][] = $this->blockedRow($order, $blockedReason);
                continue;
            }

            $invite = $this->inviteByInvitedUser((int)$order['user_id']);
            if (!$invite) {
                $result['blockedOrders']++;
                $result['blockedRows'][] = $this->blockedRow($order, 'missing invite relation');
                continue;
            }
            if ((string)$invite['invite_status'] !== self::INVITE_STATUS_ACTIVE) {
                $result['blockedOrders']++;
                $result['blockedRows'][] = $this->blockedRow($order, 'invite relation is not active');
                continue;
            }
            if ($this->rewardExists((int)$order['id'])) {
                $result['duplicateOrders']++;
                $result['blockedRows'][] = $this->blockedRow($order, 'invite reward already exists');
                continue;
            }

            $rule = $this->activeRule((int)$order['store_id']);
            if (!$rule) {
                $result['blockedOrders']++;
                $result['blockedRows'][] = $this->blockedRow($order, 'missing active distribution rule');
                continue;
            }

            $rewardAmount = round((float)($rule['invite_reward_amount'] ?? 0), 2);
            if ($rewardAmount <= 0) {
                $result['blockedOrders']++;
                $result['blockedRows'][] = $this->blockedRow($order, 'invite reward amount is not configured');
                continue;
            }

            $row = $this->rewardRow($order, $invite, $rewardAmount);
            $result['readyOrders']++;
            $result['rewardAmount'] += (float)$row['reward_amount'];
            $this->addDistributorSummary($result, $row);

            if ($apply) {
                Yii::$app->db->createCommand()->insert('{{%mall_distribution_invite_reward}}', $row)->execute();
                $result['rewardsCreated']++;
                $row['reward_id'] = (int)Yii::$app->db->getLastInsertID();

                if ((int)$invite['first_order_id'] <= 0) {
                    Yii::$app->db->createCommand()->update('{{%mall_distribution_invite}}', [
                        'first_order_id' => (int)$order['id'],
                        'first_order_at' => (int)$order['created_at'],
                        'updated_at' => time(),
                        'updated_by' => 1,
                    ], ['id' => (int)$invite['id']])->execute();
                }
            } else {
                $row['reward_id'] = null;
            }
            $result['rewards'][] = $row;
        }

        $this->roundResult($result);
        return $result;
    }

    public function summary(int $distributorId): array
    {
        $inviteRows = (new \yii\db\Query())
            ->from('{{%mall_distribution_invite}}')
            ->where(['distributor_user_id' => $distributorId, 'status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit(20)
            ->all(Yii::$app->db);

        $rewardSummary = (new \yii\db\Query())
            ->select([
                'reward_status',
                'rows' => 'COUNT(*)',
                'reward_amount' => 'SUM(reward_amount)',
            ])
            ->from('{{%mall_distribution_invite_reward}}')
            ->where(['distributor_user_id' => $distributorId, 'status' => 1])
            ->groupBy(['reward_status'])
            ->orderBy(['reward_status' => SORT_ASC])
            ->all(Yii::$app->db);

        $rewardRows = (new \yii\db\Query())
            ->from('{{%mall_distribution_invite_reward}}')
            ->where(['distributor_user_id' => $distributorId, 'status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit(20)
            ->all(Yii::$app->db);

        return [
            'invites' => $inviteRows,
            'rewardSummary' => $rewardSummary,
            'rewardRows' => $rewardRows,
        ];
    }

    private function candidateOrders(int $storeId, int $limit, int $distributorId): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_order}}')
            ->where(['>', 'status', 0])
            ->andWhere(['in', 'payment_status', [Order::PAYMENT_STATUS_COD, Order::PAYMENT_STATUS_PAID]])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit);

        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ($distributorId > 0) {
            $query->andWhere(['fx_id' => $distributorId]);
        }

        return $query->all(Yii::$app->db);
    }

    private function blockedReason(array $order): string
    {
        if ((int)$order['fx_id'] <= 0) {
            return 'missing distributor attribution';
        }
        if (!in_array((int)$order['payment_status'], [Order::PAYMENT_STATUS_COD, Order::PAYMENT_STATUS_PAID], true)) {
            return 'not paid/COD';
        }
        if ((int)$order['shipment_status'] < Order::SHIPMENT_STATUS_RECEIVED) {
            return 'not received';
        }
        if (round((float)$order['amount'], 2) <= 0) {
            return 'zero amount';
        }

        return '';
    }

    private function activeRule(int $storeId): ?array
    {
        $rule = (new \yii\db\Query())
            ->from('{{%mall_distribution_rule}}')
            ->where(['store_id' => $storeId, 'rule_status' => DistributionCommissionService::RULE_STATUS_ACTIVE, 'status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->one(Yii::$app->db);

        return $rule ?: null;
    }

    private function inviteByInvitedUser(int $invitedUserId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_distribution_invite}}')
            ->where(['invited_user_id' => $invitedUserId, 'status' => 1])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function rewardExists(int $orderId): bool
    {
        return (new \yii\db\Query())
            ->from('{{%mall_distribution_invite_reward}}')
            ->where(['order_id' => $orderId])
            ->exists(Yii::$app->db);
    }

    private function rewardRow(array $order, array $invite, float $rewardAmount): array
    {
        $now = time();
        return [
            'invite_id' => (int)$invite['id'],
            'store_id' => (int)$order['store_id'],
            'order_id' => (int)$order['id'],
            'order_sn' => (string)$order['sn'],
            'distributor_user_id' => (int)$invite['distributor_user_id'],
            'invited_user_id' => (int)$order['user_id'],
            'reward_amount' => $rewardAmount,
            'reward_status' => self::REWARD_STATUS_PENDING,
            'source' => 'first_order',
            'remark' => 'Created by mongoyia-distribution-invite-test/run',
            'settled_at' => 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    private function blockedRow(array $order, string $reason): array
    {
        return [
            'order_id' => (int)$order['id'],
            'store_id' => (int)$order['store_id'],
            'distributor_user_id' => (int)($order['fx_id'] ?? 0),
            'buyer_user_id' => (int)($order['user_id'] ?? 0),
            'order_amount' => round((float)($order['amount'] ?? 0), 2),
            'reason' => $reason,
        ];
    }

    private function addDistributorSummary(array &$result, array $row): void
    {
        $userId = (int)$row['distributor_user_id'];
        if (!isset($result['distributors'][$userId])) {
            $result['distributors'][$userId] = [
                'distributor_user_id' => $userId,
                'rewards' => 0,
                'reward_amount' => 0.0,
            ];
        }
        $result['distributors'][$userId]['rewards']++;
        $result['distributors'][$userId]['reward_amount'] += (float)$row['reward_amount'];
    }

    private function emptyResult(bool $apply): array
    {
        return [
            'apply' => $apply,
            'scanned' => 0,
            'readyOrders' => 0,
            'blockedOrders' => 0,
            'duplicateOrders' => 0,
            'rewardsCreated' => 0,
            'rewardAmount' => 0.0,
            'rewards' => [],
            'blockedRows' => [],
            'distributors' => [],
        ];
    }

    private function roundResult(array &$result): void
    {
        $result['rewardAmount'] = round((float)$result['rewardAmount'], 2);
        foreach ($result['distributors'] as &$row) {
            $row['reward_amount'] = round((float)$row['reward_amount'], 2);
        }
        unset($row);
        ksort($result['distributors']);
    }

    private function clean(string $value, int $length): string
    {
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
