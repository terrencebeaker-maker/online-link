<?php
// auto_dashboard.php - Dashboard that automatically checks pending transactions
$host = "dbmysql-204162-0.cloudclusters.net";
$user = "admin";
$password = "5ZT8bJWM";
$database = "Mpesa_DB";
$port = 19902;

$conn = new mysqli($host, $user, $password, $database, $port);
if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}

// Function to get human-readable status
function getStatusMessage($resultCode, $resultDesc) {
    $statusMessages = [
        '0' => ['text' => 'SUCCESS', 'class' => 'success', 'icon' => '✅'],
        '1' => ['text' => 'INSUFFICIENT FUNDS', 'class' => 'insufficient-funds', 'icon' => '💰'],
        '17' => ['text' => 'CANCELLED BY USER', 'class' => 'cancelled', 'icon' => '❌'],
        '26' => ['text' => 'BUSINESS ERROR', 'class' => 'error', 'icon' => '⚠️'],
        '1001' => ['text' => 'INVALID PHONE', 'class' => 'error', 'icon' => '📱'],
        '1019' => ['text' => 'TIMEOUT', 'class' => 'timeout', 'icon' => '⏱️'],
        '1032' => ['text' => 'CANCELLED BY USER', 'class' => 'cancelled', 'icon' => '❌'],
        '1037' => ['text' => 'TIMEOUT - USER UNREACHABLE', 'class' => 'timeout', 'icon' => '📵'],
        '2001' => ['text' => 'INVALID PIN', 'class' => 'error', 'icon' => '🔢'],
        '9999' => ['text' => 'SYSTEM ERROR', 'class' => 'error', 'icon' => '💥']
    ];
    
    // Handle pending status (initial acceptance)
    if ($resultCode == '0' && strpos($resultDesc, 'Request accepted for processing') !== false) {
        return ['text' => 'PENDING', 'class' => 'pending', 'icon' => '⏳'];
    }
    
    return $statusMessages[$resultCode] ?? ['text' => "ERROR: $resultDesc", 'class' => 'error', 'icon' => '❌'];
}

// Auto-update pending transactions older than 3 minutes
function autoUpdatePendingTransactions($conn) {
    $updateQuery = "
        UPDATE mpesa_transactions 
        SET ResultCode = '1037', 
            ResultDesc = 'Transaction timeout - no callback received',
            updated_at = NOW()
        WHERE ResultCode = '0' 
        AND ResultDesc LIKE '%Request accepted for processing%' 
        AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ";
    
    $conn->query($updateQuery);
    return $conn->affected_rows;
}

// Check if this is an AJAX request for status update
if (isset($_GET['action']) && $_GET['action'] === 'update_pending') {
    $updatedRows = autoUpdatePendingTransactions($conn);
    header('Content-Type: application/json');
    echo json_encode(['updated' => $updatedRows, 'timestamp' => date('Y-m-d H:i:s')]);
    exit;
}

// Auto-update old pending transactions
$autoUpdated = autoUpdatePendingTransactions($conn);

// Get statistics
$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM mpesa_transactions")->fetch_assoc()['count'],
    'successful' => $conn->query("SELECT COUNT(*) as count FROM mpesa_transactions WHERE ResultCode = '0' AND ResultDesc NOT LIKE '%Request accepted for processing%'")->fetch_assoc()['count'],
    'pending' => $conn->query("SELECT COUNT(*) as count FROM mpesa_transactions WHERE ResultCode = '0' AND ResultDesc LIKE '%Request accepted for processing%'")->fetch_assoc()['count'],
    'cancelled' => $conn->query("SELECT COUNT(*) as count FROM mpesa_transactions WHERE ResultCode IN ('17', '1032')")->fetch_assoc()['count'],
    'insufficient_funds' => $conn->query("SELECT COUNT(*) as count FROM mpesa_transactions WHERE ResultCode = '1'")->fetch_assoc()['count'],
    'timeout' => $conn->query("SELECT COUNT(*) as count FROM mpesa_transactions WHERE ResultCode IN ('1019', '1037')")->fetch_assoc()['count']
];

