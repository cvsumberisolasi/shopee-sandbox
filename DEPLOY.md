# Panduan Deploy: Render (Backend) + Vercel (Frontend)

## Prasyarat
- Akun GitHub (https://github.com/signup)
- Akun Render (https://render.com — login pakai GitHub)
- Akun Vercel (https://vercel.com — login pakai GitHub)

---

## STEP 1: Push kode ke GitHub

### 1a. Bikin repo baru di GitHub
1. Buka https://github.com/new
2. Nama: `shopee-sandbox`
3. Visibility: **Public** (biar Render/Vercel bisa akses free)
4. **JANGAN** centang "Add a README" (repo sudah punya)
5. Klik **Create repository**

### 1b. Push dari terminal
```bash
cd /home/aliden/.qwenpaw/workspaces/ggg/shopee-integration

# Set remote (ganti YOUR-USERNAME dengan username GitHub Anda)
git remote add origin https://github.com/YOUR-USERNAME/shopee-sandbox.git

# Push
git push -u origin main
```

> Kalau diminta login: bikin Personal Access Token di
> https://github.com/settings/tokens → Generate new token (classic)
> → centang `repo` → Generate → pakai token sebagai password

---

## STEP 2: Deploy Backend ke Render

### 2a. Buat Web Service
1. Buka https://render.com/dashboard
2. Klik **New +** → **Web Service**
3. Klik **Connect a repository** → pilih `shopee-sandbox`
4. Settings:
   - **Name:** `shopee-backend`
   - **Region:** Singapore (terdekat ID)
   - **Branch:** `main`
   - **Root Directory:** _(kosongkan)_
   - **Runtime:** `PHP`
   - **Build Command:** _(kosongkan — auto)_
   - **Start Command:**
     ```
     php -S 0.0.0.0:$PORT -t backend/public backend/public/router.php
     ```
   - **Instance Type:** `Free`

### 2b. Tambah Persistent Disk (untuk SQLite)
1. Scroll ke bawah → **Advanced** → **Add Disk**
   - **Name:** `shopee-data`
   - **Mount Path:** `/var/data`
   - **Size:** `1 GB`

### 2c. Set Environment Variables
Klik tab **Environment** → **Add Environment Variable** satu per satu:

| Key | Value |
|-----|-------|
| `DB_PATH` | `/var/data/shopee.db` |
| `SHOPEE_PARTNER_ID` | `1236937` |
| `SHOPEE_PARTNER_KEY` | `shpk577a6c536f717469796a5a436a626d735273714767507977564278476c41` |
| `SHOPEE_AUTH_URL` | `https://open.sandbox.test-stable.shopee.com/auth` |
| `SHOPEE_API_BASE` | `https://openplatform.sandbox.test-stable.shopee.sg` |
| `SHOPEE_REGION` | `ID` |
| `SHOPEE_SANDBOX_SHOP_ID` | `227650922` |
| `SHOPEE_AUTH_REDIRECT_URI` | `https://shopee-backend.onrender.com/callback.php` |
| `FRONTEND_URL` | _(isi nanti setelah Vercel deploy — STEP 3d)_ |

### 2d. Deploy
Klik **Create Web Service** → tunggu build selesai (~2-3 menit)

Render kasih URL: `https://shopee-backend.onrender.com`

### 2e. Test backend
```bash
curl https://shopee-backend.onrender.com/api/dashboard?shop_id=227650922
```
Kalau return JSON `{shop: {...}, counts: {...}}` → backend OK ✅

> ⚠️ Render free tier spin-down setelah 15 menit idle.
> Request pertama bisa lambat (~30 detik) saat cold start.

---

## STEP 3: Deploy Frontend ke Vercel

### 3a. Buat project di Vercel
1. Buka https://vercel.com/new
2. Klik **Import Git Repository** → pilih `shopee-sandbox`
3. Settings:
   - **Framework Preset:** `Vite`
   - **Root Directory:** `frontend`
   - **Build Command:** `npm run build`
   - **Output Directory:** `dist`

### 3b. Set Environment Variables
Klik **Environment Variables** → tambah:

| Key | Value |
|-----|-------|
| `VITE_BACKEND_URL` | `https://shopee-backend.onrender.com` |
| `VITE_SHOP_ID` | `227650922` |

### 3c. Deploy
Klik **Deploy** → tunggu ~1 menit

Vercel kasih URL: `https://shopee-sandbox-xxxxx.vercel.app`

### 3d. Update FRONTEND_URL di Render
1. Balik ke https://render.com/dashboard → pilih `shopee-backend`
2. **Environment** → edit `FRONTEND_URL` → ganti jadi URL Vercel Anda
   (mis. `https://shopee-sandbox-xxxxx.vercel.app`)
3. Render auto-restart

---

## STEP 4: Authorize Shopee (one-time)

1. Buka URL Vercel di browser
2. Klik **Authorize Shopee**
3. Login:
   - Account: `SANDBOX.dd3b9002aec08ce679c6`
   - Password: `2eb8cabd857355e9`
   - OTP (kalau diminta): `123456`
4. Approve → redirect ke backend → redirect ke Vercel → auth=ok

> ⚠️ OAuth flow: Vercel → Shopee → Backend callback → Vercel
> Kalau redirect URI salah, edit `SHOPEE_AUTH_REDIRECT_URI` di Render.

### 4a. Sync data pertama
1. Buka tab **Dashboard**
2. Klik **Sync now**
3. Tunggu sampai log muncul "synced X orders"

---

## STEP 5: Verifikasi

| URL | Cek |
|-----|-----|
| `https://shopee-backend.onrender.com/api/dashboard?shop_id=227650922` | JSON response |
| `https://shopee-backend.onrender.com/api/orders?shop_id=227650922` | Order list |
| `https://shopee-sandbox-xxxxx.vercel.app/` | Dashboard frontend |
| `https://shopee-sandbox-xxxxx.vercel.app` → Orders tab | Tabel orders |

---

## Troubleshooting

### "Backend tidak bisa diakses" / 502
- Render free tier cold start: tunggu 30 detik, refresh
- Cek logs di Render dashboard → Logs tab

### "Auth gagal" / redirect_uri mismatch
- Pastikan `SHOPEE_AUTH_REDIRECT_URI` di Render = `https://shopee-backend.onrender.com/callback.php`
- (bukan Vercel URL — callback di-handle oleh backend)

### "CORS error" di browser console
- Backend sudah set `Access-Control-Allow-Origin: *`
- Kalau masih error, cek VITE_BACKEND_URL di Vercel env

### "SQLite locked" error
- Render disk OK untuk single-writer SQLite WAL mode
- Kalau error: restart service di Render dashboard

### Render spin-down (cold start)
- Free tier spin-down setelah 15 min idle
- Request pertama ~30 detik lebih lambat
- Solusi: upgrade ke paid ($7/month) atau pakai cron ping service (UptimeRobot, dll)

---

## Biaya

| Service | Plan | Biaya |
|---------|------|-------|
| Render Backend | Free | $0/bulan |
| Render Disk 1GB | Free | $0/bulan |
| Vercel Frontend | Hobby | $0/bulan |
| **Total** | | **$0/bulan** |

> Render free tier limits:
> - 750 jam/bulan (cukup untuk 1 service 24/7)
> - Spin-down setelah 15 min idle
> - 1 GB persistent disk
