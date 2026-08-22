<?php
/**
 * PrimePrint Flash Alerts Display Component
 */

$flash = flash_get();
if ($flash): 
    $alertType = htmlspecialchars($flash['type'] ?? 'info', ENT_QUOTES, 'UTF-8');
    $alertMsg = htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8');
    $icon = match($alertType) {
        'success' => 'bi-check-circle-fill',
        'danger', 'error' => 'bi-exclamation-triangle-fill',
        'warning' => 'bi-exclamation-circle-fill',
        default   => 'bi-info-circle-fill'
    };
?>
<div class="alert alert-<?= $alertType === 'error' ? 'danger' : $alertType ?> alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm mb-4" role="alert">
  <i class="bi <?= $icon ?> fs-5"></i>
  <div><?= $alertMsg ?></div>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
