# Mongoyia Customer Chat uni-app

Phase 9.6 first runnable APP customer-service chat client.

## Run

Open this directory in HBuilderX, or use a uni-app CLI environment:

```bash
cd apps/mongoyia-customer-chat-uniapp
npm install
npm run dev:h5
```

## Entry Parameters

`pages/chat/index` accepts:

- `baseUrl`: backend origin, default `https://demo2026.mongoyia.com`
- `wsUrl`: WSS endpoint, default `wss://demo2026.mongoyia.com/ws-im`
- `gid`: product ID
- `uid`: merchant customer-service user ID, optional when `/mall/chat/token` returns it
- `store_id`: store ID, optional when `/mall/chat/token` returns it
- `customer_uuid`: optional app customer UUID
- `lang`: `zh-CN`, `en`, or `mn`

Example:

```text
/pages/chat/index?gid=2&lang=en&baseUrl=https%3A%2F%2Fdemo2026.mongoyia.com&wsUrl=wss%3A%2F%2Fdemo2026.mongoyia.com%2Fws-im
```

## Scope

- Uses existing `/mall/chat/token`, `/mall/chat/translate`, `/mall/chat/media-upload`, `/mall/chat/rating-submit`, and Python IM WSS protocol.
- Supports text, image, file, video, voice, translated-message display, and satisfaction rating.
- Does not copy order, payment, refund, stock, or complaint business logic into the APP.
