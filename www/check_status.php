<?php
/**
 * Transaction Status Check Endpoint
 * 
 * This file allows the Android app to poll for transaction status
 * It queries the database for the transaction and returns current status
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    exit(0);
}

/**
 * Log status check messages
 */
function logStatus($message) {
    error_log("[STATUS CHECK " . date('Y-m-d H:i:s') . "] " . $message);
}

/**
 * Send JSON response and exit
 */
function respondStatus($success, $message, $data = [], $httpCode = 200) {
    ob_end_clean();
    header('Content-Type: application/json', true, $httpCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data));
    exit;
}

logStatus("=== STATUS CHECK REQUEST ===");

// Get CheckoutRequestID from GET or POST
$checkoutRequestID = $_GET['checkout_request_id'] ?? null;

if (empty($checkoutRequestID)) {
    // Try POST body
    $input = json_decode(file_get_contents('php://input'), true);
    $checkoutRequestID = $input['CheckoutRequestID'] ?? $input['checkout_request_id'] ?? null;
}

if (empty($checkoutRequestID)) {
    logStatus("ERROR: No CheckoutRequestID provided");
    respondStatus(false, 'CheckoutRequestID is required', ['resultCode' => 400], 400);
}

logStatus("Checking status for: $checkoutRequestID");

// Load database configuration
try {
    require_once 'config.php';
    logStatus("Configuration loaded");
} catch (Exception $e) {
    logStatus("FATAL: Config load failed - " . $e->getMessage());
    respondStatus(false, 'Database configuration error', ['resultCode' => 500], 500);
}

if (!isset($conn) || $conn === null) {
    logStatus("FATAL: Database not connected");
    respondStatus(false, 'Database service unavailable', ['resultCode' => 503], 503);
}

// Query transaction from database
try {
    // Query using your exact table structure
    $stmt = $conn->prepare("
        SELECT 
            id,
            checkout_request_id,
            merchant_request_id,
            phone,
            amount,
            account_ref,
            user_id,
            status,
            mpesa_receipt,
            mpesa_receipt_number,
            result_desc,
            created_at,
            completed_at,
            updated_at
        FROM mpesa_transactions 
        WHERE checkout_request_id = :checkout_id
        ORDER BY created_at DESC
        LIMIT 1
    ");
    
    $stmt->execute([':checkout_id' => $checkoutRequestID]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        // Transaction not found - likely still pending or not yet saved
        logStatus("Transaction not found (might be pending initial save)");
        respondStatus(true, 'Transaction is being processed. Please wait...', [
            'status' => 'pending',
            'resultCode' => null,
            'resultDesc' => 'Transaction pending',
            'checkoutRequestID' => $checkoutRequestID
        ]);
    }
    
    $status = $transaction['status'] ?? 'pending';
    $receipt = $transaction['mpesa_receipt'] ?? $transaction['mpesa_receipt_number'] ?? null;
    
    logStatus("Transaction found - Status: $status");
    
    // Map database status to result code for mobile app
    $resultCode = null;
    $message = "Transaction processed";
    
    switch ($status) {
        case 'completed':
            $resultCode = 0;
            $message = "Payment successful! M-Pesa Receipt: $receipt";
            logStatus("✅ Transaction completed - Receipt: $receipt");
            break;
            
        case 'cancelled':
            $resultCode = 1032;
            $message = "Payment was cancelled by user";
            logStatus("❌ Transaction cancelled");
            break;
            
        case 'failed':
            $resultCode = 1;
            $message = $transaction['result_desc'] ?? 'Transaction failed';
            logStatus("❌ Transaction failed - " . $message);
            break;
            
        case 'pending':
        default:
            $resultCode = null;
            $message = "Payment is still being processed. Please wait...";
            logStatus("⏳ Transaction pending");
            break;
    }
    
    // Build response matching your Android app's expected format
    $response = [
        'success' => true,
        'message' => $message,
        'status' => $status,
        'resultCode' => $resultCode,
        'ResultCode' => $resultCode, // Duplicate for compatibility
        'resultDesc' => $transaction['result_desc'] ?? $message,
        'ResultDesc' => $transaction['result_desc'] ?? $message, // Duplicate
        'checkoutRequestID' => $transaction['checkout_request_id'],
        'CheckoutRequestID' => $transaction['checkout_request_id'], // Duplicate
        'merchantRequestID' => $transaction['merchant_request_id'],
        'MerchantRequestID' => $transaction['merchant_request_id'], // Duplicate
        'phoneNumber' => $transaction['phone'],
        'PhoneNumber' => $transaction['phone'], // Duplicate
        'amount' => floatval($transaction['amount'] ?? 0),
        'Amount' => floatval($transaction['amount'] ?? 0), // Duplicate
        'mpesaReceiptNumber' => $receipt,
        'MpesaReceiptNumber' => $receipt, // Duplicate
        'accountReference' => $transaction['account_ref'],
        'userId' => $transaction['user_id'],
        'createdAt' => $transaction['created_at'],
        'completedAt' => $transaction['completed_at'],
        'updatedAt' => $transaction['updated_at'],
        'transactionDate' => $transaction['completed_at'],
        'TransactionDate' => $transaction['completed_at'] // Duplicate
    ];
    
    logStatus("Sending response - Status: $status, ResultCode: $resultCode");
    respondStatus(true, $message, $response);
    
} catch (PDOException $e) {
    logStatus("DATABASE ERROR: " . $e->getMessage());
    respondStatus(false, 'Database query failed', [
        'resultCode' => 500,
        'error' => 'Database error occurred'
    ], 500);
}

// NO closing ?> tag
