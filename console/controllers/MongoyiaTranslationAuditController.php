<?php

namespace console\controllers;

use common\models\BaseModel;
use common\models\base\Lang;
use common\models\mall\Category;
use common\models\mall\Product;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaTranslationAuditController extends Controller
{
    public $targets = 'en,mn';
    public $limit = 200;
    public $sample = 20;
    public $strict = false;

    private $warnings = 0;
    private $failures = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'targets',
            'limit',
            'sample',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia translation dirty-data audit\n");
        $targets = $this->targetList();
        if (!$targets) {
            $this->fail('No target languages configured.');
            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ([Product::class, Category::class] as $class) {
            $this->auditClass($class, $targets);
        }
        $this->auditDuplicateActiveRows($targets);

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function auditClass($class, array $targets)
    {
        $this->section($class === Product::class ? 'Product translations' : 'Category translations');
        $fields = $this->translatableFields($class);
        if (!$fields) {
            $this->warn($class . ' has no translatable fields.');
            return;
        }

        $stats = [];
        foreach ($targets as $target) {
            $stats[$target] = [
                'required' => 0,
                'missing' => 0,
                'chinese_residue' => 0,
                'same_as_source' => 0,
                'samples' => [],
            ];
        }

        $query = $class::find()->where(['status' => BaseModel::STATUS_ACTIVE])->orderBy(['id' => SORT_ASC]);
        if ((int)$this->limit > 0) {
            $query->limit((int)$this->limit);
        }

        foreach ($query->each(100) as $model) {
            foreach ($fields as $field) {
                $source = $this->normalizeText($model->$field);
                if ($source === '') {
                    continue;
                }
                foreach ($targets as $target) {
                    $stats[$target]['required']++;
                    $translation = $this->findTranslation($model, $field, $target);
                    $content = $this->normalizeText($translation['content'] ?? '');
                    if ($content === '') {
                        $this->recordIssue($stats[$target], 'missing', $model, $field, $target, $source, '');
                        continue;
                    }
                    if ($target !== 'zh-CN' && preg_match('/[\x{4e00}-\x{9fff}]/u', $content)) {
                        $this->recordIssue($stats[$target], 'chinese_residue', $model, $field, $target, $source, $content);
                    }
                    if ($this->sameText($source, $content)) {
                        $this->recordIssue($stats[$target], 'same_as_source', $model, $field, $target, $source, $content);
                    }
                }
            }
        }

        foreach ($stats as $target => $stat) {
            $issues = $stat['missing'] + $stat['chinese_residue'] + $stat['same_as_source'];
            $message = "{$class} {$target}: required={$stat['required']} missing={$stat['missing']} chinese_residue={$stat['chinese_residue']} same_as_source={$stat['same_as_source']}";
            $issues > 0 ? $this->warn($message) : $this->ok($message);
            foreach ($stat['samples'] as $sample) {
                $this->stdout("  sample {$sample}\n");
            }
        }
    }

    private function auditDuplicateActiveRows(array $targets)
    {
        $this->section('Duplicate active translation rows');
        $rows = (new \yii\db\Query())
            ->select([
                'store_id',
                'table_code',
                'target_id',
                'name',
                'target',
                'cnt' => 'COUNT(*)',
            ])
            ->from('{{%base_lang}}')
            ->where(['target' => $targets, 'status' => BaseModel::STATUS_ACTIVE])
            ->groupBy(['store_id', 'table_code', 'target_id', 'name', 'target'])
            ->having(['>', 'COUNT(*)', 1])
            ->orderBy(['cnt' => SORT_DESC])
            ->limit((int)$this->sample)
            ->all(Yii::$app->db);

        if (!$rows) {
            $this->ok('No duplicate active translation rows found in sample scope.');
            return;
        }

        $this->warn('Duplicate active translation rows found: ' . count($rows));
        foreach ($rows as $row) {
            $this->stdout(sprintf(
                "  sample store=%s table=%s id=%s field=%s target=%s count=%s\n",
                $row['store_id'],
                $row['table_code'],
                $row['target_id'],
                $row['name'],
                $row['target'],
                $row['cnt']
            ));
        }
    }

    private function recordIssue(array &$stat, string $key, $model, string $field, string $target, string $source, string $content)
    {
        $stat[$key]++;
        if (count($stat['samples']) >= (int)$this->sample) {
            return;
        }

        $stat['samples'][] = sprintf(
            '%s id=%s field=%s target=%s issue=%s source="%s" content="%s"',
            $model::getTableCode(),
            $model->id,
            $field,
            $target,
            $key,
            mb_substr($source, 0, 40, 'UTF-8'),
            mb_substr($content, 0, 40, 'UTF-8')
        );
    }

    private function translatableFields($class)
    {
        $fields = [];
        foreach ($class::getLangFieldType() as $field => $type) {
            if ($type === 'Ueditor') {
                continue;
            }
            $fields[] = $field;
        }
        return $fields;
    }

    private function findTranslation($model, string $field, string $target)
    {
        return Lang::find()
            ->select(['id', 'content'])
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
    }

    private function normalizeText($value)
    {
        $text = trim(strip_tags((string)$value));
        return preg_replace('/\s+/u', ' ', $text);
    }

    private function sameText(string $source, string $content)
    {
        return mb_strtolower($source, 'UTF-8') === mb_strtolower($content, 'UTF-8');
    }

    private function targetList()
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)$this->targets))));
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
