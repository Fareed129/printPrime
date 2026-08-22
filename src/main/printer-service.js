const fs = require('fs');
const path = require('path');
const os = require('os');
const { exec } = require('child_process');
const util = require('util');
const execPromise = util.promisify(exec);

let ptp;
try {
  ptp = require('pdf-to-printer');
} catch (err) {
  console.warn('pdf-to-printer not yet loaded or running in standalone mode:', err.message);
}

class PrinterService {
  /**
   * Detects all installed printers on the Windows computer
   * @param {Electron.WebContents} [webContents] - Optional webContents to use Electron's native print detection
   * @returns {Promise<Array<{name: string, isDefault: boolean, status: string, driver: string, port: string}>>}
   */
  async getInstalledPrinters(webContents = null) {
    const printersMap = new Map();

    // 1. Primary: Try pdf-to-printer
    if (ptp && typeof ptp.getPrinters === 'function') {
      try {
        const ptpList = await ptp.getPrinters();
        if (Array.isArray(ptpList)) {
          for (const p of ptpList) {
            const name = typeof p === 'string' ? p : p.name || p.printerId;
            if (name) {
              printersMap.set(name.trim(), {
                name: name.trim(),
                isDefault: false,
                status: 'Ready',
                driver: 'Windows Spooler',
                port: ''
              });
            }
          }
        }
      } catch (err) {
        console.warn('pdf-to-printer getPrinters failed, falling back:', err.message);
      }
    }

    // 2. Secondary: Try Electron native webContents getPrintersAsync
    if (webContents && typeof webContents.getPrintersAsync === 'function') {
      try {
        const electronPrinters = await webContents.getPrintersAsync();
        if (Array.isArray(electronPrinters)) {
          for (const p of electronPrinters) {
            const name = p.name ? p.name.trim() : '';
            if (name) {
              const existing = printersMap.get(name) || {};
              printersMap.set(name, {
                name: name,
                displayName: p.displayName || name,
                description: p.description || '',
                isDefault: Boolean(p.isDefault || existing.isDefault),
                status: p.status === 0 ? 'Ready' : `Status Code ${p.status}`,
                driver: existing.driver || 'Windows Print Driver',
                port: existing.port || ''
              });
            }
          }
        }
      } catch (err) {
        console.warn('Electron getPrintersAsync failed:', err.message);
      }
    }

    // 3. Fallback/Enrichment: Query Windows PowerShell / WMI for accurate default printer and driver details
    try {
      const psCommand = `powershell.exe -NoProfile -Command "Get-CimInstance Win32_Printer | Select-Object Name, Default, PrinterStatus, DriverName, PortName | ConvertTo-Json -Compress"`;
      const { stdout } = await execPromise(psCommand, { timeout: 7000 });
      if (stdout && stdout.trim()) {
        let parsed = JSON.parse(stdout.trim());
        if (!Array.isArray(parsed)) {
          parsed = [parsed];
        }

        for (const item of parsed) {
          if (!item || !item.Name) continue;
          const name = item.Name.trim();
          const isDefault = Boolean(item.Default);
          const driver = item.DriverName || 'Generic Driver';
          const port = item.PortName || '';
          
          let statusText = 'Ready';
          if (item.PrinterStatus === 1) statusText = 'Other';
          else if (item.PrinterStatus === 2) statusText = 'Unknown';
          else if (item.PrinterStatus === 3) statusText = 'Ready';
          else if (item.PrinterStatus === 4) statusText = 'Printing';
          else if (item.PrinterStatus === 5) statusText = 'Warmup';
          else if (item.PrinterStatus === 7) statusText = 'Offline';

          const existing = printersMap.get(name) || {};
          printersMap.set(name, {
            name: name,
            displayName: existing.displayName || name,
            description: existing.description || '',
            isDefault: isDefault || Boolean(existing.isDefault),
            status: statusText,
            driver: driver,
            port: port
          });
        }
      }
    } catch (err) {
      console.warn('PowerShell printer detection query failed:', err.message);
    }

    const result = Array.from(printersMap.values());

    // Sort: default printer first, then alphabetical
    result.sort((a, b) => {
      if (a.isDefault && !b.isDefault) return -1;
      if (!a.isDefault && b.isDefault) return 1;
      return a.name.localeCompare(b.name);
    });

    return result;
  }

