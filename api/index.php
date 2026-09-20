<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const API_NAME = 'AnthoGuard API';
const API_VERSION = '0.3.0';

/**
 * Create a PDO database connection.
 */
function getDatabase(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/../config/database.php';

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );

    $pdo = new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

/**
 * Send JSON response.
 */
function respond(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * API information.
 */
function apiInfo(): never
{
    respond([
        'success' => true,
        'service' => API_NAME,
        'version' => API_VERSION,
        'endpoints' => [
            'GET /' => 'API information',
            'GET /?action=health' => 'Health check',
            'POST /?action=register-device' => 'Register device',
            'POST /?action=heartbeat' => 'Device heartbeat and statistics',
        ],
    ]);
}

/**
 * Health check.
 */
function health(): never
{
    try {
        $pdo = getDatabase();

        $pdo->query('SELECT 1');

        respond([
            'success' => true,
            'service' => API_NAME,
            'version' => API_VERSION,
            'status' => 'online',
            'database' => 'connected',
            'timestamp' => gmdate('c'),
        ]);
    } catch (Throwable $e) {
        respond([
            'success' => false,
            'service' => API_NAME,
            'status' => 'degraded',
            'database' => 'unavailable',
        ], 503);
    }
}

/**
 * Register a device.
 */
function registerDevice(): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond([
            'success' => false,
            'error' => 'POST request required',
        ], 405);
    }

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        respond([
            'success' => false,
            'error' => 'Invalid JSON payload',
        ], 400);
    }

    $deviceUuid = trim((string) ($input['device_uuid'] ?? ''));
    $deviceName = trim((string) ($input['device_name'] ?? ''));

    if ($deviceUuid === '' || $deviceName === '') {
        respond([
            'success' => false,
            'error' => 'device_uuid and device_name are required',
        ], 422);
    }

    try {
        $pdo = getDatabase();

        $sql = <<<'SQL'
            INSERT INTO devices (
                device_uuid,
                device_name,
                hostname,
                operating_system,
                os_release,
                architecture,
                processor,
                logical_processors,
                last_ip_address,
                last_seen_at
            ) VALUES (
                :device_uuid,
                :device_name,
                :hostname,
                :operating_system,
                :os_release,
                :architecture,
                :processor,
                :logical_processors,
                :last_ip_address,
                CURRENT_TIMESTAMP
            )
        SQL;

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            'device_uuid' => $deviceUuid,
            'device_name' => $deviceName,
            'hostname' => $input['hostname'] ?? null,
            'operating_system' => $input['operating_system'] ?? null,
            'os_release' => $input['os_release'] ?? null,
            'architecture' => $input['architecture'] ?? null,
            'processor' => $input['processor'] ?? null,
            'logical_processors' => $input['logical_processors'] ?? null,
            'last_ip_address' => $input['ip_address'] ?? null,
        ]);

        respond([
            'success' => true,
            'message' => 'Device registered successfully',
            'device_id' => (int) $pdo->lastInsertId(),
        ], 201);

    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            respond([
                'success' => false,
                'error' => 'Device already registered',
            ], 409);
        }

        respond([
            'success' => false,
            'error' => 'Database operation failed',
        ], 500);
    }
}

/**
 * Device heartbeat.
 *
 * Updates the device's last-seen information and stores
 * current memory, disk and network statistics.
 */
