<?php
// callback_log.php
$host = "dbmysql-204162-0.cloudclusters.net";
$user = "admin";
$password = "5ZT8bJWM";
$database = "Mpesa_DB";
$port = 19902;

// Connect to MySQL
$conn = new mysqli($host, $user, $password, $database, $port);
if ($conn->connect_error) die("DB Connection failed: " . $conn->connect_error);

// Get search query from form
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// Stats cards query
$statsQuery = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE WHEN ResultCode = 0 THEN 1 ELSE 0 END) as total_success,
    SUM(CASE WHEN ResultCode != 0 THEN 1 ELSE 0 END) as total_failed,
    SUM(CASE WHEN Amount IS NOT NULL THEN Amount ELSE 0 END) as total_amount
FROM mpesa_transactions";

if (!empty($search)) {
    $statsQuery .= " WHERE MpesaReceiptNumber LIKE '%$search%' OR PhoneNumber LIKE '%$search%'";
}

$statsResult = $conn->query($statsQuery);
$stats = $statsResult->fetch_assoc();

// Transactions query
if (!empty($search)) {
    $sql = "SELECT * FROM mpesa_transactions 
            WHERE MpesaReceiptNumber LIKE '%$search%' OR PhoneNumber LIKE '%$search%' 
            ORDER BY id DESC LIMIT 50";
} else {
    $sql = "SELECT * FROM mpesa_transactions ORDER BY id DESC LIMIT 50";
}
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📋 M-Pesa Transactions Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #2d3748;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header p {
            color: #718096;
            font-size: 1.1rem;
            font-weight: 400;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.2rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            border-radius: 20px 20px 0 0;
        }

        .stat-card.total::before { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-card.amount::before { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .stat-card.success::before { background: linear-gradient(135deg, #4facfe, #00f2fe); }
        .stat-card.failed::before { background: linear-gradient(135deg, #43e97b, #38f9d7); }

        .stat-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-info h3 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .stat-info p {
            color: #718096;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
        }

        .stat-card.total .stat-icon { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-card.amount .stat-icon { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .stat-card.success .stat-icon { background: linear-gradient(135deg, #4facfe, #00f2fe); }
        .stat-card.failed .stat-icon { background: linear-gradient(135deg, #43e97b, #38f9d7); }

        .search-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .search-form {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            min-width: 300px;
            padding: 1rem 1.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .search-btn {
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 15px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .table-header {
            padding: 1.5rem 2rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .table-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8fafc;
            color: #4a5568;
            font-weight: 600;
            padding: 0.8rem 0.6rem;
            text-align: left;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 0.8rem 0.6rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.85rem;
            vertical-align: middle;
            line-height: 1.4;
        }

        tr:hover {
            background: rgba(102, 126, 234, 0.05);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            gap: 0.3rem;
        }

        .status-success {
            background: rgba(72, 187, 120, 0.1);
            color: #38a169;
        }

        .status-failed {
            background: rgba(245, 101, 101, 0.1);
            color: #e53e3e;
        }

        .status-pending {
            background: rgba(237, 137, 54, 0.1);
            color: #dd6b20;
        }

        .result-code-success {
            color: #38a169;
            font-weight: 600;
        }

        .result-code-failed {
            color: #e53e3e;
            font-weight: 600;
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            color: #718096;
            font-size: 1.1rem;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e0;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .header h1 {
                font-size: 2rem;
            }

            .stat-info h3 {
                font-size: 2rem;
            }

            .search-form {
                flex-direction: column;
            }

            .search-input {
                min-width: 100%;
            }

            .table-wrapper {
                font-size: 0.8rem;
            }

            th, td {
                padding: 0.8rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-analytics"></i> M-Pesa Transactions Dashboard</h1>
            <p>Monitor and manage your M-Pesa transaction data in real-time</p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-content">
                    <div class="stat-info">
                        <h3><?= number_format($stats['total_transactions']) ?></h3>
                        <p>Total Transactions</p>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card amount">
                <div class="stat-content">
                    <div class="stat-info">
                        <h3>KES <?= number_format($stats['total_amount'], 2) ?></h3>
                        <p>Total Amount</p>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card success">
                <div class="stat-content">
                    <div class="stat-info">
                        <h3><?= number_format($stats['total_success']) ?></h3>
                        <p>Successful Transactions</p>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-check-double"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card failed">
                <div class="stat-content">
                    <div class="stat-info">
                        <h3><?= number_format($stats['total_failed']) ?></h3>
                        <p>Failed/Cancelled</p>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search Section -->
        <div class="search-section">
            <form method="get" class="search-form">
                <input type="text" name="search" class="search-input" 
                       placeholder="Search by receipt number or phone number..." 
                       value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="search-btn">
                    <i class="fas fa-search-plus"></i>
                    Search
                </button>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="table-container">
            <div class="table-header">
                <h2><i class="fas fa-database"></i> Recent Transactions</h2>
            </div>
            
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Merchant Request ID</th>
                            <th>Checkout Request ID</th>
                            <th>Result Code</th>
                            <th>Result Description</th>
                            <th>Amount</th>
                            <th>M-Pesa Receipt</th>
                            <th>Phone Number</th>
                            <th>Transaction Date</th>
                            <th>Created At</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['id']) ?></td>
                                <td><?= htmlspecialchars($row['MerchantRequestID']) ?></td>
                                <td><?= htmlspecialchars($row['CheckoutRequestID']) ?></td>
                                <td class="<?= ($row['ResultCode'] ?? 1) == 0 ? 'result-code-success' : 'result-code-failed' ?>">
                                    <?= htmlspecialchars($row['ResultCode']) ?>
                                </td>
                                <td><?= htmlspecialchars($row['ResultDesc']) ?></td>
                                <td><?= $row['Amount'] ? 'KES ' . number_format($row['Amount'], 2) : '-' ?></td>
                                <td><?= htmlspecialchars($row['MpesaReceiptNumber']) ?></td>
                                <td><?= htmlspecialchars($row['PhoneNumber']) ?></td>
                                <td><?= htmlspecialchars($row['TransactionDate']) ?></td>
                                <td><?= htmlspecialchars($row['created_at']) ?></td>
                                <td>
                                    <?php if ($row['ResultCode'] == 0 && !empty($row['MpesaReceiptNumber'])): ?>
                                        <span class="status-badge status-success">
                                            <i class="fas fa-check-circle"></i> Success
                                        </span>
                                    <?php elseif ($row['ResultCode'] != 0): ?>
                                        <span class="status-badge status-failed">
                                            <i class="fas fa-times-circle"></i> Failed
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-pending">
                                            <i class="fas fa-hourglass-half"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="no-data">
                                    <div>
                                        <i class="fas fa-inbox"></i>
                                        <br>
                                        No transactions found
                                        <?= !empty($search) ? ' for your search query' : '' ?>.
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>

<?php
$conn->close();
?>