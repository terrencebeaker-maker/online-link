<?php
/**
 * SMS OTP API Endpoint
 * Alpha Energy App - by Jimhawkins Korir
 * 
 * Uses Africa's Talking SMS API
 * 
 * Actions:
 * - send: Generate and send OTP to phone
 * - verify: Verify the OTP code
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Africa's Talking Configuration
// Get your API key from: https://account.africastalking.com
define('AT_USERNAME', 'sandbox'); // Change to your username in production
define('AT_API_KEY', 'YOUR_AFRICASTALKING_API_KEY'); // Get from africastalking.com
define('AT_SENDER_ID', 'AlphaEnergy'); // Your approved sender ID

// Database connection (for storing OTPs)
$dbHost = 'localhost';
$dbName = 'energy_app';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // If DB fails, use file-based OTP storage
    $pdo = null;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$phone = $input['phone'] ?? '';
$otp = $input['otp'] ?? '';

// Normalize phone number
function normalizePhone($phone) {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (strpos($phone, '0') === 0) {
        $phone = '+254' . substr($phone, 1);
    } elseif (strpos($phone, '254') === 0) {
        $phone = '+' . $phone;
    } elseif (strpos($phone, '+254') !== 0) {
        $phone = '+254' . $phone;
    }
    return $phone;
}

// Generate 6-digit OTP
function generateOtp() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// Store OTP (using file if no DB)
function storeOtp($phone, $otp) {
    global $pdo;
    $expiry = time() + 300; // 5 minutes
    
    if ($pdo) {
        $stmt = $pdo->prepare("
            INSERT INTO otp_codes (phone, otp_code, expires_at, attempts) 
            VALUES (?, ?, FROM_UNIXTIME(?), 0)
            ON DUPLICATE KEY UPDATE otp_code = ?, expires_at = FROM_UNIXTIME(?), attempts = 0
        ");
        $stmt->execute([$phone, $otp, $expiry, $otp, $expiry]);
    } else {
        $otpFile = sys_get_temp_dir() . '/otp_' . md5($phone) . '.json';
        file_put_contents($otpFile, json_encode([
            'otp' => $otp,
            'expires' => $expiry,
            'attempts' => 0
        ]));
    }
}

// Verify OTP
function verifyOtp($phone, $inputOtp) {
    global $pdo;
    
    if ($pdo) {
        $stmt = $pdo->prepare("SELECT otp_code, expires_at, attempts FROM otp_codes WHERE phone = ?");
        $stmt->execute([$phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) return ['valid' => false, 'error' => 'No OTP found'];
        if (strtotime($row['expires_at']) < time()) return ['valid' => false, 'error' => 'OTP expired'];
        if ($row['attempts'] >= 3) return ['valid' => false, 'error' => 'Too many attempts'];
        
        if ($row['otp_code'] === $inputOtp) {
            $pdo->prepare("DELETE FROM otp_codes WHERE phone = ?")->execute([$phone]);
            return ['valid' => true];
        }
        
        $pdo->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE phone = ?")->execute([$phone]);
        return ['valid' => false, 'error' => 'Invalid OTP'];
    } else {
        $otpFile = sys_get_temp_dir() . '/otp_' . md5($phone) . '.json';
        if (!file_exists($otpFile)) return ['valid' => false, 'error' => 'No OTP found'];
        
        $data = json_decode(file_get_contents($otpFile), true);
        if ($data['expires'] < time()) return ['valid' => false, 'error' => 'OTP expired'];
        if ($data['attempts'] >= 3) return ['valid' => false, 'error' => 'Too many attempts'];
        
        if ($data['otp'] === $inputOtp) {
            unlink($otpFile);
            return ['valid' => true];
        }
        
        $data['attempts']++;
        file_put_contents($otpFile, json_encode($data));
        return ['valid' => false, 'error' => 'Invalid OTP'];
    }
}

// Send SMS via Africa's Talking
function sendSms($phone, $message) {
    $url = 'https://api.africastalking.com/version1/messaging';
    
    $data = [
        'username' => AT_USERNAME,
        'to' => $phone,
        'message' => $message,
        'from' => AT_SENDER_ID
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'apiKey: ' . AT_API_KEY
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($httpCode === 201 && isset($result['SMSMessageData']['Recipients'][0]['status']) 
        && $result['SMSMessageData']['Recipients'][0]['status'] === 'Success') {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => $result['SMSMessageData']['Message'] ?? 'SMS failed'];
}

// Process request
$phone = normalizePhone($phone);

if ($action === 'send') {
    if (empty($phone)) {
        echo json_encode(['success' => false, 'error' => 'Phone number required']);
        exit;
    }
    
    $newOtp = generateOtp();
    storeOtp($phone, $newOtp);
    
    $message = "Your Alpha Energy verification code is: $newOtp. Valid for 5 minutes. Do not share.";
    $smsResult = sendSms($phone, $message);
    
    if ($smsResult['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'OTP sent to ' . substr($phone, 0, 7) . '****' . substr($phone, -2)
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => $smsResult['error'] ?? 'Failed to send SMS']);
    }
    exit;
}

if ($action === 'verify') {
    if (empty($phone) || empty($otp)) {
        echo json_encode(['success' => false, 'error' => 'Phone and OTP required']);
        exit;
    }
    
    $result = verifyOtp($phone, $otp);
    
    if ($result['valid']) {
        echo json_encode(['success' => true, 'message' => 'OTP verified successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => $result['error']]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action. Use: send or verify']);
