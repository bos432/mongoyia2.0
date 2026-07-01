# Mongoyia uni-app Client

Phase 9.6 delivered the first runnable customer-service chat client. Phase 13 expands the same uni-app/H5 package into the buyer APP and seller APP workbench shell while continuing to reuse backend APIs.

## Run

Open this directory in HBuilderX, or use a uni-app CLI environment:

```bash
cd apps/mongoyia-customer-chat-uniapp
npm install
npm run dev:h5
```

Source files follow the standard uni-app/Vite layout under `src/`.

`npm run build:h5` uses `MONGOYIA_APP_H5_BUILD_WARNING_GOVERNANCE_V1` in `scripts/build-h5.mjs` to keep the H5 build reproducible: it rejects project `.env*` files that set `NODE_ENV=`, suppresses the known Vite CJS notice through `VITE_CJS_IGNORE_WARNING`, and filters the uni-app build-mode `NODE_ENV=production` notice while preserving real build failures. Use `npm run build:h5:raw` only when you need the unfiltered upstream output.

For local H5 validation when the remote server does not expose CORS headers, use the built-in Vite proxy:

```text
http://127.0.0.1:5173/#/pages/chat/index?gid=2&lang=en&baseUrl=http%3A%2F%2F127.0.0.1%3A5173%2Fdemo-api&wsUrl=ws%3A%2F%2F127.0.0.1%3A5173%2Fws-im
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

## Phase 13 Routes

Buyer routes:

- `/pages/buyer/home`
- `/pages/buyer/category`
- `/pages/buyer/search`
- `/pages/buyer/product`
- `/pages/buyer/cart`
- `/pages/buyer/orders`
- `/pages/chat/index`

Seller routes:

- `/pages/seller/dashboard`
- `/pages/seller/products`
- `/pages/seller/orders`

Phase 13.0 adds the mobile route shell and shared API helpers. Buyer order submission, seller shipment submission, product/search/cart/order JSON APIs, and authenticated mobile role-flow acceptance are completed in later Phase 13 increments.
