<?php
/**
 * OPTIMIZED M-PESA CALLBACK with FCM Push Notifications
 * 
 * Compatible with existing schema:
 * - mpesa_transactions (UUID id, user_id references users)
 * - sales table (INTEGER sale_id, attendant_id references users_new)
 * - shifts table remains GLOBAL (Day/Night)
 * 
 * Features:
 * 1. Instant FCM push notification on payment completion
 * 2. Multi-station support
 * 3. Fast database updates
 * 
 * @version 2.0.0
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

function logCallback($message) {
    error_log("[MPESA-CALLBACK " . date('Y-m-d H:i:s') . "] " . $message);
}

function respondToMpesa() {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback processed']);
    exit;
}

/**
 * Send FCM Push Notification for instant payment status update
 */
function sendFcmNotification($fcmToken, $title, $body, $data = []) {
    $fcmServerKey = getenv('FCM_SERVER_KEY');
    
    if (empty($fcmServerKey) || empty($fcmToken)) {
        logCallback("FCM: Missing server key or token");
        return false;
    }
    
    $payload = [
        'to' => $fcmToken,
        'notification' => [
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'android_channel_id' => 'mpesa_payments',
            'priority' => 'high'
        ],
        'data' => array_merge([
            'type' => 'payment_status',
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
        ], $data),
        'priority' => 'high',
        'time_to_live' => 60
    ];
    
    $ch = curl_init('https://fcm.googleapis.com/fcm/send');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: key=' . $fcmServerKey
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($result, true);
    
    if ($httpCode == 200 && isset($response['success']) && $response['success'] > 0) {
        logCallback("✅ FCM notification sent successfully");
        return true;
    } else {
        logCallback("❌ FCM failed - HTTP: $httpCode, Response: $result");
        return false;
    }
}

logCallback("=== CALLBACK RECEIVED ===");

// Get callback data
$raw = file_get_contents('php://input');
logCallback("Raw payload: " . $raw);

$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logCallback("ERROR: Invalid JSON");
    respondToMpesa();
}

$stk = $data['Body']['stkCallback'] ?? null;

if (!$stk) {
    logCallback("ERROR: Invalid callback structure");
    respondToMpesa();
}

// Extract transaction details
$checkoutRequestID = $stk['CheckoutRequestID'] ?? '';
$merchantRequestID = $stk['MerchantRequestID'] ?? '';
$resultCode = isset($stk['ResultCode']) ? (int)$stk['ResultCode'] : 1;
$resultDesc = $stk['ResultDesc'] ?? 'Unknown error';

logCallback("Transaction: $checkoutRequestID, ResultCode: $resultCode");

if (empty($checkoutRequestID)) {
    respondToMpesa();
}

// Parse callback metadata
$amount = null;
$phone = null;
$receipt = null;
$transactionDate = null;
$status = 'failed';

switch ($resultCode) {
    case 0:
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
                    $dateStr = (string)$value;
                    if (strlen($dateStr) === 14) {
                        $transactionDate = sprintf('%s-%s-%s %s:%s:%s',
                            substr($dateStr, 0, 4), substr($dateStr, 4, 2),
                            substr($dateStr, 6, 2), substr($dateStr, 8, 2),
                            substr($dateStr, 10, 2), substr($dateStr, 12, 2)
                        );
                    }
                    break;
            }
        }
        logCallback("✅ SUCCESS - Amount: $amount, Receipt: $receipt");
        break;
        
    case 1032:
        $status = 'cancelled';
        $resultDesc = 'Transaction cancelled by user';
        logCallback("❌ CANCELLED by user");
        break;
        
    case 1:
        $status = 'failed';
        $resultDesc = 'Insufficient funds';
        logCallback("❌ FAILED - Insufficient funds");
        break;
        
    case 1037:
        $status = 'failed';
        $resultDesc = 'Transaction timeout';
        logCallback("❌ FAILED - Timeout");
        break;
        
    default:
        $status = 'failed';
        logCallback("❌ FAILED - Code: $resultCode");
        break;
}

// Load database
try {
    require_once 'config.php';
} catch (Exception $e) {
    logCallback("Config error: " . $e->getMessage());
    respondToMpesa();
}

if (!isset($conn) || $conn === null) {
    logCallback("DB not connected");
    respondToMpesa();
}

