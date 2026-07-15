<?php

require __DIR__ . '/config.php';

use App\Models\Database;

$db = new Database();

$db->query("CREATE TABLE IF NOT EXISTS user_telegram_digest_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    morning_enabled TINYINT(1) NOT NULL DEFAULT 1,
    morning_hour TINYINT UNSIGNED NOT NULL DEFAULT 9,
    evening_enabled TINYINT(1) NOT NULL DEFAULT 1,
    evening_hour TINYINT UNSIGNED NOT NULL DEFAULT 18,
    last_morning_sent_at DATETIME NULL,
    last_evening_sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_digest_user (user_id),
    KEY idx_digest_morning (morning_enabled, morning_hour),
    KEY idx_digest_evening (evening_enabled, evening_hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();

echo "user_telegram_digest_settings migration complete\n";
