<?php
/**
 * Health Check Endpoint
 * Helps keep the Render server warm and responds to health checks
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simple health check response
echo json_encode([
    'status' => 'healthy',
    'service' => 'M-Pesa STK Push Backend',
    'version' => '2.0.0',
    'timestamp' => date('c'),
    'uptime' => file_exists('/proc/uptime') ? floatval(file_get_contents('/proc/uptime')) : null
]);
