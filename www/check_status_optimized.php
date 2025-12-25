<?php
/**
 * ULTRA-FAST STATUS CHECK with WebSocket-like instant response
 * 
 * Optimizations:
 * 1. Minimal database query
 * 2. Fast JSON response
 * 3. Aggressive caching headers
 * 4. Multi-station support
 * 
 * @version 2.0.0
 */

ob_start();
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    exit(0);
}

function logStatus($message) {
    error_log("[STATUS-FAST " . date('H:i:s') . "] " . $message);
}

function respond($success, $message, $data = [], $httpCode = 200) {
    ob_end_clean();
    header('Content-Type: application/json', true, $httpCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s.u')
    ], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

$startTime = microtime(true);

// Get CheckoutRequestID
$checkoutRequestID = $_GET['checkout_request_id'] ?? null;

if (empty($checkoutRequestID)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $checkoutRequestID = $input['CheckoutRequestID'] ?? $input['checkout_request_id'] ?? null;
}

if (empty($checkoutRequestID)) {
    respond(false, 'CheckoutRequestID is required', ['resultCode' => 400], 400);
}

// Load config
try {
    require_once 'config.php';
} catch (Exception $e) {
    respond(false, 'Configuration error', ['resultCode' => 500], 500);
}

if (!isset($conn) || $conn === null) {
    respond(false, 'Database unavailable', ['resultCode' => 503], 503);
}

// OPTIMIZED: Single query with all needed fields
try {
    $stmt = $conn->prepare("
        SELECT 
            t.id,
            t.checkout_request_id,
            t.merchant_request_id,
            t.phone,
            t.amount,
            t.account_ref,
            t.user_id,
            t.station_id,
            t.status,
            t.mpesa_receipt,
            t.mpesa_receipt_number,
            t.result_desc,
            t.created_at,
            t.completed_at,
            s.station_name,
            s.station_code
        FROM mpesa_transactions t
        LEFT JOIN stations s ON t.station_id = s.station_id
        WHERE t.checkout_request_id = :checkout_id
        LIMIT 1
    ");
    
    $stmt->execute([':checkout_id' => $checkoutRequestID]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        respond(true, 'Transaction pending...', [
            'status' => 'pending',
            'resultCode' => null,
            'resultDesc' => 'Awaiting customer response',
            'checkoutRequestID' => $checkoutRequestID
        ]);
    }
    
    $status = $transaction['status'] ?? 'pending';
    $receipt = $transaction['mpesa_receipt'] ?? $transaction['mpesa_receipt_number'] ?? null;
    
    // Map status to result code
    $resultCode = null;
    $message = "Processing...";
    
    switch ($status) {
        case 'completed':
            $resultCode = 0;
            $message = "Payment successful! Receipt: $receipt";
            break;
        case 'cancelled':
            $resultCode = 1032;
            $message = "Payment cancelled by customer";
            break;
        case 'failed':
            $resultCode = 1;
            $message = $transaction['result_desc'] ?? 'Payment failed';
            break;
        case 'pending':
        default:
            $resultCode = null;
            $message = "Awaiting customer PIN entry...";
            break;
    }
    
    $responseTime = round((microtime(true) - $startTime) * 1000, 2);
    
    // Comprehensive response for Android app
    respond(true, $message, [
        'status' => $status,
        'resultCode' => $resultCode,
        'ResultCode' => $resultCode,
        'resultDesc' => $transaction['result_desc'] ?? $message,
        'ResultDesc' => $transaction['result_desc'] ?? $message,
        'checkoutRequestID' => $transaction['checkout_request_id'],
        'CheckoutRequestID' => $transaction['checkout_request_id'],
        'merchantRequestID' => $transaction['merchant_request_id'],
        'MerchantRequestID' => $transaction['merchant_request_id'],
        'phoneNumber' => $transaction['phone'],
        'PhoneNumber' => $transaction['phone'],
        'amount' => floatval($transaction['amount'] ?? 0),
        'Amount' => floatval($transaction['amount'] ?? 0),
        'mpesaReceiptNumber' => $receipt,
        'MpesaReceiptNumber' => $receipt,
        'accountReference' => $transaction['account_ref'],
        'stationId' => $transaction['station_id'],
        'stationName' => $transaction['station_name'],
        'stationCode' => $transaction['station_code'],
        'userId' => $transaction['user_id'],
        'createdAt' => $transaction['created_at'],
        'completedAt' => $transaction['completed_at'],
        'transactionDate' => $transaction['completed_at'],
        'TransactionDate' => $transaction['completed_at'],
        'response_time_ms' => $responseTime
    ]);
    
} catch (PDOException $e) {
    logStatus("DB ERROR: " . $e->getMessage());
    respond(false, 'Database query failed', ['resultCode' => 500], 500);
}
