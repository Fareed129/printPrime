<?php
/**
 * PrimePrint Desktop Print Agent Download Handler
 */

require_once __DIR__ . '/config/config.php';

$filePath = __DIR__ . '/downloads/PrimePrint-Agent-v1.0.0-Portable-x64.exe';

if (!file_exists($filePath)) {
    http_response_code(404);
    echo "<h1>404 Not Found</h1><p>PrimePrint Desktop Agent binary is currently unavailable. Please contact support.</p>";
    exit;
}

$fileName = 'PrimePrint-Agent-v1.0.0-Portable-x64.exe';
$fileSize = filesize($filePath);

// Send streaming download headers
header('Content-Description: File Transfer');
header('Content-Type: application/vnd.microsoft.portable-executable');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . $fileSize);

// Clean output buffer before streaming large file
if (ob_get_level()) {
    ob_end_clean();
}

$handle = fopen($filePath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}

while (!feof($handle)) {
    echo fread($handle, 1024 * 128); // 128KB chunks
    flush();
}

fclose($handle);
exit;
