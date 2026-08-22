/**
 * PrimePrint Print Agent - UI Controller & Event Handlers
 */

// Application State
const state = {
  printers: [],
  selectedPrinter: null,
  selectedPdfPath: null,
  selectedPdfName: null,
  selectedPdfSize: 0,
  isPrinting: false,
  version: '1.0.0-poc'
};

// DOM Element References
const elements = {
  globalStatusBadge: document.getElementById('globalStatusBadge'),
  globalStatusText: document.getElementById('globalStatusText'),
  appVersionTag: document.getElementById('appVersionTag'),
  printerSelect: document.getElementById('printerSelect'),
  printerCountMeta: document.getElementById('printerCountMeta'),
  btnRefreshPrinters: document.getElementById('btnRefreshPrinters'),
  printerMetaBox: document.getElementById('printerMetaBox'),
  metaPrinterDriver: document.getElementById('metaPrinterDriver'),
  metaPrinterStatus: document.getElementById('metaPrinterStatus'),
  metaPrinterPort: document.getElementById('metaPrinterPort'),
  pdfDropzone: document.getElementById('pdfDropzone'),
  dropzoneEmpty: document.getElementById('dropzoneEmpty'),
  dropzoneSelected: document.getElementById('dropzoneSelected'),
  btnChoosePdf: document.getElementById('btnChoosePdf'),
  btnUseSamplePdf: document.getElementById('btnUseSamplePdf'),
  btnChangePdf: document.getElementById('btnChangePdf'),
  selectedFileName: document.getElementById('selectedFileName'),
  selectedFileSize: document.getElementById('selectedFileSize'),
  selectedFilePath: document.getElementById('selectedFilePath'),
  btnPrintTest: document.getElementById('btnPrintTest'),
  btnPrintLabel: document.getElementById('btnPrintLabel'),
  actionFeedback: document.getElementById('actionFeedback'),
  feedbackMessage: document.getElementById('feedbackMessage'),
  logTerminal: document.getElementById('logTerminal'),
  btnCopyLogs: document.getElementById('btnCopyLogs'),
  btnClearLogs: document.getElementById('btnClearLogs'),
  systemInfoLabel: document.getElementById('systemInfoLabel')
};

/**
 * Format timestamp as HH:MM:SS
 */
