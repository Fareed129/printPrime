/**
 * PrimePrint Global Frontend Interactions & Utilities (Modern SaaS Engine)
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

  // 3. Customer Document Upload Dropzone & Interactive Live Calculator
  const dropzone = document.getElementById('customerDropzone');
  const fileInput = document.getElementById('customerFileInput');
  const filePreview = document.getElementById('filePreviewBox');
  const fileNameDisplay = document.getElementById('previewFileName');
  const fileTypeDisplay = document.getElementById('previewFileType');
  const fileSizeDisplay = document.getElementById('previewFileSize');
  const btnRemoveFile = document.getElementById('btnRemoveFile');

  // Input elements
  const copiesInput = document.getElementById('copiesInput');
  const btnMinusCopies = document.getElementById('btnMinusCopies');
  const btnPlusCopies = document.getElementById('btnPlusCopies');

  // Live displays
  const priceDisplay = document.getElementById('livePriceDisplay');
  const stickyPriceDisplay = document.getElementById('stickyPriceDisplay');
  const unitRateDisplay = document.getElementById('liveUnitRateDisplay');
  const submitBtn = document.getElementById('btnSubmitOrder');
  const submitBtnSticky = document.getElementById('btnSubmitOrderSticky');

  // Dropzone Setup
  if (dropzone && fileInput) {
    dropzone.addEventListener('click', (e) => {
      if (e.target !== fileInput) {
        fileInput.click();
      }
    });

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

  if (btnRemoveFile && fileInput) {
    btnRemoveFile.addEventListener('click', (e) => {
      e.stopPropagation();
      fileInput.value = '';
      if (filePreview) filePreview.classList.add('d-none');
      if (dropzone) dropzone.classList.remove('d-none');
      updateEstimatedPrice();
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
      if (dropzone) dropzone.classList.remove('d-none');
      updateEstimatedPrice();
      return;
    }

    const ext = file.name.split('.').pop().toLowerCase();
    if (!['pdf', 'jpg', 'jpeg', 'png'].includes(ext)) {
      alert('Only PDF, JPG, JPEG, and PNG files are supported.');
      fileInput.value = '';
      if (filePreview) filePreview.classList.add('d-none');
      if (dropzone) dropzone.classList.remove('d-none');
      updateEstimatedPrice();
      return;
    }

    if (fileNameDisplay) fileNameDisplay.textContent = file.name;
    if (fileTypeDisplay) fileTypeDisplay.textContent = ext.toUpperCase();
    if (fileSizeDisplay) {
      const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
      fileSizeDisplay.textContent = `${sizeMb} MB`;
    }

    if (dropzone) dropzone.classList.add('d-none');
    if (filePreview) {
      filePreview.classList.remove('d-none');
    }

    // Trigger price and state update
    updateEstimatedPrice();
  }

  // Copies Stepper
  if (copiesInput) {
    if (btnMinusCopies) {
      btnMinusCopies.addEventListener('click', () => {
        let current = parseInt(copiesInput.value) || 1;
        if (current > 1) {
          copiesInput.value = current - 1;
          updateEstimatedPrice();
        }
      });
    }
    if (btnPlusCopies) {
      btnPlusCopies.addEventListener('click', () => {
        let current = parseInt(copiesInput.value) || 1;
        if (current < 100) {
          copiesInput.value = current + 1;
          updateEstimatedPrice();
        }
      });
    }
  }

  // Helper to read selected option from radio pills or select elements
  function getSelectedOptionValue(name) {
    const radioChecked = document.querySelector(`input[name="${name}"]:checked`);
    if (radioChecked) return radioChecked.value;

    const selectEl = document.querySelector(`select[name="${name}"]`);
    if (selectEl) return selectEl.value;

    return '';
  }

  function updateEstimatedPrice() {
    const paperSize = getSelectedOptionValue('paper_size') || 'A4';
    const colorMode = getSelectedOptionValue('color_mode') || 'BW';
    const sideMode  = getSelectedOptionValue('side_mode')  || 'single';
    const copies    = copiesInput ? Math.max(1, Math.min(100, parseInt(copiesInput.value) || 1)) : 1;

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

    const isReadyToSubmit = isOptionAvailable && hasFile && selectedPrinter;

    if (isOptionAvailable) {
      const estimatedTotal = (unitRate * copies).toFixed(2);
      const formattedTotal = `₹${estimatedTotal}`;

      if (priceDisplay) priceDisplay.textContent = formattedTotal;
      if (stickyPriceDisplay) stickyPriceDisplay.textContent = formattedTotal;

      if (unitRateDisplay) {
        unitRateDisplay.textContent = `₹${unitRate.toFixed(2)} / page`;
        unitRateDisplay.className = 'fw-bold text-dark';
      }
    } else {
      if (priceDisplay) priceDisplay.textContent = 'Unavailable';
      if (stickyPriceDisplay) stickyPriceDisplay.textContent = 'Unavailable';

      if (unitRateDisplay) {
        unitRateDisplay.textContent = 'Rate not configured';
        unitRateDisplay.className = 'fw-bold text-danger';
      }
    }

    if (submitBtn) {
      submitBtn.disabled = !isReadyToSubmit;
    }
    if (submitBtnSticky) {
      submitBtnSticky.disabled = !isReadyToSubmit;
    }
  }

  // Bind change events to all touch pill radios & selects
  document.querySelectorAll('input[name="paper_size"], input[name="color_mode"], input[name="side_mode"], input[name="printer_id"], select[name="paper_size"], select[name="color_mode"], select[name="side_mode"]').forEach(el => {
    el.addEventListener('change', updateEstimatedPrice);
  });

  if (copiesInput) {
    copiesInput.addEventListener('input', updateEstimatedPrice);
    copiesInput.addEventListener('change', updateEstimatedPrice);
  }

  // Connect sticky bottom button to trigger form submission
  if (submitBtnSticky) {
    submitBtnSticky.addEventListener('click', (e) => {
      e.preventDefault();
      const form = document.getElementById('customerPrintForm');
      if (form) {
        if (form.reportValidity()) {
          form.submit();
        }
      }
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
