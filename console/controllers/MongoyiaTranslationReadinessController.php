<?php

namespace console\controllers;

use common\models\BaseModel;
use common\models\base\Lang;
use common\models\mall\Category;
use common\models\mall\Product;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\ActiveRecord;

class MongoyiaTranslationReadinessController extends Controller
{
    public $targets = 'en,mn';
    public $productIds = '90,102';
    public $categoryIds = '93,94,95,96,97,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114';
    public $sampleLimit = 50;
    public $minCoverage = 80;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'targets',
            'productIds',
            'categoryIds',
            'sampleLimit',
            'minCoverage',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia translation readiness check\n");

        $targets = $this->targetList();
        if (!$targets) {
            $this->fail('No target languages configured.');
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->checkFocusedProducts($targets);
        $this->checkFocusedCategories($targets);
        $this->checkConfiguredCategories($targets);
        $this->checkCoverage(Product::class, $targets, (int)$this->sampleLimit);
        $this->checkCoverage(Category::class, $targets, (int)$this->sampleLimit);

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFocusedProducts(array $targets)
    {
        $this->section('Focused products');
        $ids = $this->ids($this->productIds);
        if (!$ids) {
            $this->fail('No productIds configured for translation readiness.');
            return;
        }

        foreach ($ids as $id) {
            $product = Product::findOne($id);
            if (!$product) {
                $this->fail("Product {$id} is missing.");
                continue;
            }
            $this->checkModelTranslations($product, $targets, "product {$id}");
        }
    }

    private function checkFocusedCategories(array $targets)
    {
        $this->section('Focused categories');
        $categoryIds = (new \yii\db\Query())
            ->select('category_id')
            ->distinct()
            ->from('{{%mall_product}}')
            ->where(['id' => $this->ids($this->productIds)])
            ->andWhere(['>', 'category_id', 0])
            ->column(Yii::$app->db);

        if (!$categoryIds) {
            $this->warn('Focused products have no category_id values.');
            return;
        }

        foreach ($categoryIds as $id) {
            $category = Category::findOne((int)$id);
            if (!$category) {
                $this->warn("Category {$id} referenced by focused products is missing.");
                continue;
            }
            $this->checkModelTranslations($category, $targets, "category {$id}");
        }
    }

    private function checkConfiguredCategories(array $targets)
    {
        $this->section('Configured categories');
        $categoryIds = $this->ids($this->categoryIds);
        if (!$categoryIds) {
            $this->warn('No categoryIds configured for translation readiness.');
            return;
        }

        foreach ($categoryIds as $id) {
            $category = Category::findOne((int)$id);
            if (!$category) {
                $this->warn("Configured category {$id} is missing.");
                continue;
            }
            $this->checkModelTranslations($category, $targets, "category {$id}");
        }
    }

    private function checkCoverage(string $class, array $targets, int $limit)
    {
        $label = $class === Product::class ? 'Product coverage' : 'Category coverage';
        $this->section($label);

        $query = $class::find()
            ->where(['status' => BaseModel::STATUS_ACTIVE])
            ->orderBy(['id' => SORT_ASC]);
        if ($limit > 0) {
            $query->limit($limit);
        }

        $models = $query->all();
        if (!$models) {
            $this->warn("No active records found for {$class}.");
            return;
        }

        $stats = [];
        foreach ($targets as $target) {
            $stats[$target] = ['required' => 0, 'present' => 0, 'dirty' => 0];
        }

        foreach ($models as $model) {
            $fields = $this->translatableFields($model);
            foreach ($fields as $field) {
                $source = trim(strip_tags((string)$model->$field));
                if (!$this->requiresTranslation($source)) {
                    continue;
                }
                foreach ($targets as $target) {
                    $stats[$target]['required']++;
                    $content = $this->translationContent($model, $field, $target);
                    if ($content !== '') {
                        $stats[$target]['present']++;
                    }
                    if ($this->isDirtyTranslation($content, $target)) {
                        $stats[$target]['dirty']++;
                    }
                }
            }
        }

        foreach ($stats as $target => $stat) {
            $required = $stat['required'];
            $present = $stat['present'];
            $coverage = $required > 0 ? round(($present / $required) * 100, 2) : 100;
            $message = "{$class} {$target}: {$present}/{$required} translated ({$coverage}%), dirty={$stat['dirty']}";
            if ($coverage < (float)$this->minCoverage || $stat['dirty'] > 0) {
                $this->warn($message);
            } else {
                $this->ok($message);
            }
        }
    }

    private function checkModelTranslations(ActiveRecord $model, array $targets, string $label)
    {
        $missing = [];
        $dirty = [];
        foreach ($this->translatableFields($model) as $field) {
            $source = trim(strip_tags((string)$model->$field));
            if (!$this->requiresTranslation($source)) {
                continue;
            }

            foreach ($targets as $target) {
                $content = $this->translationContent($model, $field, $target);
                if ($content === '') {
                    $missing[] = "{$field}:{$target}";
                } elseif ($this->isDirtyTranslation($content, $target)) {
                    $dirty[] = "{$field}:{$target}";
                }
            }
        }

        if ($missing || $dirty) {
            $parts = [];
            if ($missing) {
                $parts[] = 'missing=' . implode(',', $missing);
            }
            if ($dirty) {
                $parts[] = 'dirty=' . implode(',', $dirty);
            }
            $this->warn("{$label} translation issue: " . implode(' ', $parts));
            return;
        }

        $this->ok("{$label} translations are present for " . implode(',', $targets) . '.');
    }

    private function translatableFields(ActiveRecord $model)
    {
        $fields = [];
        foreach ($model::getLangFieldType() as $field => $type) {
            if ($type === 'Ueditor') {
                continue;
            }
            $fields[] = $field;
        }
        return $fields;
    }

    private function translationContent(ActiveRecord $model, string $field, string $target)
    {
        $row = Lang::find()
            ->select('content')
            ->where([
                'table_code' => $model::getTableCode(),
                'target_id' => $model->id,
                'name' => $field,
                'target' => $target,
                'status' => BaseModel::STATUS_ACTIVE,
            ])
            ->orderBy(['updated_at' => SORT_DESC, 'id' => SORT_DESC])
            ->asArray()
            ->one();

        return trim(strip_tags((string)($row['content'] ?? '')));
    }

    private function requiresTranslation(string $source)
    {
        return trim($source) !== '';
    }

    private function isDirtyTranslation(string $content, string $target)
    {
        if ($content === '') {
            return false;
        }
        if ($target !== 'zh-CN' && preg_match('/[\x{4e00}-\x{9fff}]/u', $content)) {
            return true;
        }
        return false;
    }

    private function targetList()
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)$this->targets))));
    }

    private function ids(string $value)
    {
        return array_values(array_filter(array_map('intval', explode(',', $value))));
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
