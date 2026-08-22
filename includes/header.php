<?php
/**
 * PrimePrint Global Header Include
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

$currentUser = current_user();
$pageTitle = $pageTitle ?? APP_NAME . ' — Cloud Printing Solution';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?></title>

  <!-- Bootstrap 5.3 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <!-- PrimePrint Custom Design System -->
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>

  <!-- Top Navigation Bar -->
  <nav class="navbar navbar-expand-lg navbar-primeprint sticky-top py-2">
    <div class="container-fluid px-4">
      <a class="navbar-brand d-flex align-items-center gap-2" href="<?= APP_URL ?>/">
        <span class="brand-badge"><i class="bi bi-printer-fill"></i></span>
        <span class="fw-bold fs-5 text-dark"><?= APP_NAME ?></span>
      </a>

      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNavCollapse">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="topNavCollapse">
        <ul class="navbar-nav ms-auto align-items-center gap-2">
          <?php if ($currentUser): ?>
            <li class="nav-item">
              <span class="badge <?= $currentUser['role'] === 'admin' ? 'bg-primary' : 'bg-success' ?> rounded-pill px-3 py-2">
                <i class="bi <?= $currentUser['role'] === 'admin' ? 'bi-shield-lock-fill' : 'bi-shop' ?> me-1"></i>
                <?= $currentUser['role'] === 'admin' ? 'Super Admin' : 'Shop Manager' ?>
              </span>
            </li>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 fw-semibold text-dark" href="#" role="button" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle fs-5 text-primary"></i>
                <?= e($currentUser['name']) ?>
              </a>
              <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                <?php if ($currentUser['role'] === 'admin'): ?>
                  <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                <?php else: ?>
                  <li><a class="dropdown-item" href="<?= APP_URL ?>/shop/settings.php"><i class="bi bi-gear me-2"></i>Shop Profile</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
              </ul>
            </li>
          <?php else: ?>
            <li class="nav-item">
              <a class="btn btn-outline-primary btn-sm px-3" href="<?= APP_URL ?>/login.php"><i class="bi bi-box-arrow-in-right me-1"></i>Admin Login</a>
            </li>
            <li class="nav-item">
              <a class="btn btn-primary btn-sm px-3" href="<?= APP_URL ?>/shop/login.php"><i class="bi bi-shop me-1"></i>Shop Login</a>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </nav>

  <?php if ($currentUser): ?>
    <div class="app-layout">
      <?php 
        if ($currentUser['role'] === 'admin') {
            require_once __DIR__ . '/admin-sidebar.php';
        } else {
            require_once __DIR__ . '/shop-sidebar.php';
        }
      ?>
      <main class="app-main">
        <?php require_once __DIR__ . '/alerts.php'; ?>
  <?php else: ?>
    <?php require_once __DIR__ . '/alerts.php'; ?>
  <?php endif; ?>
