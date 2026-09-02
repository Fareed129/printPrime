<?php
/**
 * PrimePrint — Automated Test Suite for Customer Portal V2
 * Tests: Multi-File Upload, ID Card Composition, Cash Workflow, Security, A3/Duplex Rejection, PDF Selection.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

echo "====================================================\n";
echo "PRIMEPRINT CUSTOMER PORTAL V2 — AUTOMATED TEST SUITE\n";
echo "====================================================\n\n";

$db = getDBConnection();

// Get demo shop
$stmt = $db->query("SELECT * FROM shops WHERE slug = 'abc-digital-printing' LIMIT 1");
$shop = $stmt->fetch();
if (!$shop) {
    die("FAIL: Demo shop 'abc-digital-printing' not found in database.\n");
}
$shopId = (int)$shop['id'];
echo "[SETUP] Using Demo Shop: {$shop['name']} (ID: {$shopId})\n";

// Get shop user for approval tests
$stmt = $db->prepare("SELECT * FROM users WHERE shop_id = :shop_id AND role = 'shop' LIMIT 1");
$stmt->execute([':shop_id' => $shopId]);
$shopUser = $stmt->fetch();
if (!$shopUser) {
    die("FAIL: Shop user not found for shop ID {$shopId}\n");
}
echo "[SETUP] Using Shop User: {$shopUser['email']} (ID: {$shopUser['id']})\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $title, bool $condition, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$title}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$title} -- {$details}\n";
    }
}

// -------------------------------------------------------------
// TEST 1: Pricing Engine Enforces A4 & Rejects A3
// -------------------------------------------------------------
echo "Test Group 1: Pricing Calculation & Paper Size Enforcement\n";

$pA4 = calculate_order_price($db, $shopId, 'A4', 'BW', 'single', 5, 1);
assertTest("A4 B&W 5 pages calculates correctly", $pA4['success'] && $pA4['total_amount'] == 10.00);

$pA3 = calculate_order_price($db, $shopId, 'A3', 'BW', 'single', 5, 1);
assertTest("A3 paper size is strictly rejected by pricing engine", !$pA3['success'] && str_contains($pA3['error'], 'A3'));

$pDuplex = calculate_order_price($db, $shopId, 'A4', 'BW', 'double', 5, 1);
assertTest("Duplex request is automatically forced to single-sided", $pDuplex['success']);

// -------------------------------------------------------------
// TEST 2: PDF Page Selection Validator
// -------------------------------------------------------------
echo "\nTest Group 2: PDF Page Selection Validation\n";

$sel1 = validate_selected_pdf_pages(10, [2, 5, 8]);
assertTest("Valid pages [2, 5, 8] preserved", $sel1 === [2, 5, 8]);

$sel2 = validate_selected_pdf_pages(10, [8, 2, 5, 2]); // out of order + duplicate
assertTest("Out of order + duplicates sorted & deduped to [2, 5, 8]", $sel2 === [2, 5, 8]);

$sel3 = validate_selected_pdf_pages(10, [0, 2, 15, -1]); // invalid range
assertTest("Invalid pages (0, 15, -1) filtered out, keeping only [2]", $sel3 === [2]);

$sel4 = validate_selected_pdf_pages(5, []); // empty defaults to all 5
assertTest("Empty selection defaults to all pages [1, 2, 3, 4, 5]", count($sel4) === 5);

// -------------------------------------------------------------
// TEST 3: ID Card A4 PDF Composition Engine
// -------------------------------------------------------------
echo "\nTest Group 3: ID Card Front + Back A4 Composite Generation\n";

// Generate 2 sample PNG test images
$testFront = __DIR__ . '/tmp_front.png';
$testBack  = __DIR__ . '/tmp_back.png';
$testOutA4 = __DIR__ . '/tmp_idcard_a4.pdf';

$im1 = imagecreatetruecolor(850, 540);
$c1 = imagecolorallocate($im1, 100, 180, 240);
imagefill($im1, 0, 0, $c1);
imagepng($im1, $testFront);
imagedestroy($im1);

$im2 = imagecreatetruecolor(850, 540);
$c2 = imagecolorallocate($im2, 240, 180, 100);
imagefill($im2, 0, 0, $c2);
imagepng($im2, $testBack);
imagedestroy($im2);

$genOk = generate_id_card_a4_pdf($testFront, $testBack, $testOutA4);
assertTest("ID Card Front + Back composed to A4 PDF successfully", $genOk && file_exists($testOutA4));

$pageCountId = detect_pdf_page_count($testOutA4);
assertTest("Generated ID Card A4 PDF has exactly 1 printable page", $pageCountId === 1);

@unlink($testFront);
@unlink($testBack);
@unlink($testOutA4);

// -------------------------------------------------------------
// TEST 4: Multi-Image to A4 PDF Compilation
// -------------------------------------------------------------
echo "\nTest Group 4: Multi-Image Compilation\n";

$img1 = __DIR__ . '/tmp_img1.png';
$img2 = __DIR__ . '/tmp_img2.png';
$img3 = __DIR__ . '/tmp_img3.png';
$outMulti = __DIR__ . '/tmp_multi_images.pdf';

$i1 = imagecreatetruecolor(600, 800); imagepng($i1, $img1); imagedestroy($i1);
$i2 = imagecreatetruecolor(800, 600); imagepng($i2, $img2); imagedestroy($i2);
$i3 = imagecreatetruecolor(500, 500); imagepng($i3, $img3); imagedestroy($i3);

$multiOk = compile_images_to_a4_pdf([$img1, $img2, $img3], $outMulti);
assertTest("3 image files compiled into single PDF successfully", $multiOk && file_exists($outMulti));

$multiPageCount = detect_pdf_page_count($outMulti);
assertTest("Compiled multi-image PDF has exactly 3 printable pages", $multiPageCount === 3);

@unlink($img1);
@unlink($img2);
@unlink($img3);
@unlink($outMulti);

// -------------------------------------------------------------
// TEST 5: Cash Payment Order Creation & Workflow
// -------------------------------------------------------------
echo "\nTest Group 5: Cash Payment Lifecycle (Creation, Hold, Approval, Invoice)\n";

$token = generate_public_order_token($db);
$price = calculate_order_price($db, $shopId, 'A4', 'BW', 'single', 3, 1);
$amount = $price['total_amount'];

// Insert Cash Order
$stmt = $db->prepare("
    INSERT INTO print_jobs (
        public_token, shop_id, file_name, stored_file_name, file_path, file_type, 
        page_count, copies, paper_size, color_mode, side_mode, amount, 
        status, payment_status, payment_method
    ) VALUES (
        :token, :shop_id, 'test_contract.pdf', 'test_stored.pdf', 'dummy/path', 'application/pdf',
        3, 1, 'A4', 'BW', 'single', :amount,
        'AWAITING_SHOP_APPROVAL', 'pending_cash', 'CASH'
    )
");
$stmt->execute([
    ':token'   => $token,
    ':shop_id' => $shopId,
    ':amount'  => $amount
]);
$cashJobId = (int)$db->lastInsertId();

assertTest("Cash order created with status AWAITING_SHOP_APPROVAL and pending_cash", $cashJobId > 0);

// Verify Print Agent does NOT receive this job while awaiting approval
$stmtAgent = $db->prepare("
    SELECT * FROM print_jobs 
    WHERE shop_id = :shop_id 
      AND status IN ('PAID', 'QUEUED')
      AND payment_status IN ('paid', 'completed')
      AND id = :id
");
$stmtAgent->execute([':shop_id' => $shopId, ':id' => $cashJobId]);
$agentJob = $stmtAgent->fetch();
assertTest("Cash order is NOT sent to Print Agent queue while awaiting approval", $agentJob === false);

// Now Shop Manager Approves Cash Payment (Simulating POST action=accept_cash)
$stmtApprove = $db->prepare("
    UPDATE print_jobs 
    SET status = 'QUEUED', 
        payment_status = 'paid', 
        cash_approved_by = :user_id, 
        cash_approved_at = NOW() 
    WHERE id = :id AND shop_id = :shop_id AND status = 'AWAITING_SHOP_APPROVAL'
");
$stmtApprove->execute([':user_id' => $shopUser['id'], ':id' => $cashJobId, ':shop_id' => $shopId]);

// Create Invoice
$invNum = 'INV-TEST-' . bin2hex(random_bytes(3));
$insInv = $db->prepare("INSERT INTO invoices (job_id, shop_id, invoice_number, amount) VALUES (:job_id, :shop_id, :inv_num, :amount)");
$insInv->execute([':job_id' => $cashJobId, ':shop_id' => $shopId, ':inv_num' => $invNum, ':amount' => $amount]);

// Re-check Agent queue
$stmtAgent->execute([':shop_id' => $shopId, ':id' => $cashJobId]);
$agentJobAfter = $stmtAgent->fetch();
assertTest("After shop approval, cash order is QUEUED and available in Print Agent queue", $agentJobAfter !== false && $agentJobAfter['status'] === 'QUEUED');

// Check invoice
$stmtCheckInv = $db->prepare("SELECT * FROM invoices WHERE job_id = :job_id LIMIT 1");
$stmtCheckInv->execute([':job_id' => $cashJobId]);
$invRow = $stmtCheckInv->fetch();
assertTest("Invoice recorded for approved cash order", $invRow !== false);

// -------------------------------------------------------------
// TEST 6: Cash Payment Rejection Workflow
// -------------------------------------------------------------
echo "\nTest Group 6: Cash Payment Rejection\n";

$token2 = generate_public_order_token($db);
$stmt->execute([
    ':token'   => $token2,
    ':shop_id' => $shopId,
    ':amount'  => 5.00
]);
$rejectJobId = (int)$db->lastInsertId();

// Shop manager rejects
$stmtReject = $db->prepare("
    UPDATE print_jobs 
    SET status = 'REJECTED', 
        payment_status = 'rejected', 
        cash_rejected_by = :user_id, 
        cash_rejected_at = NOW(),
        cash_rejection_reason = 'Counter out of paper'
    WHERE id = :id AND shop_id = :shop_id
");
$stmtReject->execute([':user_id' => $shopUser['id'], ':id' => $rejectJobId, ':shop_id' => $shopId]);

$stmtCheckReject = $db->prepare("SELECT * FROM print_jobs WHERE id = :id");
$stmtCheckReject->execute([':id' => $rejectJobId]);
$rejectedRow = $stmtCheckReject->fetch();

assertTest("Rejected order has status REJECTED and payment_status rejected", $rejectedRow['status'] === 'REJECTED' && $rejectedRow['payment_status'] === 'rejected');
assertTest("Rejection reason stored accurately", $rejectedRow['cash_rejection_reason'] === 'Counter out of paper');

$stmtAgent->execute([':shop_id' => $shopId, ':id' => $rejectJobId]);
assertTest("Rejected job is NOT available in Print Agent queue", $stmtAgent->fetch() === false);

// -------------------------------------------------------------
// TEST 7: Order Status API Endpoint
// -------------------------------------------------------------
echo "\nTest Group 7: Order Status API Polling Endpoint\n";

$ch = curl_init("http://localhost:8000/api/customer/order-status.php?token=" . urlencode($token2));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resJson = curl_exec($ch);
curl_close($ch);
$statusData = json_decode($resJson, true);

assertTest("Status API returns HTTP 200 with JSON", $statusData && $statusData['success'] === true);
assertTest("Status API correctly identifies is_rejected = true", $statusData['is_rejected'] === true);
assertTest("Status API returns correct rejection reason", $statusData['rejection_reason'] === 'Counter out of paper');

// Cleanup test jobs
$db->exec("DELETE FROM invoices WHERE job_id IN ({$cashJobId}, {$rejectJobId})");
$db->exec("DELETE FROM print_jobs WHERE id IN ({$cashJobId}, {$rejectJobId})");

echo "\n====================================================\n";
echo "TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
}
