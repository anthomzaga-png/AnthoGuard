<?php

declare(strict_types=1);

/**
 * AnthoGuard API
 *
 * Entry point for communication between AnthoGuard agents
 * and the backend service.
 */

header('Content-Type: application/json; charset=utf-8');

const API_NAME = 'AnthoGuard API';
const API_VERSION = '0.1.0';

/**
 * Send a JSON response and terminate execution.
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
 * Basic health endpoint.
 */
function health(): never
{
    respond([
        'success' => true,
        'service' => API_NAME,
        'version' => API_VERSION,
        'status' => 'online',
        'timestamp' => gmdate('c'),
    ]);
}

/**
 * Return API information.
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
        ],
    ]);
}

$action = $_GET['action'] ?? null;

if ($action === 'health') {
    health();
}

apiInfo();



