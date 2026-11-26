CREATE DATABASE IF NOT EXISTS ondulateur
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ondulateur;

CREATE TABLE IF NOT EXISTS inverter_readings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_sn VARCHAR(100) NOT NULL,
    device_id BIGINT UNSIGNED NULL,
    device_type VARCHAR(64) NULL,
    device_state VARCHAR(64) NULL,
    request_id VARCHAR(128) NULL,
    collection_time BIGINT NULL,
    collected_at DATETIME NOT NULL,
    raw_json LONGTEXT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_inverter_readings_collected_at (collected_at),
    KEY idx_inverter_readings_device_sn (device_sn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inverter_measurements (
    reading_id BIGINT UNSIGNED NOT NULL,
    measurement_key VARCHAR(100) NOT NULL,
    measurement_value TEXT NULL,
    unit VARCHAR(32) NULL,
    measurement_name VARCHAR(255) NULL,
    PRIMARY KEY (reading_id, measurement_key),
    CONSTRAINT fk_inverter_measurements_reading
        FOREIGN KEY (reading_id) REFERENCES inverter_readings (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
