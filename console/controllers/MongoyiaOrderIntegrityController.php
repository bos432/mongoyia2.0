<?php

namespace console\controllers;

use common\models\BaseModel;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaOrderIntegrityController extends Controller
{
    public $limit = 100;
    public $strict = false;
    public $amountTolerance = 0.01;
    public $includeLegacy = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'limit',
            'strict',
            'amountTolerance',
            'includeLegacy',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia order integrity check\n");

        $parentIds = $this->recentParentOrderIds();
        if (!$parentIds) {
            $this->warn('No parent orders found for integrity sample.');
        } else {
            $this->checkParents($parentIds);
        }

        $this->checkOrderProducts();
        $this->checkProductOwnership();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function recentParentOrderIds()
    {
        $query = (new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_order}}')
            ->where(['parent_id' => 0])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC]);

        if (!$this->includeLegacy) {
            $query->andWhere(['exists', (new \yii\db\Query())
                ->select(new \yii\db\Expression('1'))
                ->from('{{%mall_order}} child')
                ->where('child.parent_id = {{%mall_order}}.id')
                ->andWhere(['>', 'child.status', BaseModel::STATUS_DELETED])
            ]);
        }

        return array_map('intval', $query
            ->limit((int)$this->limit)
            ->column(Yii::$app->db));
    }

    private function checkParents(array $parentIds)
    {
        $this->section('Parent/child totals');
        $parents = (new \yii\db\Query())
            ->from('{{%mall_order}}')
            ->where(['id' => $parentIds])
            ->indexBy('id')
            ->all(Yii::$app->db);

        foreach ($parents as $parent) {
            $children = (new \yii\db\Query())
                ->select([
                    'cnt' => 'COUNT(*)',
                    'amount' => 'COALESCE(SUM(amount), 0)',
                    'product_amount' => 'COALESCE(SUM(product_amount), 0)',
                    'number' => 'COALESCE(SUM(number), 0)',
                ])
                ->from('{{%mall_order}}')
                ->where(['parent_id' => (int)$parent['id']])
                ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
                ->one(Yii::$app->db);

            if ((int)$children['cnt'] <= 0) {
                $this->warn("Parent order {$parent['id']} has no active child orders.");
                continue;
            }

            $this->compareMoney((int)$parent['id'], 'amount', (float)$parent['amount'], (float)$children['amount']);
            $this->compareMoney((int)$parent['id'], 'product_amount', (float)$parent['product_amount'], (float)$children['product_amount']);
            if ((int)$parent['number'] !== (int)$children['number']) {
                $this->fail("Parent order {$parent['id']} number {$parent['number']} does not match child sum {$children['number']}.");
            }
        }

        $this->ok('Checked ' . count($parents) . ' parent order(s).');
    }

    private function checkOrderProducts()
    {
        $this->section('Child order products');
        $mismatchQuery = (new \yii\db\Query())
            ->select(['op.id', 'op.order_id', 'op.store_id AS op_store_id', 'o.store_id AS order_store_id'])
            ->from('{{%mall_order_product}} op')
            ->innerJoin('{{%mall_order}} o', 'o.id = op.order_id')
            ->where(['>', 'op.status', BaseModel::STATUS_DELETED])
            ->andWhere(['>', 'o.status', BaseModel::STATUS_DELETED])
            ->andWhere(['<>', 'op.store_id', new \yii\db\Expression('o.store_id')]);
        if (!$this->includeLegacy) {
            $mismatchQuery->andWhere(['>', 'o.parent_id', 0]);
        }

        $mismatches = $mismatchQuery
            ->orderBy(['op.id' => SORT_DESC])
            ->limit(10)
            ->all(Yii::$app->db);

        if ($mismatches) {
            foreach ($mismatches as $row) {
                $this->fail("Order product {$row['id']} store {$row['op_store_id']} does not match order {$row['order_id']} store {$row['order_store_id']}.");
            }
        } else {
            $this->ok('Order product store_id matches child order store_id.');
        }

        $parentLineCount = (int)(new \yii\db\Query())
            ->from('{{%mall_order_product}} op')
            ->innerJoin('{{%mall_order}} o', 'o.id = op.order_id')
            ->where(['o.parent_id' => 0])
            ->andWhere(['>', 'op.status', BaseModel::STATUS_DELETED])
            ->andWhere(['>', 'o.status', BaseModel::STATUS_DELETED])
            ->count('*', Yii::$app->db);
        if ($parentLineCount > 0) {
            $message = "Legacy parent orders have {$parentLineCount} active order product row(s); new parent/child orders should keep lines on child orders.";
            $this->includeLegacy ? $this->fail($message) : $this->warn($message);
        } else {
            $this->ok('Parent orders do not carry active order product rows.');
        }
    }

    private function checkProductOwnership()
    {
        $this->section('Product ownership');
        $mismatches = (new \yii\db\Query())
            ->select(['op.id', 'op.product_id', 'op.store_id AS op_store_id', 'p.store_id AS product_store_id'])
            ->from('{{%mall_order_product}} op')
            ->innerJoin('{{%mall_product}} p', 'p.id = op.product_id')
            ->where(['>', 'op.status', BaseModel::STATUS_DELETED])
            ->andWhere(['<>', 'op.store_id', new \yii\db\Expression('p.store_id')])
            ->orderBy(['op.id' => SORT_DESC])
            ->limit(10)
            ->all(Yii::$app->db);

        if ($mismatches) {
            foreach ($mismatches as $row) {
                $this->fail("Order product {$row['id']} store {$row['op_store_id']} does not match product {$row['product_id']} store {$row['product_store_id']}.");
            }
            return;
        }

        $this->ok('Order product store_id matches product store_id.');
    }

    private function compareMoney(int $orderId, string $field, float $parentValue, float $childValue)
    {
        if (abs($parentValue - $childValue) > (float)$this->amountTolerance) {
            $this->fail("Parent order {$orderId} {$field} {$parentValue} does not match child sum {$childValue}.");
        }
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
