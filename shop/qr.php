<?php
/**
 * PrimePrint Shop - QR Standee & Customer Link Page
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
    <p class="text-muted small mb-0">Display or print this QR standee on your shop counter for instant customer mobile ordering.</p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-secondary d-flex align-items-center gap-2" onclick="copyToClipboard('<?= $customerUrl ?>', this)">
      <i class="bi bi-clipboard"></i> Copy URL
    </button>
    <button class="btn btn-primary d-flex align-items-center gap-2" id="btnDownloadQr">
      <i class="bi bi-download"></i> Download QR PNG
    </button>
    <button class="btn btn-success d-flex align-items-center gap-2" onclick="window.print()">
      <i class="bi bi-printer"></i> Print QR Standee
    </button>
  </div>
</div>

<div class="row justify-content-center">
  <div class="col-md-8 col-lg-6">
    
    <!-- Printable QR Standee Card -->
    <div class="card card-pp printable-qr-card p-4 text-center shadow">
      
      <!-- Standee Header -->
      <div class="mb-3">
        <div class="d-inline-flex align-items-center justify-content-center brand-badge mb-2" style="width: 48px; height: 48px; font-size: 1.4rem;">
          <i class="bi bi-printer-fill"></i>
        </div>
        <h3 class="fw-bold text-dark mb-1"><?= e($shop['name']) ?></h3>
        <p class="text-secondary small mb-0">Self-Service Document Printing</p>
      </div>

      <div class="alert alert-primary py-2 px-3 mb-3 fw-semibold small text-uppercase letter-spacing">
        <i class="bi bi-phone-fill me-1"></i> Scan QR to Upload & Print Documents
      </div>

      <!-- QR Code Canvas Container -->
      <div class="d-flex justify-content-center my-3">
        <div id="shopMainQrContainer" class="p-3 bg-white rounded-3 border shadow-sm"></div>
      </div>

      <!-- Customer URL Info -->
      <div class="mt-2">
        <div class="small text-muted mb-1">Direct Customer URL:</div>
        <div class="p-2 bg-light rounded border font-monospace fw-bold text-primary text-break small">
          <?= $customerUrl ?>
        </div>
      </div>

      <div class="mt-3 pt-3 border-top text-muted small">
        <div class="row g-2">
          <div class="col-4">
            <i class="bi bi-1-circle-fill text-primary d-block fs-5"></i>
            <span>Scan QR</span>
          </div>
          <div class="col-4">
            <i class="bi bi-2-circle-fill text-primary d-block fs-5"></i>
            <span>Upload PDF</span>
          </div>
          <div class="col-4">
            <i class="bi bi-3-circle-fill text-primary d-block fs-5"></i>
            <span>Print & Collect</span>
          </div>
        </div>
      </div>

    </div>

  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  let qrGenerator = null;
  if (typeof QRCode !== 'undefined') {
    qrGenerator = new QRCode('shopMainQrContainer', {
      text: "<?= $customerUrl ?>",
      width: 240,
      height: 240
    });
  }

  // Download QR PNG button
  document.getElementById('btnDownloadQr').addEventListener('click', () => {
    const canvas = document.querySelector('#shopMainQrContainer canvas');
    if (!canvas) {
      alert('QR Code is still rendering.');
      return;
    }

    // Create high-res download canvas with shop name badge
    const dlCanvas = document.createElement('canvas');
    dlCanvas.width = 600;
    dlCanvas.height = 700;
    const ctx = dlCanvas.getContext('2d');

    // White background
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, 600, 700);

    // Border
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 4;
    ctx.strokeRect(10, 10, 580, 680);

    // Header Title
    ctx.fillStyle = '#1e3a8a';
    ctx.font = 'bold 28px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText("<?= addslashes(e($shop['name'])) ?>", 300, 65);

    ctx.fillStyle = '#64748b';
    ctx.font = '16px sans-serif';
    ctx.fillText("Scan to Print Your Documents", 300, 95);

    // Draw QR image
    ctx.drawImage(canvas, 100, 130, 400, 400);

    // Bottom URL
    ctx.fillStyle = '#2563eb';
    ctx.font = 'bold 18px monospace';
    ctx.fillText("<?= addslashes($customerUrl) ?>", 300, 570);

    ctx.fillStyle = '#94a3b8';
    ctx.font = '14px sans-serif';
    ctx.fillText("Powered by PrimePrint", 300, 640);

    const dataUrl = dlCanvas.toDataURL('image/png');
    const link = document.createElement('a');
    link.download = '<?= e($shop['slug']) ?>-primeprint-qr.png';
    link.href = dataUrl;
    link.click();
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
