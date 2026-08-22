<?php
/**
 * PrimePrint Shop - Printers Management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('shop');

$db = getDBConnection();
$shopUser = current_user();
$shopId = verify_shop_access($shopUser['shop_id']);

// Handle Manual Printer Registration
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_printer') {
    require_csrf_token();
    $printerName = trim($_POST['printer_name'] ?? '');
    $identifier  = trim($_POST['printer_identifier'] ?? '');

    if (empty($printerName)) {
        $errors[] = 'Printer name is required.';
    } else {
        $stmt = $db->prepare("
            INSERT INTO printers (shop_id, printer_name, printer_identifier, status, last_seen)
            VALUES (:shop_id, :name, :identifier, 'idle', NOW())
        ");
        $stmt->execute([
            ':shop_id'    => $shopId,
            ':name'       => $printerName,
            ':identifier' => !empty($identifier) ? $identifier : 'MANUAL-' . strtoupper(bin2hex(random_bytes(3)))
        ]);
        flash_set('success', "Printer '{$printerName}' added successfully.");
        header("Location: " . APP_URL . "/shop/printers.php");
        exit;
    }
}

// Handle Delete Printer Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_printer') {
    require_csrf_token();
    $printerId = (int)($_POST['printer_id'] ?? 0);

    // Verify shop ownership
    $stmt = $db->prepare("DELETE FROM printers WHERE id = :id AND shop_id = :shop_id");
    $stmt->execute([':id' => $printerId, ':shop_id' => $shopId]);
    flash_set('success', 'Printer removed.');
    header("Location: " . APP_URL . "/shop/printers.php");
    exit;
}

// Fetch all registered printers for this shop
$stmt = $db->prepare("SELECT * FROM printers WHERE shop_id = :shop_id ORDER BY created_at DESC");
$stmt->execute([':shop_id' => $shopId]);
$printers = $stmt->fetchAll();

// Fetch agent
$stmt = $db->prepare("SELECT * FROM print_agents WHERE shop_id = :shop_id LIMIT 1");
$stmt->execute([':shop_id' => $shopId]);
$agent = $stmt->fetch();

$pageTitle = 'Manage Printers — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h3 class="fw-bold text-dark mb-1">Hardware & Printers</h3>
    <p class="text-muted small mb-0">Manage physical printers synced with your PrimePrint Desktop Agent.</p>
  </div>
  <button class="btn btn-primary btn-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addPrinterModal">
    <i class="bi bi-plus-circle-fill"></i> Register Printer Manually
  </button>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger py-2">
    <ul class="mb-0 ps-3">
      <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<!-- Print Agent Connection Status Card -->
<div class="card card-pp mb-4 border-start border-4 border-primary">
  <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
      <div class="stat-icon blue"><i class="bi bi-hdd-network-fill"></i></div>
      <div>
        <h6 class="fw-bold text-dark mb-0">PrimePrint Windows Desktop Agent</h6>
        <span class="text-muted small">
          <?php if ($agent): ?>
            Agent Name: <strong><?= e($agent['agent_name']) ?></strong> • Version: <?= e($agent['version']) ?> • Last Heartbeat: <?= format_date($agent['last_seen']) ?>
          <?php else: ?>
            No Desktop Print Agent paired yet. Install the PrimePrint Windows Agent on your counter computer.
          <?php endif; ?>
        </span>
      </div>
    </div>
    <div>
      <span class="badge-status <?= ($agent && $agent['status'] === 'online') ? 'online' : 'offline' ?>">
        <i class="bi bi-circle-fill" style="font-size: 0.5rem;"></i>
        <?= ($agent && $agent['status'] === 'online') ? 'Agent Online' : 'Agent Disconnected' ?>
      </span>
    </div>
  </div>
</div>

<!-- Printers Table Card -->
<div class="card card-pp">
  <div class="card-pp-header">
    <h5 class="card-pp-title"><i class="bi bi-printer me-2 text-primary"></i>Connected Printers (<?= count($printers) ?>)</h5>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-pp table-hover mb-0">
        <thead>
          <tr>
            <th>Printer Name</th>
            <th>Hardware Identifier</th>
            <th>Current Status</th>
            <th>Last Active</th>
            <th>Registered Date</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($printers)): ?>
            <tr>
              <td colspan="6" class="text-center py-5 text-muted">
                <i class="bi bi-printer fs-1 d-block mb-2 text-secondary"></i>
                No printers discovered yet. When you run PrimePrint Desktop Agent, your local Windows printers will automatically appear here!
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($printers as $p): ?>
              <tr>
                <td class="fw-semibold text-dark">
                  <i class="bi bi-printer-fill text-secondary me-2"></i>
                  <?= e($p['printer_name']) ?>
                </td>
                <td><code><?= e($p['printer_identifier'] ?? '-') ?></code></td>
                <td>
                  <span class="badge-status <?= e($p['status']) ?>">
                    <?= ucfirst(e($p['status'])) ?>
                  </span>
                </td>
                <td class="small text-muted"><?= format_date($p['last_seen']) ?></td>
                <td class="small text-muted"><?= format_date($p['created_at'], 'd M Y') ?></td>
                <td class="text-end">
                  <form method="POST" action="<?= APP_URL ?>/shop/printers.php" class="d-inline" onsubmit="return confirm('Remove printer from list?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_printer">
                    <input type="hidden" name="printer_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete Printer">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Add Printer Manually -->
<div class="modal fade" id="addPrinterModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="<?= APP_URL ?>/shop/printers.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_printer">

        <div class="modal-header">
          <h5 class="modal-title fw-bold">Register Printer Manually</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Printer Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="printer_name" placeholder="e.g. Canon imageRUNNER 2006N" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Identifier / Model (Optional)</label>
            <input type="text" class="form-control" name="printer_identifier" placeholder="e.g. CANON-COUNTER-1">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Printer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
