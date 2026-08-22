<?php
/**
 * PrimePrint Admin - Add New Printing Shop
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('admin');

$db = getDBConnection();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $shopName    = trim($_POST['shop_name'] ?? '');
    $ownerName   = trim($_POST['owner_name'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $shopEmail   = trim($_POST['shop_email'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $customSlug  = trim($_POST['slug'] ?? '');

    $userName    = trim($_POST['user_name'] ?? '');
    $userEmail   = trim($_POST['user_email'] ?? '');
    $userPass    = $_POST['user_password'] ?? '';

    // Validation
    if (empty($shopName))  $errors[] = 'Shop name is required.';
    if (empty($ownerName)) $errors[] = 'Owner name is required.';
    if (empty($phone))     $errors[] = 'Phone number is required.';
    if (empty($shopEmail) || !filter_var($shopEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid shop email is required.';
    }

    if (empty($userName))  $errors[] = 'Shop manager name is required.';
    if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid shop manager login email is required.';
    }
    if (empty($userPass) || strlen($userPass) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    // Check if user login email already exists
    if (empty($errors)) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $userEmail]);
        if ($stmt->fetch()) {
            $errors[] = "A user account with email '{$userEmail}' already exists.";
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // 1. Generate unique slug
            $slugToUse = !empty($customSlug) ? slugify($customSlug) : slugify($shopName);
            $slug = generate_unique_shop_slug($db, $slugToUse);

            // 2. Insert Shop
            $stmt = $db->prepare("
                INSERT INTO shops (name, slug, owner_name, phone, email, address, status)
                VALUES (:name, :slug, :owner_name, :phone, :email, :address, 'active')
            ");
            $stmt->execute([
                ':name'       => $shopName,
                ':slug'       => $slug,
                ':owner_name' => $ownerName,
                ':phone'      => $phone,
                ':email'      => $shopEmail,
                ':address'    => $address
            ]);
            $shopId = (int)$db->lastInsertId();

            // 3. Insert Shop User
            $passwordHash = password_hash($userPass, PASSWORD_BCRYPT);
            $stmt = $db->prepare("
                INSERT INTO users (name, email, password_hash, role, shop_id, status)
                VALUES (:name, :email, :password_hash, 'shop', :shop_id, 'active')
            ");
            $stmt->execute([
                ':name'          => $userName,
                ':email'         => $userEmail,
                ':password_hash' => $passwordHash,
                ':shop_id'       => $shopId
            ]);

            // 4. Seed Standard Default Pricing for the new shop
            $defaultPricing = [
                ['paper_size' => 'A4', 'color_mode' => 'BW', 'side_mode' => 'single', 'price' => 2.00],
                ['paper_size' => 'A4', 'color_mode' => 'BW', 'side_mode' => 'double', 'price' => 3.00],
                ['paper_size' => 'A4', 'color_mode' => 'COLOR', 'side_mode' => 'single', 'price' => 10.00],
                ['paper_size' => 'A4', 'color_mode' => 'COLOR', 'side_mode' => 'double', 'price' => 15.00],
                ['paper_size' => 'A3', 'color_mode' => 'BW', 'side_mode' => 'single', 'price' => 5.00],
                ['paper_size' => 'A3', 'color_mode' => 'COLOR', 'side_mode' => 'single', 'price' => 20.00]
            ];

            $priceStmt = $db->prepare("
                INSERT INTO pricing (shop_id, paper_size, color_mode, side_mode, price_per_page, active)
                VALUES (:shop_id, :paper_size, :color_mode, :side_mode, :price, 1)
            ");
            foreach ($defaultPricing as $dp) {
                $priceStmt->execute([
                    ':shop_id'    => $shopId,
                    ':paper_size' => $dp['paper_size'],
                    ':color_mode' => $dp['color_mode'],
                    ':side_mode'  => $dp['side_mode'],
                    ':price'      => $dp['price']
                ]);
            }

            $db->commit();
            flash_set('success', "Printing Shop '{$shopName}' created successfully with dedicated login!");
            header("Location: " . APP_URL . "/admin/shop-view.php?id=" . $shopId);
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to create shop: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Add Printing Shop — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
  <a href="<?= APP_URL ?>/admin/shops.php" class="text-decoration-none text-muted small">
    <i class="bi bi-arrow-left me-1"></i> Back to Shops List
  </a>
  <h3 class="fw-bold text-dark mt-1">Register New Printing Shop</h3>
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

<form method="POST" action="<?= APP_URL ?>/admin/shop-add.php">
  <?= csrf_field() ?>

  <div class="row g-4">
    
    <!-- Shop Information Card -->
    <div class="col-lg-7">
      <div class="card card-pp">
        <div class="card-pp-header">
          <h5 class="card-pp-title"><i class="bi bi-shop me-2 text-primary"></i>Shop Details</h5>
        </div>
        <div class="card-body p-4">
          
          <div class="mb-3">
            <label for="shopNameInput" class="form-label fw-semibold text-secondary">Shop Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="shopNameInput" name="shop_name" value="<?= e($_POST['shop_name'] ?? '') ?>" placeholder="e.g. Metro Express Digital Printing" required>
          </div>

          <div class="mb-3">
            <label for="shopSlugInput" class="form-label fw-semibold text-secondary">Customer Portal Slug (URL Identifier)</label>
            <div class="input-group">
              <span class="input-group-text bg-light text-muted font-monospace"><?= APP_URL ?>/p/</span>
              <input type="text" class="form-control font-monospace" id="shopSlugInput" name="slug" value="<?= e($_POST['slug'] ?? '') ?>" placeholder="metro-express-digital-printing">
            </div>
            <div class="form-text small">Automatically generated from the shop name. Must be unique.</div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold text-secondary">Owner / Contact Person <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="owner_name" value="<?= e($_POST['owner_name'] ?? '') ?>" placeholder="e.g. Ramesh Kumar" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold text-secondary">Phone Number <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="+91 9876543210" required>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Shop Email Address <span class="text-danger">*</span></label>
            <input type="email" class="form-control" name="shop_email" value="<?= e($_POST['shop_email'] ?? '') ?>" placeholder="contact@metroprinting.com" required>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Physical Address</label>
            <textarea class="form-control" name="address" rows="3" placeholder="Shop #12, Ground Floor, Central Mall, MG Road..."><?= e($_POST['address'] ?? '') ?></textarea>
          </div>

        </div>
      </div>
    </div>

    <!-- Shop Manager Credentials Card -->
    <div class="col-lg-5">
      <div class="card card-pp">
        <div class="card-pp-header">
          <h5 class="card-pp-title"><i class="bi bi-person-badge me-2 text-primary"></i>Shop Login Credentials</h5>
        </div>
        <div class="card-body p-4">
          <p class="small text-muted mb-3">Create the primary login account for the shop owner or staff manager.</p>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Manager Full Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="user_name" value="<?= e($_POST['user_name'] ?? '') ?>" placeholder="e.g. Ramesh Kumar" required>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Login Email <span class="text-danger">*</span></label>
            <input type="email" class="form-control" name="user_email" value="<?= e($_POST['user_email'] ?? '') ?>" placeholder="shop-admin@metroprinting.com" required>
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold text-secondary">Login Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" name="user_password" placeholder="Minimum 6 characters" required>
          </div>

          <div class="alert alert-info py-2 small mb-4">
            <i class="bi bi-info-circle me-1"></i> Standard default pricing (A4 BW ₹2, A4 Color ₹10) will be automatically configured for this shop.
          </div>

          <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
            <i class="bi bi-check2-circle me-2"></i>Create Shop & Generate Portal
          </button>

        </div>
      </div>
    </div>

  </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
