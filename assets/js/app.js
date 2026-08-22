/**
 * PrimePrint Global Frontend Interactions & Utilities
 */

document.addEventListener('DOMContentLoaded', () => {
  // 1. Initialize Bootstrap Tooltips if available
  if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(el => new bootstrap.Tooltip(el));
  }

  // 2. Auto-Slug generator on shop creation/edit form
  const shopNameInput = document.getElementById('shopNameInput');
  const shopSlugInput = document.getElementById('shopSlugInput');
  if (shopNameInput && shopSlugInput && !shopSlugInput.dataset.manualEdit) {
    shopNameInput.addEventListener('input', () => {
      const slug = shopNameInput.value
        .toLowerCase()
        .trim()
        .replace(/[^\w\s-]/g, '')
        .replace(/[\s_-]+/g, '-')
        .replace(/^-+|-+$/g, '');
      shopSlugInput.value = slug;
    });

    shopSlugInput.addEventListener('input', () => {
      shopSlugInput.dataset.manualEdit = 'true';
    });
  }

  // 3. Customer Document Upload Dropzone & Live Price Calculation
  const dropzone = document.getElementById('customerDropzone');
  const fileInput = document.getElementById('customerFileInput');
  const filePreview = document.getElementById('filePreviewBox');
  const fileNameDisplay = document.getElementById('previewFileName');
  const fileTypeDisplay = document.getElementById('previewFileType');
  const fileSizeDisplay = document.getElementById('previewFileSize');
  const copiesInput = document.getElementById('copiesInput');
  const paperSizeSelect = document.getElementById('paperSizeSelect');
  const colorModeSelect = document.getElementById('colorModeSelect');
  const sideModeSelect = document.getElementById('sideModeSelect');
  const priceDisplay = document.getElementById('livePriceDisplay');
  const unitRateDisplay = document.getElementById('liveUnitRateDisplay');
  const submitBtn = document.getElementById('btnSubmitOrder');
  const printerRadios = document.querySelectorAll('input[name="printer_id"]');

  if (dropzone && fileInput) {
    dropzone.addEventListener('click', () => fileInput.click());

    ['dragenter', 'dragover'].forEach(eventName => {
      dropzone.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.add('drag-over');
      }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropzone.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('drag-over');
      }, false);
    });

    dropzone.addEventListener('drop', (e) => {
      const dt = e.dataTransfer;
      const files = dt.files;
      if (files && files.length > 0) {
        fileInput.files = files;
        handleFileSelect(files[0]);
      }
    });

    fileInput.addEventListener('change', () => {
      if (fileInput.files && fileInput.files.length > 0) {
        handleFileSelect(fileInput.files[0]);
      }
    });
  }

  function handleFileSelect(file) {
    if (!file) return;

    // Check size limit (25MB)
    const maxBytes = 25 * 1024 * 1024;
    if (file.size > maxBytes) {
      alert('File size exceeds the maximum limit of 25MB.');
      fileInput.value = '';
      if (filePreview) filePreview.classList.add('d-none');
      if (submitBtn) submitBtn.disabled = true;
      return;
    }

    const ext = file.name.split('.').pop().toLowerCase();
    if (!['pdf', 'jpg', 'jpeg', 'png'].includes(ext)) {
      alert('Only PDF, JPG, JPEG, and PNG files are supported.');
      fileInput.value = '';
      if (filePreview) filePreview.classList.add('d-none');
      if (submitBtn) submitBtn.disabled = true;
      return;
    }

    if (fileNameDisplay) fileNameDisplay.textContent = file.name;
    if (fileTypeDisplay) fileTypeDisplay.textContent = ext.toUpperCase();
    if (fileSizeDisplay) {
      const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
      fileSizeDisplay.textContent = `${sizeMb} MB`;
    }
    if (filePreview) {
      filePreview.classList.remove('d-none');
      filePreview.classList.add('d-flex');
    }

    // Trigger price and state update
    updateEstimatedPrice();
  }

  function updateEstimatedPrice() {
    if (!priceDisplay) return;

    const paperSize = paperSizeSelect ? paperSizeSelect.value : 'A4';
    const colorMode = colorModeSelect ? colorModeSelect.value : 'BW';
    const sideMode = sideModeSelect ? sideModeSelect.value : 'single';
    const copies = copiesInput ? Math.max(1, Math.min(100, parseInt(copiesInput.value) || 1)) : 1;

    let isOptionAvailable = false;
    let unitRate = 0.00;

    if (window.SHOP_PRICING_TABLE && Array.isArray(window.SHOP_PRICING_TABLE)) {
      const match = window.SHOP_PRICING_TABLE.find(p => 
        p.paper_size === paperSize && p.color_mode === colorMode && p.side_mode === sideMode
      );
      if (match) {
        unitRate = parseFloat(match.price_per_page);
        isOptionAvailable = true;
      }
    }

    const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
    const selectedPrinter = document.querySelector('input[name="printer_id"]:checked:not(:disabled)');

    if (isOptionAvailable) {
      const estimatedTotal = (unitRate * copies).toFixed(2);
      priceDisplay.textContent = `₹${estimatedTotal}`;
      if (unitRateDisplay) {
        unitRateDisplay.textContent = `₹${unitRate.toFixed(2)} / page`;
        unitRateDisplay.className = 'fw-semibold text-dark';
      }

      if (submitBtn) {
        submitBtn.disabled = !(hasFile && selectedPrinter);
      }
    } else {
      priceDisplay.textContent = 'Option Unavailable';
      if (unitRateDisplay) {
        unitRateDisplay.textContent = 'Not configured by shop';
        unitRateDisplay.className = 'fw-semibold text-danger';
      }
      if (submitBtn) {
        submitBtn.disabled = true;
      }
    }
  }

  // Bind live price change events
  [paperSizeSelect, colorModeSelect, sideModeSelect, copiesInput].forEach(el => {
    if (el) {
      el.addEventListener('change', updateEstimatedPrice);
      el.addEventListener('input', updateEstimatedPrice);
    }
  });

  if (printerRadios && printerRadios.length > 0) {
    printerRadios.forEach(radio => {
      radio.addEventListener('change', updateEstimatedPrice);
    });
  }

  // Run initial estimate calculation on load
  updateEstimatedPrice();

  // 4. Copy to Clipboard Utility
  window.copyToClipboard = function(text, btnElement) {
    if (!navigator.clipboard) {
      const textarea = document.createElement('textarea');
      textarea.value = text;
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand('copy');
      document.body.removeChild(textarea);
      showCopyToast(btnElement);
      return;
    }

    navigator.clipboard.writeText(text).then(() => {
      showCopyToast(btnElement);
    }).catch(err => {
      console.error('Clipboard copy failed:', err);
    });
  };

  function showCopyToast(btn) {
    if (!btn) return;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check-lg text-success"></i> Copied!';
    btn.disabled = true;
    setTimeout(() => {
      btn.innerHTML = originalHtml;
      btn.disabled = false;
    }, 1800);
  }
});