// Update database and send notifications
try {
    $conn->beginTransaction();
    
    // Get transaction details including FCM token
    $stmt = $conn->prepare("
        SELECT id, user_id, station_id, phone, amount, fcm_token
        FROM mpesa_transactions 
        WHERE checkout_request_id = :checkout
        LIMIT 1
    ");
    $stmt->execute([':checkout' => $checkoutRequestID]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $userId = $transaction['user_id'] ?? null;
    $stationId = $transaction['station_id'] ?? null;
    $fcmToken = $transaction['fcm_token'] ?? null;
    $transactionPhone = $transaction['phone'] ?? $phone;
    $transactionAmount = $transaction['amount'] ?? $amount;
    
    // Update mpesa_transactions (UUID-based table)
    $updateSql = "
        UPDATE mpesa_transactions
        SET status = :status,
            result_desc = :desc,
            mpesa_receipt = :receipt,
            mpesa_receipt_number = :receipt,
            completed_at = :completed,
            updated_at = NOW()
        WHERE checkout_request_id = :checkout";
    
    $stmt = $conn->prepare($updateSql);
    $stmt->execute([
        ':status' => $status,
        ':desc' => $resultDesc,
        ':receipt' => $receipt,
        ':completed' => $transactionDate,
        ':checkout' => $checkoutRequestID
    ]);
    
    $rowCount = $stmt->rowCount();
    logCallback("Updated $rowCount row(s) in mpesa_transactions");
    
    // If no row was updated, create a minimal record
    if ($rowCount == 0) {
        logCallback("Creating minimal transaction record...");
        $insertSql = "
            INSERT INTO mpesa_transactions
            (checkout_request_id, merchant_request_id, status, result_desc, 
             phone, amount, mpesa_receipt, mpesa_receipt_number, completed_at)
            VALUES 
            (:checkout, :merchant, :status, :desc, 
             :phone, :amount, :receipt, :receipt, :completed)";
        
        $stmt = $conn->prepare($insertSql);
        $stmt->execute([
            ':checkout' => $checkoutRequestID,
            ':merchant' => $merchantRequestID,
            ':status' => $status,
            ':desc' => $resultDesc,
            ':phone' => $phone,
            ':amount' => $amount ?? 0,
            ':receipt' => $receipt,
            ':completed' => $transactionDate
        ]);
        logCallback("✅ Minimal record created");
    }
    
    // Update sales table (INTEGER-based with attendant_id from users_new)
    try {
        $salesUpdateSql = "
            UPDATE sales
            SET transaction_status = :status,
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
        }
    } catch (Exception $e) {
        logCallback("Sales update skipped: " . $e->getMessage());
    }
    
    $conn->commit();
    
    // =====================================================
    // SEND FCM PUSH NOTIFICATION (INSTANT!)
    // =====================================================
    
    $amountFormatted = number_format($transactionAmount ?: $amount ?: 0, 2);
    
    if ($status === 'completed') {
        $notificationTitle = "✅ Payment Successful!";
        $notificationBody = "KES $amountFormatted received. Receipt: $receipt";
    } elseif ($status === 'cancelled') {
        $notificationTitle = "❌ Payment Cancelled";
        $notificationBody = "Customer cancelled the KES $amountFormatted payment";
    } else {
        $notificationTitle = "❌ Payment Failed";
        $notificationBody = "KES $amountFormatted payment failed: $resultDesc";
    }
    
    $notificationData = [
        'checkout_request_id' => $checkoutRequestID,
        'status' => $status,
        'result_code' => (string)$resultCode,
        'amount' => (string)($transactionAmount ?: $amount),
        'receipt' => $receipt ?? '',
        'phone' => $transactionPhone ?? ''
    ];
    
    // Send to FCM token stored with transaction
    if ($fcmToken) {
        sendFcmNotification($fcmToken, $notificationTitle, $notificationBody, $notificationData);
    }
    
    // Log notification for auditing
    try {
        $conn->prepare("
            INSERT INTO notification_logs 
            (user_id, station_id, notification_type, title, body, data, reference_type, reference_id, sent_at)
            VALUES (:user_id, :station_id, 'payment_status', :title, :body, :data, 'mpesa_transaction', :ref_id, NOW())
        ")->execute([
            ':user_id' => $userId,
            ':station_id' => $stationId,
            ':title' => $notificationTitle,
            ':body' => $notificationBody,
            ':data' => json_encode($notificationData),
            ':ref_id' => $checkoutRequestID
        ]);
    } catch (Exception $e) {
        // Notification logging is non-critical
        logCallback("Notification log skipped: " . $e->getMessage());
    }
    
    logCallback("✅ Callback processing complete - Status: $status");
    
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    logCallback("❌ DB ERROR: " . $e->getMessage());
}

respondToMpesa();
