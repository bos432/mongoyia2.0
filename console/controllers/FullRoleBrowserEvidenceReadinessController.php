<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class FullRoleBrowserEvidenceReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_FULL_ROLE_BROWSER_EVIDENCE_READINESS_V1';
    public const EVIDENCE_VERSION = 'MONGOYIA_FULL_ROLE_BROWSER_EVIDENCE_V1';

    public $evidencePath = '';
    public $templatePath = '';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $generateTemplate = false;
    public $accepted = false;
    public $strict = false;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $pending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'evidencePath',
            'templatePath',
            'handoverDir',
            'outputPath',
            'generateTemplate',
            'accepted',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia full-role browser evidence readiness\n");

        $this->checkSourceCoverage();
        if ($this->generateTemplate) {
            $path = $this->writeTemplate();
            $this->addCheck('Browser evidence template', 'PASS', $path, 'Template generated for right-side browser/manual five-role validation.');
        }
        $this->checkEvidence();

        $result = $this->result();
        $path = $this->writeReport($result);

        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->pending} pending.\n");

        if ($this->failures > 0 || ($this->strict && ($this->warnings > 0 || $this->pending > 0))) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSourceCoverage(): void
    {
        $this->section('Source coverage');
        $this->requireFileContains('docs/mongoyia-optimization-remediation-plan-20260702.md', [
            self::VERSION,
            'full-role-browser-evidence-readiness/run',
            '五类角色',
        ]);
        $this->requireFileContains('docs/mongoyia-deployment-guide-20260702.md', [
            self::VERSION,
            'full-role-browser-evidence-readiness/run',
            '右侧浏览器',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaRequirementsClosureAcceptanceController.php', [
            self::VERSION,
            'Full-role browser evidence readiness',
            'full-role-browser-evidence-readiness/run',
        ]);
    }

    private function checkEvidence(): void
    {
        $this->section('Evidence document');
        if ($this->evidencePath === '') {
            $status = $this->accepted ? 'FAIL' : 'PENDING';
            $this->addCheck('Browser evidence path', $status, 'empty', 'Pass --evidencePath after the right-side browser/manual role-flow document is filled.');
            return;
        }

        $path = $this->resolvePath($this->evidencePath);
        if (!is_file($path)) {
            $this->addCheck('Browser evidence file', 'FAIL', $this->evidencePath, 'Evidence path does not exist.');
            return;
        }
        if (!is_readable($path)) {
            $this->addCheck('Browser evidence file', 'FAIL', $this->evidencePath, 'Evidence file is not readable.');
            return;
        }

        $content = (string)file_get_contents($path);
        $this->requireContentContains($content, [
            self::EVIDENCE_VERSION,
            '验证时间',
            '验证环境',
            '测试数据摘要',
            '平台管理员',
            '商家',
            '买家',
            '客服',
            '分销员',
            '页面能正常打开',
            '登录',
            '表单提交',
            '数据保存',
            '列表展示',
            '详情查看',
            '刷新',
            '前端报错',
            '接口报错',
            'GO/NO-GO',
            'NO-GO',
            '真实支付',
            '退款',
            '提现',
            '物流商',
        ], $this->evidencePath);

        $this->checkUnfinishedChecklist($content);
        $this->checkSecretLeakage($content);

        if ($this->accepted) {
            if ($this->failures === 0 && $this->pending === 0) {
                $this->addCheck('Browser evidence accepted flag', 'PASS', $this->evidencePath, 'Evidence document is complete enough to be referenced by Phase 10-15 browser evidence flags.');
            }
        } else {
            $this->addCheck('Browser evidence accepted flag', 'PENDING', 'accepted=0', 'Review the browser evidence, then rerun with --accepted=1 before passing it into aggregate acceptance.');
        }
    }

    private function requireContentContains(string $content, array $needles, string $path): void
    {
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->addCheck('Evidence marker ' . $needle, 'FAIL', $path, "Required evidence marker is missing: {$needle}.");
                return;
            }
        }
        $this->addCheck('Evidence required markers', 'PASS', $path, 'Required role-flow, interaction, refresh, error, and safety markers are present.');
    }

    private function checkUnfinishedChecklist(string $content): void
    {
        if (preg_match('/^\s*-\s*\[\s\]/m', $content)) {
            $this->addCheck('Evidence unfinished checklist', 'PENDING', '- [ ]', 'Unchecked checklist items remain in the evidence document.');
            return;
        }
        $this->addCheck('Evidence unfinished checklist', 'PASS', 'no unchecked items', 'No unchecked checklist items were found.');
    }

    private function checkSecretLeakage(string $content): void
    {
        $patterns = [
            '/client[_-]?secret\s*[:=]\s*\S+/i',
            '/smtp[_-]?password\s*[:=]\s*\S+/i',
            '/api[_-]?key\s*[:=]\s*[A-Za-z0-9_\-]{16,}/i',
            '/authorization\s*:\s*(basic|bearer)\s+\S+/i',
            '/-----BEGIN\s+(RSA\s+)?PRIVATE\s+KEY-----/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $this->addCheck('Evidence secret leakage guard', 'FAIL', 'sensitive marker', 'The evidence document appears to contain provider secret material or raw authorization content. Remove/redact it before acceptance.');
                return;
            }
        }
        $this->addCheck('Evidence secret leakage guard', 'PASS', 'redacted', 'No provider secret, private key, or raw authorization marker was found.');
    }

    private function writeTemplate(): string
    {
        $path = $this->templatePath !== '' ? $this->resolvePath($this->templatePath) : $this->defaultTemplatePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Full-Role Browser Evidence',
            '',
            '- Evidence version: ' . self::EVIDENCE_VERSION,
            '- 验证时间: 待填写',
            '- 验证环境: 右侧浏览器 / https://demo2026.mongoyia.com / 桌面 + 390x844 + 414x896 + 768x1024',
            '- 验证人员: 待填写',
            '- 测试数据摘要: 测试账号、测试订单号、客服会话、工单/投诉、分销素材记录等，保留测试数据。',
            '- 是否达到可上线运营标准: 否。外部资料和生产签核未完成前 GO/NO-GO 必须保持 NO-GO。',
            '',
            '## Safety Boundary',
            '',
            '- [ ] 未触发真实支付成功。',
            '- [ ] 未执行退款、赔付、提现、资金结算或佣金审核。',
            '- [ ] 未调用真实物流商下单或真实物流费用扣款。',
            '- [ ] 未把生产 GO/NO-GO 从 NO-GO 改为 GO。',
            '- [ ] 未在文档中记录 OAuth、SMTP、支付、短信、翻译、物流或告警服务商密钥。',
            '',
            '## 平台管理员',
            '',
            '- [ ] 页面能正常打开: `/backend/`、运营配置、支付统计、通知日志、客服、商品、物流、评论、分销。',
            '- [ ] 登录/注销或入口流程可用。',
            '- [ ] 表单提交、数据保存、列表展示、详情查看正常。',
            '- [ ] 刷新页面后关键数据状态合理。',
            '- [ ] GO/NO-GO 显示 NO-GO。',
            '- [ ] 前端报错/接口报错: 无阻塞性错误。',
            '',
            '## 商家',
            '',
            '- [ ] 使用 `zhishichanquan / 123456` 登录。',
            '- [ ] 页面能正常打开: 商品、订单、发货、物流费用、优惠券、统计、客服隔离。',
            '- [ ] 表单提交、数据保存、列表展示、详情查看正常。',
            '- [ ] 刷新页面后关键数据状态合理。',
            '- [ ] 前端报错/接口报错: 无阻塞性错误。',
            '',
            '## 买家',
            '',
            '- [ ] 注册/登录或入口流程可用。',
            '- [ ] 页面能正常打开: 首页、分类、搜索、商品详情、购物车、结算、订单、收藏、评论、客服咨询。',
            '- [ ] 加入购物车、提交测试订单、查看订单、收藏、评论、客服消息流程可走通。',
            '- [ ] 在线支付停在支付页或模拟/COD，不触发真实支付。',
            '- [ ] 刷新页面后购物车、订单、聊天消息等关键数据状态合理。',
            '- [ ] 前端报错/接口报错: 无阻塞性错误。',
            '',
            '## 客服',
            '',
            '- [ ] 页面能正常打开: 客服工作台、会话、订单/商品上下文、工单、投诉、满意度。',
            '- [ ] 接收买家消息、回复消息、创建协助单/投诉记录。',
            '- [ ] 表单提交、数据保存、列表展示、详情查看正常。',
            '- [ ] 刷新页面后聊天、工单、投诉状态合理。',
            '- [ ] 前端报错/接口报错: 无阻塞性错误。',
            '',
            '## 分销员',
            '',
            '- [ ] 页面能正常打开: 教程/FAQ、推广素材、推广链接、业绩、提现申请入口。',
            '- [ ] 查看教程、获取素材、复制/下载素材、查看业绩、进入提现申请入口。',
            '- [ ] 表单提交、数据保存、列表展示、详情查看正常。',
            '- [ ] 刷新页面后关键数据状态合理。',
            '- [ ] 前端报错/接口报错: 无阻塞性错误。',
            '',
            '## 移动端视觉',
            '',
            '- [ ] 390x844 核心页面无明显横向溢出或遮挡。',
            '- [ ] 414x896 核心页面无明显横向溢出或遮挡。',
            '- [ ] 768x1024 核心页面无明显横向溢出或遮挡。',
            '',
            '## 发现的问题',
            '',
            '- 待填写。',
            '',
            '## Accepted Evidence Command',
            '',
            '```bash',
            '/www/server/php/83/bin/php yii full-role-browser-evidence-readiness/run \\',
            '  --evidencePath=runtime/handover/full-role-browser-evidence.md \\',
            '  --accepted=1 \\',
            '  --strict=1 \\',
            '  --interactive=0',
            '```',
            '',
            'After this command passes and reviewer approval is recorded, the same evidence path can be passed to the Phase 10-15 aggregate browser evidence options.',
            '',
        ];

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Full-Role Browser Evidence Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Evidence version: ' . self::EVIDENCE_VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Evidence path: ' . ($this->evidencePath === '' ? '(not supplied)' : $this->evidencePath),
            '- Accepted flag: ' . ($this->accepted ? 'yes' : 'no'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Scope: 五类角色 browser evidence validation for platform admin, seller, buyer, customer-service, distributor, mobile viewport, data persistence, and safety-boundary evidence document validation.',
            '- Safety: this command validates Markdown evidence and does not log in, create orders, submit payment, approve refunds/reviews/withdrawals, call providers, mutate funds/stock, or switch production GO.',
            '',
            '## Checks',
            '',
            '| Status | Area | Evidence | Notes |',
            '|---|---|---|---|',
        ];

        foreach ($this->checks as $check) {
            $lines[] = '| ' . $this->mdCell($check['status']) . ' | '
                . $this->mdCell($check['area']) . ' | `'
                . $this->mdCell($check['evidence']) . '` | '
                . $this->mdCell($check['notes']) . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## BaoTa Template Command',
            '',
            '```bash',
            'cd /www/wwwroot/demo2026.mongoyia.com',
            '/www/server/php/83/bin/php yii full-role-browser-evidence-readiness/run \\',
            '  --generateTemplate=1 \\',
            '  --templatePath=runtime/handover/full-role-browser-evidence.md \\',
            '  --interactive=0',
            '```',
            '',
            '## BaoTa Strict Evidence Command',
            '',
            '```bash',
            '/www/server/php/83/bin/php yii full-role-browser-evidence-readiness/run \\',
            '  --evidencePath=runtime/handover/full-role-browser-evidence.md \\',
            '  --accepted=1 \\',
            '  --strict=1 \\',
            '  --interactive=0',
            '```',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function requireFileContains(string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck('Source marker ' . $path, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->addCheck('Source marker ' . $path, 'FAIL', $path, "Missing marker {$needle}.");
                return;
            }
        }
        $this->addCheck('Source marker ' . $path, 'PASS', $path, 'Required browser evidence readiness markers are present.');
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function addCheck(string $area, string $status, string $evidence, string $notes): void
    {
        $status = strtoupper($status);
        if ($status === 'FAIL') {
            $this->failures++;
        } elseif ($status === 'PENDING') {
            $this->pending++;
        } elseif ($status !== 'PASS') {
            $this->warnings++;
            $status = 'WARN';
        }

        $this->checks[] = [
            'area' => $area,
            'status' => $status,
            'evidence' => $evidence,
            'notes' => $notes,
        ];
        $this->stdout(str_pad($status, 8) . "{$area}\n");
    }

    private function result(): string
    {
        if ($this->failures > 0) {
            return 'FAIL';
        }
        if ($this->warnings > 0 || $this->pending > 0) {
            return 'WARN';
        }
        return 'PASS';
    }

    private function defaultTemplatePath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'full-role-browser-evidence.md';
    }

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'full-role-browser-evidence-readiness-' . date('Ymd-His') . '.md';
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || strpos($path, '/') === 0) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function mdCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }
}
