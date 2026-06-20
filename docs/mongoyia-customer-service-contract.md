# Mongoyia Customer Service Contract

Contract version: 2026-06-19-customer-service-v1

MONGOYIA_CUSTOMER_SERVICE_CONTRACT_V1

## Current Runtime Scope

The current customer-service runtime supports the seller/platform chat workbench, signed IM authentication, product/store chat context, text messages, and image upload through the existing chat upload endpoint.

Stable runtime markers:

- Backend route: `/backend/mall/kf/index`
- Backend readonly ticket routes: `/backend/mall/kf/tickets`, `/backend/mall/kf/ticket-view?id=<ticket_id>`
- Backend ticket create route: `/backend/mall/kf/ticket-create`
- Backend ticket note route: `/backend/mall/kf/ticket-note`
- Backend ticket result route: `/backend/mall/kf/ticket-result`
- Backend ticket assign route: `/backend/mall/kf/ticket-assign`
- Backend ticket workflow route: `/backend/mall/kf/ticket-workflow`
- Backend customer-service stat export route: `/backend/mall/kf/stat-export`
- Backend customer-service stat widget readiness marker: `/backend/mall/kf/tickets`
- Backend customer-service stat apply gate marker: `/backend/mall/kf/tickets`
- Backend customer-service stat apply workflow marker: `/backend/mall/kf/tickets`
- Backend customer-service stat apply log review route: `/backend/mall/kf/stat-apply-log`
- Backend customer-service complaint export route: `/backend/mall/kf/complaint-export`
- Backend customer-service complaint evidence gate marker: `/backend/mall/kf/ticket-view?id=<ticket_id>`
- Backend customer-service resolution export route: `/backend/mall/kf/resolution-export`
- Backend customer-service SLA readiness route: `/backend/mall/kf/sla-readiness`
- Backend customer-service SLA handling route: `/backend/mall/kf/sla-handling`
- Backend customer-service result signoff route: `/backend/mall/kf/result-signoff`
- Frontend route: `/mall/chat/index?gid=<product_id>`
- Backend identity markers: `userType`, `userId`, `isPlatformOperator`, `storeMap`, `authToken`
- Transport markers: `wsAddress`, `uploadUrl`, `connect();`
- Context markers: `product_id`, `store_id`, `formatChatContext`
- Frontend mobile marker: `data-mongoyia-mobile-ui="chat"`

## Reserved Future Scope

The following capabilities beyond ticket creation/note/result/assignment/status workflow are reserved until their database fields, permissions, UI controls, regression commands, and cleanup rules land together.

MONGOYIA_CUSTOMER_SERVICE_ADVANCED_SCHEMA_V1

The advanced service workflow starts as a migration/readiness contract. The schema can be staged before broader runtime UI is enabled, and test/prod acceptance must verify the migration is applied before advanced service workflows are signed off. Local readiness may validate the migration file and dry-run plan without applying the schema.

MONGOYIA_CUSTOMER_SERVICE_ADVANCED_DRY_RUN_SERVICE_V1

Service: `common/services/mall/CustomerServiceAdvancedService.php`

- Purpose: centralize ticket types, status names, priority names, event types, and dry-run row generation before runtime UI is enabled.
- Required outputs: ticket draft for `order_assist`, ticket draft for `complaint`, audit event draft, daily stat draft.
- Boundary: dry-run service must not write database rows, open WSS, upload files, or mutate orders.

MONGOYIA_CUSTOMER_SERVICE_ADVANCED_WORKFLOW_DRY_RUN_V1

- Purpose: preflight the advanced ticket lifecycle before runtime workflows are exposed.
- Required transitions: `pending -> in_progress`, `in_progress -> resolved`.
- Required outputs: status-change event drafts, ticket status update drafts, daily stat update draft.
- Boundary: workflow dry-run must not change tickets, orders, chats, payments, or statistics.

MONGOYIA_CUSTOMER_SERVICE_TICKET_READONLY_V1

