<?php
require_once 'config.php';

$db = new App\Models\Database();

$db->query("CREATE TABLE IF NOT EXISTS telegram_ai_interaction_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    telegram_message_id BIGINT NULL,
    telegram_user_id BIGINT NULL,
    app_user_id INT NULL,
    company_id INT NULL,
    chat_type VARCHAR(20) NOT NULL DEFAULT 'private',
    message_kind VARCHAR(20) NOT NULL DEFAULT 'text',
    raw_text MEDIUMTEXT NULL,
    transcribed_text MEDIUMTEXT NULL,
    normalized_text MEDIUMTEXT NULL,
    ai_recent_context MEDIUMTEXT NULL,
    ai_raw_response LONGTEXT NULL,
    ai_parsed_json LONGTEXT NULL,
    execution_path VARCHAR(80) NULL,
    command_names TEXT NULL,
    bot_reply LONGTEXT NULL,
    audio_error TEXT NULL,
    raw_update_json LONGTEXT NULL,
    processing_status VARCHAR(50) NOT NULL DEFAULT 'received',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_telegram_ai_logs_chat_created (chat_id, created_at),
    INDEX idx_telegram_ai_logs_company_created (company_id, created_at),
    INDEX idx_telegram_ai_logs_app_user_created (app_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();

echo "Table ready: telegram_ai_interaction_logs\n";