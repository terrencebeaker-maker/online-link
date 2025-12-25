<?php
header("Content-Type: application/json");

// ✅ Railway Database Configuration
$host = getenv('DB_HOST') ?: 'mainline.proxy.rlwy.net';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: 'jPNMrNeqkNvQtnQNRKkeaMTsrcIkYfxj';
$database = getenv('DB_NAME') ?: 'railway';
$port = intval(getenv('DB_PORT') ?: 54048);


$conn = new mysqli($host, $user, $password, $database, $port);

if ($conn->connect_errno) {
    echo json_encode(["success" => false, "message" => $conn->connect_error]);
    exit();
}

$conn->set_charset("utf8mb4");

// Get JSON body from POST request
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid JSON payload"]);
    exit();
}

// Prepare and execute insert query
$stmt = $conn->prepare("
    INSERT INTO mpesa_transactions 
    (MerchantRequestID, CheckoutRequestID, ResultCode, ResultDesc, Amount, MpesaReceiptNumber, PhoneNumber, TransactionDate, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

if (!$stmt) {
    echo json_encode(["success" => false, "message" => $conn->error]);
    exit();
}

$stmt->bind_param(
    "ssisdsss",
    $data["MerchantRequestID"],
    $data["CheckoutRequestID"],
    $data["ResultCode"],
    $data["ResultDesc"],
    $data["Amount"],
    $data["MpesaReceiptNumber"],
    $data["PhoneNumber"],
    $data["TransactionDate"]
);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Transaction saved"]);
} else {
    echo json_encode(["success" => false, "message" => $stmt->error]);
}

$stmt->close();
$conn->close();
?>