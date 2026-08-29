<?php
/**
 * Shop User Sidebar Navigation
 */

$currentScript = basename($_SERVER['PHP_SELF']);
$shopUser = current_user();

// Fetch shop name for sidebar branding if available
$sidebarShopName = 'My Print Shop';
if (!empty($shopUser['shop_id'])) {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT name FROM shops WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $shopUser['shop_id']]);
        $shopRow = $stmt->fetch();
        if ($shopRow) {
            $sidebarShopName = $shopRow['name'];
        }
    } catch (Exception $e) {}
}
?>
<aside class="app-sidebar">
  <div class="sidebar-header">
    <div class="fw-bold text-dark text-truncate" title="<?= e($sidebarShopName) ?>"><?= e($sidebarShopName) ?></div>
    <div class="d-flex align-items-center justify-content-between mt-1">
      <span class="badge bg-success-subtle text-success border border-success-subtle sidebar-role-badge">Shop Portal</span>
      <span class="small text-muted">ID: #<?= (int)$shopUser['shop_id'] ?></span>
    </div>
  </div>

  <ul class="sidebar-nav">
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'dashboard.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/shop/dashboard.php">
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'qr.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/shop/qr.php">
        <i class="bi bi-qr-code"></i>
        <span>Shop QR Code</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'printers.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/shop/printers.php">
        <i class="bi bi-printer"></i>
        <span>Printers</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'pricing.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/shop/pricing.php">
        <i class="bi bi-currency-rupee"></i>
        <span>Printing Prices</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'print-jobs.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/shop/print-jobs.php">
        <i class="bi bi-file-earmark-text"></i>
        <span>Print Jobs</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'payments.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/shop/payments.php">
        <i class="bi bi-wallet2"></i>
        <span>Payments</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'invoices.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/shop/invoices.php">
        <i class="bi bi-receipt"></i>
        <span>Invoices</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'subscription.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/shop/subscription.php">
        <i class="bi bi-award-fill text-warning"></i>
        <span>License & Plans</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'settings.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/shop/settings.php">
        <i class="bi bi-gear"></i>
        <span>Shop Settings</span>
      </a>
    </li>
  </ul>

  <div class="p-3 border-top mt-auto bg-light">
    <a href="<?= APP_URL ?>/logout.php" class="btn btn-outline-danger btn-sm w-100 d-flex align-items-center justify-content-center gap-2">
      <i class="bi bi-box-arrow-right"></i>
      <span>Sign Out</span>
    </a>
  </div>
</aside>
