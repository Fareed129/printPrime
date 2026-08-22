<?php
/**
 * PrimePrint Admin - Edit Printing Shop
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('admin');

$db = getDBConnection();
$shopId = (int)($_GET['id'] ?? 0);

if ($shopId <= 0) {
    flash_set('danger', 'Invalid shop ID requested.');
    header("Location: " . APP_URL . "/admin/shops.php");
    exit;
}

$stmt = $db->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $shopId]);
$shop = $stmt->fetch();

if (!$shop) {
    flash_set('danger', 'Printing shop not found.');
    header("Location: " . APP_URL . "/admin/shops.php");
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $shopName  = trim($_POST['shop_name'] ?? '');
    $ownerName = trim($_POST['owner_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $status    = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $customSlug = trim($_POST['slug'] ?? '');

    if (empty($shopName))  $errors[] = 'Shop name is required.';
    if (empty($ownerName)) $errors[] = 'Owner name is required.';
    if (empty($phone))     $errors[] = 'Phone number is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required.';
    }

    if (empty($errors)) {
        try {
            $slugToUse = !empty($customSlug) ? slugify($customSlug) : slugify($shopName);
            $slug = generate_unique_shop_slug($db, $slugToUse, $shopId);

            $stmt = $db->prepare("
                UPDATE shops 
                SET name = :name, slug = :slug, owner_name = :owner_name, phone = :phone, email = :email, address = :address, status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                ':name'       => $shopName,
                ':slug'       => $slug,
                ':owner_name' => $ownerName,
                ':phone'      => $phone,
                ':email'      => $email,
                ':address'    => $address,
                ':status'     => $status,
                ':id'         => $shopId
            ]);

            flash_set('success', "Shop '{$shopName}' details updated successfully.");
            header("Location: " . APP_URL . "/admin/shop-view.php?id=" . $shopId);
            exit;

        } catch (Exception $e) {
            $errors[] = 'Failed to update shop: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Shop — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
  <a href="<?= APP_URL ?>/admin/shop-view.php?id=<?= $shopId ?>" class="text-decoration-none text-muted small">
    <i class="bi bi-arrow-left me-1"></i> Back to Shop Profile
  </a>
  <h3 class="fw-bold text-dark mt-1">Edit Shop Details</h3>
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

<div class="card card-pp" style="max-width: 800px;">
  <div class="card-pp-header">
    <h5 class="card-pp-title"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit: <?= e($shop['name']) ?></h5>
    <span class="badge-status <?= $shop['status'] ?>"><?= ucfirst($shop['status']) ?></span>
  </div>
  <div class="card-body p-4">
    
    <form method="POST" action="<?= APP_URL ?>/admin/shop-edit.php?id=<?= $shopId ?>">
      <?= csrf_field() ?>

      <div class="mb-3">
        <label for="shopNameInput" class="form-label fw-semibold text-secondary">Shop Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="shopNameInput" name="shop_name" value="<?= e($_POST['shop_name'] ?? $shop['name']) ?>" required>
      </div>

      <div class="mb-3">
        <label for="shopSlugInput" class="form-label fw-semibold text-secondary">Customer Portal Slug</label>
        <div class="input-group">
          <span class="input-group-text bg-light text-muted font-monospace"><?= APP_URL ?>/p/</span>
          <input type="text" class="form-control font-monospace" id="shopSlugInput" name="slug" value="<?= e($_POST['slug'] ?? $shop['slug']) ?>">
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold text-secondary">Owner Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="owner_name" value="<?= e($_POST['owner_name'] ?? $shop['owner_name']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold text-secondary">Phone Number <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="phone" value="<?= e($_POST['phone'] ?? $shop['phone']) ?>" required>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold text-secondary">Shop Email <span class="text-danger">*</span></label>
          <input type="email" class="form-control" name="email" value="<?= e($_POST['email'] ?? $shop['email']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold text-secondary">Operational Status</label>
          <select name="status" class="form-select">
            <option value="active" <?= ($shop['status'] === 'active') ? 'selected' : '' ?>>Active (Accepting Print Jobs)</option>
            <option value="inactive" <?= ($shop['status'] === 'inactive') ? 'selected' : '' ?>>Inactive (Temporarily Suspended)</option>
          </select>
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold text-secondary">Physical Address</label>
        <textarea class="form-control" name="address" rows="3"><?= e($_POST['address'] ?? $shop['address']) ?></textarea>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4 fw-semibold">Save Changes</button>
        <a href="<?= APP_URL ?>/admin/shop-view.php?id=<?= $shopId ?>" class="btn btn-outline-secondary">Cancel</a>
      </div>

    </form>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
