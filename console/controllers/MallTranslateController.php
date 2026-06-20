<?php

namespace console\controllers;

use common\helpers\GoogleTranslate;
use common\models\base\Lang;
use common\models\mall\Attribute;
use common\models\mall\AttributeItem;
use common\models\mall\Brand;
use common\models\mall\Category;
use common\models\mall\Param;
use common\models\mall\Product;
use common\models\mall\Tag;
use common\models\Store;
use Yii;
use yii\console\ExitCode;
use yii\db\ActiveRecord;

class MallTranslateController extends BaseController
{
    public $storeId = 5;
    public $allStores = false;
    public $targets = 'en,mn';
    public $limit = 0;
    public $dryRun = false;
    public $force = false;
    public $googleType = 'gtx';
    public $models = '';
    public $ids = '';
    public $fields = '';
    public $connectTimeout = 5;
    public $timeout = 8;
    public $proxy = '';
    public $reportPath = '';
    public $preview = false;
    public $failOnBadPreview = false;

    private $reportRows = [];
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'storeId',
            'allStores',
            'targets',
            'limit',
            'dryRun',
            'force',
            'googleType',
            'models',
            'ids',
            'fields',
            'connectTimeout',
            'timeout',
            'proxy',
            'reportPath',
            'preview',
            'failOnBadPreview',
        ]);
    }

    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            's' => 'storeId',
            't' => 'targets',
            'l' => 'limit',
            'm' => 'models',
            'i' => 'ids',
        ]);
    }

    public function actionFill()
    {
        GoogleTranslate::setTimeouts($this->connectTimeout, $this->timeout);
        GoogleTranslate::setProxy($this->resolveProxy());
        $stores = $this->getStores();
        if (!$stores) {
            $this->stderr("No store found.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($stores as $store) {
            $source = $store->lang_source ?: 'zh-CN';
            $targets = array_filter(array_map('trim', explode(',', $this->targets)));
            $models = $this->getModelMap();

            foreach ($models as $class) {
                $tableCode = $class::getTableCode();
                $fields = $this->getTranslatableFields($class);
                if (!$fields) {
                    continue;
                }

                $query = $class::find()->where(['store_id' => $store->id]);
                $ids = $this->getIds();
                if ($ids) {
                    $query->andWhere(['id' => $ids]);
                }

                foreach ($query->each(100) as $model) {
                    foreach ($fields as $field) {
                        $sourceText = trim((string)$model->$field);
                        if (!$this->shouldTranslateSource($sourceText)) {
                            $skipped++;
                            continue;
                        }

                        foreach ($targets as $target) {
                            if ($target === $source) {
                                continue;
                            }

                            $lang = Lang::find()->where([
                                'store_id' => $store->id,
                                'table_code' => $tableCode,
                                'target_id' => $model->id,
                                'target' => $target,
                                'name' => $field,
                            ])->one();

                            if ($lang && !$this->force && !$this->shouldReplaceTranslation((string)$lang->content, $target)) {
                                $skipped++;
                                $this->report('skip', $store->id, $class, $model->id, $field, $target, $sourceText, (string)$lang->content, 'existing translation kept');
                                continue;
                            }

                            $translated = ($this->dryRun && !$this->preview)
                                ? '[dry-run] ' . $sourceText
                                : GoogleTranslate::translate($source, $target, $sourceText, $this->googleType);
                            $translated = trim($translated);
                            if ($translated === '') {
                                $failed++;
                                $error = GoogleTranslate::getLastDiagnostic();
                                $suffix = $error ? " error={$error}" : '';
                                $this->stderr("Empty translation: store={$store->id} {$class} #{$model->id} {$field} {$source}->{$target}{$suffix}\n");
                                $this->report('fail', $store->id, $class, $model->id, $field, $target, $sourceText, '', $error ?: 'empty translation');
                                if ($this->isLimitReached($created, $updated, $failed)) {
                                    $this->clearStoreCache($store->id);
                                    $this->stdout("Done. created={$created}, updated={$updated}, skipped={$skipped}, warning={$this->warnings}, failed={$failed}\n");
                                    $this->writeReport($created, $updated, $skipped, $failed);
                                    return ExitCode::UNSPECIFIED_ERROR;
                                }
                                continue;
                            }

                            $qualityNote = $this->translationQualityNote($sourceText, $translated, $target);
                            if ($qualityNote !== '') {
                                $this->warnings++;
                                $message = "Translation quality warning: store={$store->id} {$class} #{$model->id} {$field} {$source}->{$target} {$qualityNote}";
                                if ($this->dryRun && $this->failOnBadPreview) {
                                    $failed++;
                                    $this->stderr($message . "\n");
                                    $this->report('fail', $store->id, $class, $model->id, $field, $target, $sourceText, $translated, $qualityNote);
                                    if ($this->isLimitReached($created, $updated, $failed)) {
                                        $this->clearStoreCache($store->id);
                                        $this->stdout("Done. created={$created}, updated={$updated}, skipped={$skipped}, warning={$this->warnings}, failed={$failed}\n");
                                        $this->writeReport($created, $updated, $skipped, $failed);
                                        return ExitCode::UNSPECIFIED_ERROR;
                                    }
                                    continue;
                                }
                                $this->stdout("WARN {$message}\n");
                            }

                            $isNew = !$lang;
                            if (!$lang) {
                                $lang = new Lang();
                                $lang->store_id = $store->id;
                                $lang->table_code = $tableCode;
                                $lang->target_id = $model->id;
                                $lang->name = $field;
                                $lang->source = $source;
                                $lang->target = $target;
                                $lang->type = 1;
                                $lang->sort = 50;
                                $lang->status = 1;
                                $lang->created_by = Yii::$app->params['defaultUserId'] ?? 1;
                            }

                            $lang->content = $translated;
                            $lang->updated_by = Yii::$app->params['defaultUserId'] ?? 1;

                            $this->stdout(($this->dryRun ? '[dry-run] ' : '') . ($isNew ? 'create' : 'update') . " store={$store->id} {$class} #{$model->id} {$field} {$target}: {$translated}\n");

                            if ($this->dryRun) {
                                $isNew ? $created++ : $updated++;
                                $this->report($isNew ? 'dry-create' : 'dry-update', $store->id, $class, $model->id, $field, $target, $sourceText, $translated, $qualityNote);
                            } elseif ($lang->save()) {
                                $isNew ? $created++ : $updated++;
                                $this->report($isNew ? 'create' : 'update', $store->id, $class, $model->id, $field, $target, $sourceText, $translated, '');
                            } else {
                                $failed++;
                                $error = json_encode($lang->errors, JSON_UNESCAPED_UNICODE);
                                $this->stderr($error . "\n");
                                $this->report('fail', $store->id, $class, $model->id, $field, $target, $sourceText, $translated, $error);
                            }

                            if ($this->isLimitReached($created, $updated, $failed)) {
                                $this->clearStoreCache($store->id);
                                $this->stdout("Done. created={$created}, updated={$updated}, skipped={$skipped}, warning={$this->warnings}, failed={$failed}\n");
                                $this->writeReport($created, $updated, $skipped, $failed);
                                return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
                            }
                        }
                    }
                }
            }

            $this->clearStoreCache($store->id);
        }

        $this->stdout("Done. created={$created}, updated={$updated}, skipped={$skipped}, warning={$this->warnings}, failed={$failed}\n");
        $this->writeReport($created, $updated, $skipped, $failed);
        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function resolveProxy()
    {
        $explicit = trim((string)$this->proxy);
        if ($explicit !== '') {
            return $explicit;
        }

        $envProxy = trim((string)env('GOOGLE_TRANSLATE_PROXY', ''));
        if ($envProxy !== '') {
            return $envProxy;
        }

        return $this->resolveBackendProxySetting();
    }

    private function resolveBackendProxySetting()
    {
        if (!Yii::$app->has('settingSystem')) {
            return '';
        }

        $storeIds = array_values(array_unique(array_filter([
            (int)$this->storeId,
            (int)(Yii::$app->params['defaultStoreId'] ?? 0),
            1,
        ])));

        foreach ($storeIds as $storeId) {
            try {
                $value = trim((string)Yii::$app->settingSystem->getValue('google_translate_proxy', $storeId));
                if ($value !== '') {
                    return $value;
                }
            } catch (\Throwable $e) {
            }
        }

        return '';
    }

    private function isLimitReached($created, $updated, $failed)
    {
        return $this->limit > 0 && ($created + $updated + $failed) >= $this->limit;
    }

    private function getStores()
    {
        if ($this->allStores) {
            return Store::find()->where(['status' => Store::STATUS_ACTIVE])->all();
        }

        $store = Store::findOne((int)$this->storeId);
        return $store ? [$store] : [];
    }

    private function getModelMap()
    {
        $map = [
            'product' => Product::class,
            'category' => Category::class,
            'attribute' => Attribute::class,
            'attribute-item' => AttributeItem::class,
            'brand' => Brand::class,
            'param' => Param::class,
            'tag' => Tag::class,
        ];

        $models = array_filter(array_map('trim', explode(',', strtolower($this->models))));
        if (!$models) {
            return array_values($map);
        }

        $selected = [];
        foreach ($models as $model) {
            if (isset($map[$model])) {
                $selected[] = $map[$model];
            }
        }

        return $selected;
    }

    private function getTranslatableFields($class)
    {
        $include = array_filter(array_map('trim', explode(',', $this->fields)));
        $fields = [];
        foreach ($class::getLangFieldType() as $field => $type) {
            if ($type === 'Ueditor') {
                continue;
            }
            if ($include && !in_array($field, $include, true)) {
                continue;
            }
            $fields[] = $field;
        }
        return $fields;
    }

    private function getIds()
    {
        return array_filter(array_map('intval', explode(',', $this->ids)));
    }

    private function shouldTranslateSource($text)
    {
        if ($text === '' || preg_match('/<[^>]+>/', $text)) {
            return false;
        }
        return true;
    }

    private function shouldReplaceTranslation($text, $target)
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }
        if ($target !== 'zh-CN' && preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            return true;
        }
        return false;
    }

    private function clearStoreCache($storeId)
    {
        if ($this->dryRun) {
            return;
        }

        Yii::$app->cacheSystem->refreshStoreLang($storeId);
    }

    private function translationQualityNote($source, $translated, $target)
    {
        if (!$this->preview && $this->dryRun) {
            return '';
        }

        $source = trim((string)$source);
        $translated = trim((string)$translated);
        if ($translated === '') {
            return 'empty translation';
        }
        if ($source !== '' && $source === $translated) {
            return 'same as source';
        }
        if ($target !== 'zh-CN' && preg_match('/[\x{4e00}-\x{9fff}]/u', $translated)) {
            return 'contains Chinese residue';
        }

        return '';
    }

    private function report($action, $storeId, $class, $id, $field, $target, $source, $translated, $note)
    {
        $this->reportRows[] = [
            'action' => $action,
            'store_id' => (int)$storeId,
            'model' => $this->modelLabel($class),
            'id' => (int)$id,
            'field' => $field,
            'target' => $target,
            'source' => $this->shorten($source),
            'translated' => $this->shorten($translated),
            'note' => $this->shorten($note),
        ];
    }

    private function writeReport($created, $updated, $skipped, $failed)
    {
        $path = $this->reportPath ?: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'translation' . DIRECTORY_SEPARATOR . 'mall-translate-fill-' . date('Ymd-His') . '.md';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mall Translate Fill Report',
            '',
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Mode: ' . ($this->dryRun ? 'dry-run' : 'apply'),
            '- Preview translation service: ' . ($this->preview ? 'yes' : 'no'),
            '- Fail on bad preview: ' . ($this->failOnBadPreview ? 'yes' : 'no'),
            '- Store scope: ' . ($this->allStores ? 'all active stores' : 'store ' . $this->storeId),
            '- Targets: ' . $this->targets,
            '- Models: ' . ($this->models ?: 'all'),
            '- IDs: ' . ($this->ids ?: 'all'),
            '- Fields: ' . ($this->fields ?: 'all translatable non-ueditor fields'),
            '- Summary: created=' . $created . ', updated=' . $updated . ', skipped=' . $skipped . ', warning=' . $this->warnings . ', failed=' . $failed,
            '',
            '| Action | Store | Model | ID | Field | Target | Source | Translated | Note |',
            '|---|---:|---|---:|---|---|---|---|---|',
        ];

        foreach ($this->reportRows as $row) {
            $lines[] = '| ' . implode(' | ', [
                $this->cell($row['action']),
                $row['store_id'],
                $this->cell($row['model']),
                $row['id'],
                $this->cell($row['field']),
                $this->cell($row['target']),
                $this->cell($row['source']),
                $this->cell($row['translated']),
                $this->cell($row['note']),
            ]) . ' |';
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
        $this->stdout("Report: {$path}\n");
    }

    private function modelLabel($class)
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    private function shorten($text)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
        return mb_substr($text, 0, 120, 'UTF-8');
    }

    private function cell($text)
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], (string)$text);
    }
}
