<?php
/**
 * Database Configuration for M-Pesa Integration
 * Handles PostgreSQL connection with proper error handling
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Custom error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true;
});

// Get database credentials from environment variables
$host = getenv('DB_HOST');
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_NAME');
$user = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

// Log configuration status (without exposing sensitive data)
error_log("DB Config Check - Host: " . ($host ? 'SET' : 'NOT SET'));
error_log("DB Config Check - Port: $port");
error_log("DB Config Check - Database: " . ($dbname ? 'SET' : 'NOT SET'));
error_log("DB Config Check - User: " . ($user ? 'SET' : 'NOT SET'));

// Validate required environment variables
if (!$host || !$user || !$password || !$dbname) {
    error_log("❌ CRITICAL: Missing required database environment variables");
    $conn = null;
} else {
    try {
        // Build PostgreSQL DSN
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        
        // Create PDO connection
        $conn = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 30
        ]);
        
        error_log("✅ Database connected successfully to $dbname");
        
    } catch (PDOException $e) {
        error_log("❌ Database connection failed: " . $e->getMessage());
        $conn = null;
    }
}

// M-Pesa API Configuration
// These are loaded from environment variables but have fallback defaults
define('MPESA_CONSUMER_KEY', getenv('MPESA_CONSUMER_KEY') ?: 'BqGXfPzkAS3Ada7JAV6jNcr26hKRmzVn');
define('MPESA_CONSUMER_SECRET', getenv('MPESA_CONSUMER_SECRET') ?: 'NHfO1qmG1pMzBiVy');
define('MPESA_SHORTCODE', getenv('MPESA_SHORTCODE') ?: '7887702');
define('MPESA_PASSKEY', getenv('MPESA_PASSKEY') ?: '8ba2b74132b75970ed1d1ca22396f8b4eb79106902bf8e0017f4f0558fb6cc18');
define('MPESA_TILL_NUMBER', getenv('MPESA_TILL_NUMBER') ?: '9830453');
define('MPESA_CALLBACK_URL', getenv('MPESA_CALLBACK_URL') ?: 'https://online-link.onrender.com/callback.php');

// API URLs
define('MPESA_AUTH_URL', 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
define('MPESA_STK_URL', 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest');

// NO closing ?> tag
