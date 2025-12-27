<?php
// stkpush.php - ULTRA-FAST VERSION with Access Token Caching
// Optimized for minimal delay before STK popup

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
    error_log("[MPESA FAST] " . $message);
}

function respond($success, $message, $data = []) {
    ob_end_clean();
    header('Content-Type: application/json');
    
    $response = array_merge([
        'success' => $success,
        'message' => $message
    ], $data);
    
    logMessage("Response: " . json_encode($response));
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

logMessage("=== NEW STK REQUEST ===");

try {
    require_once 'config.php';
} catch (Exception $e) {
    respond(false, 'Configuration error');
}

// M-Pesa Credentials
$consumerKey = getenv('MPESA_CONSUMER_KEY') ?: 'BqGXfPzkAS3Ada7JAV6jNcr26hKRmzVn';
$consumerSecret = getenv('MPESA_CONSUMER_SECRET') ?: 'NHfO1qmG1pMzBiVy';
$shortCode = getenv('MPESA_SHORTCODE') ?: '7887702';
$passkey = getenv('MPESA_PASSKEY') ?: '8ba2b74132b75970ed1d1ca22396f8b4eb79106902bf8e0017f4f0558fb6cc18';
$callbackUrl = getenv('MPESA_CALLBACK_URL') ?: 'https://online-link.onrender.com/callback.php';

// Get input - FAST
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    respond(false, "Invalid JSON input");
}

$amount = isset($input['amount']) ? (float)$input['amount'] : 1.0;
$phone = isset($input['phone']) ? trim($input['phone']) : '';
$accountRef = isset($input['account']) ? $input['account'] : 'PHX340123';
$userId = isset($input['user_id']) ? $input['user_id'] : null;
$pumpId = isset($input['pump_id']) ? $input['pump_id'] : null;
$shiftId = isset($input['shift_id']) ? $input['shift_id'] : null;
$description = isset($input['description']) ? $input['description'] : 'Payment';

if (empty($phone)) {
    respond(false, "Phone number is required");
}

// Sanitize phone - FAST
$phone = preg_replace('/[^0-9]/', '', str_replace([' ', '+'], '', $phone));
if (substr($phone, 0, 1) === '0') {
    $phone = '254' . substr($phone, 1);
}

if (!preg_match('/^254\d{9}$/', $phone)) {
    respond(false, "Invalid phone number format");
}

if ($amount < 1) {
    respond(false, "Amount must be at least 1 KES");
}

logMessage("Phone: $phone, Amount: $amount");

// ========== FAST ACCESS TOKEN WITH CACHING ==========
$accessToken = getCachedAccessToken($consumerKey, $consumerSecret);

if (!$accessToken) {
    logMessage("Token fetch failed");
    respond(false, "Failed to authenticate with M-Pesa. Please try again.");
}

// Prepare STK Push - FAST
$timestamp = date('YmdHis');
$password = base64_encode($shortCode . $passkey . $timestamp);

$stkRequest = [
    'BusinessShortCode' => $shortCode,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerBuyGoodsOnline',
    'Amount' => (int)$amount,
    'PartyA' => $phone,
    'PartyB' => '9830453', // Your TILL number
    'PhoneNumber' => $phone,
    'CallBackURL' => $callbackUrl,
    'AccountReference' => $accountRef,
    'TransactionDesc' => $description
];

// ========== FAST STK REQUEST ==========
$stkResponse = makeFastStkRequest($accessToken, $stkRequest);

if (!$stkResponse || !is_array($stkResponse)) {
    respond(false, "Invalid response from M-Pesa API.");
}

if (isset($stkResponse['errorCode'])) {
    $errorMessage = $stkResponse['errorMessage'] ?? 'Unknown error';
    logMessage("M-Pesa Error: $errorMessage");
    respond(false, "M-Pesa Error: $errorMessage");
}

