<?php
/**
 * PrimePrint Shop - Print Jobs Queue & History (V2)
 * Includes Counter Cash Payment Approval & Rejection Workflow
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

// 1. Handle Cash Payment Approval by Shop Operator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_cash') {
    require_csrf_token();
    $jobId = (int)($_POST['job_id'] ?? 0);

    if ($jobId > 0) {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM print_jobs WHERE id = :id AND shop_id = :shop_id FOR UPDATE");
            $stmt->execute([':id' => $jobId, ':shop_id' => $shopId]);
            $job = $stmt->fetch();

            if (!$job) {
                throw new Exception("Job not found or access denied.");
            }

            if ($job['status'] !== 'AWAITING_SHOP_APPROVAL' || $job['payment_status'] !== 'pending_cash') {
                throw new Exception("Job is not awaiting cash approval.");
            }

            // Atomically update status to QUEUED and payment to paid
            $upd = $db->prepare("
                UPDATE print_jobs 
                SET status = 'QUEUED', 
                    payment_status = 'paid', 
                    payment_method = 'CASH',
                    cash_approved_by = :approved_by, 
                    cash_approved_at = NOW()
                WHERE id = :id AND shop_id = :shop_id
            ");
            $upd->execute([
                ':approved_by' => $shopUser['id'],
                ':id'          => $jobId,
                ':shop_id'     => $shopId
            ]);

            // Create invoice record if not exists
            $stmtInv = $db->prepare("SELECT id FROM invoices WHERE job_id = :job_id LIMIT 1");
            $stmtInv->execute([':job_id' => $jobId]);
            if (!$stmtInv->fetch()) {
                $invNum = 'INV-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                $insInv = $db->prepare("
                    INSERT INTO invoices (job_id, shop_id, invoice_number, amount, created_at)
                    VALUES (:job_id, :shop_id, :inv_num, :amount, NOW())
                ");
                $insInv->execute([
                    ':job_id'  => $jobId,
                    ':shop_id' => $shopId,
                    ':inv_num' => $invNum,
                    ':amount'  => $job['amount']
                ]);
            }

            $db->commit();
            flash_set('success', "Cash Payment of " . format_currency($job['amount']) . " for Job #{$jobId} ACCEPTED. Print job is now QUEUED.");
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            flash_set('danger', "Failed to approve cash payment: " . $e->getMessage());
        }
    }
    header("Location: " . APP_URL . "/shop/print-jobs.php");
    exit;
}

// 2. Handle Cash Payment Rejection by Shop Operator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_cash') {
    require_csrf_token();
    $jobId = (int)($_POST['job_id'] ?? 0);
    $reason = trim($_POST['rejection_reason'] ?? 'Declined by counter staff');

    if ($jobId > 0) {
        $stmt = $db->prepare("
            UPDATE print_jobs 
            SET status = 'REJECTED', 
                payment_status = 'rejected', 
                cash_rejected_by = :rejected_by, 
                cash_rejected_at = NOW(),
                cash_rejection_reason = :reason
            WHERE id = :id AND shop_id = :shop_id AND status = 'AWAITING_SHOP_APPROVAL'
        ");
        $stmt->execute([
            ':rejected_by' => $shopUser['id'],
            ':reason'      => $reason,
            ':id'          => $jobId,
            ':shop_id'     => $shopId
        ]);
        flash_set('warning', "Print Job #{$jobId} was REJECTED.");
    }
    header("Location: " . APP_URL . "/shop/print-jobs.php");
    exit;
}

// 3. Handle Status Override by Shop Operator (e.g., Mark as Printed or Cancel)
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

// Fetch any Pending Cash Orders specifically for top alert banner
$stmtPendingCash = $db->prepare("
    SELECT * FROM print_jobs 
    WHERE shop_id = :shop_id AND status = 'AWAITING_SHOP_APPROVAL'
    ORDER BY created_at ASC
");
$stmtPendingCash->execute([':shop_id' => $shopId]);
$pendingCashJobs = $stmtPendingCash->fetchAll();

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
    $sql .= " AND (j.file_name LIKE :search OR j.id = :search_id OR j.public_token LIKE :search_tok)";
    $params[':search'] = "%{$search}%";
    $params[':search_tok'] = "%{$search}%";
    $params[':search_id'] = (int)$search;
}

$sql .= " ORDER BY (j.status = 'AWAITING_SHOP_APPROVAL') DESC, j.created_at DESC LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

$pageTitle = 'Print Jobs — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h3 class="fw-bold text-dark mb-1">Shop Print Queue</h3>
    <p class="text-muted small mb-0">Live customer upload requests, counter cash approvals, and document spooler.</p>
  </div>
</div>

<!-- ============================================== -->
<!-- PENDING CASH APPROVAL ALERT BANNER             -->
<!-- ============================================== -->
<?php if (!empty($pendingCashJobs)): ?>
  <div class="card border-warning bg-warning-subtle mb-4 shadow-sm">
    <div class="card-header bg-warning text-dark fw-bold d-flex align-items-center justify-content-between py-2">
      <span>
        <i class="bi bi-cash-stack fs-5 me-2"></i>
        CASH PAYMENT — <?= count($pendingCashJobs) ?> REQUEST(S) WAITING FOR PAYMENT
      </span>
      <span class="badge bg-dark text-white"><?= count($pendingCashJobs) ?> Pending</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 bg-white">
          <thead class="table-light small">
            <tr>
              <th>Order Token / Job #</th>
              <th>Document</th>
              <th>Pages & Copies</th>
              <th>Amount to Collect</th>
              <th>Requested Time</th>
              <th class="text-end pe-3">Counter Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingCashJobs as $cj): ?>
              <tr>
                <td>
                  <span class="font-monospace fw-bold text-primary fs-6"><?= e($cj['public_token']) ?></span>
                  <div class="small text-muted">Job #<?= $cj['id'] ?></div>
                </td>
                <td>
                  <i class="bi <?= !empty($cj['is_id_card']) ? 'bi-person-vcard text-success' : 'bi-file-earmark-text text-primary' ?> me-1"></i>
                  <span class="fw-semibold text-dark"><?= !empty($cj['is_id_card']) ? 'ID Card (A4 Front+Back)' : e($cj['file_name']) ?></span>
                </td>
                <td>
                  <?= $cj['page_count'] ?> pgs × <?= $cj['copies'] ?> <?= $cj['color_mode'] === 'COLOR' ? '<span class="badge bg-danger-subtle text-danger">Color</span>' : '<span class="badge bg-secondary-subtle text-secondary">B&W</span>' ?>
                </td>
                <td>
                  <span class="fw-bold fs-5 text-success"><?= format_currency($cj['amount']) ?></span>
                </td>
                <td class="small text-muted"><?= format_date($cj['created_at']) ?></td>
                <td class="text-end pe-3">
                  <!-- Accept & Print Button -->
                  <form method="POST" action="<?= APP_URL ?>/shop/print-jobs.php" class="d-inline" onsubmit="return confirm('Confirm cash payment of <?= format_currency($cj['amount']) ?> received and send to printer?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="accept_cash">
                    <input type="hidden" name="job_id" value="<?= $cj['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success fw-bold px-3 py-1 me-1 shadow-sm">
                      <i class="bi bi-check-circle-fill me-1"></i> Accept & Print
                    </button>
                  </form>

                  <!-- Reject Button -->
                  <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1" onclick="openRejectModal(<?= $cj['id'] ?>, '<?= e($cj['public_token']) ?>', '<?= format_currency($cj['amount']) ?>')">
                    <i class="bi bi-x-circle me-1"></i> Reject
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Filters Bar -->
<div class="card card-pp mb-4">
  <div class="card-body p-3">
    <form method="GET" action="<?= APP_URL ?>/shop/print-jobs.php" class="row g-2 align-items-center">
      <div class="col-md-6">
        <div class="input-group">
          <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Search by Job ID, Order Token, or file name..." value="<?= e($search) ?>">
        </div>
      </div>
      <div class="col-md-4">
        <select name="status" class="form-select">
          <option value="">All Job Statuses</option>
          <?php foreach ([
            'AWAITING_SHOP_APPROVAL', 'QUEUED', 'PRINTING', 'PRINTED', 
            'PAID', 'UPLOADED', 'PAYMENT_PENDING', 'FAILED', 'CANCELLED', 'REJECTED'
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
              <tr class="<?= $j['status'] === 'AWAITING_SHOP_APPROVAL' ? 'table-warning' : '' ?>">
                <td>
                  <span class="fw-mono fw-bold text-dark">#<?= $j['id'] ?></span>
                  <?php if (!empty($j['public_token'])): ?>
                    <div class="small text-primary font-monospace" style="font-size: 0.72rem;"><?= e($j['public_token']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-truncate" style="max-width: 180px;" title="<?= e($j['file_name']) ?>">
                  <?php if (!empty($j['is_id_card'])): ?>
                    <i class="bi bi-person-vcard text-success me-1"></i> ID Card (A4)
                  <?php else: ?>
                    <i class="bi <?= str_contains($j['file_type'], 'pdf') ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-primary' ?> me-1"></i>
                    <?= e($j['file_name']) ?>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="fw-semibold"><?= $j['page_count'] ?> pgs</span> × <?= $j['copies'] ?>
                </td>
                <td>
                  <span class="badge bg-light text-secondary border">
                    <?= e($j['paper_size']) ?> • <?= e($j['color_mode']) ?>
                  </span>
                </td>
                <td class="fw-bold text-dark"><?= format_currency($j['amount']) ?></td>
                <td>
                  <?php if ($j['payment_status'] === 'paid' || $j['payment_status'] === 'completed'): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                      <i class="bi bi-check-circle me-1"></i><?= $j['payment_method'] === 'CASH' ? 'Cash Paid' : 'Online Paid' ?>
                    </span>
                  <?php elseif ($j['payment_status'] === 'pending_cash'): ?>
                    <span class="badge bg-warning text-dark border">
                      <i class="bi bi-cash me-1"></i>Cash Pending
                    </span>
                  <?php elseif ($j['payment_status'] === 'rejected'): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                      Rejected
                    </span>
                  <?php else: ?>
                    <span class="badge bg-light text-muted border">
                      <?= ucfirst($j['payment_status']) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($j['status'] === 'AWAITING_SHOP_APPROVAL'): ?>
                    <span class="badge bg-warning text-dark fw-bold">
                      AWAITING CASH
                    </span>
                  <?php elseif ($j['status'] === 'REJECTED'): ?>
                    <span class="badge bg-danger">
                      REJECTED
                    </span>
                  <?php else: ?>
                    <span class="badge-status <?= e($j['status']) ?>">
                      <?= e($j['status']) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted"><?= format_date($j['created_at']) ?></td>
                <td class="text-end">
                  <?php if ($j['status'] === 'AWAITING_SHOP_APPROVAL'): ?>
                    <!-- Cash Action Buttons -->
                    <form method="POST" action="<?= APP_URL ?>/shop/print-jobs.php" class="d-inline" onsubmit="return confirm('Confirm cash payment received and print?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="accept_cash">
                      <input type="hidden" name="job_id" value="<?= $j['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-success px-2 py-1" title="Accept Cash & Print">
                        <i class="bi bi-check-lg"></i> Accept
                      </button>
                    </form>
                    <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1 ms-1" onclick="openRejectModal(<?= $j['id'] ?>, '<?= e($j['public_token']) ?>', '<?= format_currency($j['amount']) ?>')" title="Reject Request">
                      <i class="bi bi-x-lg"></i>
                    </button>
                  <?php elseif ($j['status'] !== 'PRINTED' && $j['status'] !== 'CANCELLED' && $j['status'] !== 'REJECTED'): ?>
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

<!-- Rejection Modal -->
<div class="modal fade" id="rejectCashModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="<?= APP_URL ?>/shop/print-jobs.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject_cash">
        <input type="hidden" name="job_id" id="rejectJobId" value="">
        <div class="modal-header">
          <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Reject Print Request?</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3">
            Are you sure you want to decline order <strong id="rejectOrderToken"></strong> (<span id="rejectAmount"></span>)?
            The customer will be notified that their request was rejected.
          </p>
          <div class="mb-3">
            <label for="rejectionReason" class="form-label small fw-semibold">Reason (Optional):</label>
            <input type="text" class="form-control" id="rejectionReason" name="rejection_reason" placeholder="e.g. Unreadable ID, Counter closed, Out of paper">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep Order</button>
          <button type="submit" class="btn btn-danger fw-semibold">Confirm Rejection</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  function openRejectModal(jobId, token, amount) {
    document.getElementById('rejectJobId').value = jobId;
    document.getElementById('rejectOrderToken').textContent = token;
    document.getElementById('rejectAmount').textContent = amount;
    new bootstrap.Modal(document.getElementById('rejectCashModal')).show();
  }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
