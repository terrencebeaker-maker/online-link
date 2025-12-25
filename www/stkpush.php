<?php
// stkpush.php - COMPLETE FIXED VERSION with requested STK format

ob_start();
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

// --- Error Handling Setup ---
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true;
});

set_exception_handler(function($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage());
    respond(false, 'Server error: ' . $exception->getMessage());
});

function logMessage($message) {
    error_log("[MPESA DEBUG] " . $message);
}

function respond($success, $message, $data = []) {
    ob_end_clean();
    header('Content-Type: application/json');
    
    $response = array_merge([
        'success' => $success,
        'message' => $message
    ], $data);
    
    $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    logMessage("Sending response: " . json_encode($response));
    
    echo $json;
    exit;
}
// --- End Error Handling Setup ---

logMessage("=== NEW REQUEST RECEIVED ===");

try {
    require_once 'config.php';
    logMessage("Config loaded successfully");
} catch (Exception $e) {
    logMessage("Config load failed: " . $e->getMessage());
    respond(false, 'Configuration error');
}

// M-Pesa Credentials (using getenv but providing defaults from your previous code)
$consumerKey = getenv('MPESA_CONSUMER_KEY') ?: 'BqGXfPzkAS3Ada7JAV6jNcr26hKRmzVn';
$consumerSecret = getenv('MPESA_CONSUMER_SECRET') ?: 'NHfO1qmG1pMzBiVy';
$shortCode = getenv('MPESA_SHORTCODE') ?: '7887702'; // Your BusinessShortCode
$passkey = getenv('MPESA_PASSKEY') ?: '8ba2b74132b75970ed1d1ca22396f8b4eb79106902bf8e0017f4f0558fb6cc18';
$callbackUrl = getenv('MPESA_CALLBACK_URL') ?: 'https://online-link.onrender.com/callback.php';

// Get input
$rawInput = file_get_contents('php://input');
logMessage("Raw input: " . $rawInput);

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logMessage("JSON decode error: " . json_last_error_msg());
    respond(false, "Invalid JSON input");
}

$amount = isset($input['amount']) ? (float)$input['amount'] : 1.0;
$phone = isset($input['phone']) ? trim($input['phone']) : '';
$accountRef = isset($input['account']) ? $input['account'] : 'PHX340123';
$userId = isset($input['user_id']) ? $input['user_id'] : null;
$pumpId = isset($input['pump_id']) ? $input['pump_id'] : null;
$shiftId = isset($input['shift_id']) ? $input['shift_id'] : null;
$description = isset($input['description']) ? $input['description'] : 'Payment';

logMessage("Parsed - Amount: $amount, Phone: $phone, Account: $accountRef, UserID: $userId");

if (empty($phone)) {
    respond(false, "Phone number is required");
}

// Sanitize phone
$phone = preg_replace('/\s+/', '', $phone);
$phone = preg_replace('/^0/', '254', $phone);
$phone = preg_replace('/^\+/', '', $phone);
$phone = preg_replace('/[^0-9]/', '', $phone);

logMessage("Sanitized phone: $phone");

if (!preg_match('/^254\d{9}$/', $phone)) {
    respond(false, "Invalid phone number format. Use 0712345678 or 254712345678");
}

if ($amount < 1) {
    respond(false, "Amount must be at least 1 KES");
}

// === M-PESA LOGIC START ===

// 1. Get access token
$accessToken = getAccessToken($consumerKey, $consumerSecret);

if (!$accessToken) {
    logMessage("Failed to get access token");
    respond(false, "Failed to authenticate with M-Pesa. Please try again.");
}

// 2. Prepare STK Push
$timestamp = date('YmdHis');
$password = base64_encode($shortCode . $passkey . $timestamp);

// --- START: YOUR REQUESTED FORMAT UPDATE ---
$stkRequest = [
    'BusinessShortCode' => $shortCode,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerBuyGoodsOnline',
    'Amount' => (int)$amount,
    'PartyA' => $phone,
    'PartyB' => '9830453', // <-- YOUR FIXED TILL NUMBER
    'PhoneNumber' => $phone,
    'CallBackURL' => $callbackUrl,
    'AccountReference' => $accountRef,
    'TransactionDesc' => $description
];
// --- END: YOUR REQUESTED FORMAT UPDATE ---

logMessage("STK Request: " . json_encode($stkRequest));

