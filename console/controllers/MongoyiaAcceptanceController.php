<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaAcceptanceController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $strict = false;
    public $profile = 'local';
    public $phpEnv = '.env';
    public $imEnv = '../../im后端/im后端/.env';
    public $platformStoreId = 5;
    public $productIds = '90,102';
    public $categoryId = '';
    public $translationAuditStrict = false;
    public $platformUsername = 'codex_platform_backend_test_5';
    public $platformPassword = 'CodexTest123';
    public $sellerUsername = 'zhishichanquan';
    public $sellerPassword = '123456';
    public $paymentUserId = 71;
    public $paymentProductIds = '90,102';
    public $paymentAmount = '1.00';
    public $imHealthScript = '../../im后端/im后端/scripts/im-healthcheck.py';
    public $imRegressionScript = '../../im后端/im后端/scripts/im-regression.py';
    public $imConcurrencyScript = '../../im后端/im后端/scripts/im-concurrency.py';
    public $imUrl = 'ws://127.0.0.1:8767';
    public $imMerchantUid = 37;
    public $imProductId = 102;
    public $imStoreId = 9;
    public $imConcurrencyUsers = 5;
    public $webClosureProductId = 79;
    public $pythonBin = 'python';
    public $reportPath = '';
    public $noReport = false;
    public $skipDeploy = false;
    public $skipPackage = false;
    public $skipSecurity = false;
    public $skipFixture = false;
    public $skipData = false;
    public $skipCatalog = false;
    public $skipTranslation = false;
    public $skipOrderIntegrity = false;
    public $skipPaymentAudit = false;
    public $skipMerchantOnboarding = false;
    public $skipProductAudit = false;
    public $skipMerchantStat = false;
    public $skipMerchantBackend = false;
    public $skipStoreProfile = false;
    public $skipDeposit = false;
    public $skipDepositAlert = false;
    public $skipLogisticsFeeDeduction = false;
    public $skipLogisticsFeeReconciliation = false;
    public $skipLogisticsFeeAdjustment = false;
    public $skipLogisticsFeeReview = false;
    public $skipSettlement = false;
    public $skipSettlementBackend = false;
    public $skipSettlementPayout = false;
    public $skipSettlementPayoutBackend = false;
    public $skipSettlementDraft = false;
    public $skipSettlementDraftBackend = false;
    public $skipSettlementDraftWorkflow = false;
    public $skipSettlementPayoutEvidence = false;
    public $skipSettlementClose = false;
    public $skipSettlementReport = false;
    public $skipSettlementReportBackend = false;
    public $skipSettlementExport = false;
    public $skipLogisticsStatusBatch = false;
    public $skipLogisticsPortReview = false;
    public $skipAutoReceive = false;
    public $skipPhase3ScheduledOps = false;
    public $skipDistribution = false;
    public $skipDistributionBackend = false;
    public $skipDistributionFrontend = false;
    public $skipDistributionWithdraw = false;
    public $skipDistributionProfile = false;
    public $skipDistributionInvite = false;
    public $skipDistributionRisk = false;
    public $skipDistributionAnalytics = false;
    public $skipDistributionAnalyticsExport = false;
    public $skipDistributionRewardWorkflow = false;
    public $skipCommissionIntegrity = false;
    public $skipIm = false;
    public $skipImMedia = false;
    public $skipImMediaTransportImplementationGate = false;
    public $skipImMediaTransportPolicyGate = false;
    public $skipImMediaUploadSkeletonGate = false;
    public $skipApi = false;
    public $skipFrontend = false;
    public $skipBackend = false;
    public $skipPayment = false;
    public $skipPaymentProvider = false;
    public $skipPaymentProviderRouteSkeletonGate = false;
    public $skipPaymentProviderWebhookDryRunGate = false;
    public $skipPaymentProviderWebhookVerificationDryRunGate = false;
    public $skipPaymentProviderWebhookAuditDryRun = false;
    public $skipPaymentProviderPaypalSandboxEvidenceGate = false;
    public $skipPaymentProviderPaypalLiveAuditWriteImplementationGate = false;
    public $skipPaymentProviderPaypalSandboxEvidenceSignoffGate = false;
    public $skipPaymentProviderPaypalSandboxEvidenceManifestValidator = false;
    public $skipPaymentProviderPaypalSandboxEvidenceRedactionChecklist = false;
    public $skipPaymentProviderPaypalSandboxEvidenceBundleReviewReadiness = false;
    public $skipPaymentProviderPaypalSandboxEvidenceBundleReviewSignoffGate = false;
    public $skipPaymentProviderPaypalSandboxEvidenceSignoffImportDryRun = false;
    public $skipPaymentProviderPaypalSandboxEvidenceReviewResultApplyGate = false;
    public $skipPaymentProviderPaypalExternalEvidenceCollectionGate = false;
    public $skipPaymentProviderPaypalExternalEvidenceManifestImportDryRun = false;
    public $skipPaymentProviderPaypalExternalEvidenceManifestReviewReadiness = false;
    public $skipPaymentProviderPaypalExternalEvidenceManifestReviewSignoffImportDryRun = false;
    public $skipPaymentProviderPaypalExternalEvidenceManifestReviewResultApplyGate = false;
    public $skipPaymentProviderPaypalLiveProviderImplementationEvidenceDryRun = false;
    public $skipPaymentProviderPaypalLiveProviderImplementationEvidenceSignoffGate = false;
    public $skipPaymentProviderPaypalLiveExecutionEvidenceReadinessGate = false;
    public $skipPaymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRun = false;
    public $skipPaymentProviderLiveVerificationEnablementGate = false;
    public $skipPaymentProviderPaypalFinalGoNoGoGate = false;
    public $skipCustomerService = false;
    public $skipCustomerServiceAdvanced = false;
    public $skipCustomerServiceTicketReadonly = false;
    public $skipCustomerServiceTicketCreate = false;
    public $skipCustomerServiceTicketNote = false;
    public $skipCustomerServiceTicketResult = false;
    public $skipCustomerServiceTicketAssign = false;
    public $skipCustomerServiceTicketWorkflow = false;
    public $skipCustomerServiceStatExport = false;
    public $skipCustomerServiceStatWidgetReadiness = false;
    public $skipCustomerServiceStatApplyGate = false;
    public $skipCustomerServiceStatApplyWorkflow = false;
    public $skipCustomerServiceStatApplyLogReview = false;
    public $skipCustomerServiceComplaintExport = false;
    public $skipCustomerServiceComplaintEvidenceGate = false;
    public $skipCustomerServiceComplaintEvidenceUploadPolicyGate = false;
    public $skipCustomerServiceComplaintEvidenceUploadImplementationGate = false;
    public $skipCustomerServiceComplaintEvidenceUploadCleanupReadiness = false;
    public $skipCustomerServiceComplaintEvidenceUploadEnablementGate = false;
    public $skipCustomerServiceComplaintEvidenceApplyWorkflow = false;
    public $skipCustomerServiceResolutionExport = false;
    public $skipCustomerServiceSlaReadiness = false;
    public $skipCustomerServiceSlaHandling = false;
    public $skipCustomerServiceResultSignoff = false;
    public $skipWebClosure = false;
    public $skipPwa = false;
    public $skipPwaOffline = false;
    public $skipPwaVisual = false;
    public $cleanupAfterRun = false;
    public $cleanupOlderThanHours = 0;
    public $cleanupIncludeChat = true;

    private $failed = [];
    private $startedAt;
    private $steps = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'strict',
            'profile',
            'phpEnv',
            'imEnv',
            'platformStoreId',
            'productIds',
            'categoryId',
            'translationAuditStrict',
            'platformUsername',
            'platformPassword',
            'sellerUsername',
            'sellerPassword',
            'paymentUserId',
            'paymentProductIds',
            'paymentAmount',
            'imHealthScript',
            'imRegressionScript',
            'imConcurrencyScript',
            'imUrl',
            'imMerchantUid',
            'imProductId',
            'imStoreId',
            'imConcurrencyUsers',
            'webClosureProductId',
            'pythonBin',
            'reportPath',
            'noReport',
            'skipDeploy',
            'skipPackage',
            'skipSecurity',
            'skipFixture',
            'skipData',
            'skipCatalog',
            'skipTranslation',
            'skipOrderIntegrity',
            'skipPaymentAudit',
            'skipMerchantOnboarding',
            'skipProductAudit',
            'skipMerchantStat',
            'skipMerchantBackend',
            'skipStoreProfile',
            'skipDeposit',
            'skipDepositAlert',
            'skipLogisticsFeeDeduction',
            'skipLogisticsFeeReconciliation',
            'skipLogisticsFeeAdjustment',
            'skipLogisticsFeeReview',
            'skipSettlement',
            'skipSettlementBackend',
            'skipSettlementPayout',
            'skipSettlementPayoutBackend',
            'skipSettlementDraft',
            'skipSettlementDraftBackend',
            'skipSettlementDraftWorkflow',
            'skipSettlementPayoutEvidence',
            'skipSettlementClose',
            'skipSettlementReport',
            'skipSettlementReportBackend',
            'skipSettlementExport',
            'skipLogisticsStatusBatch',
            'skipLogisticsPortReview',
            'skipAutoReceive',
            'skipPhase3ScheduledOps',
            'skipDistribution',
            'skipDistributionBackend',
            'skipDistributionFrontend',
            'skipDistributionWithdraw',
            'skipDistributionProfile',
            'skipDistributionInvite',
            'skipDistributionRisk',
            'skipDistributionAnalytics',
            'skipDistributionAnalyticsExport',
            'skipDistributionRewardWorkflow',
            'skipCommissionIntegrity',
            'skipIm',
            'skipImMedia',
            'skipImMediaTransportImplementationGate',
            'skipImMediaTransportPolicyGate',
            'skipImMediaUploadSkeletonGate',
            'skipApi',
            'skipFrontend',
            'skipBackend',
            'skipPayment',
            'skipPaymentProvider',
            'skipPaymentProviderRouteSkeletonGate',
            'skipPaymentProviderWebhookDryRunGate',
            'skipPaymentProviderWebhookVerificationDryRunGate',
            'skipPaymentProviderWebhookAuditDryRun',
            'skipPaymentProviderPaypalSandboxEvidenceGate',
            'skipPaymentProviderPaypalLiveAuditWriteImplementationGate',
            'skipPaymentProviderPaypalSandboxEvidenceSignoffGate',
            'skipPaymentProviderPaypalSandboxEvidenceManifestValidator',
            'skipPaymentProviderPaypalSandboxEvidenceRedactionChecklist',
            'skipPaymentProviderPaypalSandboxEvidenceBundleReviewReadiness',
            'skipPaymentProviderPaypalSandboxEvidenceBundleReviewSignoffGate',
            'skipPaymentProviderPaypalSandboxEvidenceSignoffImportDryRun',
            'skipPaymentProviderPaypalSandboxEvidenceReviewResultApplyGate',
            'skipPaymentProviderPaypalExternalEvidenceCollectionGate',
            'skipPaymentProviderPaypalExternalEvidenceManifestImportDryRun',
            'skipPaymentProviderPaypalExternalEvidenceManifestReviewReadiness',
            'skipPaymentProviderPaypalExternalEvidenceManifestReviewSignoffImportDryRun',
            'skipPaymentProviderPaypalExternalEvidenceManifestReviewResultApplyGate',
            'skipPaymentProviderPaypalLiveProviderImplementationEvidenceDryRun',
            'skipPaymentProviderPaypalLiveProviderImplementationEvidenceSignoffGate',
            'skipPaymentProviderPaypalLiveExecutionEvidenceReadinessGate',
            'skipPaymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRun',
            'skipPaymentProviderLiveVerificationEnablementGate',
            'skipPaymentProviderPaypalFinalGoNoGoGate',
            'skipCustomerService',
            'skipCustomerServiceAdvanced',
            'skipCustomerServiceTicketReadonly',
            'skipCustomerServiceTicketCreate',
            'skipCustomerServiceTicketNote',
            'skipCustomerServiceTicketResult',
            'skipCustomerServiceTicketAssign',
            'skipCustomerServiceTicketWorkflow',
            'skipCustomerServiceStatExport',
            'skipCustomerServiceStatWidgetReadiness',
            'skipCustomerServiceStatApplyGate',
            'skipCustomerServiceStatApplyWorkflow',
            'skipCustomerServiceStatApplyLogReview',
            'skipCustomerServiceComplaintExport',
            'skipCustomerServiceComplaintEvidenceGate',
            'skipCustomerServiceComplaintEvidenceUploadPolicyGate',
            'skipCustomerServiceComplaintEvidenceUploadImplementationGate',
            'skipCustomerServiceComplaintEvidenceUploadCleanupReadiness',
            'skipCustomerServiceComplaintEvidenceUploadEnablementGate',
            'skipCustomerServiceComplaintEvidenceApplyWorkflow',
            'skipCustomerServiceResolutionExport',
            'skipCustomerServiceSlaReadiness',
            'skipCustomerServiceSlaHandling',
            'skipCustomerServiceResultSignoff',
            'skipWebClosure',
            'skipPwa',
            'skipPwaOffline',
            'skipPwaVisual',
            'cleanupAfterRun',
            'cleanupOlderThanHours',
            'cleanupIncludeChat',
        ]);
    }

    public function actionRun()
    {
        $this->startedAt = time();
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->stdout("Mongoyia acceptance suite against {$this->baseUrl}\n");

        if (!$this->skipDeploy) {
            $this->runStep('deployment configuration', [
                'deploy-check/run',
                '--phpEnv=' . $this->phpEnv,
                '--imEnv=' . $this->imEnv,
                '--strict=' . ((int)$this->strict),
                '--profile=' . $this->profile,
                '--interactive=0',
            ]);
        }

        if (!$this->skipPackage) {
            $this->runStep('handover package check', [
                'mongoyia-package-check/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSecurity) {
            $this->runStep('security hardcode scan', [
                'mongoyia-security-scan/run',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipFixture) {
            $this->runStep('acceptance fixture', [
                'mongoyia-acceptance-fixture/run',
                '--apply=1',
                '--platformStoreId=' . $this->platformStoreId,
                '--platformUsername=' . $this->platformUsername,
                '--platformPassword=' . $this->platformPassword,
                '--paymentUserId=' . $this->paymentUserId,
                '--interactive=0',
            ]);
        }

        if (!$this->skipData) {
            $this->runStep('data readiness', [
                'mongoyia-data-readiness/run',
                '--platformStoreId=' . $this->platformStoreId,
                '--platformUsername=' . $this->platformUsername,
                '--sellerUsername=' . $this->sellerUsername,
                '--paymentUserId=' . $this->paymentUserId,
                '--productIds=' . $this->paymentProductIds,
                '--imMerchantUid=' . $this->imMerchantUid,
                '--imProductId=' . $this->imProductId,
                '--imStoreId=' . $this->imStoreId,
                '--interactive=0',
            ]);
        }

        if (!$this->skipCatalog) {
            $this->runStep('catalog readiness', [
                'mongoyia-catalog-readiness/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipTranslation) {
            $this->runStep('translation proxy config readiness', [
                'mongoyia-translation-proxy-config-test/run',
                '--fixture=1',
                '--interactive=0',
            ]);

            $this->runStep('translation dirty-data audit', [
                'mongoyia-translation-audit/run',
                '--strict=' . ((int)$this->translationAuditStrict),
                '--interactive=0',
            ]);

            $this->runStep('translation readiness', [
                'mongoyia-translation-readiness/run',
                '--strict=' . ((int)$this->strict),
                '--productIds=' . $this->productIds,
                '--interactive=0',
            ]);
        }

        if (!$this->skipOrderIntegrity) {
            $this->runStep('order integrity', [
                'mongoyia-order-integrity/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentAudit) {
            $this->runStep('payment audit', [
                'mongoyia-payment-audit/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipMerchantOnboarding) {
            $this->runStep('merchant onboarding Phase 2 closure', [
                'merchant-onboarding-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipProductAudit) {
            $this->runStep('product audit Phase 2 closure', [
                'product-audit-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipMerchantStat) {
            $this->runStep('merchant statistics Phase 2 closure', [
                'merchant-stat-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipMerchantBackend) {
            $this->runStep('merchant backend Phase 2 closure', [
                'merchant-backend-closure-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipStoreProfile) {
            $this->runStep('store profile Phase 2 closure', [
                'store-profile-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDeposit) {
            $this->runStep('merchant deposit Phase 3 readiness', [
                'mongoyia-deposit-readiness/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDepositAlert) {
            $this->runStep('merchant deposit alert Phase 3 closure', [
                'mongoyia-deposit-alert/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipLogisticsFeeDeduction) {
            $this->runStep('logistics fee deduction Phase 3 closure', [
                'mongoyia-logistics-fee-deduction-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipLogisticsFeeReconciliation) {
            $this->runStep('logistics fee reconciliation Phase 3 closure', [
                'mongoyia-logistics-fee-reconciliation/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipLogisticsFeeAdjustment) {
            $this->runStep('logistics fee adjustment Phase 3 closure', [
                'mongoyia-logistics-fee-adjustment/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipLogisticsFeeReview) {
            $this->runStep('logistics fee review backend Phase 3 closure', [
                'mongoyia-logistics-fee-review-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlement) {
            $this->runStep('settlement readiness Phase 3 closure', [
                'mongoyia-settlement-readiness/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlementBackend) {
            $this->runStep('settlement readiness backend Phase 3 closure', [
                'mongoyia-settlement-readiness-backend-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlementPayout) {
            $this->runStep('settlement payout readiness Phase 3 closure', [
                'mongoyia-settlement-payout-readiness/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlementPayoutBackend) {
            $this->runStep('settlement payout plan backend Phase 3 closure', [
                'mongoyia-settlement-payout-plan-backend-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlementDraft) {
            $this->runStep('settlement draft Phase 3 closure', [
                'mongoyia-settlement-draft-readiness/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlementDraftBackend) {
            $this->runStep('settlement draft backend Phase 3 closure', [
                'mongoyia-settlement-draft-backend-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlementDraftWorkflow) {
            $this->runStep('settlement draft workflow Phase 3 closure', [
                'mongoyia-settlement-draft-workflow/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlementPayoutEvidence) {
            $this->runStep('settlement payout evidence Phase 3 closure', [
                'mongoyia-settlement-payout-evidence/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlementClose) {
            $this->runStep('settlement close Phase 3 closure', [
                'mongoyia-settlement-close/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlementReport) {
            $this->runStep('settlement report Phase 3 closure', [
                'mongoyia-settlement-report/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlementReportBackend) {
            $this->runStep('settlement report backend Phase 3 closure', [
                'mongoyia-settlement-report-backend-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipSettlementExport) {
            $this->runStep('settlement export Phase 3 closure', [
                'mongoyia-settlement-export/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipLogisticsStatusBatch) {
            $this->runStep('logistics status batch Phase 3 closure', [
                'mongoyia-logistics-status-batch/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipLogisticsPortReview) {
            $this->runStep('logistics port review Phase 3 closure', [
                'mongoyia-logistics-port-review/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipAutoReceive) {
            $this->runStep('auto receive Phase 3 closure', [
                'mongoyia-auto-receive/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipPhase3ScheduledOps) {
            $this->runStep('Phase 3 scheduled ops readiness', [
                'mongoyia-phase3-scheduled-ops/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDistribution) {
            $this->runStep('distribution Phase 4 readiness', [
                'mongoyia-distribution-test/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDistributionBackend) {
            $this->runStep('distribution backend Phase 4 closure', [
                'mongoyia-distribution-backend-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDistributionFrontend) {
            $this->runStep('distribution frontend Phase 4 closure', [
                'mongoyia-distribution-frontend-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDistributionWithdraw) {
            $this->runStep('distribution withdraw Phase 4 closure', [
                'mongoyia-distribution-withdraw-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDistributionProfile) {
            $this->runStep('distribution profile/material/risk Phase 4 closure', [
                'mongoyia-distribution-profile-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDistributionInvite) {
            $this->runStep('distribution invite/reward Phase 4 closure', [
                'mongoyia-distribution-invite-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDistributionRisk) {
            $this->runStep('distribution payout-risk Phase 4 readiness', [
                'mongoyia-distribution-risk-readiness/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDistributionAnalytics) {
            $this->runStep('distribution analytics Phase 4 closure', [
                'mongoyia-distribution-analytics-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDistributionAnalyticsExport) {
            $this->runStep('distribution analytics export Phase 4 evidence', [
                'mongoyia-distribution-analytics-export/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipDistributionRewardWorkflow) {
            $this->runStep('distribution invite reward workflow Phase 4 closure', [
                'mongoyia-distribution-reward-workflow-test/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipCommissionIntegrity) {
            $this->runStep('commission integrity Phase 4 readiness', [
                'mongoyia-commission-integrity/run',
                '--fixture=1',
                '--interactive=0',
            ]);
        }

        if (!$this->skipIm) {
            $script = $this->resolvePath($this->imHealthScript);
            $this->runStep('IM healthcheck', [
                $this->pythonBin,
                $script,
                '--url',
                $this->imUrl,
            ], dirname(dirname($script)));

            $script = $this->resolvePath($this->imRegressionScript);
            $this->runStep('IM chat regression', [
                $this->pythonBin,
                $script,
                '--url',
                $this->imUrl,
                '--merchant-uid',
                (string)$this->imMerchantUid,
                '--product-id',
                (string)$this->imProductId,
                '--store-id',
                (string)$this->imStoreId,
            ], dirname(dirname($script)));

            $script = $this->resolvePath($this->imConcurrencyScript);
            $this->runStep('IM concurrency regression', [
                $this->pythonBin,
                $script,
                '--url',
                $this->imUrl,
                '--merchant-uid',
                (string)$this->imMerchantUid,
                '--product-id',
                (string)$this->imProductId,
                '--store-id',
                (string)$this->imStoreId,
                '--users',
                (string)$this->imConcurrencyUsers,
            ], dirname(dirname($script)));
        }

        if (!$this->skipImMedia) {
            $this->runStep('IM media upload readiness', [
                'mongoyia-im-media-readiness/run',
                '--baseUrl=' . $this->baseUrl,
                '--productId=' . (int)$this->imProductId,
                '--interactive=0',
            ]);
        }

        if (!$this->skipImMediaTransportImplementationGate) {
            $this->runStep('IM media transport implementation gate Phase 6 closure', [
                'mongoyia-im-media-transport-implementation-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipImMediaTransportPolicyGate) {
            $this->runStep('IM media transport policy gate Phase 6 closure', [
                'mongoyia-im-media-transport-policy-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipImMediaUploadSkeletonGate) {
            $this->runStep('IM media upload skeleton gate Phase 6 closure', [
                'mongoyia-im-media-upload-skeleton-gate/run',
                '--baseUrl=' . $this->baseUrl,
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipApi) {
            $this->runStep('API smoke', [
                'api-smoke-test/run',
                '--baseUrl=' . $this->baseUrl,
                '--interactive=0',
            ]);
        }

        if (!$this->skipFrontend) {
            $args = [
                'mall-smoke-test/run',
                '--baseUrl=' . $this->baseUrl,
                '--productIds=' . $this->productIds,
                '--interactive=0',
            ];
            if ($this->categoryId !== '') {
                $args[] = '--categoryId=' . $this->categoryId;
            }
            $this->runStep('frontend smoke', $args);
        }

        if (!$this->skipBackend) {
            $this->runStep('backend smoke', [
                'backend-smoke-test/run',
                '--baseUrl=' . $this->baseUrl,
                '--platformUsername=' . $this->platformUsername,
                '--platformPassword=' . $this->platformPassword,
                '--sellerUsername=' . $this->sellerUsername,
                '--sellerPassword=' . $this->sellerPassword,
                '--interactive=0',
            ]);
        }

        if (!$this->skipWebClosure) {
            $this->runStep('web closure fixture', [
                'mongoyia-web-closure-fixture/run',
                '--apply=1',
                '--userId=' . $this->paymentUserId,
                '--productId=' . $this->webClosureProductId,
                '--amount=' . $this->paymentAmount,
                '--interactive=0',
            ]);

            $this->runStep('web closure smoke', [
                'mongoyia-web-closure-test/run',
                '--baseUrl=' . $this->baseUrl,
                '--productIds=' . $this->productIds,
                '--interactive=0',
            ]);

            $this->runStep('coupon closure', [
                'mongoyia-coupon-test/run',
                '--interactive=0',
            ]);

            $this->runStep('favorite/review closure', [
                'mongoyia-favorite-review-test/run',
                '--baseUrl=' . $this->baseUrl,
                '--productId=' . $this->webClosureProductId,
                '--interactive=0',
            ]);

            $this->runStep('logistics basic closure', [
                'mongoyia-logistics-basic-test/run',
                '--interactive=0',
            ]);

            $this->runStep('statistics readiness', [
                'mongoyia-stat-readiness/run',
                '--interactive=0',
            ]);
        }

        if (!$this->skipPayment) {
            $this->runStep('payment regression', [
                'mall-payment-test/run',
                '--baseUrl=' . $this->baseUrl,
                '--userId=' . $this->paymentUserId,
                '--productIds=' . $this->paymentProductIds,
                '--amount=' . $this->paymentAmount,
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProvider) {
            $this->runStep('payment provider Phase 6 readiness', [
                'payment-provider-readiness/run',
                '--baseUrl=' . $this->baseUrl,
                '--profile=' . $this->profile,
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderRouteSkeletonGate) {
            $this->runStep('PayPal route skeleton gate Phase 6 closure', [
                'payment-provider-route-skeleton-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderWebhookDryRunGate) {
            $this->runStep('PayPal webhook dry-run gate Phase 6 closure', [
                'payment-provider-webhook-dry-run-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderWebhookVerificationDryRunGate) {
            $this->runStep('PayPal webhook verification dry-run gate Phase 6 closure', [
                'payment-provider-webhook-verification-dry-run-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderWebhookAuditDryRun) {
            $this->runStep('PayPal webhook audit dry-run gate Phase 6 closure', [
                'payment-provider-webhook-audit-dry-run/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalSandboxEvidenceGate) {
            $this->runStep('PayPal sandbox evidence gate Phase 6 closure', [
                'payment-provider-paypal-sandbox-evidence-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalLiveAuditWriteImplementationGate) {
            $this->runStep('PayPal live audit write implementation gate Phase 6 closure', [
                'payment-provider-paypal-live-audit-write-implementation-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalSandboxEvidenceSignoffGate) {
            $this->runStep('PayPal sandbox evidence signoff gate Phase 6 closure', [
                'payment-provider-paypal-sandbox-evidence-signoff-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalSandboxEvidenceManifestValidator) {
            $this->runStep('PayPal sandbox evidence manifest validator Phase 6 closure', [
                'payment-provider-paypal-sandbox-evidence-manifest-validator/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalSandboxEvidenceRedactionChecklist) {
            $this->runStep('PayPal sandbox evidence redaction checklist Phase 6 closure', [
                'payment-provider-paypal-sandbox-evidence-redaction-checklist/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalSandboxEvidenceBundleReviewReadiness) {
            $this->runStep('PayPal sandbox evidence bundle review readiness Phase 6 closure', [
                'payment-provider-paypal-sandbox-evidence-bundle-review-readiness/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalSandboxEvidenceBundleReviewSignoffGate) {
            $this->runStep('PayPal sandbox evidence bundle review signoff gate Phase 6 closure', [
                'payment-provider-paypal-sandbox-evidence-bundle-review-signoff-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalSandboxEvidenceSignoffImportDryRun) {
            $this->runStep('PayPal sandbox evidence signoff import dry-run Phase 6 closure', [
                'payment-provider-paypal-sandbox-evidence-signoff-import-dry-run/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalSandboxEvidenceReviewResultApplyGate) {
            $this->runStep('PayPal sandbox evidence review-result apply gate Phase 6 closure', [
                'payment-provider-paypal-sandbox-evidence-review-result-apply-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalExternalEvidenceCollectionGate) {
            $this->runStep('PayPal external evidence collection gate Phase 6 closure', [
                'payment-provider-paypal-external-evidence-collection-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalExternalEvidenceManifestImportDryRun) {
            $this->runStep('PayPal external evidence manifest import dry-run Phase 6 closure', [
                'payment-provider-paypal-external-evidence-manifest-import-dry-run/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalExternalEvidenceManifestReviewReadiness) {
            $this->runStep('PayPal external evidence manifest review readiness Phase 6 closure', [
                'payment-provider-paypal-external-evidence-manifest-review-readiness/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalExternalEvidenceManifestReviewSignoffImportDryRun) {
            $this->runStep('PayPal external evidence manifest review signoff import dry-run Phase 6 closure', [
                'payment-provider-paypal-external-evidence-manifest-review-signoff-import-dry-run/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalExternalEvidenceManifestReviewResultApplyGate) {
            $this->runStep('PayPal external evidence manifest review-result apply gate Phase 6 closure', [
                'payment-provider-paypal-external-evidence-manifest-review-result-apply-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalLiveProviderImplementationEvidenceDryRun) {
            $this->runStep('PayPal live provider implementation evidence dry-run Phase 6 closure', [
                'payment-provider-paypal-live-provider-implementation-evidence-dry-run/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalLiveProviderImplementationEvidenceSignoffGate) {
            $this->runStep('PayPal live provider implementation evidence signoff gate Phase 6 closure', [
                'payment-provider-paypal-live-provider-implementation-evidence-signoff-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalLiveExecutionEvidenceReadinessGate) {
            $this->runStep('PayPal live execution evidence readiness gate Phase 6 closure', [
                'payment-provider-paypal-live-execution-evidence-readiness-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRun) {
            $this->runStep('PayPal live execution evidence signoff import dry-run Phase 6 closure', [
                'payment-provider-paypal-live-execution-evidence-signoff-import-dry-run/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderLiveVerificationEnablementGate) {
            $this->runStep('PayPal live verification enablement gate Phase 6 closure', [
                'payment-provider-live-verification-enablement-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPaymentProviderPaypalFinalGoNoGoGate) {
            $this->runStep('PayPal final go/no-go gate Phase 6 closure', [
                'payment-provider-paypal-final-go-no-go-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerService) {
            $this->runStep('customer-service Phase 6 readiness', [
                'customer-service-test/run',
                '--baseUrl=' . $this->baseUrl,
                '--platformUsername=' . $this->platformUsername,
                '--platformPassword=' . $this->platformPassword,
                '--sellerUsername=' . $this->sellerUsername,
                '--sellerPassword=' . $this->sellerPassword,
                '--productId=' . (int)$this->imProductId,
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceAdvanced) {
            $this->runStep('advanced customer-service Phase 6 readiness', [
                'customer-service-advanced-readiness/run',
                '--baseUrl=' . $this->baseUrl,
                '--profile=' . $this->profile,
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceTicketReadonly) {
            $this->runStep('customer-service ticket readonly backend Phase 6 closure', [
                'customer-service-ticket-readonly-test/run',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceTicketCreate) {
            $this->runStep('customer-service ticket create backend Phase 6 closure', [
                'customer-service-ticket-create-test/run',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceTicketNote) {
            $this->runStep('customer-service ticket note backend Phase 6 closure', [
                'customer-service-ticket-note-test/run',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceTicketResult) {
            $this->runStep('customer-service ticket result backend Phase 6 closure', [
                'customer-service-ticket-result-test/run',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceTicketAssign) {
            $this->runStep('customer-service ticket assign backend Phase 6 closure', [
                'customer-service-ticket-assign-test/run',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceTicketWorkflow) {
            $this->runStep('customer-service ticket workflow Phase 6 closure', [
                'customer-service-ticket-workflow-test/run',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceStatExport) {
            $this->runStep('customer-service stat export Phase 6 closure', [
                'customer-service-stat-export/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceStatWidgetReadiness) {
            $this->runStep('customer-service stat widget readiness Phase 6 closure', [
                'customer-service-stat-widget-readiness/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceStatApplyGate) {
            $this->runStep('customer-service stat apply gate Phase 6 closure', [
                'customer-service-stat-apply-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceStatApplyWorkflow) {
            $this->runStep('customer-service stat apply workflow Phase 6 closure', [
                'customer-service-stat-apply-workflow/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceStatApplyLogReview) {
            $this->runStep('customer-service stat apply log review Phase 6 closure', [
                'customer-service-stat-apply-log-review/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceComplaintExport) {
            $this->runStep('customer-service complaint export Phase 6 closure', [
                'customer-service-complaint-export/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceComplaintEvidenceGate) {
            $this->runStep('customer-service complaint evidence gate Phase 6 closure', [
                'customer-service-complaint-evidence-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceComplaintEvidenceUploadPolicyGate) {
            $this->runStep('customer-service complaint evidence upload policy gate Phase 6 closure', [
                'customer-service-complaint-evidence-upload-policy-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceComplaintEvidenceUploadImplementationGate) {
            $this->runStep('customer-service complaint evidence upload implementation gate Phase 6 closure', [
                'customer-service-complaint-evidence-upload-implementation-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceComplaintEvidenceUploadCleanupReadiness) {
            $this->runStep('customer-service complaint evidence upload cleanup readiness Phase 6 closure', [
                'customer-service-complaint-evidence-upload-cleanup-readiness/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceComplaintEvidenceUploadEnablementGate) {
            $this->runStep('customer-service complaint evidence upload enablement gate Phase 6 closure', [
                'customer-service-complaint-evidence-upload-enablement-gate/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceComplaintEvidenceApplyWorkflow) {
            $this->runStep('customer-service complaint evidence apply workflow Phase 6 closure', [
                'customer-service-complaint-evidence-apply-workflow/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceResolutionExport) {
            $this->runStep('customer-service resolution export Phase 6 closure', [
                'customer-service-resolution-export/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceSlaReadiness) {
            $this->runStep('customer-service SLA readiness Phase 6 closure', [
                'customer-service-sla-readiness/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceSlaHandling) {
            $this->runStep('customer-service SLA handling dry-run Phase 6 closure', [
                'customer-service-sla-handling/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipCustomerServiceResultSignoff) {
            $this->runStep('customer-service result signoff Phase 6 closure', [
                'customer-service-result-signoff/run',
                '--fixture=1',
                '--strict=' . ((int)$this->strict),
                '--interactive=0',
            ]);
        }

        if (!$this->skipPwa) {
            $this->runStep('PWA shell Phase 5 smoke', [
                'pwa-smoke-test/run',
                '--baseUrl=' . $this->baseUrl,
                '--interactive=0',
            ]);

            if (!$this->skipPwaOffline) {
                $this->runStep('PWA offline/install readiness', [
                    'mongoyia-pwa-offline-readiness/run',
                    '--baseUrl=' . $this->baseUrl,
                    '--interactive=0',
                ]);
            }

            if (!$this->skipPwaVisual) {
                $this->runStep('PWA visual QA readiness', [
                    'mongoyia-pwa-visual-qa/run',
                    '--baseUrl=' . $this->baseUrl,
                    '--interactive=0',
                ]);
            }
        }

        if ($this->failed) {
            $this->stderr("\nAcceptance failed:\n");
            foreach ($this->failed as $step) {
                $this->stderr("- {$step}\n");
            }
            $this->writeReport(false);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->cleanupAfterRun) {
            $this->runStep('generated test data cleanup', [
                'mongoyia-test-cleanup/run',
                '--apply=1',
                '--olderThanHours=' . (int)$this->cleanupOlderThanHours,
                '--includeChat=' . ((int)$this->cleanupIncludeChat),
                '--interactive=0',
            ]);

            $this->runStep('generated test data cleanup verification', [
                'mongoyia-test-cleanup/run',
                '--failOnPending=1',
                '--includeChat=' . ((int)$this->cleanupIncludeChat),
                '--interactive=0',
            ]);

            if ($this->failed) {
                $this->stderr("\nAcceptance cleanup failed:\n");
                foreach ($this->failed as $step) {
                    $this->stderr("- {$step}\n");
                }
                $this->writeReport(false);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $this->stdout("\nAll Mongoyia acceptance steps passed.\n");
        $this->writeReport(true);
        return ExitCode::OK;
    }

    private function runStep(string $label, array $args, string $cwd = null)
    {
        $this->stdout("\n== {$label} ==\n");
        $startedAt = microtime(true);
        $result = $cwd === null ? $this->runYii($args) : $this->runProcess($args, $cwd);
        $exitCode = $result['exitCode'];
        $this->steps[] = [
            'label' => $label,
            'command' => $result['command'],
            'cwd' => $result['cwd'],
            'exitCode' => $exitCode,
            'duration' => microtime(true) - $startedAt,
            'output' => $this->redact($result['output']),
        ];
        if ($exitCode === 0) {
            $this->stdout("PASS {$label}\n");
            return;
        }

        $this->failed[] = "{$label} exited with {$exitCode}";
        $this->stderr("FAIL {$label} exited with {$exitCode}\n");
    }

    private function runYii(array $args)
    {
        $yii = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'yii';
        $parts = array_merge([PHP_BINARY, $yii], $args);
        return $this->runProcess($parts, dirname(__DIR__, 2));
    }

    private function runProcess(array $parts, string $cwd)
    {
        $command = implode(' ', array_map([$this, 'quoteArg'], $parts));
        $process = proc_open($command, [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd);

        if (!is_resource($process)) {
            return [
                'exitCode' => 1,
                'output' => 'Failed to start process.',
                'command' => $this->redactCommand($parts),
                'cwd' => $cwd,
            ];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        while (true) {
            $status = proc_get_status($process);
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $output .= $chunk;
                $index === 1 ? $this->stdout($chunk) : $this->stderr($chunk);
            }
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }
        foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
                $index === 1 ? $this->stdout($chunk) : $this->stderr($chunk);
            }
            fclose($pipes[$index]);
        }

        $exitCode = proc_close($process);
        return [
            'exitCode' => (int)$exitCode,
            'output' => $output,
            'command' => $this->redactCommand($parts),
            'cwd' => $cwd,
        ];
    }

    private function resolvePath(string $path)
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return $path;
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $path;
    }

    private function quoteArg(string $arg)
    {
        return escapeshellarg($arg);
    }

    private function writeReport(bool $passed)
    {
        if ($this->noReport) {
            return;
        }

        $path = $this->reportPath ?: $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Acceptance Report',
            '',
            '- Result: ' . ($passed ? 'PASS' : 'FAIL'),
            '- Base URL: ' . $this->baseUrl,
            '- Started at: ' . date('Y-m-d H:i:s', $this->startedAt),
            '- Finished at: ' . date('Y-m-d H:i:s'),
            '- Strict mode: ' . ($this->strict ? 'yes' : 'no'),
            '- Profile: ' . $this->profile,
            '',
            '## Signoff Summary',
            '',
            '| Item | Value |',
            '|---|---|',
            '| Acceptance result | ' . ($passed ? 'PASS' : 'FAIL') . ' |',
            '| Profile | ' . $this->profile . ' |',
            '| Strict mode | ' . ($this->strict ? 'yes' : 'no') . ' |',
            '| Base URL | ' . $this->baseUrl . ' |',
            '| IM URL | ' . $this->imUrl . ' |',
            '| Platform backend user | ' . $this->platformUsername . ' |',
            '| Seller backend user | ' . $this->sellerUsername . ' |',
            '| Payment user id | ' . $this->paymentUserId . ' |',
            '| Product ids | ' . $this->productIds . ' |',
            '| Payment product ids | ' . $this->paymentProductIds . ' |',
            '| IM merchant/product/store | ' . $this->imMerchantUid . ' / ' . $this->imProductId . ' / ' . $this->imStoreId . ' |',
            '| Web closure product id | ' . $this->webClosureProductId . ' |',
            '| Steps passed | ' . $this->passedStepCount() . ' / ' . count($this->steps) . ' |',
            '| Cleanup requested | ' . ($this->cleanupAfterRun ? 'yes' : 'no') . ' |',
            '| Cleanup verification | ' . $this->cleanupVerificationStatus() . ' |',
            '| Warning lines | ' . count($this->reportLinesMatching('/^WARN\b/m')) . ' |',
            '| Failure lines | ' . count($this->reportLinesMatching('/^FAIL\b/m')) . ' |',
            '',
            '### Signoff Notes',
            '',
        ];

        foreach ($this->signoffNotes($passed) as $note) {
            $lines[] = '- ' . $note;
        }

        $riskLines = $this->reportLinesMatching('/^(WARN|FAIL)\b/m', 50);
        if ($riskLines) {
            $lines[] = '';
            $lines[] = '### Warning / Failure Extract';
            $lines[] = '';
            foreach ($riskLines as $riskLine) {
                $lines[] = '- `' . $riskLine . '`';
            }
        }

        $lines = array_merge($lines, [
            '',
            '## Steps',
            '',
        ]);

        foreach ($this->steps as $step) {
            $lines[] = '### ' . $step['label'];
            $lines[] = '';
            $lines[] = '- Exit code: ' . $step['exitCode'];
            $lines[] = '- Duration: ' . number_format($step['duration'], 2) . 's';
            $lines[] = '- Working directory: `' . $step['cwd'] . '`';
            $lines[] = '- Command: `' . $step['command'] . '`';
            $lines[] = '';
            $lines[] = '```text';
            $lines[] = trim($step['output']);
            $lines[] = '```';
            $lines[] = '';
        }

        if ($this->failed) {
            $lines[] = '## Failures';
            $lines[] = '';
            foreach ($this->failed as $failure) {
                $lines[] = '- ' . $failure;
            }
            $lines[] = '';
        }

        $lines[] = '## Generated Test Data';
        $lines[] = '';
        $lines[] = 'This suite creates `REGPAY-*` payment test orders, `WEBFIX-*` Web closure fixture orders, payment attempts, `healthcheck_*`, `im_regression_*`, `im_concurrency_*` chat messages, and chat smoke upload files.';
        $lines[] = '';
        if ($this->cleanupAfterRun) {
            $lines[] = 'Cleanup was requested for this acceptance run. The report should include both `generated test data cleanup` and `generated test data cleanup verification`; the verification step fails if generated data remains.';
            $lines[] = '';
            $lines[] = 'To re-check manually:';
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = 'php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0';
            $lines[] = '```';
        } else {
            $lines[] = 'Cleanup was not requested for this acceptance run. Review counts with:';
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = 'php yii mongoyia-test-cleanup/run --interactive=0';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = 'Apply cleanup only after the report is accepted:';
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = 'php yii mongoyia-test-cleanup/run --apply=1 --olderThanHours=1 --interactive=0';
            $lines[] = '```';
        }
        $lines[] = '';
        $lines[] = '';

        file_put_contents($path, implode("\n", $lines));
        $this->stdout("\nAcceptance report written to {$path}\n");
    }

    private function passedStepCount()
    {
        $count = 0;
        foreach ($this->steps as $step) {
            if ((int)$step['exitCode'] === 0) {
                $count++;
            }
        }

        return $count;
    }

    private function cleanupVerificationStatus()
    {
        if (!$this->cleanupAfterRun) {
            return 'not requested';
        }

        foreach ($this->steps as $step) {
            if ($step['label'] === 'generated test data cleanup verification') {
                return ((int)$step['exitCode'] === 0) ? 'pass' : 'fail';
            }
        }

        return 'missing';
    }

    private function signoffNotes(bool $passed)
    {
        $notes = [];
        $notes[] = $passed ? 'All executed acceptance steps passed.' : 'One or more acceptance steps failed; review the step output before signoff.';
        if ($this->profile === 'test' && $this->strict) {
            $notes[] = 'Test profile strict mode was used; deployment/security/data checks should have zero warnings.';
        } elseif ($this->profile === 'local') {
            $notes[] = 'Local profile may include expected localhost, placeholder payment, and PHP upload-limit warnings; do not use local profile as test-server signoff.';
        } else {
            $notes[] = 'Confirm profile and strict mode match the target environment before signoff.';
        }
        $notes[] = $this->cleanupAfterRun ? 'Generated regression data cleanup was requested and verified by the cleanup verification step.' : 'Generated regression data cleanup was not requested; run cleanup before final signoff.';
        $notes[] = 'Record unresolved business risks separately, especially zero-price products and legacy order/payment audit warnings.';

        return $notes;
    }

    private function reportLinesMatching(string $pattern, int $limit = null)
    {
        $matches = [];
        foreach ($this->steps as $step) {
            $lines = preg_split('/\R/', (string)$step['output']);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || !preg_match($pattern, $line)) {
                    continue;
                }
                $matches[] = $line;
                if ($limit !== null && count($matches) >= $limit) {
                    return $matches;
                }
            }
        }

        return $matches;
    }

    private function defaultReportPath()
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'acceptance' . DIRECTORY_SEPARATOR . 'mongoyia-acceptance-' . date('Ymd-His') . '.md';
    }

    private function redactCommand(array $parts)
    {
        return implode(' ', array_map([$this, 'quoteArg'], array_map([$this, 'redact'], $parts)));
    }

    private function redact(string $text)
    {
        $text = preg_replace('/(--[^=\s]*(?:password|secret|token)[^=\s]*=)[^\s]+/i', '$1***', $text);
        $text = preg_replace('/((?:password|secret|token)[\'"]?\s*[=:]\s*[\'"]?)[^\'"\s,]+/i', '$1***', $text);
        return $text;
    }
}