- Purpose: provide a backend review view of staged customer-service tickets, events, and daily statistics.
- Required routes: `/backend/mall/kf/tickets`, `/backend/mall/kf/ticket-view?id=<ticket_id>`.
- Required scope: platform users can review all stores; seller users can only review their own store.
- Boundary: list and data views must not mutate orders, send IM messages, upload files, or expose staff-assisted order mutation/stat action widgets. Ticket creation, note append, result writeback, handler assignment, and ticket status workflow are the only supported write actions.

MONGOYIA_CUSTOMER_SERVICE_TICKET_CREATE_BACKEND_V1

Service: `common/services/mall/CustomerServiceTicketCreateService.php`

- Purpose: provide a permission-protected backend form for creating pending order-assist or complaint tickets.
- Required route: `/backend/mall/kf/ticket-create`.
- Required permission: `/mall/kf/ticket-create`.
- Required audit: insert one `mall_customer_service_ticket` row and one `mall_customer_service_event` row with `event_type=create`.
- Required guards: store scope must block other-store creates, unsupported ticket types must be rejected, title is required, and active duplicate tickets for the same `store_id + order_id + ticket_type` must be blocked.
- Boundary: ticket create must not mutate orders, payments, chats, IM messages, files, shipments, funds, or statistics.

MONGOYIA_CUSTOMER_SERVICE_TICKET_NOTE_BACKEND_V1

Service: `common/services/mall/CustomerServiceTicketNoteService.php`

- Purpose: provide a permission-protected backend form for appending internal processing notes to a customer-service ticket.
- Required route: `/backend/mall/kf/ticket-note`.
- Required permission: `/mall/kf/ticket-note`.
- Required audit: update only ticket `updated_at/updated_by` and append one `mall_customer_service_event` row with `event_type=note`.
- Required guards: ticket id is required, content is required, and store scope must block other-store notes.
- Boundary: ticket note must not mutate ticket status, orders, payments, chats, IM messages, files, shipments, funds, or statistics.

MONGOYIA_CUSTOMER_SERVICE_TICKET_RESULT_BACKEND_V1

Service: `common/services/mall/CustomerServiceTicketResultService.php`

- Purpose: provide a permission-protected backend form for writing back a ticket handling result with an audit event.
- Required route: `/backend/mall/kf/ticket-result`.
- Required permission: `/mall/kf/ticket-result`.
- Required audit: update only ticket `result`, `updated_at`, and `updated_by`, and append one `mall_customer_service_event` row with `event_type=note` and `metadata_json.source=customer-service-ticket-result`.
- Required guards: ticket id is required, result text is required, unchanged result text is blocked, and store scope must block other-store result writes.
- Boundary: ticket result writeback must not mutate ticket status, orders, payments, chats, IM messages, files, shipments, funds, or statistics.

MONGOYIA_CUSTOMER_SERVICE_TICKET_ASSIGN_BACKEND_V1

Service: `common/services/mall/CustomerServiceTicketAssignService.php`

- Purpose: provide a permission-protected backend form for assigning or changing merchant/platform ticket handlers.
- Required route: `/backend/mall/kf/ticket-assign`.
- Required permission: `/mall/kf/ticket-assign`.
- Required audit: update only ticket `merchant_user_id` or `platform_user_id` plus `updated_at/updated_by`, and append one `mall_customer_service_event` row with `event_type=note`.
- Required guards: ticket id is required, assignment type must be `merchant` or `platform`, assignee user id is required, seller users can only assign merchant handlers, and store scope must block other-store assignment.
- Boundary: ticket assignment must not mutate ticket status, orders, payments, chats, IM messages, files, shipments, funds, or statistics.

MONGOYIA_CUSTOMER_SERVICE_TICKET_WORKFLOW_SERVICE_V1

Service: `common/services/mall/CustomerServiceTicketWorkflowService.php`

- Purpose: provide a permission-protected backend status workflow with command-verified service rules.
- Required route: `/backend/mall/kf/ticket-workflow`.
- Required transitions: `pending -> in_progress`, `in_progress -> resolved`, `resolved -> closed`.
- Required audit: update `first_response_at`, `resolved_at`, or `closed_at` as applicable, and append a `status_change` event row.
- Required scope: optional store scope must block other-store tickets.
- Boundary: workflow service must not mutate orders, payments, chats, IM messages, files, or shipment data.

