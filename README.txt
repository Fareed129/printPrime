# PrimePrint Agent — Local Desktop Print Agent POC

**PrimePrint Agent** is a standalone Windows desktop application designed to bridge cloud printing workflows with local Windows hardware. It communicates directly with the Windows Print Spooler subsystem to detect installed printers and dispatch real PDF print jobs.

---

## 📋 Features

- **Automatic Printer Discovery**: Auto-detects all installed physical and virtual printers (USB, Network, Thermal, Wi-Fi, Microsoft Print to PDF).
- **Default Printer Detection**: Highlights and pre-selects the Windows default printer.
- **Hardware Metrics Display**: Displays printer driver, online/offline status, and spooler port info.
- **PDF Document Selection**: File browser dialog, drag-and-drop support, and 1-click bundled sample test PDF.
- **Real Windows Spooling Engine**: Sends actual print jobs through the Windows GDI/Spooler pipeline (`pdf-to-printer` / SumatraPDF direct spooler).
- **Live Activity Log**: Timestamped terminal view (`HH:MM:SS — Message`) with copy and clear utilities.
- **Zero-Dependency Portable Binary**: Packagable as a standalone Windows x64 `.exe` requiring no Node.js or .NET installations on the print shop PC.

---

## 🚀 Quick Start (Development & Local Testing)

### Prerequisites (For Development Machine Only)
- Windows 10 or Windows 11 (64-bit)
- Node.js (v18 or higher) and npm

### 1. Install Dependencies
```bash
npm install
```

### 2. Launch the Application
```bash
npm start
```

---

## 🖨️ How to Test Printing

### Step 1: Select a Printer
1. Launch PrimePrint Agent.
2. The application automatically enumerates all local Windows printers.
3. Select your target printer from the dropdown (e.g., **"Microsoft Print to PDF"** for testing without physical paper, or your connected physical printer).
4. Click the 🔄 icon if you add or turn on a new printer while the app is open.

### Step 2: Choose a PDF Document
- **Option A (Instant Test)**: Click **`Use Sample PDF`** to automatically load the pre-bundled diagnostic test document.
- **Option B (Custom File)**: Click **`[ Choose PDF ]`** to browse any local `.pdf` file, or drag and drop a PDF into the dropzone.

### Step 3: Dispatch Print Job
- Click the **`[ PRINT TEST ]`** button.
- Observe the **Live Activity Log**:
  ```
  23:15:01 — Application started
  23:15:02 — 4 printers detected
  23:15:05 — Selected Microsoft Print to PDF
  23:15:10 — Printing started: sample-test-document.pdf -> Microsoft Print to PDF
  23:15:12 — Printing completed successfully
  ```

> [!NOTE]
> **Virtual Printers (e.g. Microsoft Print to PDF / XPS)**:
> Windows uses the virtual `PORTPROMPT:` port for PDF/XPS printers, so Windows will prompt you to choose where to save the output file to simulate physical paper output.
>
> **Physical Printers (HP, Canon, Epson, Brother, TVS POS, etc.)**:
> Windows transmits the print stream silently straight to physical paper without any popup prompts.

---

## 📦 How to Build a Release Executable

To produce a single, self-contained `.exe` for deployment:

### 1. Generate the Standalone Executables
```bash
npm run build:win
```

### 2. Output Location
The compiled binaries are generated in the `dist/` directory:
- **Portable Executable**: `dist/PrimePrint Agent-v1.0.0-poc-Portable-x64.exe`
- **Installer**: `dist/PrimePrint Agent-v1.0.0-poc-Setup-x64.exe`
- **Unpacked Directory**: `dist/win-unpacked/`

---

## 🚚 How to Move the Application to the Printing Shop PC

The release build is completely **self-contained**. The printing shop computer **does NOT need Node.js, npm, Visual Studio, or .NET** installed!

### Step-by-Step Shop Deployment:

1. **Copy the Executable**:
   - Copy `dist/PrimePrint Agent-v1.0.0-poc-Portable-x64.exe` to a USB drive or file transfer service.
2. **Transfer to Shop Computer**:
   - Paste the `.exe` anywhere on the shop PC (e.g., Desktop or `C:\PrimePrint\`).
3. **Double Click to Run**:
   - Launch `PrimePrint Agent-v1.0.0-poc-Portable-x64.exe`.
   - The app will open immediately, detect the shop's physical printers, and allow you to test real document printing.

---

## 🏗️ Architecture Overview & Cloud Roadmap

```
[ PrimePrint Desktop Agent (Electron / Main Process) ]
                  │
                  ├── Renderer UI (HTML5 / Vanilla CSS / JS)
                  │     └── Live Logs, Status Indicators, File Picker
                  │
                  ├── Windows Spooler Engine (pdf-to-printer)
                  │     └── Sends silent print stream to physical/virtual print queue
                  │
                  └── (Future) Cloud Polling Agent
                        ├── Polls PHP REST API (/api/agent/jobs)
                        ├── Downloads customer PDFs securely
                        └── Dispatches to designated shop printer automatically
```

---

## 📄 License
MIT License — PrimePrint
