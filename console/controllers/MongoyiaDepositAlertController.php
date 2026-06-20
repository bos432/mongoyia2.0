<?php

namespace console\controllers;

use common\models\Store;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDepositAlertController extends Controller
{
    public $warningThreshold = 100;
    public $criticalThreshold = 20;
    public $storeId = 0;
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['warningThreshold', 'criticalThreshold', 'storeId', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia merchant deposit alert readiness\n");
        $this->checkSchema();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $this->printReport($this->scanStores());
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function runFixture(): void
    {
        $this->section('Fixture');
        $storeIds = $this->firstSellerStoreIds(3);
        if (count($storeIds) < 3) {
            $this->fail('Need at least three seller stores for deposit alert fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $this->setFund($storeIds[0], 150.00);
            $this->setFund($storeIds[1], 50.00);
            $this->setFund($storeIds[2], 10.00);

            $report = $this->scanStores($storeIds);
            $this->printReport($report);
            $this->assertSameInt(3, (int)$report['scanned'], 'Deposit alert fixture scans three stores.');
            $this->assertSameInt(1, (int)$report['ok'], 'Deposit alert fixture counts one OK store.');
            $this->assertSameInt(1, (int)$report['warning'], 'Deposit alert fixture counts one warning store.');
            $this->assertSameInt(1, (int)$report['critical'], 'Deposit alert fixture counts one critical store.');
            $this->assertSameInt(2, count($report['drafts']), 'Deposit alert fixture builds two mail drafts.');
            $this->assertDraftLevel($report, 'warning');
            $this->assertDraftLevel($report, 'critical');

            $transaction->rollBack();
            $this->ok('Deposit alert fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Deposit alert fixture failed: ' . $e->getMessage());
        }
    }

    private function scanStores(array $ids = []): array
    {
        $warning = round((float)$this->warningThreshold, 2);
        $critical = round((float)$this->criticalThreshold, 2);
        $rows = $this->storeRows($ids);
        $result = [
            'warningThreshold' => $warning,
            'criticalThreshold' => $critical,
            'scanned' => 0,
            'ok' => 0,
            'warning' => 0,
            'critical' => 0,
            'missingEmail' => 0,
            'drafts' => [],
            'rows' => [],
        ];

        foreach ($rows as $row) {
            $result['scanned']++;
            $fund = round((float)$row['fund'], 2);
            $level = 'ok';
            if ($fund <= $critical) {
                $level = 'critical';
            } elseif ($fund <= $warning) {
                $level = 'warning';
            }
            $result[$level]++;
            $email = trim((string)$row['email']);
            if ($email === '') {
                $result['missingEmail']++;
            }

            $summary = [
                'store_id' => (int)$row['id'],
                'store_name' => (string)$row['name'],
                'fund' => $fund,
                'level' => $level,
                'email' => $email,
            ];
            $result['rows'][] = $summary;
            if ($level !== 'ok') {
                $result['drafts'][] = array_merge($summary, [
                    'subject' => '[Mongoyia] Merchant deposit balance ' . strtoupper($level),
                    'body' => $this->mailBody($summary, $warning, $critical),
                    'send_ready' => $email !== '',
                ]);
            }
        }

        return $result;
    }

    private function storeRows(array $ids): array
    {
        $query = (new \yii\db\Query())
            ->select(['s.id', 's.name', 's.fund', 's.user_id', 'u.email'])
            ->from('{{%store}} s')
            ->leftJoin('{{%user}} u', 'u.id = s.user_id')
            ->where(['>', 's.status', Store::STATUS_DELETED])
            ->andWhere(['not in', 's.id', [5]])
            ->orderBy(['s.id' => SORT_ASC]);
        if ($ids) {
            $query->andWhere(['s.id' => $ids]);
        } elseif ((int)$this->storeId > 0) {
            $query->andWhere(['s.id' => (int)$this->storeId]);
        }

        return $query->all(Yii::$app->db);
    }

    private function mailBody(array $row, float $warning, float $critical): string
    {
        return "Store #{$row['store_id']} {$row['store_name']} deposit balance is " . number_format((float)$row['fund'], 2) .
            ". Warning threshold: " . number_format($warning, 2) .
            "; critical threshold: " . number_format($critical, 2) .
            ". Please recharge before logistics fee deduction is blocked.";
    }

    private function printReport(array $report): void
    {
        $this->stdout('Thresholds: warning=' . number_format((float)$report['warningThreshold'], 2) . ', critical=' . number_format((float)$report['criticalThreshold'], 2) . "\n");
        $this->stdout("Stores: {$report['scanned']}; ok={$report['ok']}; warning={$report['warning']}; critical={$report['critical']}; missing_email={$report['missingEmail']}\n");
        foreach ($report['drafts'] as $draft) {
            $this->stdout("DRAFT store={$draft['store_id']} level={$draft['level']} email=" . ($draft['email'] !== '' ? $draft['email'] : 'MISSING') . " subject={$draft['subject']}\n");
        }
    }

    private function setFund(int $storeId, float $fund): void
    {
        Yii::$app->db->createCommand()->update('{{%store}}', [
            'fund' => $fund,
            'updated_at' => time(),
        ], ['id' => $storeId])->execute();
    }

    private function firstSellerStoreIds(int $limit): array
    {
        return array_map('intval', (new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['>', 'status', Store::STATUS_DELETED])
            ->andWhere(['not in', 'id', [5]])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->column(Yii::$app->db));
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
        $this->requireColumns('{{%store}}', ['id', 'name', 'user_id', 'fund', 'status']);
        $this->requireColumns('{{%user}}', ['id', 'email', 'status']);
        if ((float)$this->criticalThreshold > (float)$this->warningThreshold) {
            $this->fail('criticalThreshold must be less than or equal to warningThreshold.');
        }
    }

    private function requireColumns(string $table, array $columns): void
    {
        $schema = Yii::$app->db->schema->getTableSchema($table);
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

    private function assertDraftLevel(array $report, string $level): void
    {
        foreach ($report['drafts'] as $draft) {
            if ((string)$draft['level'] === $level && strpos((string)$draft['body'], 'Please recharge') !== false) {
                $this->ok("Deposit alert fixture has {$level} mail draft.");
                return;
            }
        }
        $this->fail("Deposit alert fixture missing {$level} mail draft.");
    }

    private function assertSameInt(int $expected, int $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->stdout("OK   {$message}\n");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
