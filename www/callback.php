<?php
/**
 * M-Pesa Callback Handler
 * 
 * This file receives webhook callbacks from Safaricom after STK Push completion
 * It updates the transaction status in the database
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

/**
 * Log callback messages
 */
function logCallback($message) {
    error_log("[MPESA CALLBACK " . date('Y-m-d H:i:s') . "] " . $message);
}

/**
 * Always respond with success to M-Pesa to prevent retries
 */
function respondToMpesa() {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback processed']);
    exit;
}

logCallback("=== CALLBACK RECEIVED ===");

// Get callback data
$raw = file_get_contents('php://input');
logCallback("Raw payload: " . $raw);

$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logCallback("ERROR: Invalid JSON - " . json_last_error_msg());
    respondToMpesa();
}

// Extract STK callback data
$stk = $data['Body']['stkCallback'] ?? null;

if (!$stk) {
    logCallback("ERROR: Invalid callback structure - missing stkCallback");
    respondToMpesa();
}

// Extract transaction identifiers
$checkoutRequestID = $stk['CheckoutRequestID'] ?? '';
$merchantRequestID = $stk['MerchantRequestID'] ?? '';
$resultCode = isset($stk['ResultCode']) ? (int)$stk['ResultCode'] : 1;
$resultDesc = $stk['ResultDesc'] ?? 'Unknown error';

logCallback("Transaction: CheckoutRequestID=$checkoutRequestID, ResultCode=$resultCode");
logCallback("Result Description: $resultDesc");

// Validate required fields
if (empty($checkoutRequestID)) {
    logCallback("ERROR: Missing CheckoutRequestID in callback");
    respondToMpesa();
}

// Initialize transaction details
$amount = null;
$phone = null;
$receipt = null;
$transactionDate = null;
$status = 'failed';

// Process based on result code
switch ($resultCode) {
    case 0:
        // SUCCESS - Extract callback metadata
        $status = 'completed';
        $items = $stk['CallbackMetadata']['Item'] ?? [];
        
        foreach ($items as $item) {
            $name = $item['Name'] ?? '';
            $value = $item['Value'] ?? null;
            
            switch ($name) {
                case 'Amount':
                    $amount = (float)$value;
                    break;
                case 'MpesaReceiptNumber':
                    $receipt = $value;
                    break;
                case 'PhoneNumber':
                    $phone = (string)$value;
                    break;
                case 'TransactionDate':
                    // Convert M-Pesa format (YYYYMMDDHHMMSS) to PostgreSQL datetime
                    $dateStr = (string)$value;
                    if (strlen($dateStr) === 14) {
                        $transactionDate = sprintf(
                            '%s-%s-%s %s:%s:%s',
                            substr($dateStr, 0, 4),  // Year
                            substr($dateStr, 4, 2),  // Month
                            substr($dateStr, 6, 2),  // Day
                            substr($dateStr, 8, 2),  // Hour
                            substr($dateStr, 10, 2), // Minute
                            substr($dateStr, 12, 2)  // Second
                        );
                    }
                    break;
            }
        }
        
        logCallback("SUCCESS - Amount: $amount, Receipt: $receipt, Phone: $phone, Date: $transactionDate");
        break;
        
    case 1032:
        // User cancelled
        $status = 'cancelled';
        $resultDesc = 'User cancelled the transaction';
        logCallback("CANCELLED - User cancelled payment");
        break;
        
    case 1:
        // Insufficient funds
        $status = 'failed';
        $resultDesc = 'Insufficient funds';
        logCallback("FAILED - Insufficient funds");
        break;
        
    case 1037:
        // Timeout
        $status = 'failed';
        $resultDesc = 'Transaction timeout';
        logCallback("FAILED - Transaction timeout");
        break;
        
    default:
        // Other failures
        $status = 'failed';
        logCallback("FAILED - Code: $resultCode, Description: $resultDesc");
        break;
}

// Load database configuration
try {
    require_once 'config.php';
    logCallback("Database configuration loaded");
} catch (Exception $e) {
    logCallback("FATAL: Config load failed - " . $e->getMessage());
    respondToMpesa();
}

