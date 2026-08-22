<?php
/**
 * PrimePrint Global Helper Utilities
 */

require_once __DIR__ . '/config.php';

/**
 * Safe HTML Output Escaping (XSS Prevention)
 */
function e(?string $string): string {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate a URL-friendly slug from string
 */
function slugify(string $text): string {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);

    return empty($text) ? 'shop-' . bin2hex(random_bytes(3)) : $text;
}

/**
 * Generate a guaranteed unique slug for a shop
 */
function generate_unique_shop_slug(PDO $db, string $name, ?int $excludeShopId = null): string {
    $baseSlug = slugify($name);
    $slug = $baseSlug;
    $counter = 1;

    while (true) {
        $sql = "SELECT id FROM shops WHERE slug = :slug";
        if ($excludeShopId !== null) {
            $sql .= " AND id != :exclude_id";
        }
        $stmt = $db->prepare($sql);
        $params = [':slug' => $slug];
        if ($excludeShopId !== null) {
            $params[':exclude_id'] = $excludeShopId;
        }
        $stmt->execute($params);

        if (!$stmt->fetch()) {
            return $slug;
        }

        $counter++;
        $slug = "{$baseSlug}-{$counter}";
    }
}

/**
 * Format currency display
 */
function format_currency(float|int|string|null $amount): string {
    $val = (float)($amount ?? 0);
    return '₹' . number_format($val, 2);
}

/**
 * Format timestamp display
 */
function format_date(?string $datetime, string $format = 'd M Y, h:i A'): string {
    if (empty($datetime)) return '-';
    $timestamp = strtotime($datetime);
    return $timestamp ? date($format, $timestamp) : '-';
}

/**
 * Set flash message for next page view
 */
function flash_set(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type'    => $type, // success, danger, warning, info
        'message' => $message
    ];
}

/**
 * Retrieve and clear flash message
 */
function flash_get(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Recalculate price server-side using shop active pricing rules
 */
function calculate_order_price(PDO $db, int $shopId, string $paperSize, string $colorMode, string $sideMode, int $pageCount, int $copies): array {
    $pageCount = max(1, $pageCount);
    $copies = max(1, $copies);

    $stmt = $db->prepare("
        SELECT price_per_page 
        FROM pricing 
        WHERE shop_id = :shop_id 
          AND paper_size = :paper_size 
          AND color_mode = :color_mode 
          AND side_mode = :side_mode 
          AND active = 1
        LIMIT 1
    ");

    $stmt->execute([
        ':shop_id'    => $shopId,
        ':paper_size' => $paperSize,
        ':color_mode' => $colorMode,
        ':side_mode'  => $sideMode
    ]);

    $pricing = $stmt->fetch();

    if (!$pricing) {
        // Fallback default pricing if specific tier is not configured
        $defaultRate = ($colorMode === 'COLOR') ? 10.00 : (($sideMode === 'double') ? 3.00 : 2.00);
        $unitPrice = $defaultRate;
    } else {
        $unitPrice = (float)$pricing['price_per_page'];
    }

    $totalAmount = round($unitPrice * $pageCount * $copies, 2);

    return [
        'success'      => true,
        'unit_price'   => $unitPrice,
        'page_count'   => $pageCount,
        'copies'       => $copies,
        'total_amount' => $totalAmount
    ];
}
