# Shopee Sandbox Integration

PHP backend + React frontend untuk integrasi Shopee Open Platform v2 (sandbox).

## Stack
- Backend: PHP 8.x (PDO + SQLite, no framework)
- Frontend: React 18 + Vite
- Storage: SQLite (file-based, di `backend/data/shopee.db`)

## Struktur
```
shopee-integration/
├── .env                          # credentials (jangan commit)
├── backend/
│   ├── public/
│   │   ├── index.php             # API router (read DB + trigger sync)
│   │   └── callback.php          # OAuth redirect handler
│   ├── src/
│   │   ├── bootstrap.php         # autoloader + env loader
│   │   ├── Env.php
│   │   ├── Signature.php         # HMAC-SHA256 helper
│   │   ├── Database.php          # PDO + migrations + sync_log
│   │   ├── TokenStore.php        # token CRUD
│   │   ├── ShopeeClient.php      # sign + REST wrapper
│   │   └── Sync/
│   │       ├── SyncShop.php
│   │       ├── SyncProducts.php
│   │       └── SyncOrders.php
│   └── bin/
│       ├── sandbox_auth.php      # print auth URL + sandbox creds
│       └── sync.php              # CLI sync runner
└── frontend/
    ├── package.json
    ├── vite.config.js            # proxy /api -> :8080
    ├── index.html
    └── src/
        ├── main.jsx
        ├── App.jsx
        ├── styles.css
        └── pages/
            ├── Dashboard.jsx
            ├── Products.jsx
            └── Orders.jsx
```

## Cara Jalanin

### 1. Backend (terminal 1)
```bash
cd shopee-integration
/tmp/start-php.sh    # atau: php -S 127.0.0.1:8080 -t backend/public
```
Backend listen di `http://127.0.0.1:8080`.

### 2. Frontend (terminal 2)
```bash
/tmp/start-vite.sh   # atau: cd frontend && npm run dev
```
Frontend listen di `http://127.0.0.1:5173` dengan auto-proxy `/api/*` ke backend.

### 3. Authorize sandbox seller (one-time, browser)
Buka `http://127.0.0.1:5173` → klik tombol **Authorize Shopee** → login dengan:
- Account: `SANDBOX.dd3b9002aec08ce679c6`
- Password: `2eb8cabd857355e9`
- OTP (kalau diminta): `123456`

Approve → Shopee redirect ke `http://127.0.0.1:8080/callback.php` → token disimpan ke SQLite.

### 4. Sync data
- Klik **Sync now** di dashboard, atau
- CLI: `php backend/bin/sync.php`

Lihat di tab Products & Orders.

## API Endpoints (backend)
| Method | Path | Description |
|--------|------|-------------|
| GET  | `/api/auth/url` | Return sandbox auth URL |
| GET  | `/api/dashboard?shop_id=XXX` | Shop info + counts + recent sync log |
| GET  | `/api/products?shop_id=XXX&page=1&page_size=20` | Paginated products dari DB |
| GET  | `/api/orders?shop_id=XXX&page=1&page_size=20` | Paginated orders dari DB |
| POST | `/api/sync?shop_id=XXX&type={all\|shop\|products\|orders}` | Trigger sync |
| GET  | `/callback.php?code=...&shop_id=...` | OAuth callback handler |

## Konfigurasi `.env`
```bash
SHOPEE_PARTNER_ID=1236937
SHOPEE_PARTNER_KEY=shpk577a6c536f717469796a5a436a626d735273714767507977564278476c41
SHOPEE_AUTH_URL=https://open.sandbox.test-stable.shopee.com/auth
SHOPEE_API_BASE=https://openplatform.sandbox.test-stable.shopee.sg
SHOPEE_REGION=ID
SHOPEE_SANDBOX_SHOP_ID=227650922
SHOPEE_SANDBOX_ACCOUNT=SANDBOX.dd3b9002aec08ce679c6
SHOPEE_SANDBOX_PASSWORD=2eb8cabd857355e9
SHOPEE_AUTH_REDIRECT_URI=http://127.0.0.1:8080/callback.php
DB_PATH=backend/data/shopee.db
FRONTEND_URL=http://127.0.0.1:5173
```