if (!isset($conn) || $conn === null) {
    logCallback("FATAL: Database not connected");
    respondToMpesa();
}

// Update transaction in database
try {
    $conn->beginTransaction();
    
    logCallback("Starting database update transaction...");
    
    // Build update SQL matching your exact schema
    $updateSql = "
        UPDATE mpesa_transactions
        SET 
            status = :status,
            result_desc = :desc,
            mpesa_receipt = :receipt,
            mpesa_receipt_number = :receipt,
            completed_at = :completed,
            updated_at = NOW()
        WHERE checkout_request_id = :checkout";
    
    $stmt = $conn->prepare($updateSql);
    
    $params = [
        ':status' => $status,
        ':desc' => $resultDesc,
        ':receipt' => $receipt, // Will be NULL if not successful
        ':completed' => $transactionDate, // Will be NULL if not successful
        ':checkout' => $checkoutRequestID
    ];
    
    logCallback("Executing mpesa_transactions UPDATE...");
    $stmt->execute($params);
    
    $rowCount = $stmt->rowCount();
    logCallback("UPDATE affected $rowCount row(s)");
    
    if ($rowCount == 0) {
        // Transaction not found - create minimal record
        logCallback("WARNING: Transaction not found in database, creating minimal record");
        
        $insertSql = "
            INSERT INTO mpesa_transactions
            (checkout_request_id, merchant_request_id, status, result_desc, 
             phone, amount, mpesa_receipt, mpesa_receipt_number, 
             completed_at)
            VALUES 
            (:checkout, :merchant, :status, :desc, 
             :phone, :amount, :receipt, :receipt, 
             :completed)
            RETURNING id::text";
        
        $stmt2 = $conn->prepare($insertSql);
        
        $insertResult = $stmt2->execute([
            ':checkout' => $checkoutRequestID,
            ':merchant' => $merchantRequestID,
            ':status' => $status,
            ':desc' => $resultDesc,
            ':phone' => $phone,
            ':amount' => $amount ?? 0,
            ':receipt' => $receipt,
            ':completed' => $transactionDate
        ]);
        
        if ($insertResult) {
            $insertedRow = $stmt2->fetch(PDO::FETCH_ASSOC);
            $insertedId = $insertedRow['id'] ?? 'unknown';
            logCallback("✅ Minimal transaction record created with ID: " . substr($insertedId, 0, 8) . "...");
        } else {
            logCallback("⚠️ Insert executed but may have failed");
        }
    } else {
        logCallback("✅ Transaction updated successfully");
    }
    
    // Now update the corresponding sales record if it exists
    try {
        logCallback("Attempting to update sales record...");
        
        $salesUpdateSql = "
            UPDATE sales
            SET 
                transaction_status = :status,
                mpesa_receipt_number = :receipt,
                updated_at = NOW()
            WHERE checkout_request_id = :checkout";
        
        $salesStmt = $conn->prepare($salesUpdateSql);
        $salesStmt->execute([
            ':status' => strtoupper($status),
            ':receipt' => $receipt,
            ':checkout' => $checkoutRequestID
        ]);
        
        $salesRowCount = $salesStmt->rowCount();
        if ($salesRowCount > 0) {
            logCallback("✅ Sales record updated ($salesRowCount row(s))");
        } else {
            logCallback("INFO: No sales record found for this transaction (may not have been created)");
        }
    } catch (PDOException $e) {
        logCallback("WARNING: Failed to update sales record - " . $e->getMessage());
        // Continue - don't fail the entire transaction
    }
    
    $conn->commit();
    logCallback("✅ Database transaction committed");
    
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    logCallback("❌ DATABASE ERROR: " . $e->getMessage());
    logCallback("   SQL State: " . ($e->errorInfo[0] ?? 'N/A'));
    logCallback("   Error details: " . json_encode($e->errorInfo));
    // Still respond success to M-Pesa even if DB fails
}

logCallback("=== CALLBACK PROCESSING COMPLETE ===");

// Always return success to M-Pesa
respondToMpesa();

// NO closing ?> tag
