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
 * Generate same-origin root-relative asset URL with automatic cache-busting
 */
function asset_url(string $path): string {
    $cleanPath = '/' . ltrim($path, '/');
    $localPath = __DIR__ . '/../' . ltrim($path, '/');
    $version = file_exists($localPath) ? filemtime($localPath) : time();
    return $cleanPath . '?v=' . $version;
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
 * Supports PDF 1.0 - 1.7 specifications, linearized documents, and compressed object streams (/ObjStm)
 *
 * @param string $filePath Absolute path to PDF file
 * @return int|false Returns page count on success, or false if unparseable/corrupted
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

    // Read full content into buffer
    $content = $header;
    while (!feof($fp)) {
        $content .= fread($fp, 65536);
    }
    fclose($fp);

    $maxPages = 0;

    // Strategy 1: Direct uncompressed /Type /Pages ... /Count (\d+)
    if (preg_match_all('#/Type\s*/Pages[^>]*?/Count\s+(\d+)#is', $content, $matches)) {
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

    // Strategy 2: Count individual uncompressed /Type /Page objects (excluding /Pages)
    if (preg_match_all('#/Type\s*/Page\b(?!s)#i', $content, $matches)) {
        $cnt = count($matches[0]);
        if ($cnt > 0) {
            return $cnt;
        }
    }

    // Strategy 3: Search for /Count (\d+) in document trailer / catalog
    if (preg_match_all('#/Count\s+(\d+)#i', $content, $matches)) {
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

    // Strategy 4: Compressed PDF 1.5+ Object Streams (/ObjStm with /Filter /FlateDecode)
    if (preg_match_all('#stream[\r\n]+(.*?)[\r\n]+endstream#s', $content, $streamMatches)) {
        $decompressedBuffer = '';
        foreach ($streamMatches[1] as $rawStream) {
            $uncompressed = @gzuncompress($rawStream);
            if ($uncompressed === false) {
                $uncompressed = @gzinflate($rawStream);
            }
            if ($uncompressed === false && function_exists('zlib_decode')) {
                $uncompressed = @zlib_decode($rawStream);
            }
            if ($uncompressed !== false) {
                $decompressedBuffer .= ' ' . $uncompressed;
            }
        }

        if (!empty($decompressedBuffer)) {
            if (preg_match_all('#/Type\s*/Pages[^>]*?/Count\s+(\d+)#is', $decompressedBuffer, $matches)) {
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

            if (preg_match_all('#/Type\s*/Page\b(?!s)#i', $decompressedBuffer, $matches)) {
                $cnt = count($matches[0]);
                if ($cnt > 0) {
                    return $cnt;
                }
            }
        }
    }

    return false;
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
 * Enforces A4 paper size and single-sided printing only. Rejects A3.
 */
function calculate_order_price(PDO $db, int $shopId, string $paperSize, string $colorMode, string $sideMode, int $pageCount, int $copies): array {
    // Strictly reject A3 paper size
    $normSize = strtoupper(trim($paperSize));
    if ($normSize === 'A3') {
        return [
            'success' => false,
            'error'   => 'A3 printing is not supported. Please choose A4.'
        ];
    }

    // Force A4 and single-sided only
    $paperSize = 'A4';
    $sideMode  = 'single';
    $colorMode = strtoupper(trim($colorMode)) === 'COLOR' ? 'COLOR' : 'BW';

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
            'error'   => 'This printing option is currently unavailable for this shop.'
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

/**
 * Validate and clean user-selected PDF pages against actual document page count.
 * Returns sorted, unique, 1-indexed valid page numbers.
 */
function validate_selected_pdf_pages(int $totalPdfPages, array|string $requestedPages): array {
    if ($totalPdfPages <= 0) {
        return [];
    }

    $rawPages = is_array($requestedPages) ? $requestedPages : explode(',', (string)$requestedPages);
    $validPages = [];

    foreach ($rawPages as $p) {
        $pageNum = (int)trim((string)$p);
        if ($pageNum >= 1 && $pageNum <= $totalPdfPages) {
            $validPages[] = $pageNum;
        }
    }

    $validPages = array_values(array_unique($validPages));
    sort($validPages, SORT_NUMERIC);

    // If no specific pages validly selected, default to all pages
    if (empty($validPages)) {
        return range(1, $totalPdfPages);
    }

    return $validPages;
}

/**
 * Compose front and back cropped ID card images into a clean, print-ready A4 PDF document.
 * Front card is centered in upper half; back card is centered in lower half.
 *
 * @param string $frontImgPath Path to front cropped image
 * @param string $backImgPath Path to back cropped image
 * @param string $outputPath Path where composed PDF should be written
 * @return bool True on success, false on error
 */
function generate_id_card_a4_pdf(string $frontImgPath, string $backImgPath, string $outputPath): bool {
    if (!file_exists($frontImgPath) || !file_exists($backImgPath)) {
        return false;
    }

    $frontInfo = @getimagesize($frontImgPath);
    $backInfo = @getimagesize($backImgPath);
    if (!$frontInfo || !$backInfo) {
        return false;
    }

    require_once __DIR__ . '/../includes/libraries/fpdf/fpdf.php';

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $pageW = 210.0;
    $pageH = 297.0;

    // Slot 1: Front side (upper half: max dimensions ~125mm x 85mm)
    $maxW = 125.0;
    $maxH = 85.0;

    // Front image placement
    $fRatio = $frontInfo[0] / max(1, $frontInfo[1]);
    $fW = $maxW;
    $fH = $fW / $fRatio;
    if ($fH > $maxH) {
        $fH = $maxH;
        $fW = $fH * $fRatio;
    }
    $fX = ($pageW - $fW) / 2.0;
    $fY = 35.0 + ($maxH - $fH) / 2.0;
    $pdf->Image($frontImgPath, $fX, $fY, $fW, $fH);

    // Slot 2: Back side (lower half: max dimensions ~125mm x 85mm)
    $bRatio = $backInfo[0] / max(1, $backInfo[1]);
    $bW = $maxW;
    $bH = $bW / $bRatio;
    if ($bH > $maxH) {
        $bH = $maxH;
        $bW = $bH * $bRatio;
    }
    $bX = ($pageW - $bW) / 2.0;
    $bY = 155.0 + ($maxH - $bH) / 2.0;
    $pdf->Image($backImgPath, $bX, $bY, $bW, $bH);

    $pdf->Output('F', $outputPath);
    return file_exists($outputPath) && filesize($outputPath) > 0;
}

/**
 * Compile multiple image files into a single print-ready A4 PDF document.
 * Each image is scaled to fit comfortably on its own A4 page.
 *
 * @param array $imagePaths Array of absolute paths to images
 * @param string $outputPath Path where composed PDF should be written
 * @return bool True on success, false on error
 */
function compile_images_to_a4_pdf(array $imagePaths, string $outputPath): bool {
    if (empty($imagePaths)) {
        return false;
    }

    require_once __DIR__ . '/../includes/libraries/fpdf/fpdf.php';

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false);

    $pageW = 210.0;
    $pageH = 297.0;
    $margin = 10.0;
    $maxW = $pageW - (2 * $margin);
    $maxH = $pageH - (2 * $margin);

    $validPagesCount = 0;
    foreach ($imagePaths as $imgPath) {
        if (!file_exists($imgPath)) continue;
        $info = @getimagesize($imgPath);
        if (!$info) continue;

        $pdf->AddPage();
        $ratio = $info[0] / max(1, $info[1]);
        $w = $maxW;
        $h = $w / $ratio;
        if ($h > $maxH) {
            $h = $maxH;
            $w = $h * $ratio;
        }
        $x = ($pageW - $w) / 2.0;
        $y = ($pageH - $h) / 2.0;

        $pdf->Image($imgPath, $x, $y, $w, $h);
        $validPagesCount++;
    }

    if ($validPagesCount === 0) {
        return false;
    }

    $pdf->Output('F', $outputPath);
    return file_exists($outputPath) && filesize($outputPath) > 0;
}

