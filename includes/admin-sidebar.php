<?php
/**
 * Super Admin Sidebar Navigation
 */

$currentScript = basename($_SERVER['PHP_SELF']);
?>
<aside class="app-sidebar">
  <div class="sidebar-header d-flex align-items-center justify-content-between">
    <span class="text-uppercase fw-bold text-muted small">Administration</span>
    <span class="badge bg-primary-subtle text-primary border border-primary-subtle sidebar-role-badge">Super Admin</span>
  </div>

  <ul class="sidebar-nav">
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'dashboard.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/dashboard.php">
        <i class="bi bi-grid-fill"></i>
        <span>Dashboard</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= (in_array($currentScript, ['shops.php', 'shop-add.php', 'shop-edit.php', 'shop-view.php'])) ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/shops.php">
        <i class="bi bi-shop"></i>
        <span>Shops</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'print-jobs.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/print-jobs.php">
        <i class="bi bi-file-earmark-text-fill"></i>
        <span>Print Jobs</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'payments.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/payments.php">
        <i class="bi bi-credit-card-2-front-fill"></i>
        <span>Payments</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'subscriptions.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/subscriptions.php">
        <i class="bi bi-award-fill"></i>
        <span>Subscriptions</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'reports.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/reports.php">
        <i class="bi bi-graph-up-arrow"></i>
        <span>Reports</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a class="sidebar-link <?= ($currentScript === 'settings.php') ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/settings.php">
        <i class="bi bi-sliders"></i>
        <span>Settings</span>
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
