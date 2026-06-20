<?php

namespace console\controllers;

use common\models\BaseModel;
use common\models\Store;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaHostCleanupController extends Controller
{
    public $apply = false;
    public $platformStoreId = 5;

    private $changes = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'apply',
            'platformStoreId',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia store host cleanup\n");
        $this->stdout($this->apply ? "Mode: apply\n" : "Mode: dry-run\n");

        $stores = Store::find()
            ->where(['>=', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        foreach ($stores as $store) {
            $this->planStoreChange($store);
        }

        if (!$this->changes) {
            $this->stdout("No store host cleanup needed.\n");
            return ExitCode::OK;
        }

        foreach ($this->changes as $change) {
            $this->stdout(
                "STORE {$change['id']} {$change['name']}\n" .
                "  old: {$change['old']}\n" .
                "  new: {$change['new']}\n" .
                "  remove: " . implode(', ', $change['removed']) . "\n"
            );
        }

        if (!$this->apply) {
            $this->stdout("\nDry-run only. Re-run with --apply=1 to update fb_store.host_name and regenerate frontend/runtime/host.php.\n");
            return ExitCode::OK;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            foreach ($this->changes as $change) {
                Yii::$app->db->createCommand()->update('{{%store}}', [
                    'host_name' => $change['new'],
                    'updated_at' => time(),
                ], ['id' => $change['id']])->execute();
            }
            $this->generateHostFile();
            $transaction->commit();
            $this->stdout("\nCleanup applied. Updated " . count($this->changes) . " store row(s).\n");
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->stderr("Cleanup failed: " . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    private function planStoreChange(Store $store)
    {
        $hosts = $this->hostNamesFromValue($store->host_name);
        if (!$hosts) {
            return;
        }

        $legacyHosts = array_flip($this->legacyHostDomains());
        $platformHosts = array_flip($this->platformHosts());
        $kept = [];
        $removed = [];

        foreach ($hosts as $host) {
            if (isset($legacyHosts[$host])) {
                $removed[] = $host;
                continue;
            }
            if ((int)$store->id !== (int)$this->platformStoreId && isset($platformHosts[$host])) {
                $removed[] = $host;
                continue;
            }
            $kept[] = $host;
        }

        $newHostName = implode('|', array_values(array_unique($kept)));
        $oldHostName = implode('|', $hosts);
        if ($removed && $newHostName !== $oldHostName) {
            $this->changes[] = [
                'id' => (int)$store->id,
                'name' => (string)$store->name,
                'old' => $oldHostName,
                'new' => $newHostName,
                'removed' => array_values(array_unique($removed)),
            ];
        }
    }

    private function generateHostFile()
    {
        $hostMap = [];
        foreach (Store::find()->all() as $store) {
            foreach ($this->hostNamesFromValue($store->host_name) as $host) {
                $this->addHostRoute($hostMap, $host, $store->route);
            }
        }

        foreach ($this->platformHostRoutes() as $host => $route) {
            $this->addHostRoute($hostMap, $host, $route, true);
        }

        foreach ($this->hostRouteMap() as $host => $route) {
            $this->addHostRoute($hostMap, $host, $route, true);
        }

        ksort($hostMap);
        $content = "<?php\nreturn [\n";
        foreach ($hostMap as $host => $route) {
            $content .= "    '" . addslashes($host) . "' => '" . addslashes($route) . "',\n";
        }
        $content .= "];\n";

        if (!file_put_contents(Yii::getAlias('@frontend/runtime/host.php'), $content)) {
            throw new \RuntimeException('Write host file failed: ' . Yii::getAlias('@frontend/runtime/host.php'));
        }
    }

    private function addHostRoute(array &$hostMap, string $host, string $route, bool $override = false)
    {
        $host = $this->normalizeHost($host);
        $route = trim($route);
        if ($host === '' || $route === '' || in_array($host, $this->legacyHostDomains(), true)) {
            return;
        }

        if (!in_array($route, ['site', 'pay', 'cms', 'bbs', 'mall', 'wechat', 'mini', 'chat'], true)) {
            $route = Yii::$app->params['defaultRoute'] ?? 'mall';
        }

        if ($override || !isset($hostMap[$host])) {
            $hostMap[$host] = $route;
        }
    }

    private function platformHostRoutes()
    {
        $route = env('STORE_PLATFORM_ROUTE', env('DEFAULT_ROUTE', Yii::$app->params['defaultRoute'] ?? 'mall'));
        $hosts = [];
        foreach ([env('STORE_PLATFORM_DOMAIN', Yii::$app->params['storePlatformDomain'] ?? ''), env('WEB_BASE_URL', '')] as $value) {
            $host = $this->normalizeHost($value);
            if ($host === '') {
                continue;
            }
            $hosts[$host] = $route;
            if (str_starts_with($host, 'www.')) {
                $hosts[substr($host, 4)] = $route;
            }
        }

        return $hosts;
    }

    private function platformHosts()
    {
        return array_keys($this->platformHostRoutes());
    }

    private function hostRouteMap()
    {
        $map = [];
        foreach (array_filter(array_map('trim', explode(',', env('HOST_ROUTE_MAP', '')))) as $pair) {
            if (!str_contains($pair, ':')) {
                continue;
            }
            [$host, $route] = array_map('trim', explode(':', $pair, 2));
            $host = $this->normalizeHost($host);
            if ($host !== '' && $route !== '') {
                $map[$host] = $route;
            }
        }

        return $map;
    }

    private function legacyHostDomains()
    {
        $domains = [];
        $value = env('LEGACY_HOST_DOMAINS', 'mn.zlck888.com,www.funpay.com,www.funcms.com,www.funbbs.com,test.zlck888.com,test.sc.hanxuys.com');
        foreach (array_filter(array_map('trim', explode(',', $value))) as $domain) {
            $host = $this->normalizeHost($domain);
            if ($host !== '') {
                $domains[] = $host;
            }
        }

        return array_values(array_unique($domains));
    }

    private function hostNamesFromValue($value)
    {
        $hosts = [];
        foreach (array_filter(array_map('trim', explode('|', (string)$value))) as $item) {
            $host = $this->normalizeHost($item);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private function normalizeHost($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!$host && !str_contains($value, '://')) {
            $host = parse_url('https://' . $value, PHP_URL_HOST);
        }

        return strtolower((string)$host);
    }
}
