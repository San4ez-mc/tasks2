<?php

namespace App\Middleware;

use App\Models\Database;

class ApiAuthMiddleware
{
    private Database $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->ensureAuthTokensTable();
        $this->ensureConnectorLogsTable();
    }

    public function authenticate(): array
    {
        $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $matches)) {
            $this->reject(401, 'Missing or invalid Authorization header. Use Bearer token.');
        }

        $token = trim((string) ($matches[1] ?? ''));
        if ($token === '') {
            $this->reject(401, 'Empty API token.');
        }

        $tokenRow = $this->db
            ->query("SELECT * FROM auth_tokens WHERE token = :token AND type = 'api' AND expires_at > UTC_TIMESTAMP() LIMIT 1")
            ->bind(':token', $token)
            ->fetch();

        if (!$tokenRow) {
            $this->reject(401, 'API token is invalid or expired.');
        }

        $userId = (int) ($tokenRow['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->reject(401, 'API token is not bound to a valid user.');
        }

        $user = $this->db
            ->query('SELECT id, first_name, last_name, email FROM users WHERE id = :id LIMIT 1')
            ->bind(':id', $userId)
            ->fetch();

        if (!$user) {
            $this->reject(401, 'User for this token was not found.');
        }

        $companyIdFromToken = (int) ($tokenRow['company_id'] ?? 0);
        $membership = null;

        if ($companyIdFromToken > 0) {
            $membership = $this->db
                ->query('SELECT cm.company_id, cm.role FROM company_members cm WHERE cm.user_id = :user_id AND cm.company_id = :company_id LIMIT 1')
                ->bind(':user_id', $userId)
                ->bind(':company_id', $companyIdFromToken)
                ->fetch();
        }

        if (!$membership) {
            $membership = $this->db
                ->query('SELECT cm.company_id, cm.role FROM company_members cm WHERE cm.user_id = :user_id ORDER BY cm.id ASC LIMIT 1')
                ->bind(':user_id', $userId)
                ->fetch();
        }

        if (!$membership) {
            $this->reject(403, 'User has no company access.');
        }

        $companyId = (int) ($membership['company_id'] ?? 0);
        if ($companyId <= 0) {
            $this->reject(403, 'Company scope could not be resolved.');
        }

        $isReadOnly = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET';
        $readLimit = defined('MCP_RATE_LIMIT_READ_PER_MIN') ? (int) MCP_RATE_LIMIT_READ_PER_MIN : 600;
        $writeLimit = defined('MCP_RATE_LIMIT_WRITE_PER_MIN') ? (int) MCP_RATE_LIMIT_WRITE_PER_MIN : 300;
        $limitPerMinute = $isReadOnly ? max(1, $readLimit) : max(1, $writeLimit);
        $retryAfter = $this->checkRateLimit((int) ($tokenRow['id'] ?? 0), $limitPerMinute);
        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
            $this->reject(429, 'Rate limit exceeded. Please retry later.');
        }

        return [
            'token_id' => (int) ($tokenRow['id'] ?? 0),
            'token' => $token,
            'user' => $user,
            'company_id' => $companyId,
            'role' => (string) ($membership['role'] ?? 'member'),
        ];
    }

    private function checkRateLimit(int $tokenId, int $limit): int
    {
        if ($tokenId <= 0 || !$this->connectorLogsTableExists()) {
            return 0;
        }

        $windowStart = gmdate('Y-m-d H:i:s', time() - 60);
        $used = (int) (($this->db
            ->query('SELECT COUNT(*) AS cnt FROM connector_logs WHERE token_id = :token_id AND created_at >= :window_start')
            ->bind(':token_id', $tokenId)
            ->bind(':window_start', $windowStart)
            ->fetch()['cnt'] ?? 0));

        if ($used < $limit) {
            return 0;
        }

        $oldest = $this->db
            ->query('SELECT created_at FROM connector_logs WHERE token_id = :token_id AND created_at >= :window_start ORDER BY created_at ASC LIMIT 1')
            ->bind(':token_id', $tokenId)
            ->bind(':window_start', $windowStart)
            ->fetch();

        if (!$oldest || empty($oldest['created_at'])) {
            return 60;
        }

        $oldestTs = strtotime((string) $oldest['created_at']);
        if ($oldestTs === false) {
            return 60;
        }

        $retryAfter = max(1, 60 - (time() - $oldestTs));
        return $retryAfter;
    }

    private function connectorLogsTableExists(): bool
    {
        $row = $this->db
            ->query("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'connector_logs'")
            ->fetch();

        return (int) ($row['cnt'] ?? 0) > 0;
    }

    private function ensureAuthTokensTable(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS auth_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(255) NOT NULL,
            user_id INT NOT NULL,
            company_id INT NULL,
            type VARCHAR(40) NOT NULL DEFAULT 'temp',
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_auth_token (token),
            KEY idx_auth_tokens_user (user_id),
            KEY idx_auth_tokens_type_exp (type, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();
    }

    private function ensureConnectorLogsTable(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS connector_logs (
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
    }

    private function reject(int $statusCode, string $message): void
    {
        json_response([
            'ok' => false,
            'error' => $message,
            'status' => $statusCode,
        ], $statusCode);
    }
}
