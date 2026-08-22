const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('primePrintApi', {
  getPrinters: () => ipcRenderer.invoke('get-printers'),
  selectPdfDialog: () => ipcRenderer.invoke('select-pdf-dialog'),
  getFileInfo: (filePath) => ipcRenderer.invoke('get-file-info', filePath),
  getSamplePdfPath: () => ipcRenderer.invoke('get-sample-pdf-path'),
  printPdf: (payload) => ipcRenderer.invoke('print-pdf', payload),
  getAppMetadata: () => ipcRenderer.invoke('get-app-metadata'),
  openFileInExplorer: (filePath) => ipcRenderer.invoke('open-file-in-explorer', filePath),
  
  // Progress & Event listeners
  onPrintProgress: (callback) => {
    const handler = (event, data) => callback(data);
    ipcRenderer.on('print-progress', handler);
    return () => ipcRenderer.removeListener('print-progress', handler);
  }
});