function getTimestamp() {
  const now = new Date();
  const pad = (n) => n.toString().padStart(2, '0');
  return `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
}

/**
 * Format file size in KB or MB
 */
function formatFileSize(bytes) {
  if (!bytes || bytes === 0) return '0 KB';
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

/**
 * Append entry to the activity log terminal
 * Required format: "HH:MM:SS — Message"
 */
function log(message, type = 'info') {
  const time = getTimestamp();
  const entry = document.createElement('div');
  entry.className = `log-entry ${type}`;
  
  entry.innerHTML = `
    <span class="log-time">${time}</span>
    <span class="log-sep">—</span>
    <span class="log-msg">${escapeHtml(message)}</span>
  `;

  elements.logTerminal.appendChild(entry);
  elements.logTerminal.scrollTop = elements.logTerminal.scrollHeight;
}

function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Set Global Status Badge
 */
function setGlobalStatus(status, text = null) {
  elements.globalStatusBadge.className = `status-badge ${status}`;
  if (text) {
    elements.globalStatusText.textContent = text;
  } else if (status === 'ready') {
    elements.globalStatusText.textContent = 'Status: Ready';
  } else if (status === 'printing') {
    elements.globalStatusText.textContent = 'Status: Printing...';
  } else if (status === 'error') {
    elements.globalStatusText.textContent = 'Status: Error';
  }
}

/**
 * Update UI validation & Print Test button state
 */
function updateButtonState() {
  const canPrint = Boolean(state.selectedPrinter && state.selectedPdfPath && !state.isPrinting);
  elements.btnPrintTest.disabled = !canPrint;
}

/**
 * Load and render installed printers
 */
async function loadPrinters() {
  elements.btnRefreshPrinters.classList.add('spinning');
  elements.printerCountMeta.textContent = '(Detecting...)';
  
  try {
    const res = await window.primePrintApi.getPrinters();
    elements.btnRefreshPrinters.classList.remove('spinning');

    if (!res || !res.success || !Array.isArray(res.printers)) {
      throw new Error(res.error || 'Failed to detect printers.');
    }

    state.printers = res.printers;
    elements.printerCountMeta.textContent = `(${state.printers.length} detected)`;
    log(`${state.printers.length} printers detected`, 'info');

    // Populate dropdown
    elements.printerSelect.innerHTML = '';

    if (state.printers.length === 0) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'No printers detected on this computer';
      elements.printerSelect.appendChild(opt);
      state.selectedPrinter = null;
      elements.printerMetaBox.style.display = 'none';
      updateButtonState();
      return;
    }

    let defaultToSelect = null;

    state.printers.forEach((p) => {
      const opt = document.createElement('option');
      opt.value = p.name;
      opt.textContent = `${p.name}${p.isDefault ? ' (Default)' : ''} [${p.status || 'Ready'}]`;
      elements.printerSelect.appendChild(opt);

      if (p.isDefault && !defaultToSelect) {
        defaultToSelect = p.name;
      }
    });

    // Select default printer or first in list
    const initialPrinterName = defaultToSelect || state.printers[0].name;
    elements.printerSelect.value = initialPrinterName;
    selectPrinter(initialPrinterName);

  } catch (err) {
    elements.btnRefreshPrinters.classList.remove('spinning');
    elements.printerCountMeta.textContent = '(Error)';
    log(`Printer detection error: ${err.message}`, 'error');
    setGlobalStatus('error', 'Status: Printer Error');
  }
}

/**
 * Handle Printer Selection
 */
function selectPrinter(printerName) {
  if (!printerName) return;
  const printerObj = state.printers.find(p => p.name === printerName);
  state.selectedPrinter = printerName;

  if (printerObj) {
    elements.printerMetaBox.style.display = 'flex';
    elements.metaPrinterDriver.textContent = printerObj.driver || 'Windows Spooler Driver';
    elements.metaPrinterStatus.textContent = printerObj.status || 'Ready';
    elements.metaPrinterPort.textContent = printerObj.port || 'Standard Port';
  } else {
    elements.printerMetaBox.style.display = 'none';
  }

  log(`Selected ${printerName}`, 'action');
  updateButtonState();
}

/**
 * Set Selected PDF File
 */
function setSelectedPdf(filePath, fileName, fileSize) {
  state.selectedPdfPath = filePath;
  state.selectedPdfName = fileName || 'document.pdf';
  state.selectedPdfSize = fileSize || 0;

  elements.selectedFileName.textContent = state.selectedPdfName;
  elements.selectedFileSize.textContent = formatFileSize(state.selectedPdfSize);
  elements.selectedFilePath.textContent = state.selectedPdfPath;

  elements.dropzoneEmpty.style.display = 'none';
  elements.dropzoneSelected.style.display = 'flex';

  log(`Selected PDF: ${state.selectedPdfName} (${formatFileSize(state.selectedPdfSize)})`, 'info');
  updateButtonState();
}

/**
 * Clear Selected PDF File
 */
function clearSelectedPdf() {
  state.selectedPdfPath = null;
  state.selectedPdfName = null;
  state.selectedPdfSize = 0;

  elements.dropzoneEmpty.style.display = 'flex';
  elements.dropzoneSelected.style.display = 'none';

  updateButtonState();
}

/**
 * Execute Real Print Test
 */
async function executePrintTest() {
  if (!state.selectedPrinter || !state.selectedPdfPath || state.isPrinting) return;

  state.isPrinting = true;
  updateButtonState();
  setGlobalStatus('printing');
  elements.btnPrintLabel.textContent = 'PRINTING IN PROGRESS...';
  elements.actionFeedback.style.display = 'none';

  log(`Printing started: ${state.selectedPdfName} -> ${state.selectedPrinter}`, 'action');

  try {
    const response = await window.primePrintApi.printPdf({
      printerName: state.selectedPrinter,
      filePath: state.selectedPdfPath
    });

    if (response && response.success) {
      log(`Printing completed`, 'success');
      log(`Spooler confirmation: ${response.result.message}`, 'info');
      setGlobalStatus('ready');
      
      elements.actionFeedback.className = 'action-feedback success';
      elements.feedbackMessage.textContent = `Success: Print job dispatched to "${state.selectedPrinter}"`;
      elements.actionFeedback.style.display = 'flex';
    } else {
      throw new Error(response.error || 'Unknown spooler failure');
    }
  } catch (err) {
    log(`Printing failed: ${err.message}`, 'error');
    setGlobalStatus('error', 'Status: Print Failed');

    elements.actionFeedback.className = 'action-feedback error';
    elements.feedbackMessage.textContent = `Print Error: ${err.message}`;
    elements.actionFeedback.style.display = 'flex';
  } finally {
    state.isPrinting = false;
    elements.btnPrintLabel.textContent = '[ PRINT TEST ]';
    updateButtonState();
  }
}

/**
 * Initialize App
 */
async function initApp() {
  log('Application started', 'info');

  // Load App Version & Metadata
  try {
    const meta = await window.primePrintApi.getAppMetadata();
    if (meta && meta.version) {
      state.version = meta.version;
      elements.appVersionTag.textContent = `v${meta.version}`;
      elements.systemInfoLabel.textContent = `Windows ${meta.platform}-${meta.arch} | Engine v${meta.version}`;
    }
  } catch (err) {
    console.warn('Metadata query error:', err);
  }

  // Hook up progress listener from main process
  window.primePrintApi.onPrintProgress((data) => {
    if (data && data.message) {
      log(data.message, 'info');
    }
  });

  // Event Listeners
  elements.printerSelect.addEventListener('change', (e) => {
    selectPrinter(e.target.value);
  });

  elements.btnRefreshPrinters.addEventListener('click', () => {
    log('Refreshing printer devices...', 'info');
    loadPrinters();
  });

  // PDF File Picker Dialog
  elements.btnChoosePdf.addEventListener('click', async () => {
    try {
      const res = await window.primePrintApi.selectPdfDialog();
      if (res && !res.canceled && res.filePath) {
        setSelectedPdf(res.filePath, res.fileName, res.fileSize);
      }
    } catch (err) {
      log(`File picker error: ${err.message}`, 'error');
    }
  });

  elements.btnChangePdf.addEventListener('click', async () => {
    try {
      const res = await window.primePrintApi.selectPdfDialog();
      if (res && !res.canceled && res.filePath) {
        setSelectedPdf(res.filePath, res.fileName, res.fileSize);
      }
    } catch (err) {
      log(`File picker error: ${err.message}`, 'error');
    }
  });

  // Use Sample PDF button
  elements.btnUseSamplePdf.addEventListener('click', async () => {
    try {
      const res = await window.primePrintApi.getSamplePdfPath();
      if (res && res.success && res.filePath) {
        setSelectedPdf(res.filePath, res.fileName, res.fileSize);
      } else {
        log('Sample test PDF not found.', 'warning');
      }
    } catch (err) {
      log(`Sample PDF load error: ${err.message}`, 'error');
    }
  });

  // Drag and Drop
  elements.pdfDropzone.addEventListener('dragover', (e) => {
    e.preventDefault();
    e.stopPropagation();
    elements.pdfDropzone.classList.add('drag-over');
  });

  elements.pdfDropzone.addEventListener('dragleave', (e) => {
    e.preventDefault();
    e.stopPropagation();
    elements.pdfDropzone.classList.remove('drag-over');
  });

  elements.pdfDropzone.addEventListener('drop', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    elements.pdfDropzone.classList.remove('drag-over');

    const files = e.dataTransfer.files;
    if (files && files.length > 0) {
      const droppedFile = files[0];
      if (droppedFile.path) {
        try {
          const info = await window.primePrintApi.getFileInfo(droppedFile.path);
          if (info && info.success) {
            if (!info.isPdf) {
              log(`Warning: Dropped file "${info.fileName}" is not a .pdf`, 'warning');
            }
            setSelectedPdf(info.filePath, info.fileName, info.fileSize);
          }
        } catch (err) {
          setSelectedPdf(droppedFile.path, droppedFile.name, droppedFile.size);
        }
      }
    }
  });

  // Print Test Action
  elements.btnPrintTest.addEventListener('click', executePrintTest);

  // Log Utilities
  elements.btnClearLogs.addEventListener('click', () => {
    elements.logTerminal.innerHTML = '';
    log('Log cleared', 'info');
  });

  elements.btnCopyLogs.addEventListener('click', () => {
    const text = Array.from(elements.logTerminal.querySelectorAll('.log-entry'))
      .map(entry => {
        const time = entry.querySelector('.log-time')?.textContent || '';
        const msg = entry.querySelector('.log-msg')?.textContent || '';
        return `${time} — ${msg}`;
      })
      .join('\n');

    navigator.clipboard.writeText(text).then(() => {
      const prev = elements.btnCopyLogs.textContent;
      elements.btnCopyLogs.textContent = 'Copied!';
      setTimeout(() => elements.btnCopyLogs.textContent = prev, 1500);
    });
  });

  // Initial printer load
  await loadPrinters();

  // Automatically load the bundled sample test PDF for instant convenience!
  try {
    const sample = await window.primePrintApi.getSamplePdfPath();
    if (sample && sample.success) {
      setSelectedPdf(sample.filePath, sample.fileName, sample.fileSize);
    }
  } catch (e) {
    // Non-critical if sample not loaded yet
  }
}

// Start app once DOM is ready
document.addEventListener('DOMContentLoaded', initApp);
