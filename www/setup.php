<?php
// stkpush.php

require_once 'config.php';

logMessage("=== NEW REQUEST RECEIVED ===");

// ----------------------------------------------------------------------
// 1. INPUT VALIDATION
// ----------------------------------------------------------------------

$rawInput = file_get_contents('php://input');
logMessage("Raw input: " . $rawInput);

$input = json_decode($rawInput, true);

if (!$input) {
    respond(false, "Invalid JSON input.");
}

$requiredKeys = ['amount', 'phone', 'account', 'user_id', 'pump_id', 'shift_id', 'description'];
foreach ($requiredKeys as $key) {
    if (!isset($input[$key]) || empty($input[$key])) {
        respond(false, "Missing required parameter: " . $key);
    }
}

$amount = (float)$input['amount'];
$phone = trim($input['phone']);
$account = trim($input['account']);
$userId = (int)$input['user_id']; // Treat as INT/BIGINT as per the database fix
$pumpId = (int)$input['pump_id']; // Treat as INT/BIGINT as per the database fix
$shiftId = (int)$input['shift_id']; // Treat as INT/BIGINT as per the database fix
$description = trim($input['description']);

if ($amount <= 0 || !is_numeric($amount)) {
    respond(false, "Invalid amount.");
}

// Ensure phone number is in the 2547xxxxxxxxx format
if (preg_match('/^07[0-9]{8}$/', $phone)) {
    $phone = '254' . substr($phone, 1);
} elseif (!preg_match('/^2547[0-9]{8}$/', $phone)) {
    respond(false, "Invalid phone number format.");
}
logMessage("Parsed - Amount: $amount, Phone: $phone, Account: $account, UserID: $userId");
logMessage("Sanitized phone: " . $phone);


// ----------------------------------------------------------------------
// 2. M-PESA STK PUSH LOGIC
// ----------------------------------------------------------------------

$timestamp = date('YmdHis');
$password = base64_encode($shortcode . $passkey . $timestamp);
$url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

// --- Get Access Token ---
logMessage("Getting access token...");
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $url);
curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . base64_encode($consumerKey . ':' . $consumerSecret)]);
curl_setopt($curl, CURLOPT_HEADER, false);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($curl);
$httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$accessToken = null;

if ($httpStatus != 200 || $response === false) {
    logMessage("❌ Token request failed. Status: $httpStatus, Error: " . curl_error($curl));
    respond(false, "Failed to connect to M-Pesa service for token.");
}

$tokenData = json_decode($response, true);
$accessToken = $tokenData['access_token'] ?? null;
curl_close($curl);

if (!$accessToken) {
    logMessage("❌ Token response missing access_token: " . $response);
    respond(false, "M-Pesa access token error.");
}
logMessage("Access token obtained: " . substr($accessToken, 0, 10) . "...");


// --- Initiate STK Push ---
$stkUrl = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
$stkData = [
    "BusinessShortCode" => $shortcode,
    "Password" => $password,
    "Timestamp" => $timestamp,
    "TransactionType" => "CustomerBuyGoodsOnline", // Use CustomerBuyGoodsOnline for Till
    "Amount" => $amount,
    "PartyA" => $phone,
    "PartyB" => $shortcode, // Must be the same shortcode for Till
    "PhoneNumber" => $phone,
    "CallBackURL" => $callbackUrl,
    "AccountReference" => $account,
    "TransactionDesc" => $description,
];

logMessage("STK Request: " . json_encode($stkData));

$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $stkUrl);
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $accessToken
]);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($stkData));
$stkResponse = curl_exec($curl);
$httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

logMessage("STK Response: " . $stkResponse);
$stkResponse = json_decode($stkResponse, true);

$checkoutRequestID = $stkResponse['CheckoutRequestID'] ?? null;
$merchantRequestID = $stkResponse['MerchantRequestID'] ?? null;
$customerMessage = $stkResponse['CustomerMessage'] ?? "Request accepted for processing.";


// ----------------------------------------------------------------------
// 3. IMMEDIATE RESPONSE TO CLIENT (CRITICAL)
// ----------------------------------------------------------------------

if (($stkResponse['ResponseCode'] ?? '1') == '0' && $checkoutRequestID) {
    logMessage("SUCCESS - CheckoutRequestID: " . $checkoutRequestID);

    // Stop output buffering and send success response IMMEDIATELY
    ob_end_clean();
    respond(true, $customerMessage, [
        'CheckoutRequestID' => $checkoutRequestID,
        'MerchantRequestID' => $merchantRequestID,
        'CustomerMessage' => $customerMessage,
    ]);

} else {
    // Failure from M-Pesa immediately (e.g., duplicate request, invalid credentials)
    $errorDescription = $stkResponse['ResponseDescription'] ?? $stkResponse['errorMessage'] ?? 'Unknown M-Pesa error.';
    logMessage("❌ M-Pesa Push Failed. Code: " . ($stkResponse['ResponseCode'] ?? 'N/A') . ", Desc: " . $errorDescription);
    ob_end_clean();
    respond(false, $errorDescription, [], $httpStatus != 200 ? $httpStatus : 400);
}


// --- NOTE: The code below is NOT reached because respond() exits the script.
// --- The database save must be done by the callback.php
// --- We rely ONLY on the initial M-Pesa status check for this script.

// NO closing ?> tag
