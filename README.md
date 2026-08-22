# PrimePrint — Cloud Printing SaaS & Desktop Print Spooler

PrimePrint is a multi-tenant cloud printing SaaS platform that enables print shop customers to upload documents via a mobile-first portal, select printing preferences (paper size, color mode, single/double sided, copies), pay online, and route print jobs directly to physical counter printers via a lightweight Windows Desktop Print Agent.

---

## 🏗️ Architecture & Components

1. **PrimePrint Web Application**:
   - **Super Admin Console**: Platform management, multi-shop registration, global queues, revenue reports.
   - **Shop Manager Portal**: Shop dashboard, dynamic QR Standee generator, physical printer inventory, rate matrix management, live queue management.
   - **Customer Portal (`/p/{shop-slug}`)**: Mobile-first document upload (PDF, JPG, PNG), real-time pricing preview, server-side price calculation, instant token receipts.
   - **Desktop Agent REST API**: Secure token-authenticated endpoints for agent registration, heartbeat monitoring, job queue polling, and printer discovery.

2. **PrimePrint Windows Desktop Agent**:
   - Built with Electron & Node.js (`src/main/`, `src/renderer/`).
   - Native Windows printer discovery via spooler APIs.
   - Real-world PDF printing pipeline via SumatraPDF driver integration.

---

## 🚀 Quick Setup & Installation

### 1. Database & Web Application Setup
- **Requirements**: PHP 8.0+ (with PDO MySQL, fileinfo, session), MySQL / MariaDB.
- **Initialize Database**:
  ```powershell
  & "C:\xampp\php\php.exe" database/setup.php
  ```
- **Start Web Application**:
  ```powershell
  & "C:\xampp\php\php.exe" -S localhost:8000 router.php
  ```

### 2. Desktop Print Agent (Development)
- **Requirements**: Node.js 18+.
- **Install & Run**:
  ```powershell
  npm install
  npm start
  ```

---

## 🔑 Default Development Credentials

| Portal | URL | Email | Password |
| :--- | :--- | :--- | :--- |
| **Super Admin** | `http://localhost:8000/login.php` | `admin@primeprint.local` | `ChangeMe123!` |
| **Demo Shop** | `http://localhost:8000/shop/login.php` | `shop@abcprinting.local` | `ChangeMe123!` |
| **Customer QR** | `http://localhost:8000/p/abc-digital-printing` | *(No login required)* | *(No login required)* |

---

## 🧪 Automated Tests

Run the full web application test suite (16 automated tests covering auth, tenant isolation, price recalculation, uploads, and APIs):
```powershell
& "C:\xampp\php\php.exe" test/verify-web-app.php
```

---

## 📄 License
Proprietary / All Rights Reserved &copy; PrimePrint.
