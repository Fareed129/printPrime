# PrimePrint — Hostinger Production Deployment Guide

This guide details the exact manual deployment process for hosting the **PrimePrint Web Application** on **Hostinger Shared PHP/MySQL Hosting** using FileZilla FTP.

---

## ⚠️ Important Scope Separation

| Component | Target Environment | Deployment Method |
| :--- | :--- | :--- |
| **PrimePrint Web Application** | Hostinger Shared Hosting (PHP/MySQL) | Uploaded via FileZilla FTP |
| **PrimePrint Print Agent** | Shop Windows Counter PC (Desktop Application) | Packaged `.exe` running locally on shop PC |

> [!IMPORTANT]
> The **Print Agent** remains in GitHub and on shop computers. **DO NOT upload the Print Agent files, Electron binaries, or node_modules to Hostinger.**

---

## File Upload Inventory (FileZilla FTP)

### ✅ What to Upload to Hostinger (`public_html/`):
- `admin/` — Admin management dashboard
- `api/` — Public endpoints (`api/agent/`, `api/payment/`, `api/razorpay/`)
- `assets/` — Frontend CSS, JS, and QR library
- `config/` — Configuration, DB connector, helpers, auth, CSRF, Razorpay
- `customer/` — Customer upload, pricing review, and success pages
- `database/` — Migration scripts (`database/migrations/`, `schema.sql`, `seed.sql`, `migrate.php`)
- `includes/` — Shared HTML headers, footers, sidebars
- `logs/` — Runtime log directory (includes `.htaccess` protection)
- `shop/` — Shop owner portal (dashboard, printers, pricing, QR, reports)
- `uploads/` — Temporary document upload storage (includes `.htaccess` protection)
- `.htaccess` — Apache routing and security guards
- `.env` — Production environment secrets (created directly on server)
- `health.php` — System monitoring endpoint
- `index.php` — Root entry point
- `login.php` — Unified authentication entry point
- `logout.php` — Session termination
- `p.php` — Customer QR entry routing

---

### ❌ What NOT to Upload to Hostinger:
- `src/` — Electron Desktop Print Agent source
- `test-assets/` — Test documents for agent
- `package.json`, `package-lock.json` — Electron package files
- `PrimePrint Agent.exe` & Windows `.dll` / `.pak` / `.dat` / `.bin` binaries
- `locales/` & `resources/` — Electron distribution assets
- `test/` — Development test scripts
- `router.php` — Local CLI dev server router
- `node_modules/` — Local Node modules
- `.git/` — Git metadata
- `.env` (from local machine — create fresh on server!)

---

## Step-by-Step Deployment Instructions

### Step 1: Create MySQL Database in Hostinger hPanel
1. Log in to **Hostinger hPanel**.
2. Navigate to **Databases** → **MySQL Databases**.
3. Create a new database:
   - **Database Name**: e.g., `u123456789_primeprint`
   - **Username**: e.g., `u123456789_primeuser`
   - **Password**: Create a strong password (e.g., 20+ characters with symbols)
4. Record your database name, user, password, and host (usually `localhost` or `127.0.0.1` on Hostinger).

---

### Step 2: Import Database Schema & Migrations
1. In Hostinger hPanel, click **Enter phpMyAdmin** next to your newly created database.
2. Go to the **Import** tab.
3. Choose the file `database/schema.sql` from your local project and click **Go**.
4. (Optional) If you want default sample data, also import `database/seed.sql`.

---

### Step 3: Configure PHP Version & Extensions
1. In hPanel, navigate to **Advanced** → **PHP Configuration**.
2. Select **PHP 8.1**, **PHP 8.2**, or **PHP 8.3**.
3. Go to the **PHP Extensions** tab and verify the following are enabled:
   - `pdo_mysql` (Database)
   - `curl` (Razorpay API communication)
   - `json` (REST & Webhooks)
   - `mbstring` (Multibyte strings)
   - `fileinfo` (MIME validation on uploads)
   - `openssl` (HTTPS security & HMAC signatures)
   - `session` (Admin/Shop sessions)

---

### Step 4: Configure HTTPS / SSL Certificate
1. In hPanel, go to **Security** → **SSL**.
2. Install / Activate the free **Let's Encrypt SSL Certificate**.
3. Turn on **Force HTTPS**.

---

### Step 5: Upload Files via FileZilla FTP
1. Open **FileZilla** and connect to your Hostinger FTP server.
2. On the remote site, navigate to your website root directory (typically `/public_html`).
3. Upload all files and folders listed in the **"What to Upload"** section above.
4. Ensure `.htaccess` in the root, `uploads/.htaccess`, and `logs/.htaccess` are uploaded.

---

### Step 6: Create Production `.env` File
1. In Hostinger **File Manager** (or via FTP), create a file named `.env` in the root of `/public_html`.
2. Copy the template from `.env.example` and fill in your actual production credentials:

```ini
APP_ENV=production
APP_URL=https://primeprint.yourdomain.com

DB_HOST=localhost
DB_PORT=3306
DB_NAME=u123456789_primeprint
DB_USER=u123456789_primeuser
DB_PASSWORD=your_actual_hostinger_db_password

# Razorpay Production Keys (or Test Keys for staging)
RAZORPAY_KEY_ID=rzp_live_xxxxxxxxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxxxxxxxxxxxxxxxxxx
RAZORPAY_WEBHOOK_SECRET=xxxxxxxxxxxxxxxxxxxxxxxx
```

> [!CAUTION]
> Ensure permissions on `.env` are set to `600` or `640` in File Manager. Root `.htaccess` automatically denies direct web browsing to `.env`.

---

### Step 7: Set Directory Permissions
In Hostinger File Manager, verify permissions:
- `uploads/` → `755` (or `775`) — Writable by web server for customer uploads
- `logs/` → `755` (or `775`) — Writable by web server for payment and error logs
- All `.php` files → `644`
- All directories → `755`

---

### Step 8: Configure Razorpay Production Webhook
1. Log in to the [Razorpay Dashboard](https://dashboard.razorpay.com/).
2. Switch to **Live Mode** (or Test Mode for staging).
3. Navigate to **Settings** → **Webhooks** → **Add New Webhook**.
4. Set **Webhook URL**:
   ```
   https://primeprint.yourdomain.com/api/razorpay/webhook.php
   ```
5. Set **Secret**: Enter the exact secret matching `RAZORPAY_WEBHOOK_SECRET` in your `.env`.
6. Select **Active Events**:
   - `payment.captured`
   - `order.paid`
   - `payment.failed`
7. Click **Create Webhook**.

---

### Step 9: Verify Health & Application Functionality
1. **Health Check**: Open `https://primeprint.yourdomain.com/health.php`
   - Should return:
     ```json
     {
       "status": "ok",
       "app": "PrimePrint",
       "version": "1.0.0-phase3",
       "environment": "production",
       "database": "connected"
     }
     ```
2. **Admin Login**: Visit `https://primeprint.yourdomain.com/login.php` and authenticate as Super Admin (`admin@primeprint.local` / `Admin@123456`).
3. **Shop QR & Upload**: Visit your shop customer upload link `https://primeprint.yourdomain.com/p/abc-digital-printing`.
4. **Agent Connection**: In the Windows Print Agent on your counter PC, open Settings and set Server URL to `https://primeprint.yourdomain.com` with your Shop Slug.
