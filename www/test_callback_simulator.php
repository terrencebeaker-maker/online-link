<?php
// test_callback_simulator.php
// This script simulates M-Pesa callback to test your endpoint

// ======= SAMPLE CALLBACKS =======

// 1. SUCCESSFUL TRANSACTION CALLBACK
$successful_callback = [
    "Body" => [
        "stkCallback" => [
            "MerchantRequestID" => "29115-34620561-1",
            "CheckoutRequestID" => "ws_CO_191220191020363925",
            "ResultCode" => 0,
            "ResultDesc" => "The service request is processed successfully.",
            "CallbackMetadata" => [
                "Item" => [
                    ["Name" => "Amount", "Value" => 1500.00],
                    ["Name" => "MpesaReceiptNumber", "Value" => "NLJ7RT61SV"],
                    ["Name" => "TransactionDate", "Value" => "20191219102115"],
                    ["Name" => "PhoneNumber", "Value" => "254708374149"]
                ]
            ]
        ]
    ]
];

// 2. FAILED TRANSACTION
$failed_callback = [
    "Body" => [
        "stkCallback" => [
            "MerchantRequestID" => "29115-34620561-2",
            "CheckoutRequestID" => "ws_CO_191220191020363926",
            "ResultCode" => 1032,
            "ResultDesc" => "Request cancelled by user"
        ]
    ]
];

// 3. TIMEOUT
$timeout_callback = [
    "Body" => [
        "stkCallback" => [
            "MerchantRequestID" => "29115-34620561-3",
            "CheckoutRequestID" => "ws_CO_191220191020363927",
            "ResultCode" => 1037,
            "ResultDesc" => "DS timeout."
        ]
    ]
];

// ======= FUNCTION TO SEND CALLBACK =======
function sendCallback($callback_data, $callback_url = 'https://stkpush-api.onrender.com/callback.php') {
    $json_payload = json_encode($callback_data);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $callback_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json_payload)
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'response' => $response,
        'http_code' => $http_code,
        'error' => $error
    ];
}

// ======= WEB TEST INTERFACE =======
if (isset($_GET['test'])) {
    echo "<html><body>";
    echo "<h2>M-Pesa Callback Simulator</h2>";

    $callback_url = $_GET['url'] ?? 'https://stkpush-api.onrender.com/callback.php';

    echo "<form method='get'>";
    echo "<label>Callback URL:</label><br>";
    echo "<input type='text' name='url' value='$callback_url' style='width: 500px;'><br><br>";
    echo "<input type='hidden' name='test' value='1'>";
    echo "<button type='submit' name='action' value='successful'>Test Successful Payment</button> ";
    echo "<button type='submit' name='action' value='failed'>Test Failed Payment</button> ";
    echo "<button type='submit' name='action' value='timeout'>Test Timeout</button>";
    echo "</form>";

    if (isset($_GET['action'])) {
        $action = $_GET['action'];

        echo "<h3>Testing $action callback...</h3>";

        switch ($action) {
            case 'successful':
                $result = sendCallback($successful_callback, $callback_url);
                $payload = $successful_callback;
                break;
            case 'failed':
                $result = sendCallback($failed_callback, $callback_url);
                $payload = $failed_callback;
                break;
            case 'timeout':
                $result = sendCallback($timeout_callback, $callback_url);
                $payload = $timeout_callback;
                break;
        }

        echo "<h4>Results:</h4>";
        echo "<p><strong>HTTP Code:</strong> {$result['http_code']}</p>";
        echo "<p><strong>Response:</strong> {$result['response']}</p>";
        if ($result['error']) {
            echo "<p><strong>Error:</strong> {$result['error']}</p>";
        }

        echo "<hr><h4>Sent Payload:</h4><pre>" . json_encode($payload, JSON_PRETTY_PRINT) . "</pre>";

        // ======= INSERT INTO MYSQL FOR TESTING =======
        if ($action === 'successful') {
            $conn = new mysqli("127.0.0.1", "fatherss_mp", "J1iMh078@", "fatherss_mp", 3307);

            if ($conn->connect_error) {
                echo "<p style='color:red'>DB Connection failed: " . $conn->connect_error . "</p>";
            } else {
                $transID = $payload['Body']['stkCallback']['CallbackMetadata']['Item'][1]['Value'];
                $checkoutID = $payload['Body']['stkCallback']['CheckoutRequestID'];
                $amount = $payload['Body']['stkCallback']['CallbackMetadata']['Item'][0]['Value'];
                $msisdn = $payload['Body']['stkCallback']['CallbackMetadata']['Item'][3]['Value'];
                $transTime = $payload['Body']['stkCallback']['CallbackMetadata']['Item'][2]['Value'];
                $resultCode = $payload['Body']['stkCallback']['ResultCode'];
                $resultDesc = $payload['Body']['stkCallback']['ResultDesc'];

                $sql = "INSERT INTO mpesa_transactions 
                (TransID, CheckoutRequestID, TransAmount, BusinessShortCode, MSISDN, FirstName, MiddleName, LastName, 
                 StatusMessage, ProcessingStatus, ResultCode, ResultDesc, TransTime, created_at, updated_at) 
                VALUES 
                ('$transID', '$checkoutID', $amount, '174379', '$msisdn', 'John', '', 'Doe', 
                 'STK Push sent successfully', 'PENDING', $resultCode, '$resultDesc', '$transTime', NOW(), NOW())";

                if ($conn->query($sql) === TRUE) {
                    echo "<p style='color:green'>Inserted into mpesa_transactions successfully!</p>";
                } else {
                    echo "<p style='color:red'>DB Error: " . $conn->error . "</p>";
                }
                $conn->close();
            }
        }
    }

    echo "</body></html>";
    exit;
}

// ======= COMMAND LINE USAGE =======
echo "M-Pesa Callback Simulator\n";
echo "========================\n";
echo "Usage: php test_callback_simulator.php [successful|failed|timeout] [callback_url]\n";
echo "Example: php test_callback_simulator.php successful https://stkpush-api.onrender.com/callback.php\n";
echo "\nOr visit in browser with ?test=1 parameter for web interface\n";
