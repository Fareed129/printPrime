<?php
/**
 * PrimePrint Public Marketing Website Header
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

$currentUser = current_user();
$pageTitle = $pageTitle ?? 'PrimePrint — Printing Shop Automation Software';
$metaDescription = $metaDescription ?? 'PrimePrint helps printing shops automate customer document uploads, online payments, and print-job routing with QR-based self-service.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e($metaDescription) ?>">
  <meta name="keywords" content="printing shop software, print shop automation, QR printing, online printing, document printing counter, cloud print queue, digital print shop, xerox shop software">
  <link rel="canonical" href="<?= APP_URL ?>/">

  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= APP_URL ?>/">
  <meta property="og:title" content="<?= e($pageTitle) ?>">
  <meta property="og:description" content="<?= e($metaDescription) ?>">
  <meta property="og:image" content="<?= APP_URL ?>/assets/images/marketing/hero_print_shop.jpg">

  <!-- Twitter / X -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($pageTitle) ?>">
  <meta name="twitter:description" content="<?= e($metaDescription) ?>">
  <meta name="twitter:image" content="<?= APP_URL ?>/assets/images/marketing/hero_print_shop.jpg">

  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <!-- PrimePrint Marketing Stylesheet -->
  <link rel="stylesheet" href="<?= asset_url('assets/css/marketing.css') ?>">
</head>
<body class="pp-marketing">

  <!-- Registration Crosshair Marks on Canvas Edges -->
  <div class="reg-mark" style="top: 24px; left: 24px;"><span class="reg-mark-circle"></span></div>
  <div class="reg-mark" style="top: 24px; right: 24px;"><span class="reg-mark-circle"></span></div>

  <!-- Header & Navigation -->
  <header class="mkt-header">
    <nav class="mkt-navbar">
      <a href="<?= APP_URL ?>/" class="mkt-brand">
        <div class="mkt-brand-icon">
          <i class="bi bi-printer-fill"></i>
        </div>
        <div class="d-flex align-items-center">
          <span class="mkt-brand-text">PrimePrint</span>
          <span class="mkt-brand-badge">SaaS</span>
        </div>
      </a>

      <!-- Desktop Navigation Links -->
      <ul class="mkt-nav-links">
        <li><a href="#product" class="mkt-nav-link">Product</a></li>
        <li><a href="#how-it-works" class="mkt-nav-link">How It Works</a></li>
        <li><a href="#for-shops" class="mkt-nav-link">For Shops</a></li>
        <li><a href="#architecture" class="mkt-nav-link">Architecture</a></li>
        <li><a href="#faq" class="mkt-nav-link">FAQ</a></li>
      </ul>

      <!-- Action CTAs -->
      <div class="mkt-nav-actions">
        <?php if ($currentUser): ?>
          <a href="<?= APP_URL ?>/<?= $currentUser['role'] === 'admin' ? 'admin/dashboard.php' : 'shop/dashboard.php' ?>" class="btn-pp-primary">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard</span>
          </a>
        <?php else: ?>
          <button type="button" class="btn-pp-outline" onclick="openLoginModal()">
            <i class="bi bi-person-circle"></i>
            <span>Login</span>
          </button>
          <button type="button" class="btn-pp-primary" onclick="openContactModal()">
            <span>Get Started</span>
            <i class="bi bi-arrow-right"></i>
          </button>
        <?php endif; ?>

        <!-- Mobile Menu Toggle Button -->
        <button type="button" class="mkt-mobile-toggle" id="mobileNavToggle" aria-label="Toggle Navigation Menu">
          <i class="bi bi-list"></i>
        </button>
      </div>
    </nav>
  </header>
