<?php
/**
 * DETAILED DATABASE STRUCTURE CHECKER
 * This will show us EXACTLY what columns exist in your tables
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain');

echo "=== DATABASE STRUCTURE DIAGNOSTIC ===\n\n";

// Load config
try {
    require_once 'config.php';
    echo "✅ Config loaded\n\n";
} catch (Exception $e) {
    echo "❌ Config error: " . $e->getMessage() . "\n";
    exit;
}

if (!isset($conn) || $conn === null) {
    echo "❌ Database not connected\n";
    echo "Check your environment variables:\n";
    echo "DB_HOST=" . getenv('DB_HOST') . "\n";
    echo "DB_NAME=" . getenv('DB_NAME') . "\n";
    echo "DB_USER=" . getenv('DB_USER') . "\n";
    exit;
}

echo "✅ Database connected\n\n";

// Check mpesa_transactions table
echo "=== MPESA_TRANSACTIONS TABLE ===\n";
try {
    $stmt = $conn->query("
        SELECT 
            column_name, 
            data_type, 
            character_maximum_length,
            is_nullable, 
            column_default
        FROM information_schema.columns 
        WHERE table_schema = 'public' 
        AND table_name = 'mpesa_transactions' 
        ORDER BY ordinal_position
    ");
    
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($columns) > 0) {
        foreach ($columns as $col) {
            $type = $col['data_type'];
            if ($col['character_maximum_length']) {
                $type .= "(" . $col['character_maximum_length'] . ")";
            }
            echo sprintf(
                "  %-30s %-20s %-10s %s\n",
                $col['column_name'],
                $type,
                $col['is_nullable'],
                substr($col['column_default'] ?? 'NULL', 0, 30)
            );
        }
        
        // Try a test insert
        echo "\n--- Testing INSERT ---\n";
        try {
            $conn->beginTransaction();
            
            $testSql = "
                INSERT INTO mpesa_transactions 
                (checkout_request_id, merchant_request_id, phone, amount, account_ref, status)
                VALUES 
                (:checkout, :merchant, :phone, :amount, :account, :status)
                RETURNING id, checkout_request_id";
            
            $stmt = $conn->prepare($testSql);
            $result = $stmt->execute([
                ':checkout' => 'TEST-' . time(),
                ':merchant' => 'TEST-MERCHANT-' . time(),
                ':phone' => '254712345678',
                ':amount' => 10.00,
                ':account' => 'TEST',
                ':status' => 'pending'
            ]);
            
            $inserted = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inserted) {
                echo "✅ INSERT successful!\n";
                echo "   ID: " . $inserted['id'] . "\n";
                echo "   Checkout: " . $inserted['checkout_request_id'] . "\n";
            } else {
                echo "❌ INSERT failed - no data returned\n";
            }
            
            $conn->rollBack();
            echo "   (Test rolled back - not saved)\n";
            
        } catch (PDOException $e) {
            $conn->rollBack();
            echo "❌ INSERT ERROR: " . $e->getMessage() . "\n";
            echo "   SQL State: " . $e->getCode() . "\n";
        }
        
    } else {
        echo "❌ Table mpesa_transactions does NOT exist!\n";
    }
} catch (PDOException $e) {
    echo "❌ Query error: " . $e->getMessage() . "\n";
}

echo "\n=== SALES TABLE ===\n";
try {
    $stmt = $conn->query("
        SELECT 
            column_name, 
            data_type, 
            character_maximum_length,
            is_nullable, 
            column_default
        FROM information_schema.columns 
        WHERE table_schema = 'public' 
        AND table_name = 'sales' 
        ORDER BY ordinal_position
    ");
    
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($columns) > 0) {
        foreach ($columns as $col) {
            $type = $col['data_type'];
            if ($col['character_maximum_length']) {
                $type .= "(" . $col['character_maximum_length'] . ")";
            }
            echo sprintf(
                "  %-30s %-20s %-10s %s\n",
                $col['column_name'],
                $type,
                $col['is_nullable'],
                substr($col['column_default'] ?? 'NULL', 0, 30)
            );
        }
        
        // Try a test insert
        echo "\n--- Testing INSERT ---\n";
        try {
            $conn->beginTransaction();
            
            $testSql = "
                INSERT INTO sales 
                (sale_id_no, pump_shift_id, pump_id, attendant_id, amount, 
                 customer_mobile_no, transaction_status, checkout_request_id)
                VALUES 
                (:sale_id_no, :pump_shift_id, :pump_id, :attendant_id, :amount, 
                 :mobile, :status, :checkout)
                RETURNING sale_id, sale_id_no";
            
            $stmt = $conn->prepare($testSql);
            $result = $stmt->execute([
                ':sale_id_no' => 'TEST-SALE-' . time(),
                ':pump_shift_id' => 1,
                ':pump_id' => 1,
                ':attendant_id' => 1,
                ':amount' => 10.00,
                ':mobile' => '254712345678',
                ':status' => 'PENDING',
                ':checkout' => 'TEST-CHECKOUT-' . time()
            ]);
            
            $inserted = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inserted) {
                echo "✅ INSERT successful!\n";
                echo "   Sale ID: " . $inserted['sale_id'] . "\n";
                echo "   Sale ID No: " . $inserted['sale_id_no'] . "\n";
            } else {
                echo "❌ INSERT failed - no data returned\n";
            }
            
            $conn->rollBack();
            echo "   (Test rolled back - not saved)\n";
            
        } catch (PDOException $e) {
            $conn->rollBack();
            echo "❌ INSERT ERROR: " . $e->getMessage() . "\n";
            echo "   SQL State: " . $e->getCode() . "\n";
            
            // Check if pump_shift_id=1 exists
            echo "\n--- Checking Dependencies ---\n";
            try {
                $checkStmt = $conn->query("SELECT COUNT(*) FROM pump_shifts WHERE pump_shift_id = 1");
                $count = $checkStmt->fetchColumn();
                echo "   pump_shifts (id=1): " . ($count > 0 ? "✅ EXISTS" : "❌ NOT FOUND") . "\n";
                
                $checkStmt = $conn->query("SELECT COUNT(*) FROM pumps WHERE pump_id = 1");
                $count = $checkStmt->fetchColumn();
                echo "   pumps (id=1): " . ($count > 0 ? "✅ EXISTS" : "❌ NOT FOUND") . "\n";
                
                $checkStmt = $conn->query("SELECT COUNT(*) FROM users_new WHERE user_id = 1");
                $count = $checkStmt->fetchColumn();
                echo "   users_new (id=1): " . ($count > 0 ? "✅ EXISTS" : "❌ NOT FOUND") . "\n";
            } catch (PDOException $e2) {
                echo "   Error checking dependencies: " . $e2->getMessage() . "\n";
            }
        }
        
    } else {
        echo "❌ Table sales does NOT exist!\n";
    }
} catch (PDOException $e) {
    echo "❌ Query error: " . $e->getMessage() . "\n";
}

echo "\n=== CHECKING FOREIGN KEY RELATIONSHIPS ===\n";
try {
    $stmt = $conn->query("
        SELECT
            tc.table_name, 
            kcu.column_name,
            ccu.table_name AS foreign_table_name,
            ccu.column_name AS foreign_column_name
        FROM information_schema.table_constraints AS tc 
        JOIN information_schema.key_column_usage AS kcu
            ON tc.constraint_name = kcu.constraint_name
            AND tc.table_schema = kcu.table_schema
        JOIN information_schema.constraint_column_usage AS ccu
            ON ccu.constraint_name = tc.constraint_name
            AND ccu.table_schema = tc.table_schema
        WHERE tc.constraint_type = 'FOREIGN KEY' 
        AND tc.table_name IN ('sales', 'mpesa_transactions')
        ORDER BY tc.table_name
    ");
    
    $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($fks) > 0) {
        foreach ($fks as $fk) {
            echo sprintf(
                "  %s.%s -> %s.%s\n",
                $fk['table_name'],
                $fk['column_name'],
                $fk['foreign_table_name'],
                $fk['foreign_column_name']
            );
        }
    } else {
        echo "  No foreign keys found\n";
    }
} catch (PDOException $e) {
    echo "❌ Query error: " . $e->getMessage() . "\n";
}

echo "\n=== RECENT RECORDS ===\n";
try {
    $stmt = $conn->query("SELECT COUNT(*) FROM mpesa_transactions");
    $count = $stmt->fetchColumn();
    echo "mpesa_transactions: $count records\n";
    
    if ($count > 0) {
        $stmt = $conn->query("
            SELECT id, checkout_request_id, status, amount, created_at 
            FROM mpesa_transactions 
            ORDER BY created_at DESC 
            LIMIT 3
        ");
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recent as $r) {
            echo "  " . substr($r['id'], 0, 8) . "... | " . 
                 substr($r['checkout_request_id'], 0, 20) . "... | " . 
                 $r['status'] . " | " . 
                 $r['amount'] . " | " . 
                 $r['created_at'] . "\n";
        }
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

try {
    $stmt = $conn->query("SELECT COUNT(*) FROM sales");
    $count = $stmt->fetchColumn();
    echo "\nsales: $count records\n";
    
    if ($count > 0) {
        $stmt = $conn->query("
            SELECT sale_id, sale_id_no, transaction_status, amount, created_at 
            FROM sales 
            ORDER BY created_at DESC 
            LIMIT 3
        ");
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recent as $r) {
            echo "  " . $r['sale_id'] . " | " . 
                 $r['sale_id_no'] . " | " . 
                 $r['transaction_status'] . " | " . 
                 $r['amount'] . " | " . 
                 $r['created_at'] . "\n";
        }
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== DIAGNOSTIC COMPLETE ===\n";
