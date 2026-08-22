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
 * Detect page count of a PDF file using reliable binary stream inspection
 *
 * @param string $filePath Absolute path to PDF file
 * @return int|false Returns page count on success, or false if unparseable
 */
function detect_pdf_page_count(string $filePath): int|false {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return false;
    }

    $fp = @fopen($filePath, 'rb');
    if (!$fp) {
        return false;
    }

    // Read header to verify %PDF- signature
    $header = fread($fp, 1024);
    if (!str_contains($header, '%PDF-')) {
        fclose($fp);
        return false;
    }

    // Read remaining content into buffer
    $content = $header;
    while (!feof($fp)) {
        $content .= fread($fp, 65536);
    }
    fclose($fp);

    $maxPages = 0;

    // Method 1: Search for /Type /Pages ... /Count (\d+) in page tree
    if (preg_match_all('#/Type\s*/Pages[^>]*?/Count\s+(\d+)#s', $content, $matches)) {
        foreach ($matches[1] as $cnt) {
            $val = (int)$cnt;
            if ($val > $maxPages) {
                $maxPages = $val;
            }
        }
    }

    if ($maxPages > 0) {
        return $maxPages;
    }

    // Method 2: Count individual page dictionary objects: /Type /Page (excluding /Pages)
    if (preg_match_all('#/Type\s*/Page\b(?!s)#', $content, $matches)) {
        $cnt = count($matches[0]);
        if ($cnt > 0) {
            return $cnt;
        }
    }

    // Method 3: Direct /Count (\d+) pattern in trailer/catalog
    if (preg_match_all('#/Count\s+(\d+)#', $content, $matches)) {
        foreach ($matches[1] as $cnt) {
            $val = (int)$cnt;
            if ($val > $maxPages) {
                $maxPages = $val;
            }
        }
    }

    return ($maxPages > 0) ? $maxPages : false;
}

/**
 * Generate a safe, unique public order token for customer-facing tracking
 * Format: PP-XXXX-XXXX (e.g. PP-X7K9-2D4M)
 */
function generate_public_order_token(PDO $db): string {
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // base32 without ambiguous chars
    $len = strlen($chars);

    while (true) {
        $part1 = '';
        $part2 = '';
        for ($i = 0; $i < 4; $i++) {
            $part1 .= $chars[random_int(0, $len - 1)];
            $part2 .= $chars[random_int(0, $len - 1)];
        }
        $token = "PP-{$part1}-{$part2}";

        $stmt = $db->prepare("SELECT id FROM print_jobs WHERE public_token = :token LIMIT 1");
        $stmt->execute([':token' => $token]);
        if (!$stmt->fetch()) {
            return $token;
        }
    }
}

/**
 * Recalculate price server-side strictly using shop active pricing rules
 * IMPORTANT: If pricing tier is not configured by the shop, return an error. NEVER fallback to invented default rates.
 */
function calculate_order_price(PDO $db, int $shopId, string $paperSize, string $colorMode, string $sideMode, int $pageCount, int $copies): array {
    $pageCount = max(1, $pageCount);
    $copies = max(1, min(100, $copies));

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
        return [
            'success' => false,
            'error'   => 'This printing option is currently unavailable.'
        ];
    }

    $unitPrice = (float)$pricing['price_per_page'];
    $totalAmount = round($unitPrice * $pageCount * $copies, 2);

    return [
        'success'      => true,
        'unit_price'   => $unitPrice,
        'page_count'   => $pageCount,
        'copies'       => $copies,
        'total_amount' => $totalAmount
    ];
}