// --- Handle Mpesa JSON callback ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (isset($data['Body']['stkCallback'])) {
        $cb = $data['Body']['stkCallback'];
        $merchantRequestID = $cb['MerchantRequestID'] ?? '';
        $checkoutRequestID = $cb['CheckoutRequestID'] ?? '';
        $resultCode = $cb['ResultCode'] ?? '';
        $resultDesc = $cb['ResultDesc'] ?? '';
        $amount = null;
        $mpesaReceipt = '';
        $phone = '';
        $transactionDate = date('Y-m-d H:i:s');

        if (isset($cb['CallbackMetadata']['Item'])) {
            foreach ($cb['CallbackMetadata']['Item'] as $item) {
                if ($item['Name'] === 'Amount') $amount = $item['Value'];
                if ($item['Name'] === 'MpesaReceiptNumber') $mpesaReceipt = $item['Value'];
                if ($item['Name'] === 'PhoneNumber') $phone = $item['Value'];
            }
        }

        // Check if transaction already exists
        $checkStmt = $conn->prepare("SELECT id, MpesaReceiptNumber FROM mpesa_transactions WHERE CheckoutRequestID = ? LIMIT 1");
        $checkStmt->bind_param("s", $checkoutRequestID);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            // Transaction exists, update receipt if missing
            $row = $checkResult->fetch_assoc();
            if (empty($row['MpesaReceiptNumber']) && !empty($mpesaReceipt)) {
                $updateStmt = $conn->prepare("UPDATE mpesa_transactions SET MpesaReceiptNumber = ?, ResultCode = ?, ResultDesc = ? WHERE id = ?");
                $updateStmt->bind_param("ssis", $mpesaReceipt, $resultCode, $resultDesc, $row['id']);
                $updateStmt->execute();
                $updateStmt->close();
            }
        } else {
            // Insert new transaction
            $insertStmt = $conn->prepare("INSERT INTO mpesa_transactions 
                (MerchantRequestID, CheckoutRequestID, ResultCode, ResultDesc, Amount, MpesaReceiptNumber, PhoneNumber, TransactionDate, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $insertStmt->bind_param("sssdsiss", $merchantRequestID, $checkoutRequestID, $resultCode, $resultDesc, $amount, $mpesaReceipt, $phone, $transactionDate);
            $insertStmt->execute();
            $insertStmt->close();
        }

        $checkStmt->close();

        // Respond to Mpesa
        header('Content-Type: application/json');
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback received successfully']);
        exit;
    }
}

