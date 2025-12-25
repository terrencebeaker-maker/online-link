<?php
// stk_status_checker.php - Checks pending transactions and updates their status

// Railway Database Configuration
$host = "mainline.proxy.rlwy.net";
$user = "root";
$password = "jPNMrNeqkNvQtnQNRKkeaMTsrcIkYfxj";
$database = "railway";
$port = 54048;


// M-Pesa API Configuration
$consumer_key = "BqGXfPzkAS3Ada7JAV6jNcr26hKRmzVn";
$consumer_secret = "NHfO1qmG1pMzBiVy";
$business_short_code = "7887702";
$passkey = "8ba2b74132b75970ed1d1ca22396f8b4eb79106902bf8e0017f4f0558fb6cc18";

// Function to get M-Pesa access token
function getMpesaAccessToken($consumer_key, $consumer_secret) {
    $url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    
    $credentials = base64_encode($consumer_key . ':' . $consumer_secret);
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/json'
        ],
    ]);
    
    $response = curl_exec($curl);
    curl_close($curl);
    
    $result = json_decode($response, true);
    return $result['access_token'] ?? null;
}

// Function to query STK transaction status
function queryStkStatus($access_token, $business_short_code, $checkout_request_id, $passkey) {
    $url = 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';
    
    $timestamp = date('YmdHis');
    $password = base64_encode($business_short_code . $passkey . $timestamp);
    
    $data = [
        'BusinessShortCode' => $business_short_code,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $checkout_request_id
    ];
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ],
    ]);
    
    $response = curl_exec($curl);
    curl_close($curl);
    
    return json_decode($response, true);
}

// Function to log status checks
function logStatusCheck($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] STATUS CHECK: $message" . PHP_EOL;
    file_put_contents('mpesa_status_check.log', $logEntry, FILE_APPEND | LOCK_EX);
}

try {
    $conn = new mysqli($host, $user, $password, $database, $port);
    if ($conn->connect_error) {
        die("DB Connection failed: " . $conn->connect_error);
    }
    
    logStatusCheck("Starting status check for pending transactions");
    
    $access_token = getMpesaAccessToken($consumer_key, $consumer_secret);
    if (!$access_token) {
        throw new Exception("Failed to get M-Pesa access token");
    }
    
    logStatusCheck("Successfully obtained M-Pesa access token");
    
    $stmt = $conn->prepare("
        SELECT id, CheckoutRequestID, MerchantRequestID, Amount, PhoneNumber, created_at 
        FROM mpesa_transactions 
        WHERE ResultCode = '0' 
        AND ResultDesc LIKE '%Request accepted for processing%' 
        AND created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $checkedCount = 0;
    $updatedCount = 0;
    
    while ($row = $result->fetch_assoc()) {
        $checkoutRequestID = $row['CheckoutRequestID'];
        $transactionId = $row['id'];
        $checkedCount++;
        
        logStatusCheck("Checking transaction ID: $transactionId, CheckoutRequestID: $checkoutRequestID");
        
        $statusResponse = queryStkStatus($access_token, $business_short_code, $checkoutRequestID, $passkey);
        
        if (isset($statusResponse['ResultCode'])) {
            $resultCode = $statusResponse['ResultCode'];
            $resultDesc = $statusResponse['ResultDesc'] ?? '';
            
            $updateStmt = $conn->prepare("
                UPDATE mpesa_transactions 
                SET ResultCode = ?, ResultDesc = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            
            $updateStmt->bind_param("ssi", $resultCode, $resultDesc, $transactionId);
            
            if ($updateStmt->execute()) {
                $updatedCount++;
                $statusMessage = getStatusMessage($resultCode);
                logStatusCheck("Updated transaction $transactionId: $statusMessage - $resultDesc");
            }
            $updateStmt->close();
        } else {
            logStatusCheck("No valid response for transaction $transactionId: " . json_encode($statusResponse));
        }
        
        sleep(1);
    }
    
    $stmt->close();
    $conn->close();
    
    logStatusCheck("Status check completed. Checked: $checkedCount, Updated: $updatedCount transactions");
    
    if (php_sapi_name() !== 'cli') {
        echo json_encode([
            'success' => true,
            'checked' => $checkedCount,
            'updated' => $updatedCount,
            'message' => "Status check completed successfully"
        ]);
    }
    
} catch (Exception $e) {
    logStatusCheck("ERROR: " . $e->getMessage());
    
    if (php_sapi_name() !== 'cli') {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function getStatusMessage($resultCode) {
    $statusMessages = [
        '0' => 'SUCCESS: Payment completed successfully',
        '1' => 'INSUFFICIENT FUNDS: Customer does not have enough money',
        '17' => 'CANCELLED BY USER: Customer cancelled the transaction',
        '1001' => 'INVALID PHONE NUMBER: Phone number is not registered for M-Pesa',
        '1019' => 'TRANSACTION TIMEOUT: Customer did not enter PIN in time',
        '1032' => 'CANCELLED BY USER: Request canceled by user',
        '1037' => 'TIMEOUT: Could not reach customer (DS timeout)',
        '2001' => 'INVALID PIN: Wrong PIN entered too many times',
        '9999' => 'SYSTEM ERROR: Request processing failed'
    ];
    
    return $statusMessages[$resultCode] ?? "Unknown status: $resultCode";
}
?>