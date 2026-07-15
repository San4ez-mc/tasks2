<?php
/**
 * Функції-помічники додатку
 */

// ============ БАЗОВІ ХЕЛПЕРИ ============

function redirect($url)
{
    header('Location: ' . $url);
    exit();
}

function get_param($key, $default = null)
{
    return isset($_GET[$key]) ? htmlspecialchars($_GET[$key]) : $default;
}

function post_param($key, $default = null)
{
    return isset($_POST[$key]) ? htmlspecialchars($_POST[$key]) : $default;
}

function json_response($data, $code = 200)
{
    // Clean output buffer (removes BOM from PHP files with UTF-8 BOM encoding)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function flash($key, $message = null)
{
    if ($message === null) {
        if (isset($_SESSION['flash'][$key])) {
            $msg = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $msg;
        }
        return null;
    }
    $_SESSION['flash'][$key] = $message;
}

function is_auth()
{
    return isset($_SESSION['user_id']);
}

function get_user()
{
    if (is_auth()) {
        return $_SESSION['user'];
    }
    return null;
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function not_found()
{
    http_response_code(404);
    echo '404 - Сторінка не знайдена';
    exit();
}

// ============ ПОЛІЗАПОВНЕННЯ PHP < 8.0 ============

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        return $needle === '' || substr($haystack, 0, strlen($needle)) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

// ============ КОМПАНІЇ / СЕСІЯ ============

function get_user_companies($user_id = null)
{
    if ($user_id === null) {
        $user_id = $_SESSION['user_id'] ?? null;
    }
    if (!$user_id) {
        return [];
    }
    $db = new \App\Models\Database();
    return $db
        ->query('SELECT c.*, cm.role FROM companies c JOIN company_members cm ON cm.company_id = c.id WHERE cm.user_id = :user_id ORDER BY c.name ASC')
        ->bind(':user_id', (int) $user_id)
        ->fetchAll();
}

function sync_active_company()
{
    if (!is_auth()) {
        return null;
    }
    $companies = get_user_companies((int) ($_SESSION['user_id'] ?? 0));
    if (empty($companies)) {
        $_SESSION['company_id'] = null;
        return null;
    }
    $current = (int) ($_SESSION['company_id'] ?? 0);
    foreach ($companies as $company) {
        if ((int) ($company['id'] ?? 0) === $current) {
            return $current;
        }
    }
    $_SESSION['company_id'] = (int) $companies[0]['id'];
    return (int) $_SESSION['company_id'];
}

function get_active_company()
{
    $active_id = sync_active_company();
    if (!$active_id) {
        return null;
    }
    $companies = get_user_companies((int) ($_SESSION['user_id'] ?? 0));
    foreach ($companies as $company) {
        if ((int) ($company['id'] ?? 0) === (int) $active_id) {
            return $company;
        }
    }
    return null;
}

// ============ ПІДПИСКИ ============

function get_active_subscription(int $company_id): array
{
    if ($company_id <= 0) {
        return _sub_free_defaults();
    }
    try {
        $db = new \App\Models\Database();
        $row = $db->query('SELECT * FROM company_subscriptions WHERE company_id = :cid LIMIT 1')
            ->bind(':cid', $company_id)
            ->fetch();
    } catch (\Throwable $e) {
        return _sub_free_defaults();
    }
    if (!$row) {
        return _sub_free_defaults();
    }

    $plan = (string) ($row['plan'] ?? 'free');
    $status = (string) ($row['status'] ?? 'active');
    $expires_at = (string) ($row['expires_at'] ?? '');
    $is_expired = $expires_at !== '' && strtotime($expires_at) < time();

    if ($plan === 'free' || $status === 'cancelled' || $is_expired) {
        $effective_plan = 'free';
    } else {
        $effective_plan = $plan;
    }
    $is_trial = ($status === 'trial' && $effective_plan !== 'free');

    $plans = SUBSCRIPTION_PLANS;
    $plan_info = $plans[$effective_plan] ?? null;

    $days_left = 0;
    if ($expires_at !== '' && !$is_expired) {
        $diff = strtotime($expires_at) - time();
        $days_left = max(0, (int) ceil($diff / 86400));
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'plan' => $effective_plan,
        'db_plan' => $plan,
        'status' => $status,
        'member_limit' => $plan_info ? (int) $plan_info['member_limit'] : SUBSCRIPTION_FREE_MEMBER_LIMIT,
        'ai_bot' => $plan_info ? (bool) $plan_info['ai_bot'] : false,
        'price_usd' => $plan_info ? (float) $plan_info['price_usd'] : 0.0,
        'expires_at' => $expires_at,
        'cancelled_at' => (string) ($row['cancelled_at'] ?? ''),
        'paid_at' => (string) ($row['paid_at'] ?? ''),
        'days_left' => $days_left,
        'is_expired' => $is_expired,
        'plan_name' => $plan_info['name'] ?? 'Free',
        'is_active' => $effective_plan !== 'free',
        'is_trial' => $is_trial,
    ];
}

function _sub_free_defaults(): array
{
    return [
        'id' => 0,
        'plan' => 'free',
        'db_plan' => 'free',
        'status' => 'active',
        'member_limit' => SUBSCRIPTION_FREE_MEMBER_LIMIT,
        'ai_bot' => false,
        'price_usd' => 0.0,
        'expires_at' => '',
        'cancelled_at' => '',
        'paid_at' => '',
        'days_left' => 0,
        'is_expired' => false,
        'plan_name' => 'Free',
        'is_active' => false,
        'is_trial' => false,
    ];
}

// ============ TELEGRAM СПОВІЩЕННЯ АДМІНУ ============

/**
 * Надіслати текстове повідомлення адміну в Telegram через бота.
 */
function notify_admin_telegram(string $message): void
{
    $token = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : null;
    $chat_id = defined('ADMIN_TELEGRAM_ID') ? ADMIN_TELEGRAM_ID : null;

    if (!$token || !$chat_id) {
        return;
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = json_encode([
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML',
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);

    @file_get_contents($url, false, $ctx);
}
