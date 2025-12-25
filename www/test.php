<?php
// test.php - Simple endpoint to verify JSON responses work

// Start output buffering
ob_start();

// Disable errors to browser
error_reporting(0);
ini_set('display_errors', 0);

// Set JSON header
header('Content-Type: application/json');

// Get input
$input = json_decode(file_get_contents('php://input'), true);

// Clean buffer and send response
ob_end_clean();

echo json_encode([
    'success' => true,
    'message' => 'Test endpoint working',
    'received_data' => $input,
    'server_time' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION
]);
exit;
