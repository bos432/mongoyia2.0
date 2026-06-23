<?php

namespace common\services\mall;

use Yii;

class LanguageReviewService
{
    public const VERSION = 'MONGOYIA_LANGUAGE_REVIEW_V1';

    public const DOMAIN_UI = 'ui';
    public const DOMAIN_MAIL = 'mail';
    public const DOMAIN_NOTIFICATION = 'notification';
    public const DOMAIN_PAYMENT_ERROR = 'payment_error';

    private $languages = ['en', 'mn'];

    public function supportedLanguages(): array
    {
        return $this->languages;
    }

    public function supportedDomains(): array
    {
        return [
            self::DOMAIN_UI => 'UI 文案',
            self::DOMAIN_MAIL => '邮件模板/账号邮件',
            self::DOMAIN_NOTIFICATION => '站内/APP 通知文案',
            self::DOMAIN_PAYMENT_ERROR => '支付错误提示',
        ];
    }

    public function exportRows(array $options = []): array
    {
        $targets = $this->targetList($options['targets'] ?? implode(',', $this->languages));
        $domains = $this->domainList($options['domains'] ?? implode(',', array_keys($this->supportedDomains())));
        $limit = max(0, (int)($options['limit'] ?? 2000));
        $rows = [];
        $seen = [];

        foreach ($targets as $target) {
            foreach ($this->allCategories() as $category) {
                foreach ($this->sourceKeysForCategory($category) as $source) {
                    $domain = $this->domainForSource($source, $category);
                    if (!in_array($domain, $domains, true)) {
                        continue;
                    }
                    $key = $target . '|' . $category . '|' . $source;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $rows[] = $this->row($domain, $category, $source, $target, $this->translation($target, $category, $source));
                    if ($limit > 0 && count($rows) >= $limit) {
                        return $rows;
                    }
                }
            }
            foreach ($this->virtualReviewSources() as $item) {
                if (!in_array($item['domain'], $domains, true)) {
                    continue;
                }
                $key = $target . '|' . $item['category'] . '|' . $item['source'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = $this->row($item['domain'], $item['category'], $item['source'], $target, $this->translation($target, $item['category'], $item['source']), $item['notes']);
                if ($limit > 0 && count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    public function exportBundle(array $options = []): array
    {
        $rows = $this->exportRows($options);
        $dir = $this->resolvePath($options['handoverDir'] ?? 'runtime/handover');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $stamp = date('Ymd-His');
        $csvPath = $options['csvPath'] ?? ($dir . DIRECTORY_SEPARATOR . "mongoyia-language-review-export-{$stamp}.csv");
        $mdPath = $options['markdownPath'] ?? ($dir . DIRECTORY_SEPARATOR . "mongoyia-language-review-export-{$stamp}.md");
        $this->writeCsv($csvPath, $rows);
        $options['csvPath'] = $csvPath;
        $this->writeMarkdown($mdPath, $rows, $options);

        return [
            'version' => self::VERSION,
            'rows' => $rows,
            'row_count' => count($rows),
            'csv_path' => $csvPath,
            'markdown_path' => $mdPath,
            'targets' => $this->targetList($options['targets'] ?? implode(',', $this->languages)),
            'domains' => $this->domainList($options['domains'] ?? implode(',', array_keys($this->supportedDomains()))),
        ];
    }

    public function importCsv(string $inputPath, bool $apply = false): array
    {
        $path = $this->resolvePath($inputPath);
        if (!is_file($path)) {
            throw new \RuntimeException("Language review import file not found: {$inputPath}");
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new \RuntimeException("Unable to open language review import file: {$inputPath}");
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            throw new \RuntimeException('Language review import file has no header.');
        }
        $index = array_flip($header);
        foreach (['target_language', 'category', 'source', 'reviewed_translation', 'review_status'] as $field) {
            if (!isset($index[$field])) {
                fclose($handle);
                throw new \RuntimeException("Language review import file missing column: {$field}");
            }
        }

        $planned = [];
        $skipped = [];
        while (($data = fgetcsv($handle)) !== false) {
            $row = [];
            foreach ($index as $field => $position) {
                $row[$field] = (string)($data[$position] ?? '');
            }

            $target = strtolower(trim($row['target_language']));
            $category = trim($row['category']);
            $source = (string)$row['source'];
            $translation = (string)$row['reviewed_translation'];
            $reviewStatus = strtolower(trim((string)$row['review_status']));

            if (!in_array($target, $this->languages, true) || !$this->safeCategory($category) || trim($source) === '') {
                $skipped[] = ['target' => $target, 'category' => $category, 'source' => $source, 'reason' => 'invalid target/category/source'];
                continue;
            }
            if (!in_array($reviewStatus, ['approved', 'accepted', 'pass'], true) || trim($translation) === '') {
                $skipped[] = ['target' => $target, 'category' => $category, 'source' => $source, 'reason' => 'not approved or empty reviewed translation'];
                continue;
            }

            $planned[] = [
                'target_language' => $target,
                'category' => $category,
                'source' => $source,
                'reviewed_translation' => $translation,
            ];
        }
        fclose($handle);

        $written = [];
        if ($apply) {
            $grouped = [];
            foreach ($planned as $row) {
                $grouped[$row['target_language']][$row['category']][$row['source']] = $row['reviewed_translation'];
            }
            foreach ($grouped as $target => $categories) {
                foreach ($categories as $category => $messages) {
                    $file = $this->messageFile($target, $category);
                    $current = $this->loadMessages($file);
                    foreach ($messages as $source => $translation) {
                        $current[$source] = $translation;
                    }
                    $this->writeMessageFile($file, $current);
                    $written[] = $this->relativePath($file);
                }
            }
            $written = array_values(array_unique($written));
        }

        return [
            'version' => self::VERSION,
            'input_path' => $path,
            'apply' => $apply ? 1 : 0,
            'planned_count' => count($planned),
            'skipped_count' => count($skipped),
            'planned' => $planned,
            'skipped' => $skipped,
            'written_files' => $written,
            'safety' => 'Only approved rows for supported target languages and safe message categories are eligible for apply.',
        ];
    }

    public function writeImportReport(array $result, string $outputPath = ''): string
    {
        $path = $outputPath !== '' ? $this->resolvePath($outputPath) : $this->resolvePath('runtime/handover')
            . DIRECTORY_SEPARATOR . 'mongoyia-language-review-import-' . date('Ymd-His') . '.md';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Language Review Import',
            '',
            '- Version: ' . self::VERSION,
            '- Input: ' . $result['input_path'],
            '- Apply: ' . (int)$result['apply'],
            '- Planned rows: ' . (int)$result['planned_count'],
            '- Skipped rows: ' . (int)$result['skipped_count'],
            '- Written files: ' . implode(', ', $result['written_files'] ?: ['none']),
            '- Safety: approved rows only; no provider calls, no database writes, no secret handling.',
            '',
            '## Planned Rows',
            '',
            '| Target | Category | Source | Reviewed Translation |',
            '|---|---|---|---|',
        ];
        foreach (array_slice($result['planned'], 0, 80) as $row) {
            $lines[] = '| ' . $this->mdCell($row['target_language']) . ' | '
                . $this->mdCell($row['category']) . ' | '
                . $this->mdCell($row['source']) . ' | '
                . $this->mdCell($row['reviewed_translation']) . ' |';
        }
        $lines[] = '';
        $lines[] = '## Skipped Rows';
        $lines[] = '';
        $lines[] = '| Target | Category | Source | Reason |';
        $lines[] = '|---|---|---|---|';
        foreach (array_slice($result['skipped'], 0, 80) as $row) {
            $lines[] = '| ' . $this->mdCell($row['target']) . ' | '
                . $this->mdCell($row['category']) . ' | '
                . $this->mdCell($row['source']) . ' | '
                . $this->mdCell($row['reason']) . ' |';
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function row(string $domain, string $category, string $source, string $target, string $current, string $notes = ''): array
    {
        return [
            'domain' => $domain,
            'category' => $category,
            'source' => $source,
            'target_language' => $target,
            'current_translation' => $current,
            'reviewed_translation' => '',
            'review_status' => 'pending',
            'reviewer' => '',
            'notes' => $notes,
        ];
    }

    private function allCategories(): array
    {
        $categories = [];
        $base = $this->messagesDir();
        foreach (glob($base . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $categories[basename($file, '.php')] = true;
        }
        return array_keys($categories);
    }

    private function sourceKeysForCategory(string $category): array
    {
        $keys = [];
        foreach (glob($this->messagesDir() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $category . '.php') ?: [] as $file) {
            foreach (array_keys($this->loadMessages($file)) as $source) {
                $keys[$source] = true;
            }
        }
        return array_keys($keys);
    }

    private function virtualReviewSources(): array
    {
        return [
            ['domain' => self::DOMAIN_NOTIFICATION, 'category' => 'app', 'source' => 'Order status notification', 'notes' => 'Phase 12 notification title'],
            ['domain' => self::DOMAIN_NOTIFICATION, 'category' => 'app', 'source' => 'Logistics status notification', 'notes' => 'Phase 12 notification title'],
            ['domain' => self::DOMAIN_NOTIFICATION, 'category' => 'app', 'source' => 'Payment result notification', 'notes' => 'Phase 12 notification title'],
            ['domain' => self::DOMAIN_NOTIFICATION, 'category' => 'app', 'source' => 'Customer service reply notification', 'notes' => 'Phase 12 notification title'],
            ['domain' => self::DOMAIN_NOTIFICATION, 'category' => 'app', 'source' => 'Complaint result notification', 'notes' => 'Phase 12 notification title'],
            ['domain' => self::DOMAIN_PAYMENT_ERROR, 'category' => 'app', 'source' => 'Payment failed', 'notes' => 'Payment error prompt'],
            ['domain' => self::DOMAIN_PAYMENT_ERROR, 'category' => 'app', 'source' => 'Payment callback verification failed', 'notes' => 'Payment callback error prompt'],
            ['domain' => self::DOMAIN_MAIL, 'category' => 'app', 'source' => 'Check your email for further instructions.', 'notes' => 'Password recovery email prompt'],
            ['domain' => self::DOMAIN_MAIL, 'category' => 'app', 'source' => 'Please fill out your email. A link to reset password will be sent there.', 'notes' => 'Password recovery form prompt'],
        ];
    }

    private function domainForSource(string $source, string $category): string
    {
        $text = strtolower($source . ' ' . $category);
        if (preg_match('/mail|email|smtp|password|reset|verification|verify|验证码|邮箱|邮件/u', $text)) {
            return self::DOMAIN_MAIL;
        }
        if (preg_match('/payment|paypal|qpay|lianlian|pay|callback|signature|hmac|支付|付款|回调|签名/u', $text)) {
            return self::DOMAIN_PAYMENT_ERROR;
        }
        if (preg_match('/notification|notify|message|order status|logistics|complaint|customer service|通知|消息|客服|投诉|物流/u', $text)) {
            return self::DOMAIN_NOTIFICATION;
        }
        return self::DOMAIN_UI;
    }

    private function translation(string $target, string $category, string $source): string
    {
        $file = $this->messageFile($target, $category);
        $messages = $this->loadMessages($file);
        return (string)($messages[$source] ?? '');
    }

    private function writeCsv(string $path, array $rows): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $handle = fopen($path, 'wb');
        fputcsv($handle, ['domain', 'category', 'source', 'target_language', 'current_translation', 'reviewed_translation', 'review_status', 'reviewer', 'notes']);
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['domain'],
                $row['category'],
                $row['source'],
                $row['target_language'],
                $row['current_translation'],
                $row['reviewed_translation'],
                $row['review_status'],
                $row['reviewer'],
                $row['notes'],
            ]);
        }
        fclose($handle);
    }

    private function writeMarkdown(string $path, array $rows, array $options): void
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = $row['domain'] . '/' . $row['target_language'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $lines = [
            '# Mongoyia Language Review Export',
            '',
            '- Version: ' . self::VERSION,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Targets: ' . implode(', ', $this->targetList($options['targets'] ?? implode(',', $this->languages))),
            '- Domains: ' . implode(', ', $this->domainList($options['domains'] ?? implode(',', array_keys($this->supportedDomains())))),
            '- Row count: ' . count($rows),
            '- CSV: ' . ($options['csvPath'] ?? ''),
            '- Safety: this export contains review text only; it does not contain provider secrets, API keys, private keys, callback payloads, or user passwords.',
            '',
            '## Reviewer Instructions',
            '',
            'Fill `reviewed_translation`, set `review_status` to `approved`, and keep `target_language`, `category`, and `source` unchanged before importing.',
            '',
            '## Counts',
            '',
            '| Domain/Target | Rows |',
            '|---|---|',
        ];
        foreach ($counts as $key => $count) {
            $lines[] = '| ' . $this->mdCell($key) . ' | ' . (int)$count . ' |';
        }
        $lines[] = '';
        $lines[] = '## Sample Rows';
        $lines[] = '';
        $lines[] = '| Domain | Category | Target | Source | Current Translation |';
        $lines[] = '|---|---|---|---|---|';
        foreach (array_slice($rows, 0, 80) as $row) {
            $lines[] = '| ' . $this->mdCell($row['domain']) . ' | '
                . $this->mdCell($row['category']) . ' | '
                . $this->mdCell($row['target_language']) . ' | '
                . $this->mdCell($row['source']) . ' | '
                . $this->mdCell($row['current_translation']) . ' |';
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    private function loadMessages(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $messages = require $file;
        return is_array($messages) ? $messages : [];
    }

    private function writeMessageFile(string $file, array $messages): void
    {
        ksort($messages);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $lines = ["<?php", "", "return ["];
        foreach ($messages as $source => $translation) {
            $lines[] = '    ' . var_export((string)$source, true) . ' => ' . var_export((string)$translation, true) . ',';
        }
        $lines[] = '];';
        file_put_contents($file, implode("\n", $lines) . "\n");
    }

    private function targetList(string $value): array
    {
        $items = array_values(array_unique(array_filter(array_map(static function ($item) {
            return strtolower(trim($item));
        }, explode(',', $value)))));
        return array_values(array_intersect($items, $this->languages)) ?: $this->languages;
    }

    private function domainList(string $value): array
    {
        $allowed = array_keys($this->supportedDomains());
        $items = array_values(array_unique(array_filter(array_map(static function ($item) {
            return strtolower(trim($item));
        }, explode(',', $value)))));
        return array_values(array_intersect($items, $allowed)) ?: $allowed;
    }

    private function safeCategory(string $category): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9_-]+$/', $category);
    }

    private function messageFile(string $target, string $category): string
    {
        return $this->messagesDir() . DIRECTORY_SEPARATOR . $target . DIRECTORY_SEPARATOR . $category . '.php';
    }

    private function messagesDir(): string
    {
        return Yii::getAlias('@common/messages');
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || strpos($path, '/') === 0) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function relativePath(string $path): string
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        return strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
    }

    private function mdCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }
}
