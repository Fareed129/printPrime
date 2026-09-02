<?php
/**
 * PrimePrint Unified / Admin Login
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/helpers.php';

// Redirect if already logged in
if (is_logged_in()) {
    $user = current_user();
    header("Location: " . ($user['role'] === 'admin' ? APP_URL . '/admin/dashboard.php' : APP_URL . '/shop/dashboard.php'));
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (empty($password)) {
        $errors[] = 'Please enter your password.';
    }

    if (empty($errors)) {
        try {
            $db = getDBConnection();
            $stmt = $db->prepare("
                SELECT u.*, s.status AS shop_status 
                FROM users u 
                LEFT JOIN shops s ON u.shop_id = s.id 
                WHERE u.email = :email 
                LIMIT 1
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['status'] !== 'active') {
                    $errors[] = 'Your user account is inactive. Please contact the administrator.';
                } elseif ($user['role'] === 'shop' && $user['shop_status'] !== 'active') {
                    $errors[] = 'Your printing shop is currently deactivated. Please contact support.';
                } else {
                    login_user($user);
                    flash_set('success', "Welcome back, {$user['name']}!");
                    
                    if ($user['role'] === 'admin') {
                        header("Location: " . APP_URL . "/admin/dashboard.php");
                    } else {
                        header("Location: " . APP_URL . "/shop/dashboard.php");
                    }
                    exit;
                }
            } else {
                $errors[] = 'Invalid email address or password.';
            }
        } catch (Exception $e) {
            $errors[] = 'An error occurred during authentication. Please try again.';
        }
    }
}

$pageTitle = 'Login — ' . APP_NAME;
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
    
    <!-- Brand Title -->
    <div class="text-center mb-4">
      <div class="d-inline-flex align-items-center justify-content-center brand-badge mb-2" style="width: 52px; height: 52px; font-size: 1.6rem;">
        <i class="bi bi-printer-fill"></i>
      </div>
      <h2 class="fw-bold text-dark mb-1"><?= APP_NAME ?></h2>
      <p class="text-muted small">Multi-Tenant Cloud Printing Platform</p>
    </div>

    <!-- Login Card -->
    <div class="card card-pp shadow-sm">
      <div class="card-pp-body p-4">
        
        <h5 class="fw-bold mb-3 text-dark">Sign In</h5>

        <?php require_once __DIR__ . '/includes/alerts.php'; ?>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger py-2 small" role="alert">
            <ul class="mb-0 ps-3">
              <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/login.php">
          <?= csrf_field() ?>

          <div class="mb-3">
            <label for="emailInput" class="form-label small fw-semibold text-secondary">Email Address</label>
            <div class="input-group">
              <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
              <input type="email" class="form-control" id="emailInput" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="admin@primeprint.local" required autofocus>
            </div>
          </div>

          <div class="mb-4">
            <label for="passwordInput" class="form-label small fw-semibold text-secondary">Password</label>
            <div class="input-group">
              <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
              <input type="password" class="form-control" id="passwordInput" name="password" placeholder="••••••••" required>
            </div>
          </div>

          <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
            <i class="bi bi-box-arrow-in-right me-2"></i>Sign In to Console
          </button>
        </form>

      </div>
    </div>

    <!-- Quick Credentials Hint for Development -->
    <div class="card card-pp mt-3 border-dashed bg-white">
      <div class="card-body p-3 small text-muted">
        <div class="fw-bold text-dark mb-1"><i class="bi bi-key-fill text-warning me-1"></i>Development Test Logins:</div>
        <div><strong>Admin:</strong> <code>admin@primeprint.local</code> / <code>ChangeMe123!</code></div>
        <div><strong>Shop:</strong> <code>shop@abcprinting.local</code> / <code>ChangeMe123!</code></div>
        <div class="mt-2 text-danger fst-italic" style="font-size:0.75rem;">*Change these default credentials before deploying to production.</div>
      </div>
    </div>

    <div class="text-center mt-3 text-muted small">
      <a href="<?= APP_URL ?>/p/abc-digital-printing" class="text-decoration-none text-muted">
        <i class="bi bi-qr-code-scan me-1"></i>Visit Customer Demo Upload Page
      </a>
    </div>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
