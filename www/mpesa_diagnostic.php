<?php
// mpesa_diagnostic.php - Complete M-Pesa Configuration Checker

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=utf-8');

echo "=== M-PESA CONFIGURATION DIAGNOSTIC ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Credentials
$consumerKey = 'BqGXfPzkAS3Ada7JAV6jNcr26hKRmzVn';
$consumerSecret = 'NHfO1qmG1pMzBiVy';
$shortCode = '7887702';
$passkey = '8ba2b74132b75970ed1d1ca22396f8b4eb79106902bf8e0017f4f0558fb6cc18';

echo "1. CREDENTIALS CHECK\n";
echo "   Consumer Key: " . substr($consumerKey, 0, 10) . "..." . substr($consumerKey, -5) . "\n";
echo "   Consumer Secret: " . substr($consumerSecret, 0, 5) . "..." . substr($consumerSecret, -3) . "\n";
echo "   Shortcode: $shortCode\n";
echo "   Passkey: " . substr($passkey, 0, 10) . "..." . substr($passkey, -10) . "\n\n";

// Test 1: Get Access Token
echo "2. TESTING ACCESS TOKEN\n";
$credentials = base64_encode("$consumerKey:$consumerSecret");
$url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ["Authorization: Basic $credentials"],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 30
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "   ✗ CURL Error: $curlError\n";
    die("\nCannot proceed without access token.\n");
}

echo "   HTTP Status: $httpCode\n";
echo "   Response: $result\n";

if ($httpCode != 200) {
    echo "   ✗ FAILED: Invalid credentials or API access issue\n";
    echo "\n   DIAGNOSIS:\n";
    echo "   - Your Consumer Key and Secret might be wrong\n";
    echo "   - Your app might not be approved on Daraja\n";
    echo "   - You might be using sandbox credentials instead of production\n";
    die("\nCannot proceed without valid access token.\n");
}

$tokenData = json_decode($result, true);
$accessToken = $tokenData['access_token'] ?? null;

if (!$accessToken) {
    echo "   ✗ FAILED: No access token in response\n";
    die("\nCannot proceed.\n");
}

echo "   ✓ SUCCESS: Access token obtained\n";
echo "   Token: " . substr($accessToken, 0, 15) . "...\n\n";

// Test 2: Check if we're hitting production API
echo "3. VERIFYING API ENDPOINT\n";
echo "   Using: https://api.safaricom.co.ke (PRODUCTION)\n";
echo "   ✓ Correct endpoint for live transactions\n\n";

// Test 3: Validate Shortcode Format
echo "4. SHORTCODE VALIDATION\n";
if (strlen($shortCode) == 6 || strlen($shortCode) == 7) {
    echo "   ✓ Shortcode length is valid ($shortCode)\n";
} else {
    echo "   ⚠ Warning: Unusual shortcode length\n";
}

// Check if it's a paybill or till number
if (strlen($shortCode) == 6) {
    echo "   Type: Likely a Till Number\n";
} else if (strlen($shortCode) == 7) {
    echo "   Type: Likely a Paybill Number\n";
}
echo "\n";

// Test 4: Test STK Push Request Structure
echo "5. TESTING STK PUSH REQUEST\n";
$timestamp = date('YmdHis');
$password = base64_encode($shortCode . $passkey . $timestamp);

$stkRequest = [
    'BusinessShortCode' => $shortCode,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => 1,
    'PartyA' => '254720316175',
    'PartyB' => $shortCode,
    'PhoneNumber' => '254720316175',
    'CallBackURL' => 'https://online-link.onrender.com/callback.php',
    'AccountReference' => 'TEST',
    'TransactionDesc' => 'Test Payment'
];

echo "   Request Structure:\n";
echo json_encode($stkRequest, JSON_PRETTY_PRINT) . "\n\n";

$url = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
$payload = json_encode($stkRequest);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 60
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "6. STK PUSH RESPONSE\n";
echo "   HTTP Status: $httpCode\n";

