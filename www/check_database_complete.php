<?php
/**
 * COMPLETE DATABASE STRUCTURE CHECKER
 * Shows ALL tables and their columns in your database
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain');

echo "=== COMPLETE DATABASE STRUCTURE ===\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";

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

// Get ALL tables in the public schema
echo "=== ALL TABLES IN DATABASE ===\n\n";
try {
    $stmt = $conn->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_type = 'BASE TABLE'
        ORDER BY table_name
    ");
    
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) === 0) {
        echo "❌ No tables found in database!\n";
        exit;
    }
    
    echo "Found " . count($tables) . " tables:\n";
    foreach ($tables as $table) {
        echo "  • $table\n";
    }
    echo "\n";
    
    // Now show detailed structure for EACH table
    foreach ($tables as $table) {
        echo str_repeat("=", 60) . "\n";
        echo "TABLE: $table\n";
        echo str_repeat("=", 60) . "\n";
        
        // Get columns
        $colStmt = $conn->prepare("
            SELECT 
                column_name, 
                data_type, 
                character_maximum_length,
                is_nullable, 
                column_default
            FROM information_schema.columns 
            WHERE table_schema = 'public' 
            AND table_name = :table 
            ORDER BY ordinal_position
        ");
        $colStmt->execute([':table' => $table]);
        $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\nCOLUMNS:\n";
        echo sprintf("  %-30s %-20s %-10s %s\n", "COLUMN NAME", "TYPE", "NULLABLE", "DEFAULT");
        echo "  " . str_repeat("-", 80) . "\n";
        
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
        
        // Get record count
        try {
            $countStmt = $conn->query("SELECT COUNT(*) FROM \"$table\"");
            $count = $countStmt->fetchColumn();
            echo "\nRECORD COUNT: $count\n";
            
            // Show sample data (first 3 records)
            if ($count > 0) {
                echo "\nSAMPLE DATA (first 3 rows):\n";
                $sampleStmt = $conn->query("SELECT * FROM \"$table\" LIMIT 3");
                $samples = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($samples as $i => $row) {
                    echo "  Row " . ($i + 1) . ":\n";
                    foreach ($row as $key => $value) {
                        $displayValue = $value;
                        if (strlen($displayValue) > 50) {
                            $displayValue = substr($displayValue, 0, 47) . "...";
                        }
                        echo "    $key: $displayValue\n";
                    }
                }
            }
        } catch (Exception $e) {
            echo "\n⚠️ Could not count records: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Query error: " . $e->getMessage() . "\n";
}

// Show foreign key relationships
echo str_repeat("=", 60) . "\n";
echo "FOREIGN KEY RELATIONSHIPS\n";
echo str_repeat("=", 60) . "\n\n";

try {
    $stmt = $conn->query("
        SELECT
            tc.table_name, 
            kcu.column_name,
            ccu.table_name AS foreign_table_name,
            ccu.column_name AS foreign_column_name,
            tc.constraint_name
        FROM information_schema.table_constraints AS tc 
        JOIN information_schema.key_column_usage AS kcu
            ON tc.constraint_name = kcu.constraint_name
            AND tc.table_schema = kcu.table_schema
        JOIN information_schema.constraint_column_usage AS ccu
            ON ccu.constraint_name = tc.constraint_name
            AND ccu.table_schema = tc.table_schema
        WHERE tc.constraint_type = 'FOREIGN KEY'
        AND tc.table_schema = 'public'
        ORDER BY tc.table_name
    ");
    
    $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($fks) > 0) {
        foreach ($fks as $fk) {
            echo sprintf(
                "%s.%s -> %s.%s\n",
                $fk['table_name'],
                $fk['column_name'],
                $fk['foreign_table_name'],
                $fk['foreign_column_name']
            );
        }
    } else {
        echo "No foreign keys found\n";
    }
} catch (PDOException $e) {
    echo "❌ Query error: " . $e->getMessage() . "\n";
}

// Show primary keys
echo "\n" . str_repeat("=", 60) . "\n";
echo "PRIMARY KEYS\n";
echo str_repeat("=", 60) . "\n\n";

try {
    $stmt = $conn->query("
        SELECT
            tc.table_name, 
            kcu.column_name
        FROM information_schema.table_constraints AS tc 
        JOIN information_schema.key_column_usage AS kcu
            ON tc.constraint_name = kcu.constraint_name
        WHERE tc.constraint_type = 'PRIMARY KEY'
        AND tc.table_schema = 'public'
        ORDER BY tc.table_name
    ");
    
    $pks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($pks as $pk) {
        echo sprintf("%s: %s\n", $pk['table_name'], $pk['column_name']);
    }
} catch (PDOException $e) {
    echo "❌ Query error: " . $e->getMessage() . "\n";
}

// Show indexes
echo "\n" . str_repeat("=", 60) . "\n";
echo "INDEXES\n";
echo str_repeat("=", 60) . "\n\n";

try {
    $stmt = $conn->query("
        SELECT
            tablename,
            indexname,
            indexdef
        FROM pg_indexes
        WHERE schemaname = 'public'
        ORDER BY tablename, indexname
    ");
    
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $currentTable = '';
    foreach ($indexes as $idx) {
        if ($currentTable !== $idx['tablename']) {
            $currentTable = $idx['tablename'];
            echo "\n$currentTable:\n";
        }
        echo "  " . $idx['indexname'] . "\n";
    }
} catch (PDOException $e) {
    echo "⚠️ Could not fetch indexes: " . $e->getMessage() . "\n";
}

echo "\n\n=== DIAGNOSTIC COMPLETE ===\n";
