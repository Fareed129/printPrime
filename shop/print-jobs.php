<?php
/**
 * PrimePrint Shop - Print Jobs Queue & History
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

// Handle Status Override by Shop Operator (e.g., Mark as Printed or Cancel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    require_csrf_token();
    $jobId = (int)($_POST['job_id'] ?? 0);
    $newStatus = trim($_POST['new_status'] ?? '');

    $allowedStatuses = ['QUEUED', 'PRINTING', 'PRINTED', 'CANCELLED'];
    if ($jobId > 0 && in_array($newStatus, $allowedStatuses, true)) {
        $printedAt = ($newStatus === 'PRINTED') ? date('Y-m-d H:i:s') : null;
        $stmt = $db->prepare("
            UPDATE print_jobs 
            SET status = :status, printed_at = COALESCE(:printed_at, printed_at)
            WHERE id = :id AND shop_id = :shop_id
        ");
        $stmt->execute([
            ':status'     => $newStatus,
            ':printed_at' => $printedAt,
            ':id'         => $jobId,
            ':shop_id'    => $shopId
        ]);
        flash_set('success', "Job #{$jobId} marked as {$newStatus}.");
    }
    header("Location: " . APP_URL . "/shop/print-jobs.php");
    exit;
}

// Search and Filter Params
$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT j.*, p.printer_name 
    FROM print_jobs j 
    LEFT JOIN printers p ON j.printer_id = p.id 
    WHERE j.shop_id = :shop_id
";
$params = [':shop_id' => $shopId];

if (!empty($statusFilter)) {
    $sql .= " AND j.status = :status";
    $params[':status'] = $statusFilter;
}

if (!empty($search)) {
    $sql .= " AND (j.file_name LIKE :search OR j.id = :search_id)";
    $params[':search'] = "%{$search}%";
    $params[':search_id'] = (int)$search;
}

$sql .= " ORDER BY j.created_at DESC LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

$pageTitle = 'Print Jobs — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h3 class="fw-bold text-dark mb-1">Shop Print Queue</h3>
    <p class="text-muted small mb-0">Live customer upload requests, printing progress, and document queue.</p>
  </div>
</div>

<!-- Filters Bar -->
<div class="card card-pp mb-4">
  <div class="card-body p-3">
    <form method="GET" action="<?= APP_URL ?>/shop/print-jobs.php" class="row g-2 align-items-center">
      <div class="col-md-6">
        <div class="input-group">
          <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Search by Job ID or file name..." value="<?= e($search) ?>">
        </div>
      </div>
      <div class="col-md-4">
        <select name="status" class="form-select">
          <option value="">All Job Statuses</option>
          <?php foreach ([
            'UPLOADED', 'PAYMENT_PENDING', 'PAID', 'QUEUED', 
            'DOWNLOADING', 'PRINTING', 'PRINTED', 'FAILED', 'CANCELLED'
          ] as $st): ?>
            <option value="<?= $st ?>" <?= ($statusFilter === $st) ? 'selected' : '' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-fill">Filter</button>
        <?php if (!empty($statusFilter) || !empty($search)): ?>
          <a href="<?= APP_URL ?>/shop/print-jobs.php" class="btn btn-outline-secondary">Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Jobs Table -->
<div class="card card-pp">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-pp table-hover mb-0">
        <thead>
          <tr>
            <th>Job ID</th>
            <th>Document File</th>
            <th>Pages & Copies</th>
            <th>Specifications</th>
            <th>Amount</th>
            <th>Payment</th>
            <th>Status</th>
            <th>Created Time</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($jobs)): ?>
            <tr>
              <td colspan="9" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                No print jobs found.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($jobs as $j): ?>
              <tr>
                <td>
                  <span class="fw-mono fw-bold text-dark">#<?= $j['id'] ?></span>
                  <?php if (!empty($j['public_token'])): ?>
                    <div class="small text-primary font-monospace" style="font-size: 0.72rem;"><?= e($j['public_token']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-truncate" style="max-width: 180px;" title="<?= e($j['file_name']) ?>">
                  <i class="bi <?= str_contains($j['file_type'], 'pdf') ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-primary' ?> me-1"></i>
                  <?= e($j['file_name']) ?>
                </td>
                <td>
                  <span class="fw-semibold"><?= $j['page_count'] ?> pgs</span> × <?= $j['copies'] ?>
                </td>
                <td>
                  <span class="badge bg-light text-secondary border">
                    <?= e($j['paper_size']) ?> • <?= e($j['color_mode']) ?> • <?= e($j['side_mode']) ?>
                  </span>
                </td>
                <td class="fw-bold text-dark"><?= format_currency($j['amount']) ?></td>
                <td>
                  <span class="badge <?= $j['payment_status'] === 'completed' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning border' ?>">
                    <?= ucfirst($j['payment_status']) ?>
                  </span>
                </td>
                <td>
                  <span class="badge-status <?= e($j['status']) ?>">
                    <?= e($j['status']) ?>
                  </span>
                </td>
                <td class="small text-muted"><?= format_date($j['created_at']) ?></td>
                <td class="text-end">
                  <?php if ($j['status'] !== 'PRINTED' && $j['status'] !== 'CANCELLED'): ?>
                    <form method="POST" action="<?= APP_URL ?>/shop/print-jobs.php" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="job_id" value="<?= $j['id'] ?>">
                      <input type="hidden" name="new_status" value="PRINTED">
                      <button type="submit" class="btn btn-sm btn-outline-success" title="Mark as Printed">
                        <i class="bi bi-check2-circle"></i>
                      </button>
                    </form>
                    <form method="POST" action="<?= APP_URL ?>/shop/print-jobs.php" class="d-inline ms-1" onsubmit="return confirm('Cancel this print job?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="job_id" value="<?= $j['id'] ?>">
                      <input type="hidden" name="new_status" value="CANCELLED">
                      <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel Job">
                        <i class="bi bi-x-circle"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="text-muted small">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
