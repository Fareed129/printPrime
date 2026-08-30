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

  // 3. Customer Document Upload Dropzone & Live Calculation Engine
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

  // Live displays & buttons
  const priceDisplay = document.getElementById('livePriceDisplay');
  const stickyPriceDisplay = document.getElementById('stickyPriceDisplay');
  const unitRateDisplay = document.getElementById('liveUnitRateDisplay');
  const submitBtn = document.getElementById('btnSubmitOrder');
  const submitBtnSticky = document.getElementById('btnSubmitOrderSticky');
  const customerForm = document.getElementById('customerPrintForm');
  const alertBox = document.getElementById('customerAlertBox');
  const alertMsg = document.getElementById('customerAlertMsg');
  const alertIcon = document.getElementById('customerAlertIcon');

  function setCustomerAlert(type, message) {
    if (!alertBox || !alertMsg) return;
    alertBox.className = `alert alert-${type} py-2 px-3 small mb-3 rounded-3 d-flex align-items-center gap-2`;
    if (alertIcon) {
      alertIcon.className = `bi ${type === 'danger' ? 'bi-exclamation-circle-fill text-danger' : (type === 'success' ? 'bi-check-circle-fill text-success' : (type === 'warning' ? 'bi-exclamation-triangle-fill text-warning' : 'bi-info-circle-fill text-primary'))} flex-shrink-0`;
    }
    alertMsg.innerHTML = message;
    alertBox.classList.remove('d-none');
    alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

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
      setCustomerAlert('danger', 'File size exceeds the maximum limit of 25MB.');
      fileInput.value = '';
      if (filePreview) filePreview.classList.add('d-none');
      if (dropzone) dropzone.classList.remove('d-none');
      updateEstimatedPrice();
      return;
    }

    const ext = file.name.split('.').pop().toLowerCase();
    if (!['pdf', 'jpg', 'jpeg', 'png'].includes(ext)) {
      setCustomerAlert('danger', 'Only PDF, JPG, JPEG, and PNG files are supported.');
      fileInput.value = '';
      if (filePreview) filePreview.classList.add('d-none');
      if (dropzone) dropzone.classList.remove('d-none');
      updateEstimatedPrice();
      return;
    }

    if (alertBox) alertBox.classList.add('d-none');

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
    const isReadyToSubmit = isOptionAvailable && hasFile;

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
  document.querySelectorAll('input[name="paper_size"], input[name="color_mode"], input[name="side_mode"], select[name="paper_size"], select[name="color_mode"], select[name="side_mode"]').forEach(el => {
    el.addEventListener('change', updateEstimatedPrice);
  });

  if (copiesInput) {
    copiesInput.addEventListener('input', updateEstimatedPrice);
    copiesInput.addEventListener('change', updateEstimatedPrice);
  }

  // =========================================================================
  // 4. INSTANT 1-CLICK CHECKOUT & RAZORPAY PAYMENT TRIGGER
  // =========================================================================
  async function handleQuickCheckout(e) {
    if (e) e.preventDefault();
    if (!customerForm) return;

    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
      setCustomerAlert('warning', 'Please choose a document to print first.');
      if (dropzone) dropzone.click();
      return;
    }

    const originalDesktopHtml = submitBtn ? submitBtn.innerHTML : '';
    const originalStickyHtml = submitBtnSticky ? submitBtnSticky.innerHTML : '';

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Preparing Order & Gateway...';
    }
    if (submitBtnSticky) {
      submitBtnSticky.disabled = true;
      submitBtnSticky.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Preparing...';
    }

    try {
      const formData = new FormData(customerForm);

      const res = await fetch('/api/customer/quick-checkout.php', {
        method: 'POST',
        body: formData
      });

      const data = await res.json();

      if (!data.success) {
        setCustomerAlert('danger', data.error || 'Failed to prepare order. Please check with the counter.');
        restoreButtons();
        return;
      }

      // If Razorpay JS SDK isn't available on client, fallback to review URL
      if (typeof Razorpay === 'undefined') {
        window.location.href = `/customer/review.php?token=${encodeURIComponent(data.token)}`;
        return;
      }

      // Launch Razorpay Checkout Modal Directly on Current Screen
      const options = {
        key: data.key_id,
        amount: data.amount,
        currency: data.currency || 'INR',
        name: data.shop_name || 'PrimePrint',
        description: `Print Order (${data.page_count} pgs × ${data.copies} ${data.copies > 1 ? 'copies' : 'copy'})`,
        order_id: data.order_id,
        prefill: {
          name: 'Counter Customer',
          email: 'customer@primeprint.local',
          contact: '9876543210'
        },
        theme: { color: '#2563eb' },
        handler: async function (response) {
          if (submitBtn) submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Verifying Payment...';
          if (submitBtnSticky) submitBtnSticky.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Verifying...';
          setCustomerAlert('info', 'Payment authorized! Verifying signature...');

          try {
            const verifyRes = await fetch('/api/payment/verify.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                token: data.token,
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_order_id: response.razorpay_order_id,
                razorpay_signature: response.razorpay_signature
              })
            });

            const verifyData = await verifyRes.json();
            if (verifyData.success) {
              setCustomerAlert('success', 'Payment verified! Redirecting to live print tracker...');
              window.location.href = verifyData.redirect_url || `/customer/order-success.php?token=${encodeURIComponent(data.token)}`;
            } else {
              setCustomerAlert('danger', verifyData.error || 'Signature verification failed. Please show your transaction ID to the counter.');
              restoreButtons();
            }
          } catch (err) {
            setCustomerAlert('danger', 'Network error during verification. Please show your payment receipt to the counter.');
            restoreButtons();
          }
        },
        modal: {
          ondismiss: function () {
            setCustomerAlert('warning', '<strong>Payment Cancelled</strong> — Tap <strong>Pay & Print</strong> when you are ready to complete your order.');
            restoreButtons();
          }
        }
      };

      const rzp = new Razorpay(options);
      rzp.on('payment.failed', function (resp) {
        setCustomerAlert('danger', `Payment declined: ${resp.error.description || 'Transaction failed.'}`);
        restoreButtons();
      });

      rzp.open();

    } catch (err) {
      console.error('Quick checkout error:', err);
      setCustomerAlert('danger', 'Unable to initiate payment gateway. Please try again.');
      restoreButtons();
    }

    function restoreButtons() {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalDesktopHtml || '<i class="bi bi-lightning-charge-fill me-2"></i> Pay & Print Instantly';
      }
      if (submitBtnSticky) {
        submitBtnSticky.disabled = false;
        submitBtnSticky.innerHTML = originalStickyHtml || '<span>⚡ Pay & Print</span><i class="bi bi-arrow-right-short fs-5"></i>';
      }
    }
  }

  if (submitBtn) {
    submitBtn.addEventListener('click', handleQuickCheckout);
  }
  if (submitBtnSticky) {
    submitBtnSticky.addEventListener('click', handleQuickCheckout);
  }
  if (customerForm) {
    customerForm.addEventListener('submit', handleQuickCheckout);
  }

  // Initial estimate calculation on page load
  updateEstimatedPrice();

  // 5. Copy to Clipboard Utility
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
