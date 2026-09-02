<?php
/**
 * PrimePrint Shop Portal Login
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/helpers.php';

// Redirect if already logged in
if (is_logged_in()) {
    $user = current_user();
    if ($user['role'] === 'shop') {
        header("Location: " . APP_URL . "/shop/dashboard.php");
    } else {
        header("Location: " . APP_URL . "/admin/dashboard.php");
    }
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid shop email address.';
    }
    if (empty($password)) {
        $errors[] = 'Please enter your password.';
    }

    if (empty($errors)) {
        try {
            $db = getDBConnection();
            $stmt = $db->prepare("
                SELECT u.*, s.name AS shop_name, s.status AS shop_status, s.email AS shop_email 
                FROM users u 
                INNER JOIN shops s ON u.shop_id = s.id 
                WHERE (LOWER(TRIM(u.email)) = LOWER(TRIM(:email_u)) OR LOWER(TRIM(s.email)) = LOWER(TRIM(:email_s))) 
                  AND u.role = 'shop' 
                ORDER BY (LOWER(TRIM(u.email)) = LOWER(TRIM(:email_ord))) DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':email_u'   => $email,
                ':email_s'   => $email,
                ':email_ord' => $email
            ]);
            $user = $stmt->fetch();


            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['status'] !== 'active') {
                    $errors[] = 'Your shop login account is inactive. Please contact your administrator.';
                } elseif ($user['shop_status'] !== 'active') {
                    $errors[] = 'This printing shop is currently deactivated. Please contact support.';
                } else {
                    // Auto-heal: If user logged in using the updated shop email, sync users.email immediately
                    if (strtolower(trim($user['email'])) !== strtolower(trim($email))) {
                        $syncStmt = $db->prepare("UPDATE users SET email = :email WHERE id = :id");
                        $syncStmt->execute([':email' => $email, ':id' => $user['id']]);
                        $user['email'] = $email;
                    }

                    login_user($user);
                    flash_set('success', "Welcome to {$user['shop_name']} Dashboard!");
                    header("Location: " . APP_URL . "/shop/dashboard.php");
                    exit;
                }
            } else {
                $errors[] = 'Invalid shop email address or password.';
            }
        } catch (Exception $e) {
            $errors[] = 'An error occurred. Please try again.';
        }
    }
}

$pageTitle = 'Shop Login — ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">

  <div class="container" style="max-width: 440px;">
    
    <div class="text-center mb-4">
      <div class="d-inline-flex align-items-center justify-content-center brand-badge mb-2 bg-success" style="width: 52px; height: 52px; font-size: 1.6rem; background: linear-gradient(135deg, #059669 0%, #10b981 100%);">
        <i class="bi bi-shop"></i>
      </div>
      <h2 class="fw-bold text-dark mb-1"><?= APP_NAME ?></h2>
      <p class="text-muted small">Printing Shop Partner Portal</p>
    </div>

    <div class="card card-pp shadow-sm">
      <div class="card-pp-body p-4">
        
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h5 class="fw-bold text-dark mb-0">Shop Sign In</h5>
          <span class="badge bg-success-subtle text-success border border-success-subtle">Shop User</span>
        </div>

        <?php require_once __DIR__ . '/../includes/alerts.php'; ?>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger py-2 small" role="alert">
            <ul class="mb-0 ps-3">
              <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/shop/login.php">
          <?= csrf_field() ?>

          <div class="mb-3">
            <label for="emailInput" class="form-label small fw-semibold text-secondary">Shop Email Address</label>
            <div class="input-group">
              <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
              <input type="email" class="form-control" id="emailInput" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="shop@abcprinting.local" required autofocus>
            </div>
          </div>

          <div class="mb-4">
            <label for="passwordInput" class="form-label small fw-semibold text-secondary">Password</label>
            <div class="input-group">
              <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
              <input type="password" class="form-control" id="passwordInput" name="password" placeholder="••••••••" required>
            </div>
          </div>

          <button type="submit" class="btn btn-success w-100 py-2 fw-semibold">
            <i class="bi bi-box-arrow-in-right me-2"></i>Sign In to Shop Dashboard
          </button>
        </form>

      </div>
    </div>

    <div class="text-center mt-3 text-muted small">
      <a href="<?= APP_URL ?>/login.php" class="text-decoration-none text-muted">
        <i class="bi bi-shield-lock me-1"></i>Super Admin Login
      </a>
    </div>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