MONGOYIA_CUSTOMER_SERVICE_STAT_EXPORT_BACKEND_V1

Service: `common/services/mall/CustomerServiceStatExportService.php`

- Purpose: provide a permission-protected backend CSV export and command-generated Markdown/CSV evidence for customer-service daily statistics.
- Required route: `/backend/mall/kf/stat-export`.
- Required permission: `/mall/kf/stat-export`.
- Required scope: platform users can export all stores or a selected store; seller users can only export their own store.
- Required outputs: daily stat rows, totals for sessions/tickets/order assists/complaints/resolved/unresolved, and first-response/resolution seconds.
- Boundary: stat export must not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, or update statistics.

MONGOYIA_CUSTOMER_SERVICE_STAT_WIDGET_READINESS_V1

Service: `common/services/mall/CustomerServiceStatWidgetReadinessService.php`

- Purpose: provide customer-service stat widget readiness evidence before any statistic write widget is enabled.
- Required backend marker: `/backend/mall/kf/tickets` must expose `data-mongoyia-customer-service-stat-widget-readiness="reserved"` and a disabled apply marker.
- Required command: `customer-service-stat-widget-readiness/run --fixture=1`.
- Required outputs: daily totals, store scope, ticket mix, resolution rate, response-time readiness, and reserved write-workflow status.
- Boundary: statistic write widgets remain disabled. This readiness must not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or enable statistic write widgets.

MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_GATE_V1

Service: `common/services/mall/CustomerServiceStatApplyGateService.php`

- Purpose: provide a dry-run apply gate before customer-service daily statistics are rebuilt or upserted.
- Required backend marker: `/backend/mall/kf/tickets` must expose `data-mongoyia-customer-service-stat-apply-gate="reserved"` and a disabled apply marker.
- Required command: `customer-service-stat-apply-gate/run --fixture=1`.
- Required outputs: source-ticket aggregation, insert/update/skip plan rows, diff summary, reserved audit-event gate, and reserved write-handler gate.
- Boundary: statistic write handling remains disabled. This gate must not create tickets, mutate ticket workflow state, write customer-service statistics, send IM messages, upload files, change orders, change payments, write fund logs, or enable statistic apply handling.

MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_WORKFLOW_V1

Service: `common/services/mall/CustomerServiceStatApplyWorkflowService.php`

- Purpose: provide an audited CLI workflow for customer-service daily statistic rebuild/upsert after the dry-run gate is reviewed.
- Required audit table: `mall_customer_service_stat_apply_log`.
- Required backend marker: `/backend/mall/kf/tickets` must expose `data-mongoyia-customer-service-stat-apply-workflow="reserved"` and keep the backend apply marker disabled.
- Required command: `customer-service-stat-apply-workflow/run --fixture=1`.
- Required outputs: dry-run plan rows, explicit apply mode, batch number, operator id, insert/update/skip audit rows, before/after JSON, and Markdown/CSV evidence.
- Boundary: default command mode is dry-run. Apply mode must be explicit and must not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, or enable backend statistic apply controls.

MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_LOG_REVIEW_V1

Service: `common/services/mall/CustomerServiceStatApplyLogReviewService.php`

- Purpose: provide a permission-protected backend readonly review and command-generated Markdown/CSV evidence for customer-service statistic apply audit logs.
- Required route: `/backend/mall/kf/stat-apply-log`.
- Required permission: `/mall/kf/stat-apply-log`.
- Required command: `customer-service-stat-apply-log-review/run --fixture=1`.
- Required scope: platform users can review all stores or a selected store; seller users can only review their own store.
- Required outputs: audit rows, batch/store/operator totals, insert/update/skip totals, source-ticket totals, diff summaries, and store/batch/operation filters.
- Boundary: apply log review must not create tickets, mutate ticket workflow state, write customer-service statistics, send IM messages, upload files, change orders, change payments, write fund logs, or enable backend statistic apply controls.

MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EXPORT_BACKEND_V1

Service: `common/services/mall/CustomerServiceComplaintExportService.php`

- Purpose: provide a permission-protected backend CSV export and command-generated Markdown/CSV evidence for complaint tickets.
- Required route: `/backend/mall/kf/complaint-export`.
- Required permission: `/mall/kf/complaint-export`.
- Required scope: platform users can export all stores or a selected store; seller users can only export their own store.
- Required outputs: complaint ticket rows, status totals, evidence JSON presence, event counts, and resolution seconds.
- Boundary: complaint export must not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or change complaint evidence JSON.

MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_GATE_V1

Service: `common/services/mall/CustomerServiceComplaintEvidenceGateService.php`

- Purpose: provide a readiness gate before complaint evidence upload or evidence_json write handling is enabled.
- Required backend marker: `/backend/mall/kf/ticket-view` must expose `data-mongoyia-customer-service-complaint-evidence-gate="reserved"` and a disabled apply marker.
- Required command: `customer-service-complaint-evidence-gate/run --fixture=1`.
- Required outputs: valid evidence JSON, missing evidence, invalid evidence JSON, upload-required, repair-required, manual-review buckets, and reserved upload/write gate checks.
- Boundary: complaint evidence write handling remains disabled. This gate must not upload files, create tickets, mutate ticket workflow state, write complaint evidence JSON, send IM messages, change orders, change payments, write fund logs, update statistics, or enable complaint evidence write handling.

MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_POLICY_GATE_V1

Service: `common/services/mall/CustomerServiceComplaintEvidenceUploadPolicyGateService.php`

- Purpose: provide a customer-service complaint evidence upload policy gate before backend file upload controls are implemented.
- Required backend marker: `/backend/mall/kf/ticket-view` must expose `MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_POLICY_GATE_V1` while keeping complaint evidence upload/write controls disabled.
- Required command: `customer-service-complaint-evidence-upload-policy-gate/run --fixture=1`.
- Required first enablement file policy: png, jpg/jpeg, and webp images up to 5 MB.
- Reserved policy: pdf, video, audio, unknown MIME, oversized files, and path-traversal filenames remain blocked until storage, antivirus, preview, retention, audit, and cleanup rules land together.
- Boundary: this policy gate must not upload files, write evidence_json, create tickets, append events, mutate ticket workflow state, send IM messages, change orders, change payments, write fund logs, update statistics, or enable backend complaint evidence controls.

MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_IMPLEMENTATION_GATE_V1

Service: `common/services/mall/CustomerServiceComplaintEvidenceUploadImplementationGateService.php`

- Purpose: provide a customer-service complaint evidence upload implementation gate before backend upload controls are enabled.
- Required backend marker: `/backend/mall/kf/ticket-view` must expose `MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_IMPLEMENTATION_GATE_V1` while keeping complaint evidence upload/write controls disabled.
- Required command: `customer-service-complaint-evidence-upload-implementation-gate/run --fixture=1`.
- Required storage rule: storage root must stay outside the public web root, under a runtime complaint-evidence area.
- Required storage key rule: storage keys must include ticket id, date, sha256, and extension without trusting the original file path.
- Required audit rule: future upload apply must append one customer-service event row, preserve ticket workflow status, and include metadata source `customer-service-complaint-evidence-upload`.
- Required cleanup rule: generated fixture/tmp evidence paths must have cleanup coverage before backend controls are enabled.
- Boundary: this implementation gate must not upload files, create directories, write evidence_json, create tickets, append events, mutate ticket workflow state, send IM messages, change orders, change payments, write fund logs, update statistics, or enable backend complaint evidence controls.

MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_CLEANUP_READINESS_V1

Service: `common/services/mall/CustomerServiceComplaintEvidenceUploadCleanupReadinessService.php`

