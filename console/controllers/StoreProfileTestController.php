<?php

namespace console\controllers;

use common\models\Store;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class StoreProfileTestController extends Controller
{
    public $cleanup = true;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'cleanup',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia store profile Phase 2 test\n");

        $this->checkSchema();
        $this->checkFilesAndPermission();
        if ($this->failures === 0) {
            $this->runFixtureFlow();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%store}}', [
            'name',
            'name_en',
            'name_mn',
            'brief',
            'brief_en',
            'brief_mn',
            'main_products',
            'logo',
            'contact_name',
            'contact_phone',
            'business_hours',
        ]);
    }

    private function checkFilesAndPermission()
    {
        $this->section('Backend entrance');
        foreach ([
            'backend/modules/mall/controllers/StoreProfileController.php',
            'backend/modules/mall/views/store-profile/edit.php',
            'console/migrations/m260618_153500_mongoyia_store_profile_permission.php',
        ] as $file) {
            $this->requireFile($file);
        }

        $permissionExists = (new \yii\db\Query())
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/store-profile/edit', 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$permissionExists) {
            $this->fail('Missing active backend permission /mall/store-profile/edit. Run migration m260618_153500_mongoyia_store_profile_permission.');
        } else {
            $this->ok('Permission exists: /mall/store-profile/edit');
        }

        $class = 'backend\modules\mall\controllers\StoreProfileController';
        foreach (['actionEdit'] as $method) {
            if (!class_exists($class) || !method_exists($class, $method)) {
                $this->fail("Missing route method {$class}::{$method}.");
                continue;
            }
            $this->ok("Route method exists: {$class}::{$method}");
        }
    }

    private function runFixtureFlow()
    {
        $this->section('Fixture flow');
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $store = $this->fixtureStore();
            $oldProtected = [
                'user_id' => $store->user_id,
                'host_name' => $store->host_name,
                'code' => $store->code,
                'route' => $store->route,
                'status' => $store->status,
            ];

            $suffix = date('YmdHis');
            $store->name = 'PROFILEFIX Store ' . $suffix;
            $store->name_en = 'PROFILEFIX Store EN';
            $store->name_mn = 'PROFILEFIX Store MN';
            $store->brief = 'PROFILEFIX brief';
            $store->brief_en = 'PROFILEFIX brief EN';
            $store->brief_mn = 'PROFILEFIX brief MN';
            $store->main_products = 'PROFILEFIX goods';
            $store->logo = '/attachment/store/profilefix.png';
            $store->contact_name = 'PROFILEFIX Contact';
            $store->contact_phone = '000000';
            $store->business_hours = '09:00-18:00';
            if (!$store->save()) {
                throw new \RuntimeException('Store profile save failed: ' . json_encode($store->errors));
            }

            $reloaded = Store::findOne($store->id);
            foreach (['name_en', 'name_mn', 'brief_en', 'brief_mn', 'main_products', 'logo', 'contact_name', 'contact_phone', 'business_hours'] as $field) {
                if (trim((string)$reloaded->{$field}) === '') {
                    $this->fail("Store profile field {$field} was not persisted.");
                    throw new \RuntimeException("Missing persisted field {$field}.");
                }
            }
            $this->ok('Store multilingual/profile fields are writable.');

            foreach ($oldProtected as $field => $value) {
                if ((string)$reloaded->{$field} !== (string)$value) {
                    $this->fail("Protected store field {$field} changed unexpectedly.");
                    throw new \RuntimeException("Protected field changed: {$field}.");
                }
            }
            $this->ok('Protected store ownership/routing fields remain unchanged in profile flow.');

            if ($this->cleanup) {
                $transaction->rollBack();
                $this->ok('Fixture data rolled back by default cleanup.');
            } else {
                $transaction->commit();
                $this->warn('Fixture data was committed because --cleanup=0 was used.');
            }
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Fixture flow failed: ' . $e->getMessage());
        }
    }

    private function fixtureStore(): Store
    {
        $store = Store::find()
            ->where(['>', 'status', Store::STATUS_DELETED])
            ->andWhere(['route' => 'mall'])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        if (!$store) {
            throw new \RuntimeException('No active mall store is available for store profile fixture.');
        }

        return $store;
    }

    private function requireColumns(string $table, array $columns)
    {
        $schema = Yii::$app->db->schema->getTableSchema($table, true);
        if (!$schema) {
            $this->fail("Missing table {$table}.");
            return;
        }

        foreach ($columns as $column) {
            if (!isset($schema->columns[$column])) {
                $this->fail("Table {$table} missing column {$column}.");
                return;
            }
        }
        $this->ok("Table {$table} has required columns.");
    }

    private function requireFile(string $path)
    {
        if (is_file(Yii::getAlias('@app') . '/../' . $path)) {
            $this->ok("File exists: {$path}");
            return;
        }

        $this->fail("Missing file {$path}.");
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
