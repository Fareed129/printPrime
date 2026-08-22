const { app, BrowserWindow, ipcMain, dialog, shell } = require('electron');
const path = require('path');
const fs = require('fs');
const printerService = require('./printer-service');
const packageJson = require('../../package.json');

let mainWindow = null;

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 860,
    height: 740,
    minWidth: 760,
    minHeight: 620,
    title: `PrimePrint Agent v${packageJson.version}`,
    backgroundColor: '#0f172a',
    autoHideMenuBar: true,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: false
    }
  });

  mainWindow.loadFile(path.join(__dirname, '../renderer/index.html'));

  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}

// App lifecycle
app.whenReady().then(() => {
  createWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow();
    }
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

// IPC Handlers

// 1. Get installed Windows printers
ipcMain.handle('get-printers', async () => {
  try {
    const webContents = mainWindow ? mainWindow.webContents : null;
    const printers = await printerService.getInstalledPrinters(webContents);
    return { success: true, printers };
  } catch (err) {
    console.error('Error fetching printers:', err);
    return { success: false, error: err.message, printers: [] };
  }
});

// 2. Select PDF file dialog
ipcMain.handle('select-pdf-dialog', async () => {
  if (!mainWindow) return { canceled: true };

  const result = await dialog.showOpenDialog(mainWindow, {
    title: 'Select PDF Document to Print',
    properties: ['openFile'],
    filters: [
      { name: 'PDF Documents (*.pdf)', extensions: ['pdf'] },
      { name: 'All Files (*.*)', extensions: ['*'] }
    ]
  });

  if (result.canceled || result.filePaths.length === 0) {
    return { canceled: true };
  }

  const selectedPath = result.filePaths[0];
  try {
    const stats = fs.statSync(selectedPath);
    return {
      canceled: false,
      filePath: selectedPath,
      fileName: path.basename(selectedPath),
      fileSize: stats.size,
      lastModified: stats.mtime
    };
  } catch (err) {
    return {
      canceled: false,
      filePath: selectedPath,
      fileName: path.basename(selectedPath),
      fileSize: 0,
      error: err.message
    };
  }
});

// 3. Get info for a specific file path (e.g. from drag and drop)
ipcMain.handle('get-file-info', async (event, filePath) => {
  try {
    if (!filePath || !fs.existsSync(filePath)) {
      return { success: false, error: 'File does not exist' };
    }
    const stats = fs.statSync(filePath);
    return {
      success: true,
      filePath: filePath,
      fileName: path.basename(filePath),
      fileSize: stats.size,
      lastModified: stats.mtime,
      isPdf: filePath.toLowerCase().endsWith('.pdf')
    };
  } catch (err) {
    return { success: false, error: err.message };
  }
});

// 4. Get bundled sample PDF path
ipcMain.handle('get-sample-pdf-path', async () => {
  // In development: <root>/test-assets/sample-test-document.pdf
  // In packaged app: <resourcesPath>/test-assets/sample-test-document.pdf or app.getAppPath()
  let samplePath = path.join(__dirname, '../../test-assets/sample-test-document.pdf');
  
  if (!fs.existsSync(samplePath)) {
    // If running in packaged app
    samplePath = path.join(process.resourcesPath, 'test-assets/sample-test-document.pdf');
  }

  if (fs.existsSync(samplePath)) {
    const stats = fs.statSync(samplePath);
    return {
      success: true,
      filePath: samplePath,
      fileName: path.basename(samplePath),
      fileSize: stats.size
    };
  }

  return { success: false, error: 'Sample PDF not found' };
});

// 5. Print PDF to chosen printer
ipcMain.handle('print-pdf', async (event, { printerName, filePath }) => {
  try {
    const progressCallback = (data) => {
      if (mainWindow && !mainWindow.isDestroyed()) {
        mainWindow.webContents.send('print-progress', data);
      }
    };

    const result = await printerService.printPdf({
      printerName,
      filePath,
      onProgress: progressCallback
    });

    return { success: true, result };
  } catch (err) {
    console.error('Print execution failed:', err);
    return { success: false, error: err.message };
  }
});

// 6. App & System metadata
ipcMain.handle('get-app-metadata', async () => {
  return {
    version: packageJson.version,
    name: 'PrimePrint Agent',
    nodeVersion: process.versions.node,
    electronVersion: process.versions.electron,
    platform: process.platform,
    arch: process.arch
  };
});

// 7. Open file in explorer
ipcMain.handle('open-file-in-explorer', async (event, filePath) => {
  if (filePath && fs.existsSync(filePath)) {
    shell.showItemInFolder(filePath);
    return { success: true };
  }
  return { success: false, error: 'File not found' };
});
