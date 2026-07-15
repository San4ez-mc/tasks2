<?php

require __DIR__ . '/config.php';

use App\Models\Database;

$db = new Database();

$db->query("CREATE TABLE IF NOT EXISTS connector_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_id INT UNSIGNED NULL,
    user_id INT NOT NULL,
    company_id INT NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'mcp_claude',
    action VARCHAR(64) NOT NULL,
    tool_input JSON NULL,
    tool_output JSON NULL,
    status ENUM('ok','error','dry_run') NOT NULL DEFAULT 'ok',
    error_msg TEXT NULL,
    duration_ms INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_connector_token_time (token_id, created_at),
    KEY idx_connector_user_time (user_id, created_at),
    KEY idx_connector_company_action (company_id, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();

echo "connector_logs migration complete\n";
