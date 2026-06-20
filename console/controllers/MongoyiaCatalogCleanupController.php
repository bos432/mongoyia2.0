<?php

namespace console\controllers;

use common\models\BaseModel;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaCatalogCleanupController extends Controller
{
    public $apply = false;
    public $fixOrphanCategories = true;
    public $limit = 100;

    private $orphanCategories = [];
    private $zeroPriceProducts = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'apply',
            'fixOrphanCategories',
            'limit',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia catalog cleanup\n");
        $this->stdout($this->apply ? "Mode: apply\n" : "Mode: dry-run\n");

        $this->orphanCategories = $this->findOrphanCategories();
        $this->zeroPriceProducts = $this->findZeroPriceProducts();

        $this->printOrphanCategories();
        $this->printZeroPriceProducts();

        if (!$this->apply) {
            $this->stdout("\nDry-run only. Re-run with --apply=1 to set active orphan categories to top-level parent_id=0.\n");
            $this->stdout("Zero-price products are reported only; set real prices or deactivate them from the backend after business confirmation.\n");
            return ExitCode::OK;
        }

        if (!$this->fixOrphanCategories || !$this->orphanCategories) {
            $this->stdout("\nNo category cleanup applied.\n");
            return ExitCode::OK;
        }

        $ids = array_map('intval', array_column($this->orphanCategories, 'id'));
        $count = Yii::$app->db->createCommand()->update('{{%mall_category}}', [
            'parent_id' => 0,
            'updated_at' => time(),
        ], ['id' => $ids])->execute();

        $this->stdout("\nCategory cleanup applied. Updated {$count} category row(s).\n");
        return ExitCode::OK;
    }

    private function findOrphanCategories()
    {
        return (new \yii\db\Query())
            ->select([
                'c.id',
                'c.parent_id',
                'c.store_id',
                'c.name',
                'parent_name' => 'parent.name',
                'parent_status' => 'parent.status',
            ])
            ->from(['c' => '{{%mall_category}}'])
            ->leftJoin(['parent' => '{{%mall_category}}'], 'parent.id = c.parent_id')
            ->where(['c.status' => BaseModel::STATUS_ACTIVE])
            ->andWhere(['>', 'c.parent_id', 0])
            ->andWhere(['or', ['parent.id' => null], ['<=', 'parent.status', BaseModel::STATUS_DELETED]])
            ->orderBy(['c.id' => SORT_ASC])
            ->limit((int)$this->limit)
            ->all(Yii::$app->db);
    }

    private function findZeroPriceProducts()
    {
        return (new \yii\db\Query())
            ->select(['id', 'store_id', 'category_id', 'name', 'sku', 'stock', 'price'])
            ->from('{{%mall_product}}')
            ->where(['status' => BaseModel::STATUS_ACTIVE])
            ->andWhere(['<=', 'price', 0])
            ->orderBy(['id' => SORT_ASC])
            ->limit((int)$this->limit)
            ->all(Yii::$app->db);
    }

    private function printOrphanCategories()
    {
        $this->stdout("\n[Orphan active categories]\n");
        if (!$this->orphanCategories) {
            $this->stdout("No orphan active categories found.\n");
            return;
        }

        foreach ($this->orphanCategories as $row) {
            $parent = $row['parent_name'] ? "{$row['parent_name']} status={$row['parent_status']}" : 'missing';
            $this->stdout("CATEGORY {$row['id']} {$row['name']} parent={$row['parent_id']} ({$parent}) -> parent_id=0\n");
        }
    }

    private function printZeroPriceProducts()
    {
        $this->stdout("\n[Zero-price active products]\n");
        if (!$this->zeroPriceProducts) {
            $this->stdout("No zero-price active products found.\n");
            return;
        }

        foreach ($this->zeroPriceProducts as $row) {
            $this->stdout("PRODUCT {$row['id']} {$row['name']} store={$row['store_id']} category={$row['category_id']} sku={$row['sku']} stock={$row['stock']} price={$row['price']}\n");
        }
    }
}
