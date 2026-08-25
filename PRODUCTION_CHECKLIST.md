# PrimePrint — Production Deployment Checklist

Use this checklist prior to and immediately following deployment to Hostinger production.

---

## Pre-Deployment Verification
- [ ] Database created on Hostinger with dedicated user and strong password
- [ ] Initial database schema (`database/schema.sql`) imported via phpMyAdmin
- [ ] Migrations verified up-to-date (`database/migrations/*.sql`)
- [ ] PHP version configured to **PHP 8.1+** (8.1, 8.2, or 8.3)
- [ ] Required PHP extensions active: `pdo_mysql`, `curl`, `json`, `mbstring`, `fileinfo`, `openssl`, `session`
- [ ] SSL / TLS Certificate installed and **Force HTTPS** enabled
- [ ] Only Web Application files uploaded via FileZilla (Print Agent files and binaries excluded)

---

## Server & Environment Configuration
- [ ] Production `.env` file created in web root with `APP_ENV=production`
- [ ] `APP_URL` configured to permanent HTTPS domain (e.g. `https://primeprint.yourdomain.com`)
- [ ] Hostinger MySQL credentials set in `.env` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`)
- [ ] Razorpay Live/Test credentials configured in `.env` (`RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`)
- [ ] File permissions set: `uploads/` (`755`/`775`), `logs/` (`755`/`775`), scripts (`644`)
- [ ] `.htaccess` in web root actively protecting `.env`, `logs/`, `config/`, and `database/`
- [ ] `uploads/.htaccess` actively preventing direct execution of PHP files

---

## Post-Deployment Functional Verification
- [ ] **Health Endpoint**: `GET https://YOUR-DOMAIN/health.php` returns HTTP 200 `{ "status": "ok", "database": "connected" }`
- [ ] **Admin Login**: Super Admin login authenticates at `/login.php`
- [ ] **Shop Login**: Shop operator login authenticates at `/shop/login.php`
- [ ] **Customer Portal**: QR upload portal loads at `/p/{shop-slug}`
- [ ] **Document Upload**: PDF and image uploads succeed with page count calculation
- [ ] **Pricing Calculation**: Selected options (A4/A3, BW/Color, Single/Double) calculate accurate totals
- [ ] **Razorpay Checkout**: Order created; modal displays public Key ID with test/live card payment
- [ ] **Razorpay Webhook**: `POST /api/razorpay/webhook.php` receives and cryptographically validates webhook signature
- [ ] **Job Queue State**: Paid job moves automatically to `status = QUEUED` and `payment_status = paid`
- [ ] **Agent API**: Desktop Print Agent connects via `X-Agent-Token` to `https://YOUR-DOMAIN`
- [ ] **Agent Heartbeat**: Heartbeat ping succeeds every 60s and updates `last_seen`
- [ ] **Agent Printer Sync**: Windows printers sync to shop database
- [ ] **Agent Job Polling**: Agent receives QUEUED jobs and downloads documents to `%TEMP%\PrimePrintAgent\jobs\`
- [ ] **Phase 4A Compliance**: Downloaded jobs display `READY TO PRINT` without automatic spooling
- [ ] **No Localhost Dependency**: Zero hardcoded references to `localhost:8000` in production paths
- [ ] **No Cloudflare Dependency**: Web app runs directly on permanent domain without tunnels
- [ ] **No Committed Secrets**: Git repository is clean of real `.env` or API secrets
