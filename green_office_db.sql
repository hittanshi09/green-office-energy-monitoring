-- Green Office schema. Select/create your database before importing.
-- For existing installations use: php scripts/setup.php
-- Setup preserves readings and upgrades old integer meters safely. No DROP statements.

CREATE TABLE IF NOT EXISTS tbl_reading (
    reading_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reading_date DATE NOT NULL,
    meter_reading DECIMAL(12,3) NOT NULL,
    meter_reset TINYINT NOT NULL DEFAULT 0,
    INDEX reading_date_index (reading_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rooms (
    room_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    area DECIMAL(10,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS equipment (
    equipment_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    watts DECIMAL(12,3) NOT NULL,
    quantity INT NOT NULL,
    hours_per_day DECIMAL(5,2) NOT NULL,
    days_per_month INT NOT NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    user_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'user',
    active TINYINT NOT NULL DEFAULT 1,
    auth_version INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_throttle (
    bucket_key CHAR(64) NOT NULL PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    window_started DATETIME NOT NULL,
    INDEX throttle_expiry (window_started)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS actions (
    action_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    owner VARCHAR(100) NOT NULL DEFAULT '',
    due_date DATE NULL,
    completed_date DATE NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    estimated_kwh DECIMAL(12,3) NULL,
    notes TEXT NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT actions_created_by_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS office_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO office_settings (setting_key, setting_value) VALUES ('_readings_lock', '1'), ('_accounts_lock', '1'), ('_auth_lock', '1');