- Purpose: provide customer-service complaint evidence upload cleanup readiness before backend upload or cleanup controls are enabled.
- Required backend marker: `/backend/mall/kf/ticket-view` must expose `MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_CLEANUP_READINESS_V1` while keeping complaint evidence upload/write controls disabled.
- Required command: `customer-service-complaint-evidence-upload-cleanup-readiness/run --fixture=1`.
- Required cleanup scope: only generated `fixture-*` and `tmp-*` complaint-evidence paths are in cleanup scope.
- Required exclusion scope: reviewed ticket evidence paths, public web uploads, and handover reports are outside cleanup scope.
- Required apply guard: future destructive cleanup must run dry-run first and require `COMPLAINT_EVIDENCE_CLEANUP_APPLY`.
- Boundary: this cleanup readiness gate must not upload files, create directories, delete files, write evidence_json, create tickets, append events, mutate ticket workflow state, send IM messages, change orders, change payments, write fund logs, update statistics, or enable backend complaint evidence controls.

MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_ENABLEMENT_GATE_V1

Service: `common/services/mall/CustomerServiceComplaintEvidenceUploadEnablementGateService.php`

- Purpose: provide a customer-service complaint evidence upload enablement gate before real backend upload controls are exposed.
- Required backend marker: `/backend/mall/kf/ticket-view` must expose `MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_ENABLEMENT_GATE_V1` and `data-mongoyia-customer-service-complaint-evidence-upload="disabled"` while keeping file inputs and upload forms absent.
- Required command: `customer-service-complaint-evidence-upload-enablement-gate/run --fixture=1`.
- Required future permission: `/mall/kf/complaint-evidence-upload`.
- Required preconditions: upload policy gate, implementation gate, cleanup readiness, and audited evidence apply workflow must pass before enablement.
- Required backend action contract: future upload POST must be store-scoped, CSRF-protected, audit one event row, preserve ticket status, and leave `evidence_json` unchanged on validation or storage failure.
- Boundary: this enablement gate must not upload files, create directories, delete files, write evidence_json, create tickets, append events, mutate ticket workflow state, send IM messages, change orders, change payments, write fund logs, update statistics, or enable backend complaint evidence controls.

MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_APPLY_WORKFLOW_V1

Service: `common/services/mall/CustomerServiceComplaintEvidenceApplyWorkflowService.php`

- Purpose: provide a customer-service complaint evidence apply workflow for already-reviewed complaint evidence JSON; the workflow is CLI-only and audited.
- Required backend marker: `/backend/mall/kf/ticket-view` must expose `MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_APPLY_WORKFLOW_V1` while keeping `data-mongoyia-customer-service-complaint-evidence-apply="disabled"`.
- Required command: `customer-service-complaint-evidence-apply-workflow/run --fixture=1`.
- Required apply guard: real writes require `--apply=1 --confirmApply=COMPLAINT_EVIDENCE_APPLY`; default mode is dry-run.
- Required outputs: old/new evidence status, normalized evidence length, written count, event audit ID, and Markdown/CSV evidence files.
- Boundary: apply mode writes only `mall_customer_service_ticket.evidence_json` plus one `mall_customer_service_event` audit row. It must not upload files, create tickets, mutate ticket workflow status, send IM messages, change orders, change payments, write fund logs, update statistics, or enable backend complaint evidence write controls.

MONGOYIA_CUSTOMER_SERVICE_RESOLUTION_EXPORT_BACKEND_V1

Service: `common/services/mall/CustomerServiceResolutionExportService.php`

- Purpose: provide a permission-protected backend CSV export and command-generated Markdown/CSV evidence for resolved or closed customer-service tickets.
- Required route: `/backend/mall/kf/resolution-export`.
- Required permission: `/mall/kf/resolution-export`.
- Required scope: platform users can export all stores or a selected store; seller users can only export their own store.
- Required outputs: resolved/closed ticket rows, order-assist and complaint counts, status-change event counts, resolution seconds, and result-field presence.
- Boundary: resolution export must not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or change ticket result fields.

MONGOYIA_CUSTOMER_SERVICE_SLA_READINESS_BACKEND_V1

Service: `common/services/mall/CustomerServiceSlaReadinessService.php`

