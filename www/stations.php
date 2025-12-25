<?php
/**
 * STATIONS API - CRUD operations for multi-station management
 * 
 * Endpoints:
 * - GET /stations.php - List all stations (with optional filters)
 * - GET /stations.php?id=1 - Get single station
 * - POST /stations.php - Create new station
 * - PUT /stations.php?id=1 - Update station
 * - DELETE /stations.php?id=1 - Deactivate station
 * 
 * @version 1.0.0
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    exit(0);
}

function respond($success, $message, $data = [], $httpCode = 200) {
    ob_end_clean();
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

require_once 'config.php';

if (!isset($conn) || $conn === null) {
    respond(false, 'Database connection failed', [], 503);
}

$method = $_SERVER['REQUEST_METHOD'];
$stationId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$userId = $_GET['user_id'] ?? null;  // For filtering accessible stations

switch ($method) {
    case 'GET':
        handleGet($conn, $stationId, $userId);
        break;
    case 'POST':
        handlePost($conn);
        break;
    case 'PUT':
        handlePut($conn, $stationId);
        break;
    case 'DELETE':
        handleDelete($conn, $stationId);
        break;
    default:
        respond(false, 'Method not allowed', [], 405);
}

function handleGet($conn, $stationId, $userId) {
    try {
        if ($stationId) {
            // Get single station with summary
            $stmt = $conn->prepare("
                SELECT 
                    s.*,
                    (SELECT COUNT(*) FROM pumps p WHERE p.station_id = s.station_id AND p.is_active = TRUE) as pump_count,
                    (SELECT COUNT(*) FROM users u WHERE u.station_id = s.station_id AND u.is_active = TRUE) as user_count,
                    (SELECT COALESCE(SUM(amount), 0) FROM sales sa 
                     WHERE sa.station_id = s.station_id 
                       AND sa.created_at::DATE = CURRENT_DATE) as today_sales
                FROM stations s
                WHERE s.station_id = :station_id
            ");
            $stmt->execute([':station_id' => $stationId]);
            $station = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$station) {
                respond(false, 'Station not found', [], 404);
            }
            
            respond(true, 'Station retrieved', ['data' => $station]);
            
        } elseif ($userId) {
            // Get stations accessible by user
            $stmt = $conn->prepare("
                SELECT 
                    s.*,
                    us.station_role,
                    us.is_primary_station,
                    us.can_view_reports,
                    us.can_manage_pumps,
                    (SELECT COUNT(*) FROM pumps p WHERE p.station_id = s.station_id AND p.is_active = TRUE) as pump_count,
                    (SELECT COALESCE(SUM(amount), 0) FROM sales sa 
                     WHERE sa.station_id = s.station_id 
                       AND sa.created_at::DATE = CURRENT_DATE) as today_sales
                FROM stations s
                INNER JOIN user_stations us ON s.station_id = us.station_id
                WHERE us.user_id = :user_id 
                  AND us.is_active = TRUE 
                  AND s.is_active = TRUE
                ORDER BY us.is_primary_station DESC, s.station_name ASC
            ");
            $stmt->execute([':user_id' => $userId]);
            $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            respond(true, 'User stations retrieved', [
                'data' => $stations,
                'count' => count($stations)
            ]);
            
        } else {
            // Get all active stations with summaries
            $stmt = $conn->query("
                SELECT 
                    s.*,
                    (SELECT COUNT(*) FROM pumps p WHERE p.station_id = s.station_id AND p.is_active = TRUE) as pump_count,
                    (SELECT COUNT(*) FROM users u WHERE u.station_id = s.station_id AND u.is_active = TRUE) as user_count,
                    (SELECT COUNT(*) FROM pump_shifts ps 
                     WHERE ps.station_id = s.station_id AND ps.is_closed = FALSE) as active_shifts,
                    (SELECT COALESCE(SUM(amount), 0) FROM sales sa 
                     WHERE sa.station_id = s.station_id 
                       AND sa.created_at::DATE = CURRENT_DATE) as today_sales,
                    (SELECT COUNT(*) FROM sales sa 
                     WHERE sa.station_id = s.station_id 
                       AND sa.created_at::DATE = CURRENT_DATE) as today_transactions
                FROM stations s
                WHERE s.is_active = TRUE
                ORDER BY s.station_name ASC
            ");
            $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate totals
            $totalSales = array_sum(array_column($stations, 'today_sales'));
            $totalTransactions = array_sum(array_column($stations, 'today_transactions'));
            
            respond(true, 'All stations retrieved', [
                'data' => $stations,
                'count' => count($stations),
                'summary' => [
                    'total_stations' => count($stations),
                    'total_today_sales' => $totalSales,
                    'total_today_transactions' => $totalTransactions
                ]
            ]);
        }
        
    } catch (PDOException $e) {
        error_log("Station GET error: " . $e->getMessage());
        respond(false, 'Failed to retrieve stations', [], 500);
    }
}

function handlePost($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['station_code']) || empty($input['station_name'])) {
        respond(false, 'station_code and station_name are required', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO stations (
                station_code, station_name, station_type,
                physical_address, city, county, region,
                gps_latitude, gps_longitude,
                mpesa_till_number, mpesa_shortcode, mpesa_passkey,
                mpesa_consumer_key, mpesa_consumer_secret,
                station_phone, station_email, manager_name, manager_phone,
                operating_hours_start, operating_hours_end, is_24_hours,
                fuel_types, is_active, created_by
            ) VALUES (
                :code, :name, :type,
                :address, :city, :county, :region,
                :lat, :lng,
                :till, :shortcode, :passkey,
                :consumer_key, :consumer_secret,
                :phone, :email, :manager_name, :manager_phone,
                :hours_start, :hours_end, :is_24hrs,
                :fuel_types, TRUE, :created_by
            )
            RETURNING station_id
        ");
        
        $stmt->execute([
            ':code' => $input['station_code'],
            ':name' => $input['station_name'],
            ':type' => $input['station_type'] ?? 'petrol_station',
            ':address' => $input['physical_address'] ?? null,
            ':city' => $input['city'] ?? null,
            ':county' => $input['county'] ?? null,
            ':region' => $input['region'] ?? null,
            ':lat' => $input['gps_latitude'] ?? null,
            ':lng' => $input['gps_longitude'] ?? null,
            ':till' => $input['mpesa_till_number'] ?? null,
            ':shortcode' => $input['mpesa_shortcode'] ?? null,
            ':passkey' => $input['mpesa_passkey'] ?? null,
            ':consumer_key' => $input['mpesa_consumer_key'] ?? null,
            ':consumer_secret' => $input['mpesa_consumer_secret'] ?? null,
            ':phone' => $input['station_phone'] ?? null,
            ':email' => $input['station_email'] ?? null,
            ':manager_name' => $input['manager_name'] ?? null,
            ':manager_phone' => $input['manager_phone'] ?? null,
            ':hours_start' => $input['operating_hours_start'] ?? '06:00:00',
            ':hours_end' => $input['operating_hours_end'] ?? '22:00:00',
            ':is_24hrs' => $input['is_24_hours'] ?? false,
            ':fuel_types' => $input['fuel_types'] ? '{' . implode(',', $input['fuel_types']) . '}' : null,
            ':created_by' => $input['created_by'] ?? null
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        respond(true, 'Station created successfully', [
            'station_id' => $result['station_id']
        ], 201);
        
    } catch (PDOException $e) {
        error_log("Station CREATE error: " . $e->getMessage());
        
        if (strpos($e->getMessage(), 'unique constraint') !== false) {
            respond(false, 'Station code already exists', [], 409);
        }
        
        respond(false, 'Failed to create station', [], 500);
    }
}

function handlePut($conn, $stationId) {
    if (!$stationId) {
        respond(false, 'Station ID required', [], 400);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    try {
        // Build dynamic update query
        $updates = [];
        $params = [':station_id' => $stationId];
        
        $allowedFields = [
            'station_code', 'station_name', 'station_type',
            'physical_address', 'city', 'county', 'region',
            'gps_latitude', 'gps_longitude',
            'mpesa_till_number', 'mpesa_shortcode', 'mpesa_passkey',
            'mpesa_consumer_key', 'mpesa_consumer_secret',
            'station_phone', 'station_email', 'manager_name', 'manager_phone',
            'operating_hours_start', 'operating_hours_end', 'is_24_hours',
            'is_active', 'is_online'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $input[$field];
            }
        }
        
        if (empty($updates)) {
            respond(false, 'No fields to update', [], 400);
        }
        
        $updates[] = "updated_at = NOW()";
        
        $sql = "UPDATE stations SET " . implode(', ', $updates) . " WHERE station_id = :station_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        if ($stmt->rowCount() === 0) {
            respond(false, 'Station not found', [], 404);
        }
        
        respond(true, 'Station updated successfully');
        
    } catch (PDOException $e) {
        error_log("Station UPDATE error: " . $e->getMessage());
        respond(false, 'Failed to update station', [], 500);
    }
}

function handleDelete($conn, $stationId) {
    if (!$stationId) {
        respond(false, 'Station ID required', [], 400);
    }
    
    try {
        // Soft delete - just deactivate
        $stmt = $conn->prepare("
            UPDATE stations 
            SET is_active = FALSE, updated_at = NOW() 
            WHERE station_id = :station_id
        ");
        $stmt->execute([':station_id' => $stationId]);
        
        if ($stmt->rowCount() === 0) {
            respond(false, 'Station not found', [], 404);
        }
        
        respond(true, 'Station deactivated successfully');
        
    } catch (PDOException $e) {
        error_log("Station DELETE error: " . $e->getMessage());
        respond(false, 'Failed to deactivate station', [], 500);
    }
}
