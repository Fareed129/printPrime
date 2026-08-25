# PrimePrint — Automated Local FTP Deployment Guide

`deploy.ps1` is an automated, production-safe PowerShell tool that deploys changed **PrimePrint Web Application** files directly from your Windows development PC to **Hostinger Shared Hosting** via FTP/FTPS.

---

## ⚡ Quick Start Workflow

After making changes in your local workspace:

```powershell
# 1. Commit and push your changes to GitHub
git add .
git commit -m "feat: your new update"
git push origin main

# 2. Deploy only changed Web App files to Hostinger
.\deploy.ps1
```

---

## 🛠️ Initial One-Time Setup

### Step 1: Create `.deploy.env`
Copy `.deploy.env.example` to `.deploy.env`:

```powershell
Copy-Item .deploy.env.example .deploy.env
```

Open `.deploy.env` and fill in your Hostinger FTP credentials:

```ini
# Hostinger FTP Host (find this in Hostinger hPanel -> FTP Accounts)
FTP_HOST=ftp.yourdomain.com

# Standard FTP Port
FTP_PORT=21

# Hostinger FTP Username
FTP_USERNAME=u123456789@yourdomain.com

# Hostinger FTP Password
FTP_PASSWORD=your_secure_ftp_password

# Remote Web Root on Hostinger
FTP_REMOTE_ROOT=/public_html

# Enable FTPS (Explicit TLS) - Recommended
FTP_USE_TLS=true
```

> [!NOTE]
> `.deploy.env` is strictly ignored by Git and will **never** be committed or uploaded.

---

### Step 2: Test FTP Connection
Verify that your FTP credentials and network connection work:

```powershell
.\deploy.ps1 -TestConnection
```

You should see:
```text
✅ FTP Connection Successful!
   Host:        ftp.yourdomain.com
   User:        u123456789@yourdomain.com
   Remote Root: /public_html
   Protocol:    FTPS (Explicit TLS)
```

---

### Step 3: Run Initial Baseline Deployment
If deploying for the very first time to establish a baseline:

```powershell
# 1. Preview what will be uploaded
.\deploy.ps1 -Initial -DryRun

# 2. Execute initial baseline upload
.\deploy.ps1 -Initial
```

This scans all deployable Web App files, confirms the upload list with you, uploads the baseline to Hostinger, and creates `.deploy-state.json` recording the deployed Git commit SHA.

---

## 🚀 Everyday Commands & Options

### 1. Normal Incremental Deployment
```powershell
.\deploy.ps1
```
- Compares your current Git commit against the `lastDeployedCommit`.
- Shows the list of added, modified, renamed, and deleted files.
- Asks for confirmation before uploading.

---

### 2. Dry Run Mode (Preview Only)
```powershell
.\deploy.ps1 -DryRun
```
- Calculates all changed files and prints the exact upload/delete plan without touching the remote server.

---

### 3. Check Deployment Status
```powershell
.\deploy.ps1 -ShowStatus
```
- Displays current branch, current Git SHA, last deployed SHA, and pending file counts.

---

### 4. Non-Interactive Auto-Confirm
```powershell
.\deploy.ps1 -Yes
```
- Bypasses the `[Y/N]` prompt for automated non-destructive uploads.

---

## 🛡️ Safety Rules & Security Features

| Feature | How It Protects You |
| :--- | :--- |
| **Clean Git Working Tree Required** | Prevents deploying half-edited, uncommitted code. |
| **Strict `.env` Isolation** | Even if local `.env` changes, it is **strictly blocked** from upload to preserve Hostinger production secrets. |
| **Print Agent Exclusion** | `src/`, `package.json`, `PrimePrint Agent.exe`, DLLs, and node_modules are **strictly ignored**. |
| **Deletion Confirmation** | Requires typing `DELETE` before deleting any remote file. |
| **Transactional State Update** | `.deploy-state.json` updates **only if 100% of uploads succeed**. |
| **Migration Alerts** | Detects changes in `database/migrations/*.sql` and prompts you to run `/database/migrate.php`. |
| **Credential-Free Logging** | Logs saved to `deployments/` without logging passwords. |

---

## 🗄️ Database Migrations

When you add a new SQL migration in `database/migrations/`:
1. `deploy.ps1` uploads the new `.sql` file to Hostinger.
2. `deploy.ps1` displays a yellow banner:
   ```text
   ⚠️  DATABASE MIGRATION DETECTED:
      • database/migrations/004_new_feature.sql
      👉 Please run migrations on Hostinger via https://yourdomain.com/database/migrate.php
   ```
3. Open `https://yourdomain.com/database/migrate.php` in your browser to apply the migration cleanly.