- Purpose: provide a permission-protected backend CSV export and command-generated Markdown/CSV evidence for customer-service SLA readiness.
- Required route: `/backend/mall/kf/sla-readiness`.
- Required permission: `/mall/kf/sla-readiness`.
- Required scope: platform users can export all stores or a selected store; seller users can only export their own store.
- Required outputs: ticket rows, open/resolved/closed status totals, first-response SLA breaches, resolution SLA breaches, and resolved/closed rows missing result text.
- Boundary: SLA readiness must not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or change ticket SLA/result fields.

MONGOYIA_CUSTOMER_SERVICE_SLA_HANDLING_BACKEND_V1

Service: `common/services/mall/CustomerServiceSlaHandlingService.php`

- Purpose: provide a permission-protected backend CSV export and command-generated Markdown/CSV dry-run report for customer-service SLA handling suggestions.
- Required route: `/backend/mall/kf/sla-handling`.
- Required permission: `/mall/kf/sla-handling`.
- Required scope: platform users can export all stores or a selected store; seller users can only export their own store.
- Required outputs: ticket rows grouped by `first_response_overdue`, `resolution_overdue`, `result_writeback_required`, `first_response_watch`, `resolution_watch`, or `no_action`.
- Boundary: SLA handling must not create tickets, mutate ticket workflow state, write ticket results, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or run automatic SLA handling.

MONGOYIA_CUSTOMER_SERVICE_RESULT_SIGNOFF_BACKEND_V1

Service: `common/services/mall/CustomerServiceResultSignoffService.php`

- Purpose: provide a permission-protected backend CSV export and command-generated Markdown/CSV evidence for result writeback signoff planning.
- Required route: `/backend/mall/kf/result-signoff`.
- Required permission: `/mall/kf/result-signoff`.
- Required scope: platform users can export all stores or a selected store; seller users can only export their own store.
- Required outputs: ticket rows grouped as `ready_for_signoff`, `needs_result_writeback`, `premature_result_review`, or `continue_workflow`.
- Boundary: result signoff must not create tickets, mutate ticket workflow state, write ticket results, send IM messages, upload files, change orders, change payments, write fund logs, or update statistics.

MONGOYIA_CUSTOMER_SERVICE_TICKET_SCHEMA_V1

Table: `mall_customer_service_ticket`

- Purpose: store order-assist and complaint tickets created from customer-service sessions.
- Required keys: `ticket_sn`, `ticket_type`, `ticket_status`, `priority`, `store_id`, `product_id`, `order_id`, `customer_user_id`, `customer_uuid`, `merchant_user_id`, `platform_user_id`, `chat_uuid`.
- Required audit fields: `first_response_at`, `resolved_at`, `closed_at`, `created_at`, `updated_at`, `created_by`, `updated_by`.

MONGOYIA_CUSTOMER_SERVICE_EVENT_SCHEMA_V1

Table: `mall_customer_service_event`

- Purpose: append-only workflow notes and status transition records for customer-service tickets.
- Required keys: `ticket_id`, `event_type`, `from_status`, `to_status`, `operator_user_id`, `operator_type`, `metadata_json`.
- This table must be used before any staff-assisted order action can mutate business data.

MONGOYIA_CUSTOMER_SERVICE_STAT_DAILY_SCHEMA_V1

Table: `mall_customer_service_stat_daily`

- Purpose: daily service counters for workbench reporting.
- Required keys: `stat_date`, `store_id`, `service_user_id`, `session_count`, `ticket_count`, `order_assist_count`, `complaint_count`, `resolved_count`, `unresolved_count`, `first_response_seconds_total`, `resolved_seconds_total`.
- Unique grain: `stat_date + store_id + service_user_id`.

MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_LOG_SCHEMA_V1

Table: `mall_customer_service_stat_apply_log`

- Purpose: append audit evidence for every customer-service statistic apply workflow row.
- Required keys: `batch_sn`, `stat_date`, `store_id`, `service_user_id`, `operation`, `stat_id`, `source_ticket_count`, `operator_user_id`, `applied_at`.
- Required evidence: `before_json`, `after_json`, and `diff_summary`.
- This table must be populated by explicit CLI apply mode and reviewed through `MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_LOG_REVIEW_V1` before backend statistic apply controls can be considered for enablement.

