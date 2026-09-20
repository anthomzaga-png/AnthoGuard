-- ============================================================
-- AnthoGuard Database Schema
-- Version: 0.1.0
-- ============================================================

CREATE DATABASE IF NOT EXISTS anthoguard
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE anthoguard;

-- ============================================================
-- Registered Devices
-- ============================================================

CREATE TABLE IF NOT EXISTS devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_uuid CHAR(36) NOT NULL UNIQUE,
    device_name VARCHAR(255) NOT NULL,
    hostname VARCHAR(255) NULL,
    operating_system VARCHAR(100) NULL,
    os_release VARCHAR(255) NULL,
    architecture VARCHAR(50) NULL,
    processor VARCHAR(255) NULL,
    logical_processors INT UNSIGNED NULL,
    status ENUM('active', 'inactive', 'lost', 'recovered') NOT NULL DEFAULT 'active',
    last_ip_address VARCHAR(45) NULL,
    last_seen_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_devices_status (status),
    INDEX idx_devices_last_seen (last_seen_at)
);

-- ============================================================
-- Device Memory / System Statistics
-- ============================================================

CREATE TABLE IF NOT EXISTS device_stats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NOT NULL,

    memory_total_kb BIGINT UNSIGNED NULL,
    memory_available_kb BIGINT UNSIGNED NULL,
    memory_free_kb BIGINT UNSIGNED NULL,

    disk_total_bytes BIGINT UNSIGNED NULL,
    disk_used_bytes BIGINT UNSIGNED NULL,
    disk_free_bytes BIGINT UNSIGNED NULL,
    disk_usage_percent DECIMAL(5,2) NULL,

    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_device_stats_device
        FOREIGN KEY (device_id)
        REFERENCES devices(id)
        ON DELETE CASCADE,

    INDEX idx_device_stats_device (device_id),
    INDEX idx_device_stats_recorded (recorded_at)
);

-- ============================================================
-- Network Addresses
-- ============================================================

CREATE TABLE IF NOT EXISTS device_network (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_device_network_device
        FOREIGN KEY (device_id)
        REFERENCES devices(id)
        ON DELETE CASCADE,

    INDEX idx_device_network_device (device_id),
    INDEX idx_device_network_ip (ip_address)
);

-- ============================================================
-- Security / Recovery Events
-- ============================================================

CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NOT NULL,

    event_type VARCHAR(100) NOT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'info',
    message TEXT NULL,

    metadata JSON NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_events_device
        FOREIGN KEY (device_id)
        REFERENCES devices(id)
        ON DELETE CASCADE,

    INDEX idx_events_device (device_id),
    INDEX idx_events_type (event_type),
    INDEX idx_events_severity (severity),
    INDEX idx_events_created (created_at)
);

-- ============================================================
-- API Authentication Tokens
-- ============================================================

CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NOT NULL,

    token_hash CHAR(64) NOT NULL UNIQUE,

    expires_at TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_api_tokens_device
        FOREIGN KEY (device_id)
        REFERENCES devices(id)
        ON DELETE CASCADE,

    INDEX idx_api_tokens_device (device_id),
    INDEX idx_api_tokens_expires (expires_at)
);
