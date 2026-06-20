# Mongoyia IM Media Contract

Contract version: 2026-06-19-im-media-v1

This document defines the next media transport contract for Phase 6 IM work. It is a readiness artifact only. No runtime enablement is included here, and current production code must continue to reject file, video, and voice uploads until the full implementation gate is satisfied.

## Current Runtime

| Media | State | Runtime gate |
|---|---|---|
| Text | Enabled | WebSocket `msg_type=1`, bounded by Python text-length validation. |
| Image | Enabled | `/mall/chat/upload`, 5 MB PHP guard, image extension allowlist, image-body validation, and WebSocket `msg_type=2` with same-origin `/attachment/` URL validation. |
| File | Reserved / rejected | No UI control, no PHP allowlist, no Python payload rule. |
| Video | Reserved / rejected | No UI control, no PHP allowlist, no Python payload rule. |
| Voice | Reserved / rejected | No UI control, no PHP allowlist, no Python payload rule. |

## Future Media Contract

| Media | Proposed message type | Max size | Extensions | MIME allowlist | Storage path | UI marker | Cleanup prefix |
|---|---:|---:|---|---|---|---|---|
| File | `msg_type=3` | 20 MB | `pdf`, `doc`, `docx`, `xls`, `xlsx`, `txt`, `zip` | `application/pdf`, `application/zip`, `text/plain`, `application/msword`, `application/vnd.openxmlformats-officedocument.*` | `/attachment/chat-file/YYYY/MM/DD/` | `MONGOYIA_IM_FILE_CONTRACT_V1` | `chat_file_smoke_` |
| Video | `msg_type=4` | 50 MB | `mp4`, `webm`, `mov` | `video/mp4`, `video/webm`, `video/quicktime` | `/attachment/chat-video/YYYY/MM/DD/` | `MONGOYIA_IM_VIDEO_CONTRACT_V1` | `chat_video_smoke_` |
| Voice | `msg_type=5` | 10 MB | `mp3`, `m4a`, `wav`, `ogg`, `webm` | `audio/mpeg`, `audio/mp4`, `audio/wav`, `audio/ogg`, `audio/webm` | `/attachment/chat-voice/YYYY/MM/DD/` | `MONGOYIA_IM_VOICE_CONTRACT_V1` | `chat_voice_smoke_` |

## Transport Policy Gate

MONGOYIA_IM_MEDIA_TRANSPORT_POLICY_GATE_V1

`mongoyia-im-media-transport-policy-gate/run --fixture=1` is the read-only Phase 6 policy gate that freezes the first implementation policy before any file/video/voice transport is enabled. Current runtime still rejects `msg_type=3/4/5`.

The first enablement policy is:

- File `msg_type=3`: 20 MB max, `pdf/doc/docx/xls/xlsx/txt/csv/zip`, MIME allowlist, PDF/Office/ZIP/text body-signature checks, `/attachment/chat-file/YYYY/MM/DD/`, cleanup prefix `chat_file_smoke_`.
- Video `msg_type=4`: 50 MB max, `mp4/webm`, MIME allowlist, MP4/WebM body-signature checks, `/attachment/chat-video/YYYY/MM/DD/`, cleanup prefix `chat_video_smoke_`.
- Voice `msg_type=5`: 10 MB max, `mp3/m4a/ogg/webm/wav`, MIME allowlist, audio body-signature checks, `/attachment/chat-voice/YYYY/MM/DD/`, cleanup prefix `chat_voice_smoke_`.

Policy signoff requires business approval for allowed formats, security approval for MIME/signature/path rules, storage quota and retention ownership, and cleanup evidence. The policy gate does not create upload directories, write messages, expose controls, or change Python IM acceptance.

## Upload Skeleton Gate

MONGOYIA_IM_MEDIA_UPLOAD_SKELETON_V1

`mongoyia-im-media-upload-skeleton-gate/run --fixture=1` verifies the disabled-by-default PHP upload endpoint skeleton at `CHAT_MEDIA_UPLOAD_URL=/mall/chat/media-upload`. The skeleton is intentionally not a live media transport implementation.

Runtime rules:

- `IM_FILE_VIDEO_VOICE_ENABLED=false` in local and test templates.
- `ImMediaUploadSkeletonService` keeps `implementationReady=false` and `enabled=false`.
- `ImMediaUploadSkeletonService` includes a disabled-by-default validation helper for file/video/voice extension, MIME, and body-signature policy samples; it does not save files or make the endpoint live.
- `ImMediaUploadSkeletonService` includes a storage preflight helper that plans future file/video/voice storage under `runtime/mongoyia-im-media/` outside the public `web/` root without creating directories or writing files.
- `ImMediaUploadSkeletonService` includes a cleanup dry-run helper scoped to generated `chat_file_smoke_`, `chat_video_smoke_`, and `chat_voice_smoke_` prefixes behind an explicit future `IM_MEDIA_UPLOAD_CLEANUP_APPLY` guard.
- `ImMediaUploadSkeletonService` includes an enablement precondition helper: frontend/backend file/video/voice controls and write permissions stay disabled until live PHP upload implementation, Python `msg_type=3/4/5` acceptance, WSS regression evidence, and cleanup evidence all exist.
- `/mall/chat/media-upload` returns JSON code `403` with `enabled=false` for file, video, and voice requests.
- The endpoint does not save uploaded files, create upload directories, write chat messages, mutate orders/payments/funds, or expose frontend/backend file/video/voice controls.
- Future enablement still requires PHP upload guards, Python `msg_type=3/4/5` payload acceptance, UI controls, regression scripts, cleanup, WSS evidence, and security/business signoff in one reviewed increment.

## Transport Implementation Gate

MONGOYIA_IM_MEDIA_TRANSPORT_IMPLEMENTATION_GATE_V1

`mongoyia-im-media-transport-implementation-gate/run --fixture=1` is the read-only Phase 6 transport implementation gate for future file/video/voice messages. It records the implementation contract for `msg_type=3/4/5`, while current runtime must continue to reject those message types and keep file/video/voice UI controls hidden.

File, video, or voice media may be enabled only when all items below land in the same reviewed increment:

- PHP upload endpoint has explicit size, extension, MIME, body-signature, path, and filename-prefix guards for the media type.
- Python IM payload validation accepts the new `msg_type` and rejects remote URLs, path traversal, backslashes, control characters, and over-sized payloads.
- Frontend chat and backend customer-service UI expose the corresponding stable marker and use the documented upload endpoint.
- Regression scripts cover valid media payloads, invalid media payloads, path traversal, remote URL rejection, and message-history non-persistence for rejected payloads.
- Readiness command verifies valid upload, dangerous extension rejection, invalid body rejection, reserved-type rejection for media that remains disabled, and cleanup of generated smoke files.
- `mongoyia-test-cleanup/run --failOnPending=1` reports zero pending generated chat files and messages after the readiness chain.

## Boundary Rule

No runtime enablement: `docs/mongoyia-im-media-contract.md` is a contract and readiness checklist, not a feature flag. If any `MONGOYIA_IM_FILE_CONTRACT_V1`, `MONGOYIA_IM_VIDEO_CONTRACT_V1`, or `MONGOYIA_IM_VOICE_CONTRACT_V1` UI marker appears before matching backend and Python rules are implemented, `mongoyia-im-media-readiness/run` must fail.