MONGOYIA_CUSTOMER_SERVICE_ORDER_ASSIST_RESERVED_V1

- Order lookup from the customer-service workbench.
- Staff-assisted order status explanation.
- No order mutation from chat without a separate audited workflow.

MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_RESERVED_V1

- Complaint creation from a chat session.
- Complaint assignment and handling records beyond the current ticket assignment workflow.
- Complaint evidence upload rules. Read-only complaint evidence export is enabled through `MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EXPORT_BACKEND_V1`, and upload/write readiness is gated through `MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_GATE_V1`.
- Resolution/result writeback rules. Read-only resolution result export is enabled through `MONGOYIA_CUSTOMER_SERVICE_RESOLUTION_EXPORT_BACKEND_V1`.
- Result writeback approval rules remain reserved. Audited result text writeback is enabled through `MONGOYIA_CUSTOMER_SERVICE_TICKET_RESULT_BACKEND_V1`, and read-only result signoff planning is enabled through `MONGOYIA_CUSTOMER_SERVICE_RESULT_SIGNOFF_BACKEND_V1`.

MONGOYIA_CUSTOMER_SERVICE_STAT_RESERVED_V1

- Customer-service response statistics.
- Session volume and unread/handled counts.
- Exportable service performance reports.
- Customer-service statistic rebuild/write handling. Dry-run apply plans are gated through `MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_GATE_V1`; audited CLI apply is provided by `MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_WORKFLOW_V1`.
- Customer-service statistic apply log review is enabled through `MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_LOG_REVIEW_V1`; it is read-only and does not enable backend statistic writes.
- SLA readiness export for first response and resolution breaches is enabled through `MONGOYIA_CUSTOMER_SERVICE_SLA_READINESS_BACKEND_V1`. Dry-run SLA handling suggestions are enabled through `MONGOYIA_CUSTOMER_SERVICE_SLA_HANDLING_BACKEND_V1`.

## Boundaries

- Do not change IM token payload rules without updating PHP, Python IM validation, and regression scripts together.
- Do not open file, video, or voice transport through the customer-service UI before the IM media contract is implemented.
- Do not expose staff-assisted order mutation, complaint evidence handling, or statistics write widgets before their permissions, schema, audit trail, and cleanup rules exist.
- Run advanced customer-service dry-run, workflow dry-run, readonly backend test, ticket create test, ticket note test, ticket result test, ticket assign test, and ticket workflow test before runtime enablement of broader order-assist/complaint/stat flows, and require applied schema on test/prod profiles.
- Run customer-service stat export test before using backend statistic export evidence for signoff; this remains read-only and does not enable statistic write workflows.
- Run customer-service stat widget readiness before exposing statistic write widgets; this remains read-only and keeps write buttons disabled until a separate audited apply workflow lands.
- Run customer-service stat apply gate before exposing statistic rebuild/upsert handling; this remains read-only and keeps write controls disabled until audit event, operator approval, rollback, and cleanup rules land together.
- Run customer-service stat apply workflow before any real statistic rebuild/upsert; dry-run is default, apply mode must be explicit, and backend write controls remain disabled.
- Run customer-service stat apply log review after any explicit statistic apply; this remains read-only and keeps backend write controls disabled.
- Run customer-service complaint export test before using backend complaint CSV evidence for signoff; this remains read-only and does not enable complaint upload or order mutation workflows.
- Run customer-service complaint evidence gate before exposing complaint evidence upload or evidence_json write handling; this remains read-only and keeps write controls disabled until a separate audited apply workflow lands.
- Run customer-service resolution export test before using backend resolution/result CSV evidence for signoff; this remains read-only and does not enable result writeback or order mutation workflows.
- Run customer-service SLA readiness and SLA handling tests before using backend SLA CSV evidence for signoff; both remain read-only and do not enable automatic SLA handling or ticket result writeback.
- Run customer-service result signoff test before using backend result writeback plans for signoff; this remains read-only and complements the audited result writeback workflow.
- This contract is readiness-only; it does not replace public-domain WSS evidence.
