<?php

namespace console\controllers;

use common\models\BaseModel;
use common\models\base\Lang;
use common\models\mall\Category;
use common\models\mall\Product;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaTranslationReviewController extends Controller
{
    public $output = "@runtime/translation/mn-review.csv";
    public $input = "@runtime/translation/mn-review.csv";
    public $report = "@runtime/translation/mn-review-import-report.csv";
    public $target = "mn";
    public $limit = 0;
    public $includeUeditor = true;
    public $dryRun = true;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            "output", "input", "report", "target", "limit", "includeUeditor", "dryRun",
        ]);
    }

    public function actionRun()
    {
        $path = Yii::getAlias($this->output);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = fopen($path, "wb");
        if (!$fp) {
            $this->stderr("Cannot open {$path}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fp, [
            "table_code", "target_id", "field", "source_text",
            "mn_translation", "review_translation", "is_machine", "char_src", "char_mn",
            "needs_review", "review_status", "review_note",
        ]);

        $total = 0;
        $needsReview = 0;
        $total += $this->exportRows($fp, Product::class, ["name", "brief"], $needsReview);
        if ($this->includeUeditor) {
            $total += $this->exportRows($fp, Product::class, ["content"], $needsReview);
        }
        $total += $this->exportRows($fp, Category::class, ["name", "brief"], $needsReview);
        fclose($fp);

        $this->stdout("\nExported {$total} mn translation rows to {$path}\n");
        $this->stdout("{$needsReview} rows flagged for human review.\n");
        return ExitCode::OK;
    }

    private function exportRows($fp, string $class, array $fields, int &$needsReview): int
    {
        $short = basename(str_replace("\\", "/", $class));
        $this->stdout("[{$short}]");
        $query = $class::find()
            ->where(["status" => BaseModel::STATUS_ACTIVE])
            ->orderBy(["id" => SORT_ASC]);
        if ((int)$this->limit > 0) {
            $query->limit((int)$this->limit);
        }
        $count = 0;
        foreach ($query->each(50) as $model) {
            foreach ($fields as $field) {
                $source = $this->normalize($model->$field);
                if ($source === "") continue;
                $row = $this->findRow($model, $field, $this->target);
                $mn = $this->normalize($row["content"] ?? "");
                $machine = $this->isMachine($source, $mn, $row);
                $review = ($machine || $mn === "") ? "YES" : "NO";
                if ($review === "YES") $needsReview++;
                fputcsv($fp, [
                    $class::getTableCode(), $model->id, $field,
                    mb_substr($source, 0, 200, "UTF-8"),
                    mb_substr($mn, 0, 200, "UTF-8"),
                    "",
                    $machine ? "YES" : "NO",
                    mb_strlen($source, "UTF-8"),
                    mb_strlen($mn, "UTF-8"),
                    $review,
                    "",
                    "",
                ]);
                $count++;
            }
        }
        $this->stdout(" {$count} rows\n");
        return $count;
    }

    public function actionCheck()
    {
        return $this->processImport(true);
    }

    public function actionImport()
    {
        return $this->processImport((bool)$this->dryRun);
    }

    private function processImport(bool $dryRun): int
    {
        $input = Yii::getAlias($this->input);
        if (!is_file($input)) {
            $this->stderr("Input CSV not found: {$input}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $report = Yii::getAlias($this->report);
        $dir = dirname($report);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $in = fopen($input, "rb");
        if (!$in) {
            $this->stderr("Cannot open {$input}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $out = fopen($report, "wb");
        if (!$out) {
            fclose($in);
            $this->stderr("Cannot open {$report}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, [
            "row", "table_code", "target_id", "field", "status", "message",
            "old_length", "new_length",
        ]);

        $header = fgetcsv($in);
        $header = $this->normalizeHeader($header ?: []);
        $required = ["table_code", "target_id", "field"];
        foreach ($required as $column) {
            if (!array_key_exists($column, $header)) {
                fclose($in);
                fclose($out);
                $this->stderr("Input CSV missing required column: {$column}\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $stats = [
            "checked" => 0,
            "valid" => 0,
            "changed" => 0,
            "created" => 0,
            "unchanged" => 0,
            "skipped" => 0,
            "failed" => 0,
        ];

        $transaction = $dryRun ? null : Yii::$app->db->beginTransaction();
        try {
            $rowNumber = 1;
            while (($raw = fgetcsv($in)) !== false) {
                $rowNumber++;
                if ($this->isEmptyCsvRow($raw)) {
                    continue;
                }
                $stats["checked"]++;
                $row = $this->rowFromHeader($header, $raw);
                $result = $this->applyReviewRow($row, $dryRun);
                $stats[$result["bucket"]]++;
                if ($result["status"] !== "failed") {
                    $stats["valid"]++;
                }
                fputcsv($out, [
                    $rowNumber,
                    $row["table_code"] ?? "",
                    $row["target_id"] ?? "",
                    $row["field"] ?? "",
                    $result["status"],
                    $result["message"],
                    $result["old_length"],
                    $result["new_length"],
                ]);
            }

            if ($transaction) {
                $transaction->commit();
            }
        } catch (\Throwable $e) {
            if ($transaction) {
                $transaction->rollBack();
            }
            fclose($in);
            fclose($out);
            $this->stderr("Import failed: {$e->getMessage()}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        fclose($in);
        fclose($out);

        $mode = $dryRun ? "DRY-RUN" : "APPLY";
        $this->stdout("Mongoyia translation review import {$mode}\n");
        $this->stdout("Input: {$input}\n");
        $this->stdout("Report: {$report}\n");
        $this->stdout("Checked {$stats["checked"]}, valid {$stats["valid"]}, changed {$stats["changed"]}, created {$stats["created"]}, unchanged {$stats["unchanged"]}, skipped {$stats["skipped"]}, failed {$stats["failed"]}.\n");

        return $stats["failed"] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function applyReviewRow(array $row, bool $dryRun): array
    {
        $tableCode = (int)($row["table_code"] ?? 0);
        $targetId = (int)($row["target_id"] ?? 0);
        $field = trim((string)($row["field"] ?? ""));
        $content = $this->reviewContent($row);

        if ($content === "") {
            return $this->importResult("skipped", "skipped", "empty review_translation", 0, 0);
        }

        $class = $this->classByTableCode($tableCode);
        if ($class === null) {
            return $this->importResult("failed", "failed", "unsupported table_code", 0, mb_strlen($content, "UTF-8"));
        }
        if (!array_key_exists($field, $class::$mapLangFieldType)) {
            return $this->importResult("failed", "failed", "unsupported field", 0, mb_strlen($content, "UTF-8"));
        }

        $model = $class::find()
            ->where(["id" => $targetId, "status" => BaseModel::STATUS_ACTIVE])
            ->one();
        if (!$model) {
            return $this->importResult("failed", "failed", "active source row not found", 0, mb_strlen($content, "UTF-8"));
        }

        $lang = Lang::find()
            ->where([
                "store_id" => $model->store_id,
                "table_code" => $tableCode,
                "target_id" => $targetId,
                "name" => $field,
                "target" => $this->target,
                "status" => BaseModel::STATUS_ACTIVE,
            ])
            ->orderBy(["updated_at" => SORT_DESC, "id" => SORT_DESC])
            ->one();

        $old = $lang ? (string)$lang->content : "";
        if ($old === $content) {
            return $this->importResult("unchanged", "unchanged", "content unchanged", mb_strlen($old, "UTF-8"), mb_strlen($content, "UTF-8"));
        }

        if ($dryRun) {
            $bucket = $lang ? "changed" : "created";
            $message = $lang ? "would update translation" : "would create translation";
            return $this->importResult($bucket, $bucket, $message, mb_strlen($old, "UTF-8"), mb_strlen($content, "UTF-8"));
        }

        if (!$lang) {
            $lang = new Lang();
            $lang->store_id = $model->store_id;
            $lang->name = $field;
            $lang->source = "zh-CN";
            $lang->target = $this->target;
            $lang->table_code = $tableCode;
            $lang->target_id = $targetId;
            $lang->type = BaseModel::TYPE_DEFAULT;
            $lang->sort = BaseModel::SORT_DEFAULT;
            $lang->status = BaseModel::STATUS_ACTIVE;
        }
        $lang->content = $content;

        if (!$lang->save()) {
            return $this->importResult("failed", "failed", json_encode($lang->errors, JSON_UNESCAPED_UNICODE), mb_strlen($old, "UTF-8"), mb_strlen($content, "UTF-8"));
        }

        $bucket = $old === "" ? "created" : "changed";
        $message = $old === "" ? "created translation" : "updated translation";
        return $this->importResult($bucket, $bucket, $message, mb_strlen($old, "UTF-8"), mb_strlen($content, "UTF-8"));
    }

    private function reviewContent(array $row): string
    {
        foreach (["review_translation", "approved_translation", "corrected_translation"] as $column) {
            if (isset($row[$column]) && trim((string)$row[$column]) !== "") {
                return trim((string)$row[$column]);
            }
        }

        return trim((string)($row["mn_translation"] ?? ""));
    }

    private function normalizeHeader(array $header): array
    {
        $map = [];
        foreach ($header as $index => $column) {
            $column = preg_replace('/^\xEF\xBB\xBF/', '', (string)$column);
            $map[trim($column)] = $index;
        }
        return $map;
    }

    private function rowFromHeader(array $header, array $raw): array
    {
        $row = [];
        foreach ($header as $column => $index) {
            $row[$column] = $raw[$index] ?? "";
        }
        return $row;
    }

    private function isEmptyCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string)$value) !== "") {
                return false;
            }
        }
        return true;
    }

    private function classByTableCode(int $tableCode): ?string
    {
        $map = [
            Product::getTableCode() => Product::class,
            Category::getTableCode() => Category::class,
        ];
        return $map[$tableCode] ?? null;
    }

    private function importResult(string $bucket, string $status, string $message, int $oldLength, int $newLength): array
    {
        return [
            "bucket" => $bucket,
            "status" => $status,
            "message" => $message,
            "old_length" => $oldLength,
            "new_length" => $newLength,
        ];
    }

    private function isMachine(string $source, string $content, $row): bool
    {
        if ($content === "") return true;
        if (mb_strtolower($source, "UTF-8") === mb_strtolower($content, "UTF-8")) return true;
        if (!empty($row["updated_at"]) && !empty($row["created_at"])) {
            $c = is_numeric($row["created_at"]) ? (int)$row["created_at"] : strtotime($row["created_at"]);
            $u = is_numeric($row["updated_at"]) ? (int)$row["updated_at"] : strtotime($row["updated_at"]);
            if ($u > $c + 60) return false;
        }
        if (preg_match("/[\x{4e00}-\x{9fff}]/u", $content)) return true;
        return true;
    }

    private function findRow($model, string $field, string $target)
    {
        return Lang::find()
            ->select(["id", "content", "created_at", "updated_at"])
            ->where([
                "table_code" => $model::getTableCode(),
                "target_id" => $model->id,
                "name" => $field,
                "target" => $target,
                "status" => BaseModel::STATUS_ACTIVE,
            ])
            ->orderBy(["updated_at" => SORT_DESC, "id" => SORT_DESC])
            ->asArray()->one();
    }

    private function normalize($value): string
    {
        return preg_replace("/\s+/u", " ", trim(strip_tags((string)$value)));
    }
}
