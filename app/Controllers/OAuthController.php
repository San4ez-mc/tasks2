<?php

namespace App\Controllers;

use App\Models\Database;

/**
 * Minimal OAuth 2.0 server for MCP connector (Claude.ai, Claude Desktop).
 *
 * Flow:
 *  1. GET  /.well-known/oauth-authorization-server  — discovery metadata
 *  2. POST /oauth/register                          — Dynamic Client Registration (RFC 7591)
 *  3. GET  /oauth/authorize                         — show "Enter API token" form
 *  4. POST /oauth/authorize                         — validate token, issue code, redirect
 *  5. POST /oauth/token                             — exchange code → access_token (= api token)
 */
class OAuthController
{
    private Database $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->ensureTables();
    }

    // ─── 0. Protected Resource Metadata (RFC 9728) ───────────────────────────
    // Claude.ai discovers this FIRST before oauth-authorization-server

    public function protectedResource(): void
    {
        $this->corsHeaders();
        json_response([
            'resource'              => APP_URL . '/mcp',
            'authorization_servers' => [APP_URL],
            'bearer_methods_supported' => ['header', 'query'],
            'scopes_supported'      => ['mcp'],
        ]);
    }

    // ─── 1. Discovery ────────────────────────────────────────────────────────

    public function discovery(): void
    {
        $this->corsHeaders();
        json_response([
            'issuer'                                     => APP_URL,
            'authorization_endpoint'                     => APP_URL . '/oauth/authorize',
            'token_endpoint'                             => APP_URL . '/oauth/token',
            'registration_endpoint'                      => APP_URL . '/oauth/register',
            'grant_types_supported'                      => ['authorization_code'],
            'response_types_supported'                   => ['code'],
            'code_challenge_methods_supported'           => ['S256'],
            'token_endpoint_auth_methods_supported'      => ['none', 'client_secret_post'],
            'introspection_endpoint'                     => APP_URL . '/oauth/introspect',
            'scopes_supported'                           => ['mcp'],
        ]);
    }

    // ─── 2. Dynamic Client Registration ─────────────────────────────────────

    public function register(): void
    {
        $this->corsHeaders();
        $body = $this->jsonBody();

        $redirectUris = $body['redirect_uris'] ?? [];
        if (!is_array($redirectUris)) { $redirectUris = []; }

        $clientName = (string) ($body['client_name'] ?? 'MCP Client');
        $clientId   = 'mcp_' . bin2hex(random_bytes(16));
        $secret     = bin2hex(random_bytes(24));

        $this->db->query(
            "INSERT INTO oauth_clients (client_id, redirect_uris, client_name, client_secret) VALUES (:cid, :uris, :name, :secret)"
        )
            ->bind(':cid',    $clientId)
            ->bind(':uris',   json_encode($redirectUris))
            ->bind(':name',   $clientName)
            ->bind(':secret', $secret)
            ->execute();

        json_response([
            'client_id'                  => $clientId,
            'client_secret'              => $secret,
            'client_id_issued_at'        => time(),
            'client_secret_expires_at'   => 0,
            'client_name'                => $clientName,
            'redirect_uris'              => $redirectUris,
            'grant_types'                => ['authorization_code'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'client_secret_post',
            'scope'                      => 'mcp',
        ], 201);
    }

    // ─── Генерація фіксованих credentials для налаштувань Claude.ai ──────────

    public function clientCredentials(int $userId): array
    {
        // Шукаємо наявний фіксований client для юзера
        $row = $this->db
            ->query("SELECT * FROM oauth_clients WHERE client_name = :name AND client_secret IS NOT NULL LIMIT 1")
            ->bind(':name', 'claude-ai-user-' . $userId)
            ->fetch();

        if ($row) {
            return [
                'client_id'     => (string) ($row['client_id'] ?? ''),
                'client_secret' => (string) ($row['client_secret'] ?? ''),
            ];
        }

        // Створюємо новий
        $clientId = 'claude_' . bin2hex(random_bytes(12));
        $secret   = bin2hex(random_bytes(24));

        $this->db->query(
            "INSERT INTO oauth_clients (client_id, redirect_uris, client_name, client_secret) VALUES (:cid, :uris, :name, :secret)"
        )
            ->bind(':cid',    $clientId)
            ->bind(':uris',   json_encode(['https://claude.ai/api/mcp/auth_callback']))
            ->bind(':name',   'claude-ai-user-' . $userId)
            ->bind(':secret', $secret)
            ->execute();

        return ['client_id' => $clientId, 'client_secret' => $secret];
    }

    // ─── 3 + 4. Authorization endpoint ───────────────────────────────────────

    public function authorize(): void
    {
        $this->corsHeaders();
        $clientId            = trim((string) ($_GET['client_id']             ?? ''));
        $redirectUri         = trim((string) ($_GET['redirect_uri']          ?? ''));
        $state               = trim((string) ($_GET['state']                 ?? ''));
        $codeChallenge       = trim((string) ($_GET['code_challenge']        ?? ''));
        $codeChallengeMethod = trim((string) ($_GET['code_challenge_method'] ?? 'S256'));

        if ($clientId === '') {
            $this->oauthError('invalid_request', 'client_id is required', $redirectUri, $state);
        }

        $client = $this->db
            ->query("SELECT * FROM oauth_clients WHERE client_id = :cid LIMIT 1")
            ->bind(':cid', $clientId)
            ->fetch();

        if (!$client) {
            $this->oauthError('invalid_client', 'Unknown client_id', $redirectUri, $state);
        }

        $allowedUris = json_decode((string) ($client['redirect_uris'] ?? '[]'), true) ?: [];

        // Перевіряємо redirect_uri м'яко: точний збіг або починається з зареєстрованого
        if ($redirectUri !== '' && !empty($allowedUris)) {
            $uriAllowed = false;
            foreach ($allowedUris as $allowed) {
                if ($redirectUri === $allowed || str_starts_with($redirectUri, rtrim($allowed, '/') . '?') || str_starts_with($redirectUri, rtrim($allowed, '/') . '/')) {
                    $uriAllowed = true;
                    break;
                }
                // Якщо зареєстровані і нові URI мають однаковий base (без query)
                $allowedBase = strtok($allowed, '?');
                $reqBase     = strtok($redirectUri, '?');
                if ($allowedBase === $reqBase) {
                    $uriAllowed = true;
                    break;
                }
            }
            if (!$uriAllowed) {
                // Зберігаємо новий redirect_uri до registered списку (Claude.ai може його динамічно змінювати)
                $allowedUris[] = $redirectUri;
                $this->db->query("UPDATE oauth_clients SET redirect_uris = :uris WHERE client_id = :cid")
                    ->bind(':uris', json_encode(array_values(array_unique($allowedUris))))
                    ->bind(':cid', $clientId)
                    ->execute();
            }
        }

        if ($redirectUri === '' && !empty($allowedUris)) {
            $redirectUri = $allowedUris[0];
        }

        // Обробка "_logout=1" — скидаємо сесію для OAuth і показуємо форму токена
        if (isset($_GET['_logout'])) {
            session_unset();
            $this->showLoginForm($clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod);
        }

        // ── 1. Токен прямо в URL (token= або login_hint=) — seamless, без форми ──
        $urlToken = trim((string) ($_GET['token'] ?? $_GET['login_hint'] ?? ''));
        if ($urlToken !== '') {
            $tokenRow = $this->db
                ->query("SELECT * FROM auth_tokens WHERE token = :token AND type = 'api' AND expires_at > UTC_TIMESTAMP() LIMIT 1")
                ->bind(':token', $urlToken)
                ->fetch();
            if ($tokenRow) {
                $this->issueCodeAndRedirect((int) $tokenRow['id'], $clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod);
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = trim((string) ($_POST['_action'] ?? ''));

            if ($action === 'connect_session') {
                $this->authorizeViaSession($clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod);
            }

            $this->authorizeViaLogin($clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod);
        }

        // ── 2. Активна сесія в браузері — кнопка "Підключити" ──
        $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
        if ($sessionUserId > 0) {
            $this->showConnectForm($client, $clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod);
        }

        // ── 3. Форма з полем для API-токена ──
        $this->showLoginForm($clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod);
    }

    private function authorizeViaSession(
        string $clientId,
        string $redirectUri,
        string $state,
        string $codeChallenge,
        string $codeChallengeMethod
    ): void {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->showLoginForm($clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod, 'Сесія закінчилась. Увійдіть знову.');
        }

        $tokenRow = $this->getOrCreateApiToken($userId);
        if (!$tokenRow) {
            $this->showLoginForm($clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod, 'Не вдалось отримати токен. Спробуйте ще раз.');
        }

        $this->issueCodeAndRedirect((int) $tokenRow['id'], $clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod);
    }

    private function authorizeViaLogin(
        string $clientId,
        string $redirectUri,
        string $state,
        string $codeChallenge,
        string $codeChallengeMethod
    ): void {
        $apiToken = trim((string) ($_POST['api_token'] ?? ''));

        if ($apiToken === '') {
            $this->showLoginForm($clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod, 'Введіть API-токен.');
        }

        $tokenRow = $this->db
            ->query("SELECT * FROM auth_tokens WHERE token = :token AND type = 'api' AND expires_at > UTC_TIMESTAMP() LIMIT 1")
            ->bind(':token', $apiToken)
            ->fetch();

        if (!$tokenRow) {
            $this->showLoginForm($clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod, 'Невірний або прострочений API-токен.');
        }

        $this->issueCodeAndRedirect((int) $tokenRow['id'], $clientId, $redirectUri, $state, $codeChallenge, $codeChallengeMethod);
    }

    private function getOrCreateApiToken(int $userId): ?array
    {
        // Беремо перший живий API токен для юзера
        $row = $this->db
            ->query("SELECT id, token FROM auth_tokens WHERE user_id = :uid AND type = 'api' AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1")
            ->bind(':uid', $userId)
            ->fetch();

        if ($row) {
            return $row;
        }

        // Якщо нема — створюємо новий
        $companyRow = $this->db
            ->query("SELECT company_id FROM company_members WHERE user_id = :uid ORDER BY id ASC LIMIT 1")
            ->bind(':uid', $userId)
            ->fetch();
        $companyId = (int) ($companyRow['company_id'] ?? 0);

        $token     = 'tt_api_' . bin2hex(random_bytes(24));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 31536000);

        $this->db->query(
            "INSERT INTO auth_tokens (token, user_id, company_id, type, expires_at) VALUES (:tok, :uid, :cid, 'api', :exp)"
        )
            ->bind(':tok', $token)
            ->bind(':uid', $userId)
            ->bind(':cid', $companyId > 0 ? $companyId : null)
            ->bind(':exp', $expiresAt)
            ->execute();

        return $this->db
            ->query("SELECT id, token FROM auth_tokens WHERE token = :tok LIMIT 1")
            ->bind(':tok', $token)
            ->fetch() ?: null;
    }

    private function issueCodeAndRedirect(
        int $tokenId,
        string $clientId,
        string $redirectUri,
        string $state,
        string $codeChallenge,
        string $codeChallengeMethod
    ): void {
        $code      = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 300);

        $this->db->query(
            "INSERT INTO oauth_codes (code, client_id, token_id, code_challenge, code_challenge_method, redirect_uri, expires_at)
             VALUES (:code, :cid, :tid, :cc, :ccm, :ruri, :exp)"
        )
            ->bind(':code', $code)
            ->bind(':cid', $clientId)
            ->bind(':tid', $tokenId)
            ->bind(':cc', $codeChallenge)
            ->bind(':ccm', $codeChallengeMethod)
            ->bind(':ruri', $redirectUri)
            ->bind(':exp', $expiresAt)
            ->execute();

        $params = ['code' => $code];
        if ($state !== '') {
            $params['state'] = $state;
        }

        $sep      = str_contains($redirectUri, '?') ? '&' : '?';
        $location = $redirectUri . $sep . http_build_query($params);
        $logEntry = date('Y-m-d H:i:s') . ' CODE_ISSUED code_prefix=' . substr($code, 0, 10) . ' redirect=' . $location . "\n";
        @file_put_contents(__DIR__ . '/../../storage/oauth_debug.log', $logEntry, FILE_APPEND | LOCK_EX);
        header('Location: ' . $location);
        exit();
    }

    private function showConnectForm(
        array $client,
        string $clientId,
        string $redirectUri,
        string $state,
        string $codeChallenge,
        string $codeChallengeMethod
    ): void {
        $esc        = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $clientName = $esc($client['client_name'] ?? 'Claude');
        $userRow    = $this->db
            ->query("SELECT first_name, last_name, email FROM users WHERE id = :id LIMIT 1")
            ->bind(':id', (int) ($_SESSION['user_id'] ?? 0))
            ->fetch();
        $userName   = $userRow ? $esc(trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? '')) ?: ($userRow['email'] ?? '')) : '';

        echo '<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FINEKO Task Tracker — Підключення</title>
