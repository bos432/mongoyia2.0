<?php

namespace console\components;

use Yii;

class DatabaseAcceptanceGuard
{
    public const VERSION = 'MONGOYIA_PHASE10_15_DB_ACCESS_PREFLIGHT_V1';

    public static function preflight(): array
    {
        try {
            $db = Yii::$app->db;
            $db->open();
            $db->createCommand('SELECT 1')->queryScalar();
            $db->schema->getTableSchema('{{%migration}}', true);

            return [
                'ok' => true,
                'message' => 'Console database connection and schema lookup are available.',
                'diagnostic' => self::safeConnectionSummary(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => self::friendlyMessage($e),
                'diagnostic' => self::safeConnectionSummary(),
            ];
        }
    }

    private static function friendlyMessage(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (stripos($message, 'Access denied') !== false) {
            return 'Console database access was denied. Fix the BaoTa `.env` DB_DSN/DB_USERNAME/DB_PASSWORD or grant this MySQL user access to the configured database, then rerun migrations and acceptance.';
        }

        return 'Console database preflight failed: ' . $message;
    }

    private static function safeConnectionSummary(): string
    {
        try {
            $db = Yii::$app->db;
            $dsn = (string)$db->dsn;
            $username = (string)$db->username;
        } catch (\Throwable $e) {
            return 'database component unavailable';
        }

        $parts = [
            'username=' . ($username !== '' ? $username : '(empty)'),
        ];
        foreach (['host', 'port', 'dbname'] as $key) {
            $value = self::dsnPart($dsn, $key);
            if ($value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        return implode(', ', $parts);
    }

    private static function dsnPart(string $dsn, string $key): string
    {
        if (preg_match('/(?:^|;)' . preg_quote($key, '/') . '=([^;]+)/', $dsn, $matches)) {
            return $matches[1];
        }

        return '';
    }
}
