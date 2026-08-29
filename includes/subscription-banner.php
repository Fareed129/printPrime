<?php
/**
 * PrimePrint SaaS Subscription Warning Banner & Expired Modal Include
 */

if (empty($shop) && !empty($shopId)) {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $shopId]);
        $shop = $stmt->fetch();
    } catch (Exception $e) {}
}

if (!empty($shop)) {
    $subExpiresAt = $shop['subscription_expires_at'] ?? null;
    $daysLeft = !empty($subExpiresAt) ? (int)ceil((strtotime($subExpiresAt) - time()) / 86400) : 0;
    $isExpired = empty($subExpiresAt) || strtotime($subExpiresAt) < time();
    $isExpiring = !$isExpired && $daysLeft <= 7;

    if ($isExpired): ?>
        <div class="alert alert-danger border-danger shadow-sm d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 mb-4 rounded-3" role="alert">
          <div class="d-flex align-items-center gap-3">
            <i class="bi bi-exclamation-octagon-fill fs-2 text-danger"></i>
            <div>
              <h6 class="fw-bold mb-0 text-danger">PrimePrint Shop License Expired!</h6>
              <div class="small text-muted">Your customer upload counter is paused. Renew your 3-month license now to resume auto-printing.</div>
            </div>
          </div>
          <div class="d-flex gap-2">
            <a href="<?= APP_URL ?>/shop/subscription.php" class="btn btn-danger btn-sm fw-bold px-3 shadow-sm">
              <i class="bi bi-lightning-charge-fill me-1"></i> Renew License via Razorpay
            </a>
            <a href="https://wa.me/919876543210?text=<?= urlencode('Hi PrimePrint Support, I want to renew license for ' . $shop['name']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-whatsapp"></i> Chat Support
            </a>
          </div>
        </div>
    <?php elseif ($isExpiring): ?>
        <div class="alert alert-warning border-warning shadow-sm d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 mb-4 rounded-3" role="alert">
          <div class="d-flex align-items-center gap-3">
            <i class="bi bi-hourglass-split fs-2 text-warning"></i>
            <div>
              <h6 class="fw-bold mb-0 text-dark">License Expiring in <?= $daysLeft ?> Day<?= $daysLeft > 1 ? 's' : '' ?>!</h6>
              <div class="small text-muted">Valid until <?= date('d M Y', strtotime($subExpiresAt)) ?>. Renew now to avoid counter interruption.</div>
            </div>
          </div>
          <a href="<?= APP_URL ?>/shop/subscription.php" class="btn btn-warning btn-sm fw-bold px-3 shadow-sm text-dark">
            <i class="bi bi-arrow-repeat me-1"></i> Renew for 3 Months (₹1,499)
          </a>
        </div>
    <?php endif;
}
?>