## Konvensi Sign Shopee v2 (yang sudah ditangani ShopeeClient)
1. **Common params di query string**: `partner_id`, `timestamp`, `sign`, `access_token`
2. **Endpoint-specific params di JSON body**
3. **Signature base string**: `partner_id + api_path + timestamp + sorted_json_body`
   - Body dikecualikan untuk endpoint `/api/v2/auth/*`
4. Header: `Authorization: Bearer {access_token}` untuk endpoint yang butuh

## Sandbox-Specific Notes
- App status masih **Developing** — beberapa API mungkin return `error_permission`. Endpoint `Shop` & `Product` biasanya available duluan.
- Test order harus dibuat manual di Seller Center sandbox dulu sebelum bisa di-fetch via API.
- Domain API sandbox: `https://openplatform.sandbox.test-stable.shopee.sg` (beda dari production `https://partner.shopeemobile.com`).
- Untuk test dari Seller Center sandbox, OTP verification selalu `123456`.

## Deployment

### Frontend → Vercel (recommended)

1. Push repo ke GitHub
2. Buka https://vercel.com/new → import repo
3. Set root directory ke `frontend`
4. Add env vars:
   - `VITE_BACKEND_URL` = URL backend Anda (lihat opsi di bawah)
   - `VITE_SHOP_ID` = `227650922`
5. Deploy. Vercel kasih URL `https://your-app.vercel.app`

### Backend → beberapa opsi (PHP)

| Opsi | Cocok untuk | Catatan |
|------|-------------|---------|
| **ngrok tunnel** | Demo cepat, <1 hari | Butuh jalanin PHP lokal + `ngrok http 8080` |
| **cloudflared tunnel** | Demo cepat, no signup | `cloudflared tunnel --url http://127.0.0.1:8080` |
| **Railway** | Demo > 1 hari, gratis | https://railway.app → New Project → PHP + persistent volume |
| **Render** | Alternatif Railway | https://render.com → Web Service → PHP |
| **VPS** (DigitalOcean, dll) | Production | Butuh setup sendiri |

#### Backend di Railway (contoh)
1. Push repo ke GitHub
2. Railway → New Project → Deploy from GitHub repo
3. Set root directory: kosong (default)
4. Start command: `php -S 0.0.0.0:$PORT -t backend/public`
5. Tambah volume mount ke `backend/data` (persistent)
6. Set env vars di Railway dashboard (semua dari `.env`)
7. Railway kasih URL → set jadi `VITE_BACKEND_URL` di Vercel

#### Backend via tunnel (paling cepat)
```bash
# Install ngrok atau cloudflared (apt/dmg)
ngrok http 8080                    # dapat URL https://abc123.ngrok-free.app
# atau
cloudflared tunnel --url http://127.0.0.1:8080

# Pakai URL itu sebagai VITE_BACKEND_URL di Vercel
# Catatan: tunnel URL ganti tiap restart (kecuali plan paid)
```

### Setelah Deploy

1. Buka URL Vercel di browser
2. Klik **Authorize Shopee** → login dengan kredensial sandbox
3. Callback harus point ke BACKEND URL, bukan frontend. Edit `SHOPEE_AUTH_REDIRECT_URI` di backend env jadi `https://backend-url/callback.php`
4. Sync data via tombol **Sync now**

## Yang Di-skip (tambah nanti)
- Webhook push handler (butuh `ngrok` + endpoint publik)
- Auto-refresh token di Sync (sudah ada fallback, bisa improve)
- Production switch (ganti `.env` values + OAuth flow jadi real redirect)
- Cron auto-sync (jalanin `bin/sync.php` tiap 15 menit)
- Rate limit handling + exponential backoff

## Catatan
- `.env`, `backend/data/*.db` sudah di-`.gitignore`
- Password seller sandbox disimpan plaintext di `.env` untuk demo. Production wajib pakai secret manager.
- SQLite single-writer — sync concurrent + read OK karena WAL mode.