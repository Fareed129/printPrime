<?php
/**
 * PrimePrint Shop - QR Standee & Customer Link Page (Reliable Standee Generator)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('shop');

$db = getDBConnection();
$shopUser = current_user();
$shopId = verify_shop_access($shopUser['shop_id']);

$stmt = $db->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $shopId]);
$shop = $stmt->fetch();

$customerUrl = APP_URL . "/p/" . $shop['slug'];

$pageTitle = 'Shop QR Standee — ' . $shop['name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 no-print">
  <div>
    <h3 class="fw-bold text-dark mb-1">Shop QR Code Standee</h3>
    <p class="text-muted small mb-0">Display or print this QR standee on your shop counter for instant customer self-service printing.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= $customerUrl ?>" target="_blank" class="btn btn-outline-primary d-flex align-items-center gap-2">
      <i class="bi bi-box-arrow-up-right"></i> Open Customer Portal
    </a>
    <button class="btn btn-outline-secondary d-flex align-items-center gap-2" onclick="copyToClipboard('<?= $customerUrl ?>', this)">
      <i class="bi bi-clipboard"></i> Copy URL
    </button>
    <button class="btn btn-primary d-flex align-items-center gap-2" id="btnDownloadQr">
      <i class="bi bi-download"></i> Download Standee PNG
    </button>
    <button class="btn btn-success d-flex align-items-center gap-2" onclick="window.print()">
      <i class="bi bi-printer"></i> Print Standee
    </button>
  </div>
</div>

<div class="row justify-content-center">
  <div class="col-md-8 col-lg-6">
    
    <!-- Printable QR Standee Card -->
    <div class="card card-pp printable-qr-card p-4 text-center shadow border-2">
      
      <!-- Standee Header -->
      <div class="mb-3">
        <div class="d-inline-flex align-items-center justify-content-center brand-badge mb-2 shadow-sm" style="width: 52px; height: 52px; font-size: 1.5rem; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; border-radius: 12px;">
          <i class="bi bi-printer-fill"></i>
        </div>
        <h3 class="fw-bold text-dark mb-1 font-heading"><?= e($shop['name']) ?></h3>
        <p class="text-primary fw-semibold small mb-0">⚡ Instant Self-Service Document Printing</p>
      </div>

      <div class="alert alert-primary py-2 px-3 mb-3 fw-bold small text-uppercase letter-spacing rounded-3">
        <i class="bi bi-phone-fill me-1"></i> Scan with Camera or Any UPI App
      </div>

      <!-- QR Code Canvas Container -->
      <div class="d-flex justify-content-center my-3">
        <div id="shopMainQrContainer" class="p-3 bg-white rounded-3 border shadow-sm" style="min-width: 270px; min-height: 270px; display: flex; align-items: center; justify-content: center;">
          <!-- Fallback placeholder image rendered immediately while JS initializes -->
          <img id="fallbackQrImg" src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=<?= urlencode($customerUrl) ?>" alt="Shop QR Code" style="width: 240px; height: 240px; display: block;" crossorigin="anonymous">
        </div>
      </div>

      <!-- Customer URL Info -->
      <div class="mt-2">
        <div class="small text-muted mb-1 fw-semibold">Direct Counter Link:</div>
        <div class="p-2 bg-light rounded border font-monospace fw-bold text-primary text-break small">
          <?= $customerUrl ?>
        </div>
      </div>

      <div class="mt-4 pt-3 border-top text-muted small">
        <div class="row g-2">
          <div class="col-4">
            <div class="p-2 bg-light rounded-3">
              <i class="bi bi-1-circle-fill text-primary d-block fs-5 mb-1"></i>
              <span class="fw-semibold text-dark d-block">1. Scan QR</span>
              <span style="font-size: 0.72rem;">Open camera / UPI</span>
            </div>
          </div>
          <div class="col-4">
            <div class="p-2 bg-light rounded-3">
              <i class="bi bi-2-circle-fill text-primary d-block fs-5 mb-1"></i>
              <span class="fw-semibold text-dark d-block">2. Upload File</span>
              <span style="font-size: 0.72rem;">PDF or Photos</span>
            </div>
          </div>
          <div class="col-4">
            <div class="p-2 bg-light rounded-3">
              <i class="bi bi-3-circle-fill text-primary d-block fs-5 mb-1"></i>
              <span class="fw-semibold text-dark d-block">3. Pay & Collect</span>
              <span style="font-size: 0.72rem;">Auto prints at desk</span>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-3 text-muted" style="font-size: 0.75rem;">
        Powered by <strong>PrimePrint Cloud SaaS</strong>
      </div>

    </div>

  </div>
</div>

<script>
(function() {
  const qrUrl = "<?= $customerUrl ?>";
  const shopName = "<?= addslashes(e($shop['name'])) ?>";
  const container = document.getElementById('shopMainQrContainer');
  let activeCanvas = null;

  function initQrCode() {
    if (typeof QRCode !== 'undefined' && container) {
      try {
        container.innerHTML = '';
        const qr = new QRCode(container, {
          text: qrUrl,
          width: 240,
          height: 240,
          colorDark: "#000000",
          colorLight: "#ffffff",
          correctLevel: (typeof QRCode.CorrectLevel !== 'undefined') ? QRCode.CorrectLevel.H : 2
        });
        
        // Locate generated canvas or image
        setTimeout(() => {
          activeCanvas = container.querySelector('canvas');
        }, 150);
      } catch (err) {
        console.warn('QRCode library error, using fallback image', err);
      }
    }
  }

  // Run on DOM ready & window load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initQrCode);
  } else {
    initQrCode();
  }
  window.addEventListener('load', initQrCode);

  // High-Resolution Standee PNG Exporter
  const btnDownload = document.getElementById('btnDownloadQr');
  if (btnDownload) {
    btnDownload.addEventListener('click', () => {
      let qrSource = container.querySelector('canvas') || container.querySelector('img') || document.getElementById('fallbackQrImg');
      
      if (!qrSource) {
        alert('QR code is still generating. Please try again in a moment.');
        return;
      }

      // Create high-res 800 x 1000 counter standee card
      const dlCanvas = document.createElement('canvas');
      dlCanvas.width = 800;
      dlCanvas.height = 1000;
      const ctx = dlCanvas.getContext('2d');

      // 1. Background Fill
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, 800, 1000);

      // 2. Outer Border Frame
      ctx.strokeStyle = '#2563eb';
      ctx.lineWidth = 8;
      ctx.strokeRect(20, 20, 760, 960);

      // Inner subtle border
      ctx.strokeStyle = '#e2e8f0';
      ctx.lineWidth = 2;
      ctx.strokeRect(32, 32, 736, 936);

      // 3. Top Blue Header Banner
      ctx.fillStyle = '#2563eb';
      ctx.fillRect(36, 36, 728, 140);

      // 4. Shop Name Text in Header
      ctx.fillStyle = '#ffffff';
      ctx.font = 'bold 36px "Plus Jakarta Sans", sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(shopName, 400, 95);

      ctx.fillStyle = '#bfdbfe';
      ctx.font = '600 18px sans-serif';
      ctx.fillText('⚡ Self-Service Cloud Printing Counter', 400, 135);

      // 5. Instruction Pill
      ctx.fillStyle = '#f1f5f9';
      ctx.fillRect(100, 205, 600, 50);
      ctx.strokeStyle = '#cbd5e1';
      ctx.lineWidth = 1;
      ctx.strokeRect(100, 205, 600, 50);

      ctx.fillStyle = '#0f172a';
      ctx.font = 'bold 18px sans-serif';
      ctx.fillText('📱 SCAN WITH ANY CAMERA OR UPI APP', 400, 237);

      // 6. Draw QR Code in Center (420 x 420 px)
      try {
        ctx.drawImage(qrSource, 190, 280, 420, 420);
      } catch (e) {
        console.error('Canvas drawImage error:', e);
      }

      // QR Outline
      ctx.strokeStyle = '#e2e8f0';
      ctx.lineWidth = 3;
      ctx.strokeRect(180, 270, 440, 440);

      // 7. Counter URL Box
      ctx.fillStyle = '#eff6ff';
      ctx.fillRect(80, 730, 640, 60);
      ctx.strokeStyle = '#93c5fd';
      ctx.lineWidth = 2;
      ctx.strokeRect(80, 730, 640, 60);

      ctx.fillStyle = '#1d4ed8';
      ctx.font = 'bold 20px monospace';
      ctx.fillText(qrUrl, 400, 768);

      // 8. 3 Steps Summary
      ctx.fillStyle = '#475569';
      ctx.font = '600 16px sans-serif';
      ctx.fillText('1. Scan QR   ➜   2. Upload Document   ➜   3. Pay & Collect Prints', 400, 835);

      // 9. Footer Branding
      ctx.fillStyle = '#94a3b8';
      ctx.font = '14px sans-serif';
      ctx.fillText('Powered by PrimePrint Cloud SaaS — www.primeprint.local', 400, 930);

      // Download triggered
      const dataUrl = dlCanvas.toDataURL('image/png');
      const link = document.createElement('a');
      link.download = '<?= e($shop['slug']) ?>-standee-qr.png';
      link.href = dataUrl;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