' . $this->oauthPageStyles() . '
</head>
<body>
<div class="card">
  <div class="logo">🔐 FINEKO Task Tracker</div>
  <div class="subtitle"><strong>' . $clientName . '</strong> хоче отримати доступ до вашого акаунта.</div>
  <div class="user-badge">👤 ' . $userName . '</div>
  <form method="POST">
    <input type="hidden" name="_action" value="connect_session">
    <button type="submit" class="btn">Підключити акаунт</button>
  </form>
  <div class="hint" style="margin-top:14px;">
    <a href="/oauth/authorize?' . $esc(http_build_query([
            'client_id' => $clientId, 'redirect_uri' => $redirectUri,
            'state' => $state, 'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod, 'response_type' => 'code',
            '_logout' => '1',
        ])) . '" style="color:#888;">Увійти під іншим акаунтом</a>
  </div>
</div>
</body>
</html>';
        exit();
    }

    private function showLoginForm(
        string $clientId,
        string $redirectUri,
        string $state,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $error = ''
    ): void {
        $esc       = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $errorHtml = $error !== '' ? '<p class="error">' . $esc($error) . '</p>' : '';

        echo '<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FINEKO Task Tracker — Підключення</title>
' . $this->oauthPageStyles() . '
</head>
<body>
<div class="card">
  <div class="logo">🔐 FINEKO Task Tracker</div>
  <div class="subtitle">Увійдіть у свій акаунт, щоб підключити Claude.</div>
  ' . $errorHtml . '
  <form method="POST">
    <label for="api_token">API-токен</label>
    <input type="password" id="api_token" name="api_token" placeholder="tt_api_..." autocomplete="off" autofocus required style="font-family:monospace; font-size:13px;">
    <button type="submit" class="btn">Підключити</button>
  </form>
  <div class="hint">
    Токен знаходиться в <strong>Акаунт → Налаштування → API Token для MCP/Claude</strong><br>
    Потрібна допомога? <a href="https://t.me/olexandrmatsuk" target="_blank">Написати в Telegram</a>
  </div>
</div>
</body>
</html>';
        exit();
    }

    private function oauthPageStyles(): string
    {
        return '<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .card { background: #fff; border-radius: 12px; padding: 40px; max-width: 400px; width: 100%; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
  .logo { font-size: 20px; font-weight: 700; color: #1a1a1a; margin-bottom: 8px; }
  .subtitle { color: #555; font-size: 14px; margin-bottom: 24px; line-height: 1.5; }
  .user-badge { background: #f0f4ff; border: 1.5px solid #c7d2fe; border-radius: 8px; padding: 10px 14px; font-size: 14px; color: #3730a3; font-weight: 500; margin-bottom: 20px; }
  label { display: block; font-size: 13px; font-weight: 600; color: #333; margin-bottom: 6px; }
  input[type=email], input[type=password] { width: 100%; padding: 10px 14px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 14px; outline: none; transition: border-color .2s; }
  input:focus { border-color: #4f6ef7; }
  .btn { width: 100%; margin-top: 20px; padding: 12px; background: #4f6ef7; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: background .2s; }
  .btn:hover { background: #3b57e3; }
  .hint { margin-top: 16px; font-size: 12px; color: #888; line-height: 1.6; }
  .hint a { color: #4f6ef7; text-decoration: none; }
  .error { color: #e53935; font-size: 13px; margin-bottom: 16px; padding: 10px 14px; background: #fff3f3; border-radius: 8px; border: 1px solid #f5c6c6; }
</style>';
    }

    // ─── 5. Token endpoint ────────────────────────────────────────────────────

    public function token(): void
    {
        $this->corsHeaders();
        $body = $this->formBody();

        // Тимчасовий лог для діагностики
        $logDir = defined('ROOT_PATH') ? ROOT_PATH : __DIR__ . '/../..';
        $logFile = $logDir . '/oauth_debug.log';
        $logEntry = date('Y-m-d H:i:s') . ' TOKEN_REQUEST body=' . json_encode($body, JSON_UNESCAPED_UNICODE)
            . ' raw=' . substr(file_get_contents('php://input') ?: '', 0, 200) . "\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

        $grantType   = trim((string) ($body['grant_type']    ?? ''));
        $code        = trim((string) ($body['code']          ?? ''));
        $redirectUri = trim((string) ($body['redirect_uri']  ?? ''));
        $clientId    = trim((string) ($body['client_id']     ?? ''));
        $codeVerifier = trim((string) ($body['code_verifier'] ?? ''));

        if ($grantType !== 'authorization_code') {
            json_response(['error' => 'unsupported_grant_type'], 400);
        }
        if ($code === '') {
            json_response(['error' => 'invalid_request', 'error_description' => 'code is required'], 400);
        }

        $codeRow = $this->db
            ->query("SELECT * FROM oauth_codes WHERE code = :code AND used = 0 AND expires_at > UTC_TIMESTAMP() LIMIT 1")
            ->bind(':code', $code)
            ->fetch();

        if (!$codeRow) {
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " TOKEN_ERROR code_not_found code=$code\n", FILE_APPEND | LOCK_EX);
            json_response(['error' => 'invalid_grant', 'error_description' => 'Code is invalid or expired'], 400);
        }

        // Verify client_id
        if ($clientId !== '' && $clientId !== (string) ($codeRow['client_id'] ?? '')) {
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " TOKEN_ERROR client_mismatch sent=$clientId stored={$codeRow['client_id']}\n", FILE_APPEND | LOCK_EX);
            json_response(['error' => 'invalid_client'], 400);
        }

        // Verify client_secret if provided
        $clientSecret = trim((string) ($body['client_secret'] ?? ''));
        if ($clientSecret !== '') {
            $clientRow = $this->db
                ->query("SELECT client_secret FROM oauth_clients WHERE client_id = :cid LIMIT 1")
                ->bind(':cid', (string) ($codeRow['client_id'] ?? ''))
                ->fetch();
            $storedSecret = (string) ($clientRow['client_secret'] ?? '');
            if ($storedSecret !== '' && !hash_equals($storedSecret, $clientSecret)) {
                json_response(['error' => 'invalid_client', 'error_description' => 'Invalid client_secret'], 401);
            }
        }

        // Verify PKCE
        $storedChallenge = (string) ($codeRow['code_challenge'] ?? '');
        if ($storedChallenge !== '' && $codeVerifier !== '') {
            $method = strtoupper((string) ($codeRow['code_challenge_method'] ?? 'S256'));
            if ($method === 'S256') {
                $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
                if (!hash_equals($storedChallenge, $computed)) {
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . " TOKEN_ERROR pkce_failed stored=$storedChallenge computed=$computed verifier=$codeVerifier\n", FILE_APPEND | LOCK_EX);
                    json_response(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed'], 400);
                }
            }
        }

        // Mark code as used
        $this->db->query("UPDATE oauth_codes SET used = 1 WHERE code = :code")
            ->bind(':code', $code)
            ->execute();

        // Fetch the actual API token value
        $tokenId = (int) ($codeRow['token_id'] ?? 0);
        $tokenRow = $this->db
            ->query("SELECT token, expires_at FROM auth_tokens WHERE id = :id AND type = 'api' AND expires_at > UTC_TIMESTAMP() LIMIT 1")
            ->bind(':id', $tokenId)
            ->fetch();

        if (!$tokenRow) {
            json_response(['error' => 'invalid_grant', 'error_description' => 'Associated API token is no longer valid'], 400);
        }

        $accessToken = (string) ($tokenRow['token'] ?? '');
        $expiresIn = max(0, strtotime((string) ($tokenRow['expires_at'] ?? '')) - time());

        $response = [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => $expiresIn > 0 ? $expiresIn : 31536000,
            'scope'        => 'mcp',
            'resource'     => APP_URL . '/mcp',
        ];
        $logEntry2 = date('Y-m-d H:i:s') . ' TOKEN_RESPONSE_OK token_prefix=' . substr($accessToken, 0, 15) . "\n";
        @file_put_contents($logFile, $logEntry2, FILE_APPEND | LOCK_EX);

        json_response($response);
    }

    // ─── 6. Token Introspection (RFC 7662) ───────────────────────────────────

    public function introspect(): void
    {
        $this->corsHeaders();
        $body  = $this->formBody();
        $token = trim((string) ($body['token'] ?? ''));

        if ($token === '') {
            json_response(['active' => false]);
        }

        $tokenRow = $this->db
            ->query("SELECT t.*, cm.company_id as cm_company FROM auth_tokens t LEFT JOIN company_members cm ON cm.user_id = t.user_id WHERE t.token = :tok AND t.type = 'api' AND t.expires_at > UTC_TIMESTAMP() LIMIT 1")
            ->bind(':tok', $token)
            ->fetch();

        if (!$tokenRow) {
            json_response(['active' => false]);
        }

        $exp = strtotime((string) ($tokenRow['expires_at'] ?? ''));
        json_response([
            'active'    => true,
            'scope'     => 'mcp',
            'token_type'=> 'Bearer',
            'exp'       => $exp ?: (time() + 31536000),
            'sub'       => (string) ($tokenRow['user_id'] ?? ''),
            'iss'       => APP_URL,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function corsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function formBody(): array
    {
        if (!empty($_POST)) {
            return $_POST;
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        // Try JSON first, then form-encoded
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        parse_str($raw, $params);
        return is_array($params) ? $params : [];
    }

    private function oauthError(string $error, string $description, string $redirectUri, string $state): void
    {
        if ($redirectUri !== '') {
            $params = ['error' => $error, 'error_description' => $description];
            if ($state !== '') {
                $params['state'] = $state;
            }
            $sep = str_contains($redirectUri, '?') ? '&' : '?';
            header('Location: ' . $redirectUri . $sep . http_build_query($params));
            exit();
        }
        json_response(['error' => $error, 'error_description' => $description], 400);
    }

    private function ensureTables(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS oauth_clients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id VARCHAR(100) NOT NULL,
            redirect_uris TEXT NOT NULL,
            client_name VARCHAR(255) NULL,
            client_secret VARCHAR(100) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_oauth_client_id (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();

        // Додаємо колонку client_secret якщо ще нема
        $cols = $this->db->query("SHOW COLUMNS FROM oauth_clients LIKE 'client_secret'")->fetchAll();
        if (empty($cols)) {
            $this->db->query("ALTER TABLE oauth_clients ADD COLUMN client_secret VARCHAR(100) NULL")->execute();
        }

        $this->db->query("CREATE TABLE IF NOT EXISTS oauth_codes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(128) NOT NULL,
            client_id VARCHAR(100) NOT NULL,
            token_id INT UNSIGNED NOT NULL,
            code_challenge VARCHAR(255) NULL,
            code_challenge_method VARCHAR(10) NULL DEFAULT 'S256',
            redirect_uri VARCHAR(500) NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_oauth_code (code),
            KEY idx_oauth_codes_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();
    }
}
