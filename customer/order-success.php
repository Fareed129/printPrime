<?php
/**
 * PrimePrint Customer - Order Status & Payment Receipt Confirmation
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$token = trim($_GET['token'] ?? '');
$jobId = (int)($_GET['id'] ?? 0);

if (empty($token) && $jobId <= 0) {
    header("Location: " . APP_URL . "/login.php");
    exit;
}

$db = getDBConnection();
if (!empty($token)) {
    $stmt = $db->prepare("
        SELECT j.*, s.name AS shop_name, s.slug AS shop_slug, s.phone AS shop_phone 
        FROM print_jobs j 
        INNER JOIN shops s ON j.shop_id = s.id 
        WHERE j.public_token = :token 
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
} else {
    $stmt = $db->prepare("
        SELECT j.*, s.name AS shop_name, s.slug AS shop_slug, s.phone AS shop_phone 
        FROM print_jobs j 
        INNER JOIN shops s ON j.shop_id = s.id 
        WHERE j.id = :id 
        LIMIT 1
    ");
    $stmt->execute([':id' => $jobId]);
}
$job = $stmt->fetch();

if (!$job) {
    die("Error: Print job order not found.");
}

// If payment is still pending, route to review page
if ($job['payment_status'] !== 'completed' && !empty($job['public_token'])) {
    header("Location: " . APP_URL . "/customer/review.php?token=" . urlencode($job['public_token']));
    exit;
}

$pageTitle = 'Order Receipt #' . $job['public_token'] . ' — ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 20px 10px;">

  <div class="container" style="max-width: 520px;">
    
    <div class="card card-pp shadow-sm text-center p-4 mb-3">
      
      <!-- Success Icon -->
      <div class="d-inline-flex align-items-center justify-content-center bg-success text-white rounded-circle mb-3 mx-auto shadow-sm" style="width: 64px; height: 64px; font-size: 2rem;">
        <i class="bi bi-check-lg"></i>
      </div>

      <h4 class="fw-bold text-dark mb-1">Payment Confirmed & Spooling!</h4>
      <p class="text-muted small mb-3">Your print request has been paid and sent to <strong><?= e($job['shop_name']) ?></strong></p>

      <!-- Order ID Badge -->
      <div class="p-3 bg-light rounded-3 border mb-4">
        <span class="text-muted small d-block text-uppercase fw-semibold">Public Order Token</span>
        <span class="fw-mono fw-bold fs-2 text-primary"><?= e($job['public_token'] ?? '#' . $job['id']) ?></span>
      </div>

      <!-- Job Summary Details List -->
      <div class="list-group list-group-flush text-start small mb-4">
        <div class="list-group-item d-flex justify-content-between px-0 py-2">
          <span class="text-muted">Document:</span>
          <span class="fw-semibold text-dark text-truncate" style="max-width: 250px;"><?= e($job['file_name']) ?></span>
        </div>
        <div class="list-group-item d-flex justify-content-between px-0 py-2">
          <span class="text-muted">Pages & Copies:</span>
          <span class="fw-semibold text-dark"><?= $job['page_count'] ?> pages × <?= $job['copies'] ?> <?= $job['copies'] > 1 ? 'copies' : 'copy' ?></span>
        </div>
        <div class="list-group-item d-flex justify-content-between px-0 py-2">
          <span class="text-muted">Format:</span>
          <span class="badge bg-light text-secondary border"><?= e($job['paper_size']) ?> • <?= e($job['color_mode']) ?> • <?= e($job['side_mode']) ?></span>
        </div>
        <div class="list-group-item d-flex justify-content-between px-0 py-2">
          <span class="text-muted">Queue Status:</span>
          <span class="badge-status <?= e($job['status']) ?>"><?= e($job['status']) ?></span>
        </div>
        <div class="list-group-item d-flex justify-content-between px-0 py-2">
          <span class="fw-bold text-dark">Amount Paid:</span>
          <span class="fw-bold fs-5 text-success"><?= format_currency($job['amount']) ?></span>
        </div>
      </div>

      <div class="d-grid gap-2">
        <a href="<?= APP_URL ?>/p/<?= e($job['shop_slug']) ?>" class="btn btn-primary fw-semibold py-2">
          <i class="bi bi-plus-circle me-1"></i> Print Another Document
        </a>
      </div>

    </div>

    <div class="text-center text-muted small">
      <div>Need assistance? Call <?= e($job['shop_name']) ?>: <strong><?= e($job['shop_phone']) ?></strong></div>
      <div class="mt-1">&copy; <?= date('Y') ?> PrimePrint Cloud SaaS</div>
    </div>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