if ($curlError) {
    echo "   ✗ CURL Error: $curlError\n";
} else {
    echo "   Response:\n";
    $formatted = json_decode($result, true);
    echo json_encode($formatted, JSON_PRETTY_PRINT) . "\n\n";
    
    if (isset($formatted['ResponseCode'])) {
        $code = $formatted['ResponseCode'];
        $desc = $formatted['ResponseDescription'] ?? 'No description';
        
        if ($code == '0') {
            echo "   ✓ STK Push request accepted by M-Pesa\n";
            echo "   CheckoutRequestID: " . ($formatted['CheckoutRequestID'] ?? 'N/A') . "\n\n";
        } else {
            echo "   ✗ STK Push rejected\n";
            echo "   Code: $code\n";
            echo "   Description: $desc\n\n";
        }
    }
    
    if (isset($formatted['errorCode'])) {
        echo "   ✗ M-PESA API ERROR\n";
        echo "   Error Code: " . $formatted['errorCode'] . "\n";
        echo "   Error Message: " . ($formatted['errorMessage'] ?? 'N/A') . "\n\n";
        
        echo "   COMMON ERROR CODES:\n";
        echo "   - 400.002.02: Invalid credentials\n";
        echo "   - 500.001.1001: Wrong passkey\n";
        echo "   - 401: Invalid access token\n";
        echo "   - 403: Forbidden - API not enabled for your account\n\n";
    }
    
    if (isset($formatted['ResultCode'])) {
        $resultCode = $formatted['ResultCode'];
        echo "   Result Code: $resultCode\n";
        
        switch($resultCode) {
            case 11:
                echo "   ⚠ The DebitParty is in an invalid state\n";
                echo "   MEANING: Paybill not properly configured for STK Push\n";
                break;
            case 1032:
                echo "   ⚠ Request cancelled by user\n";
                break;
            case 1037:
                echo "   ⚠ Timeout - user didn't enter PIN\n";
                break;
            case 2001:
                echo "   ⚠ Wrong PIN entered\n";
                break;
        }
    }
}

echo "\n7. DIAGNOSIS & RECOMMENDATIONS\n";
echo "=================================\n\n";

if ($httpCode == 200 && isset($formatted['ResponseCode']) && $formatted['ResponseCode'] == '0') {
    echo "✓ Your API credentials are CORRECT\n";
    echo "✓ STK Push request was accepted\n\n";
    echo "If you didn't receive the prompt on your phone, the issue is:\n\n";
    echo "🔴 PAYBILL CONFIGURATION ISSUE\n";
    echo "   Your paybill $shortCode is NOT properly configured for STK Push.\n\n";
    echo "   ACTION REQUIRED:\n";
    echo "   1. Contact Safaricom Business Care: 0711 051 444\n";
    echo "   2. Request: 'Enable Lipa Na M-Pesa Online (STK Push) for paybill $shortCode'\n";
    echo "   3. They will need to:\n";
    echo "      - Verify your paybill is active\n";
    echo "      - Enable the STK Push feature\n";
    echo "      - Provide you with the correct passkey\n";
    echo "   4. It may take 24-48 hours to activate\n\n";
} else if ($httpCode == 401) {
    echo "🔴 AUTHENTICATION FAILED\n";
    echo "   Your access token is invalid or expired.\n";
    echo "   - Verify your Consumer Key and Secret\n";
    echo "   - Check if your app is approved on Daraja Portal\n\n";
} else if ($httpCode == 403) {
    echo "🔴 ACCESS FORBIDDEN\n";
    echo "   Your app doesn't have permission to use STK Push API.\n";
    echo "   - Go to https://developer.safaricom.co.ke\n";
    echo "   - Check if your app has 'Lipa Na M-Pesa Online' enabled\n";
    echo "   - Contact Safaricom to enable this feature\n\n";
} else if (isset($formatted['errorCode']) && $formatted['errorCode'] == '500.001.1001') {
    echo "🔴 WRONG PASSKEY\n";
    echo "   The passkey you're using doesn't match the shortcode.\n";
    echo "   - Log into https://developer.safaricom.co.ke\n";
    echo "   - Go to your app settings\n";
    echo "   - Get the correct passkey for shortcode $shortCode\n\n";
} else {
    echo "🔴 CONFIGURATION ERROR\n";
    echo "   There's an issue with your M-Pesa setup.\n";
    echo "   Review the error messages above and:\n";
    echo "   1. Verify all credentials on Daraja Portal\n";
    echo "   2. Ensure your app is approved for production\n";
    echo "   3. Contact Safaricom Business Care if needed\n\n";
}

echo "=== END DIAGNOSTIC ===\n";
?>