// 3. Make STK Push
$stkResponse = makeStkRequest($accessToken, $stkRequest);

logMessage("STK Response: " . json_encode($stkResponse));

if (!$stkResponse || !is_array($stkResponse)) {
    respond(false, "Invalid response structure from M-Pesa API.");
}

if (isset($stkResponse['errorCode'])) {
    // M-Pesa API rejected the request immediately
    $errorCode = $stkResponse['errorCode'];
    $errorMessage = $stkResponse['errorMessage'] ?? 'Unknown error';
    logMessage("M-Pesa API Rejection - Code: $errorCode, Message: $errorMessage");
    respond(false, "M-Pesa API Rejection: $errorMessage (Code: $errorCode)");
}

// 4. Check Response Code
if (($stkResponse['ResponseCode'] ?? '1') == '0') {
    // SUCCESS - Request accepted, pop-up sent
    $checkoutRequestID = $stkResponse['CheckoutRequestID'] ?? '';
    $merchantRequestID = $stkResponse['MerchantRequestID'] ?? '';
    $responseDesc = $stkResponse['ResponseDescription'] ?? 'Success. Request accepted for processing';
    $customerMessage = $stkResponse['CustomerMessage'] ?? 'Please check your phone and enter M-Pesa PIN.';
    
    if (empty($checkoutRequestID)) {
        logMessage("ERROR: Missing CheckoutRequestID in M-Pesa successful response.");
        respond(false, "M-Pesa accepted, but transaction ID is missing.", ['CheckoutRequestID' => '']);
    }
    
    logMessage("SUCCESS - CheckoutRequestID: $checkoutRequestID");
    
    // --- DATABASE SAVE ---
    $saleId = null;
    $userUUID = null;
    $saleIdNo = null;
    
    if (isset($conn) && $conn !== null) {
        try {
            logMessage("Attempting database operations...");
            
            // 1. Look up user's UUID from users table if attendant_id (integer) was provided
            if ($userId !== null && !empty($userId) && is_numeric($userId)) {
                try {
                    // Try to find user by attendant_id (integer column in users table)
                    $userQuery = $conn->prepare("SELECT id FROM users WHERE attendant_id = :attendant_id LIMIT 1");
                    $userQuery->execute([':attendant_id' => (int)$userId]);
                    $userRow = $userQuery->fetch(PDO::FETCH_ASSOC);
                    if ($userRow) {
                        $userUUID = $userRow['id'];
                        logMessage("✅ Found user UUID: " . substr($userUUID, 0, 8) . "...");
                    } else {
                        logMessage("⚠️ No user found with attendant_id: $userId");
                    }
                } catch (PDOException $e) {
                    logMessage("⚠️ User lookup failed: " . $e->getMessage());
                }
            }
            
            // 2. Generate a better account reference
            $saleIdNo = "SALE-" . time() . "-" . ($pumpId ?: "0");
            $betterAccountRef = $saleIdNo;
            
            // 3. Insert into mpesa_transactions with user UUID if found
            if ($userUUID !== null) {
                $sql = "INSERT INTO mpesa_transactions 
                        (checkout_request_id, merchant_request_id, phone, amount, account_ref, user_id, status, created_at)
                        VALUES 
                        (:checkout, :merchant, :phone, :amount, :account, :user_id, 'pending', NOW())
                        RETURNING id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':checkout' => $checkoutRequestID,
                    ':merchant' => $merchantRequestID,
                    ':phone' => $phone,
                    ':amount' => $amount,
                    ':account' => $betterAccountRef,
                    ':user_id' => $userUUID
                ]);
            } else {
                $sql = "INSERT INTO mpesa_transactions 
                        (checkout_request_id, merchant_request_id, phone, amount, account_ref, status, created_at)
                        VALUES 
                        (:checkout, :merchant, :phone, :amount, :account, 'pending', NOW())
                        RETURNING id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':checkout' => $checkoutRequestID,
                    ':merchant' => $merchantRequestID,
                    ':phone' => $phone,
                    ':amount' => $amount,
                    ':account' => $betterAccountRef
                ]);
            }
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $saleId = $result['id'] ?? null;
            logMessage("✅ mpesa_transactions saved with ID: " . ($saleId ? substr($saleId, 0, 8) . "..." : "null"));
            
            // 4. Also create a sales record for filtering/tracking (if pump_id and shift_id provided)
            if ($pumpId !== null && $shiftId !== null) {
                try {
                    logMessage("Attempting sales INSERT with pump_id=$pumpId, shift_id=$shiftId, attendant_id=$userId");
                    
                    // Validate pump_id exists
                    $pumpCheck = $conn->prepare("SELECT pump_id FROM pumps WHERE pump_id = :pump_id");
                    $pumpCheck->execute([':pump_id' => (int)$pumpId]);
                    if (!$pumpCheck->fetch()) {
                        logMessage("⚠️ pump_id $pumpId not found in pumps table - skipping sales insert");
                        throw new Exception("Invalid pump_id: $pumpId");
                    }
                    
                    // Validate pump_shift_id exists
                    $shiftCheck = $conn->prepare("SELECT pump_shift_id FROM pump_shifts WHERE pump_shift_id = :shift_id");
                    $shiftCheck->execute([':shift_id' => (int)$shiftId]);
                    if (!$shiftCheck->fetch()) {
                        logMessage("⚠️ pump_shift_id $shiftId not found in pump_shifts table - skipping sales insert");
                        throw new Exception("Invalid pump_shift_id: $shiftId");
                    }
                    
                    $salesSql = "INSERT INTO sales 
                                (sale_id_no, pump_shift_id, pump_id, attendant_id, amount, 
                                 customer_mobile_no, transaction_status, checkout_request_id, created_at)
                                VALUES 
                                (:sale_id_no, :shift_id, :pump_id, :attendant_id, :amount, 
                                 :phone, 'PENDING', :checkout, NOW())
                                RETURNING sale_id";
                    $salesStmt = $conn->prepare($salesSql);
                    $salesStmt->execute([
                        ':sale_id_no' => $saleIdNo,
                        ':shift_id' => (int)$shiftId,
                        ':pump_id' => (int)$pumpId,
                        ':attendant_id' => (int)($userId ?: 1),
                        ':amount' => $amount,
                        ':phone' => $phone,
                        ':checkout' => $checkoutRequestID
                    ]);
                    $salesResult = $salesStmt->fetch(PDO::FETCH_ASSOC);
                    logMessage("✅ Sales record created with ID: " . ($salesResult['sale_id'] ?? 'unknown'));
                } catch (Exception $e) {
                    logMessage("⚠️ Sales insert failed: " . $e->getMessage());
                }
            } else {
                logMessage("⚠️ pump_id or shift_id is null - skipping sales insert");
            }
            
        } catch (PDOException $e) {
            logMessage("❌ DB Error during save: " . $e->getMessage());
        }
    } else {
        logMessage("WARNING: Database connection not available");
    }
    
    // CRITICAL: Send success response back to the mobile app
    // MUST include snake_case fields to match Android @SerializedName annotations
    respond(true, $customerMessage, [
        'checkout_request_id' => $checkoutRequestID,  // Required by Android
        'merchant_request_id' => $merchantRequestID,  // Required by Android
        'sale_id' => $saleId ? (string)$saleId : null, // Required by Android
        'CheckoutRequestID' => $checkoutRequestID,
        'MerchantRequestID' => $merchantRequestID,
        'saleId' => $saleId ? (string)$saleId : null,
        'CustomerMessage' => $customerMessage
    ]);
    
} else {
    // STK Push Request failed on M-Pesa side
    $errorCode = $stkResponse['ResponseCode'];
    $errorDesc = $stkResponse['ResponseDescription'] ?? 'Unknown error';
    $customerMessage = $stkResponse['CustomerMessage'] ?? $errorDesc;
    
    logMessage("M-Pesa Rejected - Code: $errorCode, Description: $errorDesc");
    respond(false, $customerMessage, [
        'ErrorCode' => $errorCode,
        'ErrorDescription' => $errorDesc
    ]);
}

// === HELPER FUNCTIONS (UNCHANGED) ===

function getAccessToken($key, $secret) {
    $credentials = base64_encode("$key:$secret");
    $url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Basic $credentials"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200) return null;
    
    $json = json_decode($result, true);
    return $json['access_token'] ?? null;
}

function makeStkRequest($token, $data) {
    $url = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 20
    ]);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    if (empty($result)) return null;
    
    return json_decode($result, true);
}

// NO closing ?> tag