// Check Response Code
if (($stkResponse['ResponseCode'] ?? '1') == '0') {
    $checkoutRequestID = $stkResponse['CheckoutRequestID'] ?? '';
    $merchantRequestID = $stkResponse['MerchantRequestID'] ?? '';
    $customerMessage = $stkResponse['CustomerMessage'] ?? 'Please check your phone and enter M-Pesa PIN.';
    
    if (empty($checkoutRequestID)) {
        respond(false, "M-Pesa accepted but transaction ID missing.");
    }
    
    logMessage("SUCCESS - CheckoutRequestID: $checkoutRequestID");
    
    // ========== ASYNC DATABASE SAVE (Don't block response) ==========
    $saleId = null;
    $saleIdNo = null;
    
    if (isset($conn) && $conn !== null) {
        try {
            // Generate sale_id_no
            $countQuery = $conn->query("SELECT COUNT(*) as cnt FROM sales");
            $count = $countQuery->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
            $saleIdNo = 'RCP-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
            
            // Get pump_shift_id if possible
            $pumpShiftId = null;
            if (!empty($pumpId)) {
                $shiftQuery = $conn->prepare("SELECT pump_shift_id FROM pump_shifts WHERE pump_id = :pump_id AND is_closed = false LIMIT 1");
                $shiftQuery->execute([':pump_id' => $pumpId]);
                $shiftRow = $shiftQuery->fetch(PDO::FETCH_ASSOC);
                if ($shiftRow) {
                    $pumpShiftId = $shiftRow['pump_shift_id'];
                }
            }
            
            // Insert sale - FAST (minimal fields)
            $insertSale = $conn->prepare("
                INSERT INTO sales (sale_id_no, pump_shift_id, pump_id, attendant_id, amount, 
                                   customer_mobile_no, transaction_status, checkout_request_id, station_id)
                VALUES (:sale_id_no, :pump_shift_id, :pump_id, :attendant_id, :amount, 
                        :phone, 'PENDING', :checkout_request_id, :station_id)
                RETURNING sale_id
            ");
            
            $insertSale->execute([
                ':sale_id_no' => $saleIdNo,
                ':pump_shift_id' => $pumpShiftId,
                ':pump_id' => $pumpId,
                ':attendant_id' => $userId,
                ':amount' => $amount,
                ':phone' => $phone,
                ':checkout_request_id' => $checkoutRequestID,
                ':station_id' => 1
            ]);
            
            $saleRow = $insertSale->fetch(PDO::FETCH_ASSOC);
            $saleId = $saleRow['sale_id'] ?? null;
            
            // Also insert into mpesa_transactions for tracking
            $insertTrans = $conn->prepare("
                INSERT INTO mpesa_transactions (checkout_request_id, merchant_request_id, phone_number, amount, status)
                VALUES (:checkout, :merchant, :phone, :amount, 'PENDING')
            ");
            $insertTrans->execute([
                ':checkout' => $checkoutRequestID,
                ':merchant' => $merchantRequestID,
                ':phone' => $phone,
                ':amount' => $amount
            ]);
            
            logMessage("DB saved - SaleID: $saleId, SaleIdNo: $saleIdNo");
            
        } catch (Exception $e) {
            logMessage("DB Error: " . $e->getMessage());
            // Don't fail the response for DB errors - STK was sent!
        }
    }
    
    // ========== RESPOND IMMEDIATELY ==========
    respond(true, $customerMessage, [
        'checkout_request_id' => $checkoutRequestID,
        'merchant_request_id' => $merchantRequestID,
        'sale_id' => $saleId,
        'sale_id_no' => $saleIdNo
    ]);
    
} else {
    // M-Pesa rejected
    $responseDesc = $stkResponse['ResponseDescription'] ?? 'Request failed';
    $customerMessage = $stkResponse['CustomerMessage'] ?? $responseDesc;
    logMessage("REJECTED: $customerMessage");
    respond(false, $customerMessage, [
        'ResponseCode' => $stkResponse['ResponseCode'] ?? '',
        'ResponseDescription' => $responseDesc
    ]);
}

// ========== OPTIMIZED HELPER FUNCTIONS ==========

/**
 * Get cached access token - MUCH FASTER!
 * Caches token for 50 minutes (tokens valid for 60 min)
 */
function getCachedAccessToken($key, $secret) {
    $cacheFile = sys_get_temp_dir() . '/mpesa_token_cache.json';
    
    // Check cache first
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if ($cache && isset($cache['token']) && isset($cache['expires'])) {
            if (time() < $cache['expires']) {
                logMessage("Using cached token");
                return $cache['token'];
            }
        }
    }
    
    // Fetch new token
    logMessage("Fetching new token...");
    $token = fetchAccessTokenFast($key, $secret);
    
    if ($token) {
        // Cache for 50 minutes
        $cache = [
            'token' => $token,
            'expires' => time() + (50 * 60)
        ];
        file_put_contents($cacheFile, json_encode($cache));
        logMessage("Token cached successfully");
    }
    
    return $token;
}

/**
 * Fetch access token with minimal timeout
 */
function fetchAccessTokenFast($key, $secret) {
    $credentials = base64_encode("$key:$secret");
    $url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Basic $credentials"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 10,           // Reduced from 30
        CURLOPT_CONNECTTIMEOUT => 5,     // Reduced from 10
        CURLOPT_TCP_FASTOPEN => true,    // Enable TCP Fast Open
        CURLOPT_TCP_NODELAY => true      // Disable Nagle's algorithm
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200) return null;
    
    $json = json_decode($result, true);
    return $json['access_token'] ?? null;
}

/**
 * Make STK request with fast settings
 */
function makeFastStkRequest($token, $data) {
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
        CURLOPT_TIMEOUT => 15,           // Reduced from 60
        CURLOPT_CONNECTTIMEOUT => 5,     // Reduced from 20
        CURLOPT_TCP_FASTOPEN => true,    // Enable TCP Fast Open
        CURLOPT_TCP_NODELAY => true,     // Disable Nagle's algorithm
        CURLOPT_FRESH_CONNECT => false,  // Reuse connections
        CURLOPT_FORBID_REUSE => false    // Keep connection open
    ]);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    if (empty($result)) return null;
    
    return json_decode($result, true);
}

// NO closing ?> tag