// --- Display transactions ---
$result = $conn->query("SELECT * FROM mpesa_transactions ORDER BY id DESC LIMIT 50");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>📱 M-Pesa Live Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: #f5f6fa;
            color: #2f3542;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
            position: relative;
        }
        .header h1 { margin: 0; font-size: 2.5em; }
        .header p { margin: 10px 0 0 0; opacity: 0.9; }
        
        .live-indicator {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.2);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        .live-dot {
            width: 8px;
            height: 8px;
            background: #2ed573;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 5px solid;
            transition: transform 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card.total { border-left-color: #3742fa; }
        .stat-card.success { border-left-color: #2ed573; }
        .stat-card.pending { border-left-color: #ffa502; }
        .stat-card.cancelled { border-left-color: #ff4757; }
        .stat-card.insufficient { border-left-color: #ffa502; }
        .stat-card.timeout { border-left-color: #747d8c; }
        
        .stat-number { font-size: 2.5em; font-weight: bold; margin-bottom: 10px; }
        .stat-label { color: #747d8c; font-weight: 500; }
        
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .table-header {
            background: #2f3542;
            color: white;
            padding: 20px;
            font-size: 1.2em;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .auto-update-info {
            font-size: 14px;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        th, td { 
            padding: 15px 10px; 
            text-align: left; 
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }
        th { 
            background: #f8f9fa; 
            font-weight: 600;
            color: #2f3542;
            position: sticky;
            top: 0;
        }
        tr:hover { background: #f8f9fa; }
        
        .status {
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            min-width: 120px;
            justify-content: center;
        }
        .status.success { background: #d4edda; color: #155724; }
        .status.pending { background: #fff3cd; color: #856404; animation: blink 2s infinite; }
        .status.cancelled { background: #f8d7da; color: #721c24; }
        .status.insufficient-funds { background: #fff3cd; color: #856404; }
        .status.timeout { background: #e2e3e5; color: #383d41; }
        .status.error { background: #f8d7da; color: #721c24; }
        
        @keyframes blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0.6; }
        }
        
        .amount { 
            font-weight: bold; 
            color: #2ed573; 
        }
        .receipt { 
            font-family: 'Courier New', monospace; 
            background: #f8f9fa; 
            padding: 4px 8px; 
            border-radius: 4px;
            font-size: 12px;
        }
        .phone { color: #3742fa; font-weight: 500; }
        .date { color: #747d8c; font-size: 12px; }
        
        .alert {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        @media (max-width: 768px) {
            .table-container { overflow-x: auto; }
            th, td { padding: 10px 8px; font-size: 12px; }
            .header h1 { font-size: 2em; }
            .stats { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
            .live-indicator { position: static; margin-top: 15px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="live-indicator">
                <div class="live-dot"></div>
                LIVE MONITORING
            </div>
            <h1>📱 M-Pesa Live Dashboard</h1>
            <p>Real-time transaction monitoring with auto-status updates</p>
        </div>
        
        <?php if ($autoUpdated > 0): ?>
        <div class="alert">
            ⚡ Auto-updated <?= $autoUpdated ?> pending transactions that were older than 5 minutes
        </div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card total">
                <div class="stat-number"><?= $stats['total'] ?></div>
                <div class="stat-label">📊 Total Transactions</div>
            </div>
            <div class="stat-card success">
                <div class="stat-number"><?= $stats['successful'] ?></div>
                <div class="stat-label">✅ Successful Payments</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-number"><?= $stats['pending'] ?></div>
                <div class="stat-label">⏳ Pending Transactions</div>
            </div>
            <div class="stat-card cancelled">
                <div class="stat-number"><?= $stats['cancelled'] ?></div>
                <div class="stat-label">❌ Cancelled by User</div>
            </div>
            <div class="stat-card insufficient">
                <div class="stat-number"><?= $stats['insufficient_funds'] ?></div>
                <div class="stat-label">💰 Insufficient Funds</div>
            </div>
            <div class="stat-card timeout">
                <div class="stat-number"><?= $stats['timeout'] ?></div>
                <div class="stat-label">⏱️ Timeout/Failed</div>
            </div>
        </div>

        <div class="table-container">
            <div class="table-header">
                <span>📋 Live Transaction Feed</span>
                <div class="auto-update-info">
                    ⚡ Auto-refresh: 15s | Old pending → Timeout: 5min
                </div>
            </div>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Status</th>
                    <th>Amount</th>
                    <th>Receipt</th>
                    <th>Phone</th>
                    <th>Time</th>
                    <th>Age</th>
                </tr>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php 
                    $status = getStatusMessage($row["ResultCode"] ?? '9999', $row["ResultDesc"] ?? '');
                    $createdTime = new DateTime($row["created_at"]);
                    $now = new DateTime();
                    $age = $now->diff($createdTime);
                    $ageString = '';
                    if ($age->h > 0) $ageString = $age->h . 'h ';
                    $ageString .= $age->i . 'm ago';
                    ?>
                    <tr>
                        <td><strong>#<?= htmlspecialchars($row["id"] ?? '') ?></strong></td>
                        <td>
                            <span class="status <?= $status['class'] ?>">
                                <?= $status['icon'] ?> <?= $status['text'] ?>
                            </span>
                        </td>
                        <td class="amount">
                            <?= !empty($row["Amount"]) ? 'KES ' . number_format($row["Amount"], 2) : '-' ?>
                        </td>
                        <td>
                            <?= !empty($row["MpesaReceiptNumber"]) ? 
                                '<span class="receipt">' . htmlspecialchars($row["MpesaReceiptNumber"]) . '</span>' : '-' ?>
                        </td>
                        <td class="phone"><?= htmlspecialchars($row["PhoneNumber"] ?? '') ?></td>
                        <td class="date">
                            <?= date('g:i A', strtotime($row["created_at"])) ?>
                        </td>
                        <td class="date"><?= $ageString ?></td>
                    </tr>
                <?php endwhile; ?>
            </table>
        </div>
    </div>

    <script>
        // Auto-refresh every 15 seconds
        setInterval(function() {
            window.location.reload();
        }, 15000);
        
        // Show notification for page activity
        document.title = '🔴 M-Pesa Dashboard - Live';
        
        // Optional: Update pending transactions via AJAX
        function updatePendingTransactions() {
            fetch('?action=update_pending')
                .then(response => response.json())
                .then(data => {
                    if (data.updated > 0) {
                        console.log(`Updated ${data.updated} pending transactions at ${data.timestamp}`);
                    }
                })
                .catch(error => console.log('Update check failed:', error));
        }
        
        // Check for updates every 2 minutes
        setInterval(updatePendingTransactions, 120000);
    </script>
</body>
</html>
<?php
$conn->close();
?>