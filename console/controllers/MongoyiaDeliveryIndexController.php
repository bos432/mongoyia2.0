<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDeliveryIndexController extends Controller
{
    public $acceptancePath = '';
    public $signoffPath = '';
    public $riskPath = '';
    public $pwaEvidencePath = '';
    public $pwaOfflineReadinessPath = '';
    public $pwaVisualQaPath = '';
    public $paymentCallbackReadinessPath = '';
    public $paymentProviderReadinessPath = '';
    public $paymentProviderRouteSkeletonGatePath = '';
    public $paymentProviderWebhookDryRunGatePath = '';
    public $paymentProviderWebhookVerificationDryRunGatePath = '';
    public $paymentProviderWebhookAuditDryRunPath = '';
    public $paymentProviderPaypalSandboxEvidenceGatePath = '';
    public $paymentProviderPaypalLiveAuditWriteImplementationGatePath = '';
    public $paymentProviderPaypalSandboxEvidenceSignoffGatePath = '';
    public $paymentProviderPaypalSandboxEvidenceManifestValidatorPath = '';
    public $paymentProviderPaypalSandboxEvidenceRedactionChecklistPath = '';
    public $paymentProviderPaypalSandboxEvidenceBundleReviewReadinessPath = '';
    public $paymentProviderPaypalSandboxEvidenceBundleReviewSignoffGatePath = '';
    public $paymentProviderPaypalSandboxEvidenceSignoffImportDryRunPath = '';
    public $paymentProviderPaypalSandboxEvidenceReviewResultApplyGatePath = '';
    public $paymentProviderPaypalExternalEvidenceCollectionGatePath = '';
    public $paymentProviderPaypalExternalEvidenceManifestImportDryRunPath = '';
    public $paymentProviderPaypalExternalEvidenceManifestReviewReadinessPath = '';
    public $paymentProviderPaypalExternalEvidenceManifestReviewSignoffImportDryRunPath = '';
    public $paymentProviderPaypalExternalEvidenceManifestReviewResultApplyGatePath = '';
    public $paymentProviderPaypalLiveProviderImplementationEvidenceDryRunPath = '';
    public $paymentProviderPaypalLiveProviderImplementationEvidenceSignoffGatePath = '';
    public $paymentProviderPaypalLiveExecutionEvidenceReadinessGatePath = '';
    public $paymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunPath = '';
    public $paymentProviderLiveVerificationEnablementGatePath = '';
    public $paymentProviderPaypalFinalGoNoGoGatePath = '';
    public $productionHealthPath = '';
    public $productionMonitorPath = '';
    public $productionBackupVerifyEvidencePath = '';
    public $productionScheduledCheckEvidencePath = '';
    public $productionLoadTestEvidencePath = '';
    public $productionEvidenceSummaryPath = '';
    public $productionExternalEvidenceImportDryRunPath = '';
    public $productionExternalEvidenceReviewReadinessPath = '';
    public $productionExternalEvidenceReviewResultApplyGatePath = '';
    public $productionExternalEvidenceFinalAcceptanceGatePath = '';
    public $productionLaunchSignoffReadinessGatePath = '';
    public $productionGoLiveGatePath = '';
    public $imMediaReadinessPath = '';
    public $imMediaTransportImplementationGatePath = '';
    public $imMediaTransportPolicyGatePath = '';
    public $imMediaUploadSkeletonGatePath = '';
    public $customerServiceReadinessPath = '';
    public $customerServiceAdvancedReadinessPath = '';
    public $customerServiceStatExportPath = '';
    public $customerServiceStatWidgetReadinessPath = '';
    public $customerServiceStatApplyGatePath = '';
    public $customerServiceStatApplyWorkflowPath = '';
    public $customerServiceStatApplyLogReviewPath = '';
    public $customerServiceComplaintExportPath = '';
    public $customerServiceComplaintEvidenceGatePath = '';
    public $customerServiceComplaintEvidenceUploadPolicyGatePath = '';
    public $customerServiceComplaintEvidenceUploadImplementationGatePath = '';
    public $customerServiceComplaintEvidenceUploadCleanupReadinessPath = '';
    public $customerServiceComplaintEvidenceUploadEnablementGatePath = '';
    public $customerServiceComplaintEvidenceApplyWorkflowPath = '';
    public $customerServiceResolutionExportPath = '';
    public $customerServiceSlaReadinessPath = '';
    public $customerServiceSlaHandlingPath = '';
    public $customerServiceResultSignoffPath = '';
    public $outputPath = '';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'acceptancePath',
            'signoffPath',
            'riskPath',
            'pwaEvidencePath',
            'pwaOfflineReadinessPath',
            'pwaVisualQaPath',
            'paymentCallbackReadinessPath',
            'paymentProviderReadinessPath',
            'paymentProviderRouteSkeletonGatePath',
            'paymentProviderWebhookDryRunGatePath',
            'paymentProviderWebhookVerificationDryRunGatePath',
            'paymentProviderWebhookAuditDryRunPath',
            'paymentProviderPaypalSandboxEvidenceGatePath',
            'paymentProviderPaypalLiveAuditWriteImplementationGatePath',
            'paymentProviderPaypalSandboxEvidenceSignoffGatePath',
            'paymentProviderPaypalSandboxEvidenceManifestValidatorPath',
            'paymentProviderPaypalSandboxEvidenceRedactionChecklistPath',
            'paymentProviderPaypalSandboxEvidenceBundleReviewReadinessPath',
            'paymentProviderPaypalSandboxEvidenceBundleReviewSignoffGatePath',
            'paymentProviderPaypalSandboxEvidenceSignoffImportDryRunPath',
            'paymentProviderPaypalSandboxEvidenceReviewResultApplyGatePath',
            'paymentProviderPaypalExternalEvidenceCollectionGatePath',
            'paymentProviderPaypalExternalEvidenceManifestImportDryRunPath',
            'paymentProviderPaypalExternalEvidenceManifestReviewReadinessPath',
            'paymentProviderPaypalExternalEvidenceManifestReviewSignoffImportDryRunPath',
            'paymentProviderPaypalExternalEvidenceManifestReviewResultApplyGatePath',
            'paymentProviderPaypalLiveProviderImplementationEvidenceDryRunPath',
            'paymentProviderPaypalLiveProviderImplementationEvidenceSignoffGatePath',
            'paymentProviderPaypalLiveExecutionEvidenceReadinessGatePath',
            'paymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunPath',
            'paymentProviderLiveVerificationEnablementGatePath',
            'paymentProviderPaypalFinalGoNoGoGatePath',
            'productionHealthPath',
            'productionMonitorPath',
            'productionBackupVerifyEvidencePath',
            'productionScheduledCheckEvidencePath',
            'productionLoadTestEvidencePath',
            'productionEvidenceSummaryPath',
            'productionExternalEvidenceImportDryRunPath',
            'productionExternalEvidenceReviewReadinessPath',
            'productionExternalEvidenceReviewResultApplyGatePath',
            'productionExternalEvidenceFinalAcceptanceGatePath',
            'productionLaunchSignoffReadinessGatePath',
            'productionGoLiveGatePath',
            'imMediaReadinessPath',
            'imMediaTransportImplementationGatePath',
            'imMediaTransportPolicyGatePath',
            'imMediaUploadSkeletonGatePath',
            'customerServiceReadinessPath',
            'customerServiceAdvancedReadinessPath',
            'customerServiceStatExportPath',
            'customerServiceStatWidgetReadinessPath',
            'customerServiceStatApplyGatePath',
            'customerServiceStatApplyWorkflowPath',
            'customerServiceStatApplyLogReviewPath',
            'customerServiceComplaintExportPath',
            'customerServiceComplaintEvidenceGatePath',
            'customerServiceComplaintEvidenceUploadPolicyGatePath',
            'customerServiceComplaintEvidenceUploadImplementationGatePath',
            'customerServiceComplaintEvidenceUploadCleanupReadinessPath',
            'customerServiceComplaintEvidenceUploadEnablementGatePath',
            'customerServiceComplaintEvidenceApplyWorkflowPath',
            'customerServiceResolutionExportPath',
            'customerServiceSlaReadinessPath',
            'customerServiceSlaHandlingPath',
            'customerServiceResultSignoffPath',
            'outputPath',
        ]);
    }

    public function actionRun()
    {
        $acceptancePath = $this->acceptancePath !== ''
            ? $this->resolvePath($this->acceptancePath)
            : $this->latestAcceptanceFile('mongoyia-acceptance-*.md');
        $signoffPath = $this->signoffPath !== ''
            ? $this->resolvePath($this->signoffPath)
            : $this->latestAcceptanceFile('mongoyia-signoff-*.md');
        $riskPath = $this->riskPath !== ''
            ? $this->resolvePath($this->riskPath)
            : $this->latestAcceptanceFile('mongoyia-risk-register-*.md');
        $pwaEvidencePath = $this->pwaEvidencePath !== ''
            ? $this->resolvePath($this->pwaEvidencePath)
            : $this->latestHandoverFile('mongoyia-pwa-mobile-ui-evidence-*.md');
        $pwaOfflineReadinessPath = $this->pwaOfflineReadinessPath !== ''
            ? $this->resolvePath($this->pwaOfflineReadinessPath)
            : $this->latestHandoverFile('mongoyia-pwa-offline-readiness-*.md');
        $pwaVisualQaPath = $this->pwaVisualQaPath !== ''
            ? $this->resolvePath($this->pwaVisualQaPath)
            : $this->latestHandoverFile('mongoyia-pwa-visual-qa-*.md');
        $paymentCallbackReadinessPath = $this->paymentCallbackReadinessPath !== ''
            ? $this->resolvePath($this->paymentCallbackReadinessPath)
            : $this->latestHandoverFile('mongoyia-payment-callback-readiness-*.md');
        $paymentProviderReadinessPath = $this->paymentProviderReadinessPath !== ''
            ? $this->resolvePath($this->paymentProviderReadinessPath)
            : $this->latestHandoverFile('mongoyia-payment-provider-readiness-*.md');
        $paymentProviderRouteSkeletonGatePath = $this->paymentProviderRouteSkeletonGatePath !== ''
            ? $this->resolvePath($this->paymentProviderRouteSkeletonGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-route-skeleton-gate-*.md');
        $paymentProviderWebhookDryRunGatePath = $this->paymentProviderWebhookDryRunGatePath !== ''
            ? $this->resolvePath($this->paymentProviderWebhookDryRunGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-webhook-dry-run-gate-*.md');
        $paymentProviderWebhookVerificationDryRunGatePath = $this->paymentProviderWebhookVerificationDryRunGatePath !== ''
            ? $this->resolvePath($this->paymentProviderWebhookVerificationDryRunGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-webhook-verification-dry-run-gate-*.md');
        $paymentProviderWebhookAuditDryRunPath = $this->paymentProviderWebhookAuditDryRunPath !== ''
            ? $this->resolvePath($this->paymentProviderWebhookAuditDryRunPath)
            : $this->latestHandoverFile('mongoyia-payment-provider-webhook-audit-dry-run-*.md');
        $paymentProviderPaypalSandboxEvidenceGatePath = $this->paymentProviderPaypalSandboxEvidenceGatePath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalSandboxEvidenceGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-gate-*.md');
        $paymentProviderPaypalLiveAuditWriteImplementationGatePath = $this->paymentProviderPaypalLiveAuditWriteImplementationGatePath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalLiveAuditWriteImplementationGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-live-audit-write-implementation-gate-*.md');
        $paymentProviderPaypalSandboxEvidenceSignoffGatePath = $this->paymentProviderPaypalSandboxEvidenceSignoffGatePath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalSandboxEvidenceSignoffGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-signoff-gate-*.md');
        $paymentProviderPaypalSandboxEvidenceManifestValidatorPath = $this->paymentProviderPaypalSandboxEvidenceManifestValidatorPath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalSandboxEvidenceManifestValidatorPath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-manifest-validator-*.md');
        $paymentProviderPaypalSandboxEvidenceRedactionChecklistPath = $this->paymentProviderPaypalSandboxEvidenceRedactionChecklistPath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalSandboxEvidenceRedactionChecklistPath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-redaction-checklist-*.md');
        $paymentProviderPaypalSandboxEvidenceBundleReviewReadinessPath = $this->paymentProviderPaypalSandboxEvidenceBundleReviewReadinessPath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalSandboxEvidenceBundleReviewReadinessPath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-bundle-review-readiness-*.md');
        $paymentProviderPaypalSandboxEvidenceBundleReviewSignoffGatePath = $this->paymentProviderPaypalSandboxEvidenceBundleReviewSignoffGatePath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalSandboxEvidenceBundleReviewSignoffGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-bundle-review-signoff-gate-*.md');
        $paymentProviderPaypalSandboxEvidenceSignoffImportDryRunPath = $this->paymentProviderPaypalSandboxEvidenceSignoffImportDryRunPath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalSandboxEvidenceSignoffImportDryRunPath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-signoff-import-dry-run-*.md');
        $paymentProviderPaypalSandboxEvidenceReviewResultApplyGatePath = $this->paymentProviderPaypalSandboxEvidenceReviewResultApplyGatePath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalSandboxEvidenceReviewResultApplyGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-review-result-apply-gate-*.md');
        $paymentProviderPaypalExternalEvidenceCollectionGatePath = $this->paymentProviderPaypalExternalEvidenceCollectionGatePath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalExternalEvidenceCollectionGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-external-evidence-collection-gate-*.md');
        $paymentProviderPaypalExternalEvidenceManifestImportDryRunPath = $this->paymentProviderPaypalExternalEvidenceManifestImportDryRunPath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalExternalEvidenceManifestImportDryRunPath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-external-evidence-manifest-import-dry-run-*.md');
        $paymentProviderPaypalExternalEvidenceManifestReviewReadinessPath = $this->paymentProviderPaypalExternalEvidenceManifestReviewReadinessPath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalExternalEvidenceManifestReviewReadinessPath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-external-evidence-manifest-review-readiness-*.md');
        $paymentProviderPaypalExternalEvidenceManifestReviewSignoffImportDryRunPath = $this->paymentProviderPaypalExternalEvidenceManifestReviewSignoffImportDryRunPath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalExternalEvidenceManifestReviewSignoffImportDryRunPath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-external-evidence-manifest-review-signoff-import-dry-run-*.md');
        $paymentProviderPaypalExternalEvidenceManifestReviewResultApplyGatePath = $this->paymentProviderPaypalExternalEvidenceManifestReviewResultApplyGatePath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalExternalEvidenceManifestReviewResultApplyGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-external-evidence-manifest-review-result-apply-gate-*.md');
        $paymentProviderPaypalLiveProviderImplementationEvidenceDryRunPath = $this->paymentProviderPaypalLiveProviderImplementationEvidenceDryRunPath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalLiveProviderImplementationEvidenceDryRunPath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-live-provider-implementation-evidence-dry-run-*.md');
        $paymentProviderPaypalLiveProviderImplementationEvidenceSignoffGatePath = $this->paymentProviderPaypalLiveProviderImplementationEvidenceSignoffGatePath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalLiveProviderImplementationEvidenceSignoffGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-live-provider-implementation-evidence-signoff-gate-*.md');
        $paymentProviderPaypalLiveExecutionEvidenceReadinessGatePath = $this->paymentProviderPaypalLiveExecutionEvidenceReadinessGatePath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalLiveExecutionEvidenceReadinessGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-live-execution-evidence-readiness-gate-*.md');
        $paymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunPath = $this->paymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunPath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunPath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-live-execution-evidence-signoff-import-dry-run-*.md');
        $paymentProviderLiveVerificationEnablementGatePath = $this->paymentProviderLiveVerificationEnablementGatePath !== ''
            ? $this->resolvePath($this->paymentProviderLiveVerificationEnablementGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-live-verification-enablement-gate-*.md');
        $paymentProviderPaypalFinalGoNoGoGatePath = $this->paymentProviderPaypalFinalGoNoGoGatePath !== ''
            ? $this->resolvePath($this->paymentProviderPaypalFinalGoNoGoGatePath)
            : $this->latestHandoverFile('mongoyia-payment-provider-paypal-final-go-no-go-gate-*.md');
        $productionHealthPath = $this->productionHealthPath !== ''
            ? $this->resolvePath($this->productionHealthPath)
            : $this->latestHandoverFile('mongoyia-production-health-*.md');
        $productionMonitorPath = $this->productionMonitorPath !== ''
            ? $this->resolvePath($this->productionMonitorPath)
            : $this->latestHandoverFile('mongoyia-production-monitor-*.md');
        $productionBackupVerifyEvidencePath = $this->productionBackupVerifyEvidencePath !== ''
            ? $this->resolvePath($this->productionBackupVerifyEvidencePath)
            : $this->latestHandoverFile('mongoyia-production-backup-verify-evidence-*.md');
        $productionScheduledCheckEvidencePath = $this->productionScheduledCheckEvidencePath !== ''
            ? $this->resolvePath($this->productionScheduledCheckEvidencePath)
            : $this->latestHandoverFile('mongoyia-production-scheduled-check-evidence-*.md');
        $productionLoadTestEvidencePath = $this->productionLoadTestEvidencePath !== ''
            ? $this->resolvePath($this->productionLoadTestEvidencePath)
            : $this->latestHandoverFile('mongoyia-production-load-test-evidence-*.md');
        $productionEvidenceSummaryPath = $this->productionEvidenceSummaryPath !== ''
            ? $this->resolvePath($this->productionEvidenceSummaryPath)
            : $this->latestHandoverFile('mongoyia-production-evidence-summary-*.md');
        $productionExternalEvidenceImportDryRunPath = $this->productionExternalEvidenceImportDryRunPath !== ''
            ? $this->resolvePath($this->productionExternalEvidenceImportDryRunPath)
            : $this->latestHandoverFile('mongoyia-production-external-evidence-import-dry-run-*.md');
        $productionExternalEvidenceReviewReadinessPath = $this->productionExternalEvidenceReviewReadinessPath !== ''
            ? $this->resolvePath($this->productionExternalEvidenceReviewReadinessPath)
            : $this->latestHandoverFile('mongoyia-production-external-evidence-review-readiness-*.md');
        $productionExternalEvidenceReviewResultApplyGatePath = $this->productionExternalEvidenceReviewResultApplyGatePath !== ''
            ? $this->resolvePath($this->productionExternalEvidenceReviewResultApplyGatePath)
            : $this->latestHandoverFile('mongoyia-production-external-evidence-review-result-apply-gate-*.md');
        $productionExternalEvidenceFinalAcceptanceGatePath = $this->productionExternalEvidenceFinalAcceptanceGatePath !== ''
            ? $this->resolvePath($this->productionExternalEvidenceFinalAcceptanceGatePath)
            : $this->latestHandoverFile('mongoyia-production-external-evidence-final-acceptance-gate-*.md');
        $productionLaunchSignoffReadinessGatePath = $this->productionLaunchSignoffReadinessGatePath !== ''
            ? $this->resolvePath($this->productionLaunchSignoffReadinessGatePath)
            : $this->latestHandoverFile('mongoyia-production-launch-signoff-readiness-gate-*.md');
        $productionGoLiveGatePath = $this->productionGoLiveGatePath !== ''
            ? $this->resolvePath($this->productionGoLiveGatePath)
            : $this->latestHandoverFile('mongoyia-production-go-live-gate-*.md');
        $imMediaReadinessPath = $this->imMediaReadinessPath !== ''
            ? $this->resolvePath($this->imMediaReadinessPath)
            : $this->latestHandoverFile('mongoyia-im-media-readiness-*.md');
        $imMediaTransportImplementationGatePath = $this->imMediaTransportImplementationGatePath !== ''
            ? $this->resolvePath($this->imMediaTransportImplementationGatePath)
            : $this->latestHandoverFile('mongoyia-im-media-transport-implementation-gate-*.md');
        $imMediaTransportPolicyGatePath = $this->imMediaTransportPolicyGatePath !== ''
            ? $this->resolvePath($this->imMediaTransportPolicyGatePath)
            : $this->latestHandoverFile('mongoyia-im-media-transport-policy-gate-*.md');
        $imMediaUploadSkeletonGatePath = $this->imMediaUploadSkeletonGatePath !== ''
            ? $this->resolvePath($this->imMediaUploadSkeletonGatePath)
            : $this->latestHandoverFile('mongoyia-im-media-upload-skeleton-gate-*.md');
        $customerServiceReadinessPath = $this->customerServiceReadinessPath !== ''
            ? $this->resolvePath($this->customerServiceReadinessPath)
            : $this->latestHandoverFile('mongoyia-customer-service-readiness-*.md');
        $customerServiceAdvancedReadinessPath = $this->customerServiceAdvancedReadinessPath !== ''
            ? $this->resolvePath($this->customerServiceAdvancedReadinessPath)
            : $this->latestHandoverFile('mongoyia-customer-service-advanced-readiness-*.md');
        $customerServiceStatExportPath = $this->customerServiceStatExportPath !== ''
            ? $this->resolvePath($this->customerServiceStatExportPath)
            : $this->latestHandoverFile('mongoyia-customer-service-stat-export-*.md');
        $customerServiceStatWidgetReadinessPath = $this->customerServiceStatWidgetReadinessPath !== ''
            ? $this->resolvePath($this->customerServiceStatWidgetReadinessPath)
            : $this->latestHandoverFile('mongoyia-customer-service-stat-widget-readiness-*.md');
        $customerServiceStatApplyGatePath = $this->customerServiceStatApplyGatePath !== ''
            ? $this->resolvePath($this->customerServiceStatApplyGatePath)
            : $this->latestHandoverFile('mongoyia-customer-service-stat-apply-gate-*.md');
        $customerServiceStatApplyWorkflowPath = $this->customerServiceStatApplyWorkflowPath !== ''
            ? $this->resolvePath($this->customerServiceStatApplyWorkflowPath)
            : $this->latestHandoverFile('mongoyia-customer-service-stat-apply-workflow-*.md');
        $customerServiceStatApplyLogReviewPath = $this->customerServiceStatApplyLogReviewPath !== ''
            ? $this->resolvePath($this->customerServiceStatApplyLogReviewPath)
            : $this->latestHandoverFile('mongoyia-customer-service-stat-apply-log-review-*.md');
        $customerServiceComplaintExportPath = $this->customerServiceComplaintExportPath !== ''
            ? $this->resolvePath($this->customerServiceComplaintExportPath)
            : $this->latestHandoverFile('mongoyia-customer-service-complaint-export-*.md');
        $customerServiceComplaintEvidenceGatePath = $this->customerServiceComplaintEvidenceGatePath !== ''
            ? $this->resolvePath($this->customerServiceComplaintEvidenceGatePath)
            : $this->latestHandoverFile('mongoyia-customer-service-complaint-evidence-gate-*.md');
        $customerServiceComplaintEvidenceUploadPolicyGatePath = $this->customerServiceComplaintEvidenceUploadPolicyGatePath !== ''
            ? $this->resolvePath($this->customerServiceComplaintEvidenceUploadPolicyGatePath)
            : $this->latestHandoverFile('mongoyia-customer-service-complaint-evidence-upload-policy-gate-*.md');
        $customerServiceComplaintEvidenceUploadImplementationGatePath = $this->customerServiceComplaintEvidenceUploadImplementationGatePath !== ''
            ? $this->resolvePath($this->customerServiceComplaintEvidenceUploadImplementationGatePath)
            : $this->latestHandoverFile('mongoyia-customer-service-complaint-evidence-upload-implementation-gate-*.md');
        $customerServiceComplaintEvidenceUploadCleanupReadinessPath = $this->customerServiceComplaintEvidenceUploadCleanupReadinessPath !== ''
            ? $this->resolvePath($this->customerServiceComplaintEvidenceUploadCleanupReadinessPath)
            : $this->latestHandoverFile('mongoyia-customer-service-complaint-evidence-upload-cleanup-readiness-*.md');
        $customerServiceComplaintEvidenceUploadEnablementGatePath = $this->customerServiceComplaintEvidenceUploadEnablementGatePath !== ''
            ? $this->resolvePath($this->customerServiceComplaintEvidenceUploadEnablementGatePath)
            : $this->latestHandoverFile('mongoyia-customer-service-complaint-evidence-upload-enablement-gate-*.md');
        $customerServiceComplaintEvidenceApplyWorkflowPath = $this->customerServiceComplaintEvidenceApplyWorkflowPath !== ''
            ? $this->resolvePath($this->customerServiceComplaintEvidenceApplyWorkflowPath)
            : $this->latestHandoverFile('mongoyia-customer-service-complaint-evidence-apply-workflow-*.md');
        $customerServiceResolutionExportPath = $this->customerServiceResolutionExportPath !== ''
            ? $this->resolvePath($this->customerServiceResolutionExportPath)
            : $this->latestHandoverFile('mongoyia-customer-service-resolution-export-*.md');
        $customerServiceSlaReadinessPath = $this->customerServiceSlaReadinessPath !== ''
            ? $this->resolvePath($this->customerServiceSlaReadinessPath)
            : $this->latestHandoverFile('mongoyia-customer-service-sla-readiness-*.md');
        $customerServiceSlaHandlingPath = $this->customerServiceSlaHandlingPath !== ''
            ? $this->resolvePath($this->customerServiceSlaHandlingPath)
            : $this->latestHandoverFile('mongoyia-customer-service-sla-handling-*.md');
        $customerServiceResultSignoffPath = $this->customerServiceResultSignoffPath !== ''
            ? $this->resolvePath($this->customerServiceResultSignoffPath)
            : $this->latestHandoverFile('mongoyia-customer-service-result-signoff-*.md');

        $outputPath = $this->outputPath !== ''
            ? $this->resolvePath($this->outputPath)
            : $this->projectRoot() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'acceptance' . DIRECTORY_SEPARATOR . 'mongoyia-delivery-index-' . date('Ymd-His') . '.md';

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Delivery Index',
            '',
            '| Item | Value |',
            '|---|---|',
            '| Generated at | ' . date('Y-m-d H:i:s') . ' |',
            '| PHP/Yii source | funboot_K84jE/funboot |',
            '| Python IM source | im后端/im后端 |',
            '| Database baseline | outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql |',
            '| Local mall URL | ' . $this->localHttpUrl('8089', '/') . ' |',
            '| Local IM URL | ' . $this->localWsUrl('8767') . ' |',
            '| Latest acceptance report | ' . $this->displayPath($acceptancePath) . ' |',
            '| Latest signoff file | ' . $this->displayPath($signoffPath) . ' |',
            '| Latest risk register | ' . $this->displayPath($riskPath) . ' |',
            '| Latest PWA mobile UI evidence | ' . $this->displayPath($pwaEvidencePath) . ' |',
            '| PWA mobile UI evidence result | ' . $this->readReportResult($pwaEvidencePath) . ' |',
            '| Latest PWA offline/install readiness | ' . $this->displayPath($pwaOfflineReadinessPath) . ' |',
            '| PWA offline/install readiness result | ' . $this->readReportResult($pwaOfflineReadinessPath) . ' |',
            '| Latest PWA visual QA readiness | ' . $this->displayPath($pwaVisualQaPath) . ' |',
            '| PWA visual QA readiness result | ' . $this->readReportResult($pwaVisualQaPath) . ' |',
            '| Latest payment callback readiness | ' . $this->displayPath($paymentCallbackReadinessPath) . ' |',
            '| Payment callback readiness result | ' . $this->readReportResult($paymentCallbackReadinessPath) . ' |',
            '| Latest payment provider readiness | ' . $this->displayPath($paymentProviderReadinessPath) . ' |',
            '| Payment provider readiness result | ' . $this->readReportResult($paymentProviderReadinessPath) . ' |',
            '| Latest payment provider route skeleton gate | ' . $this->displayPath($paymentProviderRouteSkeletonGatePath) . ' |',
            '| Payment provider route skeleton gate result | ' . $this->readReportResult($paymentProviderRouteSkeletonGatePath) . ' |',
            '| Latest payment provider webhook dry-run gate | ' . $this->displayPath($paymentProviderWebhookDryRunGatePath) . ' |',
            '| Payment provider webhook dry-run gate result | ' . $this->readReportResult($paymentProviderWebhookDryRunGatePath) . ' |',
            '| Latest payment provider webhook verification dry-run gate | ' . $this->displayPath($paymentProviderWebhookVerificationDryRunGatePath) . ' |',
            '| Payment provider webhook verification dry-run gate result | ' . $this->readReportResult($paymentProviderWebhookVerificationDryRunGatePath) . ' |',
            '| Latest payment provider webhook audit dry-run | ' . $this->displayPath($paymentProviderWebhookAuditDryRunPath) . ' |',
            '| Payment provider webhook audit dry-run result | ' . $this->readReportResult($paymentProviderWebhookAuditDryRunPath) . ' |',
            '| Latest payment provider PayPal sandbox evidence gate | ' . $this->displayPath($paymentProviderPaypalSandboxEvidenceGatePath) . ' |',
            '| Payment provider PayPal sandbox evidence gate result | ' . $this->readReportResult($paymentProviderPaypalSandboxEvidenceGatePath) . ' |',
            '| Latest payment provider PayPal live audit write implementation gate | ' . $this->displayPath($paymentProviderPaypalLiveAuditWriteImplementationGatePath) . ' |',
            '| Payment provider PayPal live audit write implementation gate result | ' . $this->readReportResult($paymentProviderPaypalLiveAuditWriteImplementationGatePath) . ' |',
            '| Latest payment provider PayPal sandbox evidence signoff gate | ' . $this->displayPath($paymentProviderPaypalSandboxEvidenceSignoffGatePath) . ' |',
            '| Payment provider PayPal sandbox evidence signoff gate result | ' . $this->readReportResult($paymentProviderPaypalSandboxEvidenceSignoffGatePath) . ' |',
            '| Latest payment provider PayPal sandbox evidence manifest validator | ' . $this->displayPath($paymentProviderPaypalSandboxEvidenceManifestValidatorPath) . ' |',
            '| Payment provider PayPal sandbox evidence manifest validator result | ' . $this->readReportResult($paymentProviderPaypalSandboxEvidenceManifestValidatorPath) . ' |',
            '| Latest payment provider PayPal sandbox evidence redaction checklist | ' . $this->displayPath($paymentProviderPaypalSandboxEvidenceRedactionChecklistPath) . ' |',
            '| Payment provider PayPal sandbox evidence redaction checklist result | ' . $this->readReportResult($paymentProviderPaypalSandboxEvidenceRedactionChecklistPath) . ' |',
            '| Latest payment provider PayPal sandbox evidence bundle review readiness | ' . $this->displayPath($paymentProviderPaypalSandboxEvidenceBundleReviewReadinessPath) . ' |',
            '| Payment provider PayPal sandbox evidence bundle review readiness result | ' . $this->readReportResult($paymentProviderPaypalSandboxEvidenceBundleReviewReadinessPath) . ' |',
            '| Latest payment provider PayPal sandbox evidence bundle review signoff gate | ' . $this->displayPath($paymentProviderPaypalSandboxEvidenceBundleReviewSignoffGatePath) . ' |',
            '| Payment provider PayPal sandbox evidence bundle review signoff gate result | ' . $this->readReportResult($paymentProviderPaypalSandboxEvidenceBundleReviewSignoffGatePath) . ' |',
            '| Latest payment provider PayPal sandbox evidence signoff import dry-run | ' . $this->displayPath($paymentProviderPaypalSandboxEvidenceSignoffImportDryRunPath) . ' |',
            '| Payment provider PayPal sandbox evidence signoff import dry-run result | ' . $this->readReportResult($paymentProviderPaypalSandboxEvidenceSignoffImportDryRunPath) . ' |',
            '| Latest payment provider PayPal sandbox evidence review-result apply gate | ' . $this->displayPath($paymentProviderPaypalSandboxEvidenceReviewResultApplyGatePath) . ' |',
            '| Payment provider PayPal sandbox evidence review-result apply gate result | ' . $this->readReportResult($paymentProviderPaypalSandboxEvidenceReviewResultApplyGatePath) . ' |',
            '| Latest payment provider PayPal external evidence collection gate | ' . $this->displayPath($paymentProviderPaypalExternalEvidenceCollectionGatePath) . ' |',
            '| Payment provider PayPal external evidence collection gate result | ' . $this->readReportResult($paymentProviderPaypalExternalEvidenceCollectionGatePath) . ' |',
            '| Latest payment provider PayPal external evidence manifest import dry-run | ' . $this->displayPath($paymentProviderPaypalExternalEvidenceManifestImportDryRunPath) . ' |',
            '| Payment provider PayPal external evidence manifest import dry-run result | ' . $this->readReportResult($paymentProviderPaypalExternalEvidenceManifestImportDryRunPath) . ' |',
            '| Latest payment provider PayPal external evidence manifest review readiness | ' . $this->displayPath($paymentProviderPaypalExternalEvidenceManifestReviewReadinessPath) . ' |',
            '| Payment provider PayPal external evidence manifest review readiness result | ' . $this->readReportResult($paymentProviderPaypalExternalEvidenceManifestReviewReadinessPath) . ' |',
            '| Latest payment provider PayPal external evidence manifest review signoff import dry-run | ' . $this->displayPath($paymentProviderPaypalExternalEvidenceManifestReviewSignoffImportDryRunPath) . ' |',
            '| Payment provider PayPal external evidence manifest review signoff import dry-run result | ' . $this->readReportResult($paymentProviderPaypalExternalEvidenceManifestReviewSignoffImportDryRunPath) . ' |',
            '| Latest payment provider PayPal external evidence manifest review-result apply gate | ' . $this->displayPath($paymentProviderPaypalExternalEvidenceManifestReviewResultApplyGatePath) . ' |',
            '| Payment provider PayPal external evidence manifest review-result apply gate result | ' . $this->readReportResult($paymentProviderPaypalExternalEvidenceManifestReviewResultApplyGatePath) . ' |',
            '| Latest payment provider PayPal live provider implementation evidence dry-run | ' . $this->displayPath($paymentProviderPaypalLiveProviderImplementationEvidenceDryRunPath) . ' |',
            '| Payment provider PayPal live provider implementation evidence dry-run result | ' . $this->readReportResult($paymentProviderPaypalLiveProviderImplementationEvidenceDryRunPath) . ' |',
            '| Latest payment provider PayPal live provider implementation evidence signoff gate | ' . $this->displayPath($paymentProviderPaypalLiveProviderImplementationEvidenceSignoffGatePath) . ' |',
            '| Payment provider PayPal live provider implementation evidence signoff gate result | ' . $this->readReportResult($paymentProviderPaypalLiveProviderImplementationEvidenceSignoffGatePath) . ' |',
            '| Latest payment provider PayPal live execution evidence readiness gate | ' . $this->displayPath($paymentProviderPaypalLiveExecutionEvidenceReadinessGatePath) . ' |',
            '| Payment provider PayPal live execution evidence readiness gate result | ' . $this->readReportResult($paymentProviderPaypalLiveExecutionEvidenceReadinessGatePath) . ' |',
            '| Latest payment provider PayPal live execution evidence signoff import dry-run | ' . $this->displayPath($paymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunPath) . ' |',
            '| Payment provider PayPal live execution evidence signoff import dry-run result | ' . $this->readReportResult($paymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunPath) . ' |',
            '| Latest payment provider live verification enablement gate | ' . $this->displayPath($paymentProviderLiveVerificationEnablementGatePath) . ' |',
            '| Payment provider live verification enablement gate result | ' . $this->readReportResult($paymentProviderLiveVerificationEnablementGatePath) . ' |',
            '| Latest payment provider PayPal final go/no-go gate | ' . $this->displayPath($paymentProviderPaypalFinalGoNoGoGatePath) . ' |',
            '| Payment provider PayPal final go/no-go gate result | ' . $this->readReportResult($paymentProviderPaypalFinalGoNoGoGatePath) . ' |',
            '| Latest production health | ' . $this->displayPath($productionHealthPath) . ' |',
            '| Production health result | ' . $this->readReportResult($productionHealthPath) . ' |',
            '| Latest production monitor | ' . $this->displayPath($productionMonitorPath) . ' |',
            '| Production monitor result | ' . $this->readReportResult($productionMonitorPath) . ' |',
            '| Latest production backup verify evidence | ' . $this->displayPath($productionBackupVerifyEvidencePath) . ' |',
            '| Production backup verify evidence result | ' . $this->readReportResult($productionBackupVerifyEvidencePath) . ' |',
            '| Latest production scheduled-check evidence | ' . $this->displayPath($productionScheduledCheckEvidencePath) . ' |',
            '| Production scheduled-check evidence result | ' . $this->readReportResult($productionScheduledCheckEvidencePath) . ' |',
            '| Latest production load-test evidence | ' . $this->displayPath($productionLoadTestEvidencePath) . ' |',
            '| Production load-test evidence result | ' . $this->readReportResult($productionLoadTestEvidencePath) . ' |',
            '| Latest production evidence summary | ' . $this->displayPath($productionEvidenceSummaryPath) . ' |',
            '| Production evidence summary result | ' . $this->readReportResult($productionEvidenceSummaryPath) . ' |',
            '| Latest production external evidence import dry-run | ' . $this->displayPath($productionExternalEvidenceImportDryRunPath) . ' |',
            '| Production external evidence import dry-run result | ' . $this->readReportResult($productionExternalEvidenceImportDryRunPath) . ' |',
            '| Latest production external evidence review readiness | ' . $this->displayPath($productionExternalEvidenceReviewReadinessPath) . ' |',
            '| Production external evidence review readiness result | ' . $this->readReportResult($productionExternalEvidenceReviewReadinessPath) . ' |',
            '| Latest production external evidence review-result apply gate | ' . $this->displayPath($productionExternalEvidenceReviewResultApplyGatePath) . ' |',
            '| Production external evidence review-result apply gate result | ' . $this->readReportResult($productionExternalEvidenceReviewResultApplyGatePath) . ' |',
            '| Latest production external evidence final acceptance gate | ' . $this->displayPath($productionExternalEvidenceFinalAcceptanceGatePath) . ' |',
            '| Production external evidence final acceptance gate result | ' . $this->readReportResult($productionExternalEvidenceFinalAcceptanceGatePath) . ' |',
            '| Latest production launch signoff readiness gate | ' . $this->displayPath($productionLaunchSignoffReadinessGatePath) . ' |',
            '| Production launch signoff readiness gate result | ' . $this->readReportResult($productionLaunchSignoffReadinessGatePath) . ' |',
            '| Latest production go-live gate | ' . $this->displayPath($productionGoLiveGatePath) . ' |',
            '| Production go-live gate result | ' . $this->readReportResult($productionGoLiveGatePath) . ' |',
            '| Latest IM media readiness | ' . $this->displayPath($imMediaReadinessPath) . ' |',
            '| IM media readiness result | ' . $this->readReportResult($imMediaReadinessPath) . ' |',
            '| Latest IM media transport implementation gate | ' . $this->displayPath($imMediaTransportImplementationGatePath) . ' |',
            '| IM media transport implementation gate result | ' . $this->readReportResult($imMediaTransportImplementationGatePath) . ' |',
            '| Latest IM media transport policy gate | ' . $this->displayPath($imMediaTransportPolicyGatePath) . ' |',
            '| IM media transport policy gate result | ' . $this->readReportResult($imMediaTransportPolicyGatePath) . ' |',
            '| Latest IM media upload skeleton gate | ' . $this->displayPath($imMediaUploadSkeletonGatePath) . ' |',
            '| IM media upload skeleton gate result | ' . $this->readReportResult($imMediaUploadSkeletonGatePath) . ' |',
            '| Latest customer-service readiness | ' . $this->displayPath($customerServiceReadinessPath) . ' |',
            '| Customer-service readiness result | ' . $this->readReportResult($customerServiceReadinessPath) . ' |',
            '| Latest advanced customer-service readiness | ' . $this->displayPath($customerServiceAdvancedReadinessPath) . ' |',
            '| Advanced customer-service readiness result | ' . $this->readReportResult($customerServiceAdvancedReadinessPath) . ' |',
            '| Latest customer-service stat export | ' . $this->displayPath($customerServiceStatExportPath) . ' |',
            '| Customer-service stat export result | ' . $this->readReportResult($customerServiceStatExportPath) . ' |',
            '| Latest customer-service stat widget readiness | ' . $this->displayPath($customerServiceStatWidgetReadinessPath) . ' |',
            '| Customer-service stat widget readiness result | ' . $this->readReportResult($customerServiceStatWidgetReadinessPath) . ' |',
            '| Latest customer-service stat apply gate | ' . $this->displayPath($customerServiceStatApplyGatePath) . ' |',
            '| Customer-service stat apply gate result | ' . $this->readReportResult($customerServiceStatApplyGatePath) . ' |',
            '| Latest customer-service stat apply workflow | ' . $this->displayPath($customerServiceStatApplyWorkflowPath) . ' |',
            '| Customer-service stat apply workflow result | ' . $this->readReportResult($customerServiceStatApplyWorkflowPath) . ' |',
            '| Latest customer-service stat apply log review | ' . $this->displayPath($customerServiceStatApplyLogReviewPath) . ' |',
            '| Customer-service stat apply log review result | ' . $this->readReportResult($customerServiceStatApplyLogReviewPath) . ' |',
            '| Latest customer-service complaint export | ' . $this->displayPath($customerServiceComplaintExportPath) . ' |',
            '| Customer-service complaint export result | ' . $this->readReportResult($customerServiceComplaintExportPath) . ' |',
            '| Latest customer-service complaint evidence gate | ' . $this->displayPath($customerServiceComplaintEvidenceGatePath) . ' |',
            '| Customer-service complaint evidence gate result | ' . $this->readReportResult($customerServiceComplaintEvidenceGatePath) . ' |',
            '| Latest customer-service complaint evidence upload policy gate | ' . $this->displayPath($customerServiceComplaintEvidenceUploadPolicyGatePath) . ' |',
            '| Customer-service complaint evidence upload policy gate result | ' . $this->readReportResult($customerServiceComplaintEvidenceUploadPolicyGatePath) . ' |',
            '| Latest customer-service complaint evidence upload implementation gate | ' . $this->displayPath($customerServiceComplaintEvidenceUploadImplementationGatePath) . ' |',
            '| Customer-service complaint evidence upload implementation gate result | ' . $this->readReportResult($customerServiceComplaintEvidenceUploadImplementationGatePath) . ' |',
            '| Latest customer-service complaint evidence upload cleanup readiness | ' . $this->displayPath($customerServiceComplaintEvidenceUploadCleanupReadinessPath) . ' |',
            '| Customer-service complaint evidence upload cleanup readiness result | ' . $this->readReportResult($customerServiceComplaintEvidenceUploadCleanupReadinessPath) . ' |',
            '| Latest customer-service complaint evidence upload enablement gate | ' . $this->displayPath($customerServiceComplaintEvidenceUploadEnablementGatePath) . ' |',
            '| Customer-service complaint evidence upload enablement gate result | ' . $this->readReportResult($customerServiceComplaintEvidenceUploadEnablementGatePath) . ' |',
            '| Latest customer-service complaint evidence apply workflow | ' . $this->displayPath($customerServiceComplaintEvidenceApplyWorkflowPath) . ' |',
            '| Customer-service complaint evidence apply workflow result | ' . $this->readReportResult($customerServiceComplaintEvidenceApplyWorkflowPath) . ' |',
            '| Latest customer-service resolution export | ' . $this->displayPath($customerServiceResolutionExportPath) . ' |',
            '| Customer-service resolution export result | ' . $this->readReportResult($customerServiceResolutionExportPath) . ' |',
            '| Latest customer-service SLA readiness | ' . $this->displayPath($customerServiceSlaReadinessPath) . ' |',
            '| Customer-service SLA readiness result | ' . $this->readReportResult($customerServiceSlaReadinessPath) . ' |',
            '| Latest customer-service SLA handling | ' . $this->displayPath($customerServiceSlaHandlingPath) . ' |',
            '| Customer-service SLA handling result | ' . $this->readReportResult($customerServiceSlaHandlingPath) . ' |',
            '| Latest customer-service result signoff | ' . $this->displayPath($customerServiceResultSignoffPath) . ' |',
            '| Customer-service result signoff result | ' . $this->readReportResult($customerServiceResultSignoffPath) . ' |',
            '',
            '## Start Here',
            '',
            '- `MONGOYIA_README.md`',
            '- `docs/mongoyia-cn-overview.md`',
            '- `docs/mongoyia-package-index.md`',
            '- `docs/mongoyia-delivery-status.md`',
            '- `docs/mongoyia-test-server-runbook.md`',
            '- `docs/mongoyia-deploy-checklist.md`',
            '- `docs/mongoyia-acceptance-signoff-template.md`',
            '- `docs/mongoyia-local-baseline.md`',
            '- `docs/mongoyia-manual-qa-checklist.md`',
            '- `docs/mongoyia-payment-provider-contract.md`',
            '- `docs/mongoyia-customer-service-contract.md`',
            '- `docs/mongoyia-im-media-contract.md`',
            '',
            '## Test Server Commands',
            '',
            '```bash',
            'php yii mongoyia-package-check/run --interactive=0',
            'php yii deploy-check/run --profile=test --strict=1 --interactive=0',
            'php yii mongoyia-acceptance/run --baseUrl=https://<test-domain> --profile=test --strict=1 --cleanupAfterRun=1 --interactive=0',
            'php yii pwa-smoke-test/run --baseUrl=https://<test-domain> --interactive=0',
            'php yii mongoyia-pwa-offline-readiness/run --baseUrl=https://<test-domain> --interactive=0',
            'php yii mongoyia-pwa-visual-qa/run --baseUrl=https://<test-domain> --interactive=0',
            'php yii payment-provider-readiness/run --baseUrl=https://<test-domain> --profile=test --interactive=0',
            'php yii payment-provider-route-skeleton-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-webhook-dry-run-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-webhook-verification-dry-run-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-webhook-audit-dry-run/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-sandbox-evidence-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-live-audit-write-implementation-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-sandbox-evidence-signoff-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-sandbox-evidence-manifest-validator/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-sandbox-evidence-redaction-checklist/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-sandbox-evidence-bundle-review-readiness/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-sandbox-evidence-bundle-review-signoff-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-sandbox-evidence-signoff-import-dry-run/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-sandbox-evidence-review-result-apply-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-external-evidence-collection-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-external-evidence-manifest-import-dry-run/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-external-evidence-manifest-review-readiness/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-external-evidence-manifest-review-signoff-import-dry-run/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-external-evidence-manifest-review-result-apply-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-live-provider-implementation-evidence-dry-run/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-live-provider-implementation-evidence-signoff-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-live-execution-evidence-readiness-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-live-execution-evidence-signoff-import-dry-run/run --fixture=1 --interactive=0',
            'php yii payment-provider-live-verification-enablement-gate/run --fixture=1 --interactive=0',
            'php yii payment-provider-paypal-final-go-no-go-gate/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-health/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-monitor/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-backup-verify-evidence/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-scheduled-check-evidence/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-load-test-evidence/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-evidence-summary/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-external-evidence-import-dry-run/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-external-evidence-review-readiness/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-external-evidence-review-result-apply-gate/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-external-evidence-final-acceptance-gate/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-launch-signoff-readiness-gate/run --fixture=1 --interactive=0',
            'php yii mongoyia-production-go-live-gate/run --fixture=1 --interactive=0',
            'php yii mongoyia-im-media-readiness/run --baseUrl=https://<test-domain> --interactive=0',
            'php yii mongoyia-im-media-transport-implementation-gate/run --fixture=1 --interactive=0',
            'php yii mongoyia-im-media-transport-policy-gate/run --fixture=1 --interactive=0',
            'php yii mongoyia-im-media-upload-skeleton-gate/run --baseUrl=https://<test-domain> --fixture=1 --interactive=0',
            'php yii customer-service-test/run --baseUrl=https://<test-domain> --interactive=0',
            'php yii customer-service-advanced-readiness/run --baseUrl=https://<test-domain> --profile=test --interactive=0',
            'php yii customer-service-ticket-readonly-test/run --interactive=0',
            'php yii customer-service-ticket-create-test/run --interactive=0',
            'php yii customer-service-ticket-note-test/run --interactive=0',
            'php yii customer-service-ticket-assign-test/run --interactive=0',
            'php yii customer-service-ticket-workflow-test/run --interactive=0',
            'php yii customer-service-stat-export/run --fixture=1 --interactive=0',
            'php yii customer-service-stat-widget-readiness/run --fixture=1 --interactive=0',
            'php yii customer-service-stat-apply-gate/run --fixture=1 --interactive=0',
            'php yii customer-service-stat-apply-workflow/run --fixture=1 --interactive=0',
            'php yii customer-service-stat-apply-log-review/run --fixture=1 --interactive=0',
            'php yii customer-service-complaint-export/run --fixture=1 --interactive=0',
            'php yii customer-service-complaint-evidence-gate/run --fixture=1 --interactive=0',
            'php yii customer-service-complaint-evidence-upload-policy-gate/run --fixture=1 --interactive=0',
            'php yii customer-service-complaint-evidence-upload-implementation-gate/run --fixture=1 --interactive=0',
            'php yii customer-service-complaint-evidence-upload-cleanup-readiness/run --fixture=1 --interactive=0',
            'php yii customer-service-complaint-evidence-upload-enablement-gate/run --fixture=1 --interactive=0',
            'php yii customer-service-complaint-evidence-apply-workflow/run --fixture=1 --interactive=0',
            'php yii customer-service-resolution-export/run --fixture=1 --interactive=0',
            'php yii customer-service-sla-readiness/run --fixture=1 --interactive=0',
            'php yii customer-service-sla-handling/run --fixture=1 --interactive=0',
            'php yii customer-service-result-signoff/run --fixture=1 --interactive=0',
            'php yii mongoyia-signoff/run --interactive=0',
            'php yii mongoyia-risk-register/run --interactive=0',
            'php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0',
            'powershell -ExecutionPolicy Bypass -File console/shell/mongoyia-archive-handover.ps1',
            '```',
            '',
            '## Wrapper Scripts',
            '',
            '- `console/shell/mongoyia-test-profile-preflight.ps1`',
            '- `console/shell/mongoyia-test-profile-preflight.sh`',
            '- `console/shell/mongoyia-acceptance.ps1`',
            '- `console/shell/mongoyia-acceptance.sh`',
            '- `console/shell/mongoyia-final-handover.ps1`',
            '- `console/shell/mongoyia-final-handover.sh`',
            '- `console/shell/mongoyia-archive-handover.ps1`',
            '- `console/shell/mongoyia-archive-handover.sh`',
            '',
            '## Scope Boundary',
            '',
            '- This package is prepared for test-server acceptance.',
            '- It is not a final production launch package.',
            '- Production still needs provider credential confirmation, monitoring, backups, reconciliation, settlement, manual translation QA, IM load testing, and final business signoff.',
            '',
        ];

        file_put_contents($outputPath, implode("\n", $lines));
        $this->stdout("Delivery index written to {$outputPath}\n");
        return ExitCode::OK;
    }

    private function latestAcceptanceFile(string $pattern)
    {
        return $this->latestFileIn('runtime' . DIRECTORY_SEPARATOR . 'acceptance', $pattern);
    }

    private function latestHandoverFile(string $pattern)
    {
        return $this->latestFileIn('runtime' . DIRECTORY_SEPARATOR . 'handover', $pattern);
    }

    private function latestFileIn(string $relativeDir, string $pattern)
    {
        $files = glob($this->projectRoot() . DIRECTORY_SEPARATOR . $relativeDir . DIRECTORY_SEPARATOR . $pattern);
        if (!$files) {
            return '';
        }

        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0];
    }

    private function readReportResult(string $path)
    {
        if ($path === '' || !is_file($path)) {
            return 'not generated';
        }

        $content = file_get_contents($path);
        if ($content !== false && preg_match('/^- Result:\s*([A-Z]+)\s*$/m', $content, $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }

    private function displayPath(string $path)
    {
        return $path !== '' ? $this->relativePath($path) : 'not generated';
    }

    private function localHttpUrl(string $port, string $path = '')
    {
        return 'http://' . '127.0.0.1' . ':' . $port . $path;
    }

    private function localWsUrl(string $port)
    {
        return 'ws://' . '127.0.0.1' . ':' . $port;
    }

    private function resolvePath(string $path)
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function relativePath(string $path)
    {
        $root = rtrim($this->projectRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $root) ? str_replace('\\', '/', substr($path, strlen($root))) : $path;
    }

    private function projectRoot()
    {
        return dirname(__DIR__, 2);
    }
}