  /**
   * Prints a PDF file to the specified Windows printer
   * @param {object} options
   * @param {string} options.printerName - Exact printer name in Windows Spooler
   * @param {string} options.filePath - Absolute path to the PDF file
   * @param {Function} [options.onProgress] - Optional progress callback
   * @returns {Promise<{success: boolean, message: string, printer: string, file: string, durationMs: number}>}
   */
  async printPdf({ printerName, filePath, onProgress = () => {} }) {
    const startTime = Date.now();

    if (!filePath) {
      throw new Error('No PDF file path specified.');
    }

    if (!fs.existsSync(filePath)) {
      throw new Error(`PDF file does not exist at path: "${filePath}"`);
    }

    const stats = fs.statSync(filePath);
    if (stats.size === 0) {
      throw new Error('Selected PDF file is empty (0 bytes).');
    }

    if (!printerName || !printerName.trim()) {
      throw new Error('No target printer selected.');
    }

    const trimmedPrinter = printerName.trim();
    onProgress({ status: 'validating', message: `Verifying printer "${trimmedPrinter}" and PDF file...` });

    // Validate that printer exists in Windows
    const currentPrinters = await this.getInstalledPrinters();
    const printerMatch = currentPrinters.find(
      p => p.name.toLowerCase() === trimmedPrinter.toLowerCase()
    );

    if (!printerMatch) {
      throw new Error(
        `Printer "${trimmedPrinter}" was not found among installed Windows printers. Available: ${currentPrinters.map(p => p.name).join(', ')}`
      );
    }

    const isPromptPort = printerMatch.port && printerMatch.port.toUpperCase().includes('PORTPROMPT');
    if (isPromptPort) {
      onProgress({
        status: 'spooling',
        message: `Spooling to "${trimmedPrinter}" (Virtual printer: Windows will show "Save Output" dialog to simulate paper)...`
      });
    } else {
      onProgress({
        status: 'spooling',
        message: `Sending PDF (${(stats.size / 1024).toFixed(1)} KB) to Windows Spooler for "${trimmedPrinter}"...`
      });
    }

    // Ensure the PDF file is outside app.asar so external Windows binaries (SumatraPDF) can open it
    let targetFilePath = filePath;
    let tempExtractedPdfPath = null;

    if (filePath.includes('app.asar')) {
      try {
        const tempFolder = path.join(os.tmpdir(), 'primeprint-agent-print-files');
        if (!fs.existsSync(tempFolder)) {
          fs.mkdirSync(tempFolder, { recursive: true });
        }
        tempExtractedPdfPath = path.join(tempFolder, `${Date.now()}_${path.basename(filePath)}`);
        const pdfData = fs.readFileSync(filePath);
        fs.writeFileSync(tempExtractedPdfPath, pdfData);
        targetFilePath = tempExtractedPdfPath;
      } catch (asarCopyErr) {
        console.warn('Failed to extract PDF from app.asar to temp file:', asarCopyErr.message);
      }
    }

    // Execute real print using pdf-to-printer
    if (ptp && typeof ptp.print === 'function') {
      try {
        const printOptions = {
          printer: trimmedPrinter,
          win32: ['-print-settings', 'fit']
        };

        const resolvedSumatraPath = this.getSumatraExecutablePath();
        if (resolvedSumatraPath) {
          printOptions.sumatraPdfPath = resolvedSumatraPath;
        }

        await ptp.print(targetFilePath, printOptions);

        const durationMs = Date.now() - startTime;
        return {
          success: true,
          message: `Print job successfully dispatched to Windows Spooler for "${trimmedPrinter}" in ${durationMs}ms`,
          printer: trimmedPrinter,
          file: path.basename(filePath),
          durationMs,
          isPromptPort
        };
      } catch (err) {
        console.error('pdf-to-printer print error:', err);
        throw new Error(`Windows Print Spooler error: ${err.message}`);
      } finally {
        if (tempExtractedPdfPath && fs.existsSync(tempExtractedPdfPath)) {
          try {
            fs.unlinkSync(tempExtractedPdfPath);
          } catch (cleanupErr) {
            // Ignore cleanup error
          }
        }
      }
    } else {
      if (tempExtractedPdfPath && fs.existsSync(tempExtractedPdfPath)) {
        try { fs.unlinkSync(tempExtractedPdfPath); } catch (e) {}
      }
      throw new Error('Printing engine (pdf-to-printer) is not available.');
    }
  }

  /**
   * Resolves SumatraPDF executable path, handling app.asar, app.asar.unpacked, and fallback temp extraction
   * @returns {string|null} Path to SumatraPDF.exe or null
   */
  getSumatraExecutablePath() {
    const defaultAsarPath = path.join(__dirname, '../../node_modules/pdf-to-printer/dist/SumatraPDF-3.4.6-32.exe');

    if (defaultAsarPath.includes('app.asar')) {
      const unpackedPath = defaultAsarPath.replace('app.asar', 'app.asar.unpacked');
      if (fs.existsSync(unpackedPath)) {
        return unpackedPath;
      }

      try {
        const tempDirPath = path.join(os.tmpdir(), 'primeprint-agent');
        if (!fs.existsSync(tempDirPath)) {
          fs.mkdirSync(tempDirPath, { recursive: true });
        }
        const tempSumatraPath = path.join(tempDirPath, 'SumatraPDF-3.4.6-32.exe');
        if (!fs.existsSync(tempSumatraPath) || fs.statSync(tempSumatraPath).size === 0) {
          const binaryBuffer = fs.readFileSync(defaultAsarPath);
          fs.writeFileSync(tempSumatraPath, binaryBuffer);
        }
        if (fs.existsSync(tempSumatraPath) && fs.statSync(tempSumatraPath).size > 0) {
          return tempSumatraPath;
        }
      } catch (extractErr) {
        console.warn('Failed to extract SumatraPDF to temp fallback:', extractErr.message);
      }
    } else if (fs.existsSync(defaultAsarPath)) {
      return defaultAsarPath;
    }

    return null;
  }
}

module.exports = new PrinterService();

