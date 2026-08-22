<?php
/**
 * PrimePrint Admin - Profile & System Settings
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('admin');

$db = getDBConnection();
$currentUser = current_user();
$errors = [];

// Handle Admin Profile / Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($name)) $errors[] = 'Name is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required.';
    }

    if (!empty($newPassword)) {
        if (strlen($newPassword) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'Password confirmation does not match.';
        }
    }

    if (empty($errors)) {
        try {
            // Check unique email
            $stmt = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
            $stmt->execute([':email' => $email, ':id' => $currentUser['id']]);
            if ($stmt->fetch()) {
                $errors[] = 'This email address is already in use by another user.';
            } else {
                if (!empty($newPassword)) {
                    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("UPDATE users SET name = :name, email = :email, password_hash = :hash WHERE id = :id");
                    $stmt->execute([':name' => $name, ':email' => $email, ':hash' => $hash, ':id' => $currentUser['id']]);
                } else {
                    $stmt = $db->prepare("UPDATE users SET name = :name, email = :email WHERE id = :id");
                    $stmt->execute([':name' => $name, ':email' => $email, ':id' => $currentUser['id']]);
                }

                // Update session
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['email'] = $email;

                flash_set('success', 'Admin profile settings updated successfully.');
                header("Location: " . APP_URL . "/admin/settings.php");
                exit;
            }
        } catch (Exception $e) {
            $errors[] = 'Failed to update settings: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Settings — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
  <h3 class="fw-bold text-dark mb-1">System Settings & Profile</h3>
  <p class="text-muted small mb-0">Manage super admin credentials and platform configuration.</p>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger py-2" role="alert">
    <ul class="mb-0 ps-3">
      <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="row g-4">
  
  <div class="col-lg-6">
    <div class="card card-pp">
      <div class="card-pp-header">
        <h5 class="card-pp-title"><i class="bi bi-person-gear me-2 text-primary"></i>Super Admin Profile</h5>
      </div>
      <div class="card-body p-4">
        
        <form method="POST" action="<?= APP_URL ?>/admin/settings.php">
          <?= csrf_field() ?>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Full Name</label>
            <input type="text" class="form-control" name="name" value="<?= e($currentUser['name']) ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Email Address</label>
            <input type="email" class="form-control" name="email" value="<?= e($currentUser['email']) ?>" required>
          </div>

          <hr class="my-4">
          <h6 class="fw-bold text-dark mb-2">Change Password</h6>
          <p class="small text-muted mb-3">Leave blank if you do not wish to change your password.</p>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">New Password</label>
            <input type="password" class="form-control" name="new_password" placeholder="Minimum 6 characters">
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold text-secondary">Confirm New Password</label>
            <input type="password" class="form-control" name="confirm_password" placeholder="Re-type new password">
          </div>

          <button type="submit" class="btn btn-primary px-4 fw-semibold">
            <i class="bi bi-check2-circle me-1"></i> Update Settings
          </button>
        </form>

      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card card-pp">
      <div class="card-pp-header">
        <h5 class="card-pp-title"><i class="bi bi-hdd-stack me-2 text-primary"></i>Environment & Technology</h5>
      </div>
      <div class="card-body p-4">
        
        <div class="list-group list-group-flush">
          <div class="list-group-item d-flex justify-content-between px-0 py-2">
            <span class="text-muted">Application:</span>
            <span class="fw-semibold text-dark"><?= APP_NAME ?> v<?= APP_VERSION ?></span>
          </div>
          <div class="list-group-item d-flex justify-content-between px-0 py-2">
            <span class="text-muted">PHP Version:</span>
            <span class="fw-semibold text-dark"><?= PHP_VERSION ?></span>
          </div>
          <div class="list-group-item d-flex justify-content-between px-0 py-2">
            <span class="text-muted">Database Engine:</span>
            <span class="fw-semibold text-dark">MySQL / MariaDB (PDO)</span>
          </div>
          <div class="list-group-item d-flex justify-content-between px-0 py-2">
            <span class="text-muted">Max Document Size:</span>
            <span class="fw-semibold text-dark">25 MB</span>
          </div>
          <div class="list-group-item d-flex justify-content-between px-0 py-2">
            <span class="text-muted">Allowed File Types:</span>
            <span class="fw-semibold text-dark">PDF, JPG, JPEG, PNG</span>
          </div>
          <div class="list-group-item d-flex justify-content-between px-0 py-2">
            <span class="text-muted">Print Spooler Integration:</span>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">PrimePrint Desktop Agent (Electron)</span>
          </div>
        </div>

      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