function heartbeat(): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond([
            'success' => false,
            'error' => 'POST request required',
        ], 405);
    }

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        respond([
            'success' => false,
            'error' => 'Invalid JSON payload',
        ], 400);
    }

    $deviceUuid = trim((string) ($input['device_uuid'] ?? ''));

    if ($deviceUuid === '') {
        respond([
            'success' => false,
            'error' => 'device_uuid is required',
        ], 422);
    }

    try {
        $pdo = getDatabase();

        $pdo->beginTransaction();

        /**
         * Find the registered device.
         */
        $stmt = $pdo->prepare(
            'SELECT id FROM devices WHERE device_uuid = :device_uuid LIMIT 1'
        );

        $stmt->execute([
            'device_uuid' => $deviceUuid,
        ]);

        $device = $stmt->fetch();

        if (!$device) {
            $pdo->rollBack();

            respond([
                'success' => false,
                'error' => 'Device not registered',
            ], 404);
        }

        $deviceId = (int) $device['id'];

        /**
         * Update device heartbeat information.
         */
        $update = $pdo->prepare(
            <<<'SQL'
                UPDATE devices
                SET
                    device_name = :device_name,
                    hostname = :hostname,
                    operating_system = :operating_system,
                    os_release = :os_release,
                    architecture = :architecture,
                    processor = :processor,
                    logical_processors = :logical_processors,
                    last_ip_address = :last_ip_address,
                    status = 'active',
                    last_seen_at = CURRENT_TIMESTAMP
                WHERE id = :device_id
            SQL
        );

        $update->execute([
            'device_name' => $input['device_name'] ?? null,
            'hostname' => $input['hostname'] ?? null,
            'operating_system' => $input['operating_system'] ?? null,
            'os_release' => $input['os_release'] ?? null,
            'architecture' => $input['architecture'] ?? null,
            'processor' => $input['processor'] ?? null,
            'logical_processors' => $input['logical_processors'] ?? null,
            'last_ip_address' => $input['ip_address'] ?? null,
            'device_id' => $deviceId,
        ]);

        /**
         * Store memory and disk statistics.
         */
        $stats = $input['memory'] ?? [];
        $disk = $input['disk'] ?? [];

        $statsStatement = $pdo->prepare(
            <<<'SQL'
                INSERT INTO device_stats (
                    device_id,
                    memory_total_kb,
                    memory_available_kb,
                    memory_free_kb,
                    disk_total_bytes,
                    disk_used_bytes,
                    disk_free_bytes,
                    disk_usage_percent
                ) VALUES (
                    :device_id,
                    :memory_total_kb,
                    :memory_available_kb,
                    :memory_free_kb,
                    :disk_total_bytes,
                    :disk_used_bytes,
                    :disk_free_bytes,
                    :disk_usage_percent
                )
            SQL
        );

        $statsStatement->execute([
            'device_id' => $deviceId,
            'memory_total_kb' => $stats['total_kb'] ?? null,
            'memory_available_kb' => $stats['available_kb'] ?? null,
            'memory_free_kb' => $stats['free_kb'] ?? null,
            'disk_total_bytes' => $disk['total_bytes'] ?? null,
            'disk_used_bytes' => $disk['used_bytes'] ?? null,
            'disk_free_bytes' => $disk['free_bytes'] ?? null,
            'disk_usage_percent' => $disk['usage_percent'] ?? null,
        ]);

        /**

        * Store network addresses.
         */
        $network = $input['network'] ?? [];
        $ipAddresses = $network['ip_addresses'] ?? [];

        if (is_array($ipAddresses)) {
            $networkStatement = $pdo->prepare(
                <<<'SQL'
                    INSERT INTO device_network (
                        device_id,
                        ip_address
                    ) VALUES (
                        :device_id,
                        :ip_address
                    )
                SQL
            );

            foreach ($ipAddresses as $ipAddress) {
                if (!is_string($ipAddress) || trim($ipAddress) === '') {
                    continue;
                }

                $networkStatement->execute([
                    'device_id' => $deviceId,
                    'ip_address' => trim($ipAddress),
                ]);
            }
        }

        $pdo->commit();

        respond([
            'success' => true,
            'message' => 'Heartbeat received',
            'device_id' => $deviceId,
            'status' => 'active',
            'server_time' => gmdate('c'),
        ]);

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        respond([
            'success' => false,
            'error' => 'Heartbeat processing failed',
        ], 500);
    }
}

$action = $_GET['action'] ?? null;

switch ($action) {
    case 'health':
        health();

    case 'register-device':
        registerDevice();

    case 'heartbeat':
        heartbeat();

    default:
        apiInfo();
}
