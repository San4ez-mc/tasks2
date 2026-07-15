<?php
/**
 * Точка входу додатку
 */

require_once 'config.php';

use App\Models\Database;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\TaskController;
use App\Controllers\ResultController;
use App\Controllers\CompanyController;
use App\Controllers\AccountController;
use App\Controllers\TelegramWebhookController;
use App\Controllers\TemplateController;
use App\Controllers\WeeklyPlanController;
use App\Controllers\ApiController;
use App\Controllers\McpController;
use App\Controllers\OAuthController;
use App\Controllers\ProjectController;
use App\Controllers\SearchController;
use App\Controllers\TrainingController;
use App\Controllers\SubscriptionController;
use App\Middleware\ApiAuthMiddleware;

// ============ РОУТІНГ ============

$request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$request_method = $_SERVER['REQUEST_METHOD'];

// Видалити префікс додатку для різних режимів хостингу (root/subdir, encoded/decoded)
$scriptDir = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($scriptDir !== '' && $scriptDir !== '.') {
    if (str_starts_with($request_uri, $scriptDir . '/')) {
        $request_uri = substr($request_uri, strlen($scriptDir . '/'));
    } elseif ($request_uri === $scriptDir) {
        $request_uri = '';
    }
}

// Legacy compatibility for old folder name deployments.
$request_uri = str_replace(['task%20traker%20backend/', 'task traker backend/'], '', $request_uri);

// Розбити URL на частини
$parts = explode('/', $request_uri);
$controller = $parts[0] ?? '';
$action = $parts[1] ?? 'index';
$id = $parts[2] ?? null;

// Якщо controller пуста, то це дашборд
if (empty($controller)) {
    $controller = 'dashboard';
    $action = 'index';
}

// Маршрути без авторизації
$public_routes = ['auth', 'login', 'register', 'telegram', 'api', 'health', 'mcp', 'training', 'oauth', '.well-known'];

// Якщо маршрут громадський, не перевіряємо авторизацію
if (!in_array($controller, $public_routes) && !is_auth()) {
    redirect('/auth/login');
}

if (is_auth()) {
    sync_active_company();
}

// ============ ОБРОБКА МАРШРУТІВ ============

try {
    switch ($controller) {
        // ========== АВТОРИЗАЦІЯ ==========
        case 'auth':
        case 'login':
        case 'register':
            $auth = new AuthController();
            if ($action === 'login') {
                if ($request_method === 'GET') {
                    $auth->login();
                } else {
                    $auth->login_post();
                }
            } elseif ($action === 'forgot-password') {
                if ($request_method === 'GET') {
                    $auth->forgot_password();
                } else {
                    $auth->forgot_password_post();
                }
            } elseif ($action === 'reset-password' && $request_method === 'GET' && $id !== null) {
                $auth->reset_password((string) $id);
            } elseif ($action === 'reset-password-complete' && $request_method === 'POST') {
                $auth->reset_password_post();
            } elseif ($action === 'register') {
                if ($request_method === 'GET') {
                    $auth->register();
                } else {
                    $auth->register_post();
                }
            } elseif ($action === 'logout') {
                $auth->logout();
            } elseif ($action === 'google' && $request_method === 'POST') {
                $auth->google_login();
            } elseif ($action === 'telegram-token' && $request_method === 'GET') {
                $auth->telegram_token($id);
            } elseif ($action === 'onboard' && $request_method === 'GET' && $id !== null) {
                $auth->onboard((string) $id);
            } elseif ($action === 'onboard-complete' && $request_method === 'POST') {
                $auth->onboard_post();
            } else {
                $auth->login();
            }
            break;

        // ========== ПОШУК ==========
        case 'search':
            $search = new SearchController();
            $search->search();
            break;

        // ========== НАВЧАННЯ ==========
        case 'training':
            $training = new TrainingController();
            if ($action === 'pay' && $request_method === 'POST') {
                $training->pay();
            } elseif ($action === 'callback') {
                $training->callback();
            } else {
                redirect('/account/settings#training');
            }
            break;

        // ========== ДАШБОРД ==========
        case 'dashboard':
        case '':
            $dashboard = new DashboardController();
            if ($action === 'index' || $action === '') {
                $dashboard->index();
            } else {
                not_found();
            }
            break;

        // ========== ПРОЕКТИ ==========
        case 'projects':
            $proj = new ProjectController();
            if ($action === 'index' || $action === '' || $action === 'list') {
                $proj->index();
            } elseif ($action === 'view' && $id !== null) {
                $proj->view((int) $id);
            } elseif ($action === 'create') {
                if ($request_method === 'GET') {
                    $proj->create();
                } else {
                    $proj->create_post();
                }
            } elseif ($action === 'edit' && $id !== null) {
                if ($request_method === 'GET') {
                    $proj->edit((int) $id);
                } else {
                    $proj->edit_post((int) $id);
                }
            } elseif ($action === 'delete' && $id !== null && $request_method === 'POST') {
                $proj->delete_post((int) $id);
            } else {
                $proj->index();
            }
            break;

        // ========== ЗАВДАННЯ ==========
        case 'tasks':
            $task = new TaskController();
            switch ($action) {
                case 'index':
                    $task->index();
                    break;
                case 'create':
                    if ($request_method === 'GET') {
                        $task->create();
                    } else {
                        $task->store();
                    }
                    break;
                case 'edit':
                    if ($request_method === 'GET') {
                        $task->edit($id);
                    } else {
                        $task->update($id);
                    }
                    break;
                case 'view':
                    $task->view($id);
                    break;
                case 'delete':
                    $task->delete($id);
                    break;
                case 'accept':
                    if ($request_method === 'POST') {
                        $task->accept($id);
                    } else {
                        not_found();
                    }
                    break;
                case 'resolve-overdue':
                    if ($request_method === 'POST') {
                        $task->resolveOverdue();
                    } else {
                        not_found();
                    }
                    break;
                default:
                    not_found();
            }
            break;

        // ========== ПЛАН-ФАКТ ==========
        case 'weekly-plans':
            $weeklyPlan = new WeeklyPlanController();
            switch ($action) {
                case 'index':
                case '':
                    $weeklyPlan->index();
                    break;
                case 'create':
                    if ($request_method === 'POST') {
                        $weeklyPlan->create();
                    } else {
                        $weeklyPlan->createForm();
                    }
                    break;
                case 'view':
                    $weeklyPlan->view($id);
                    break;
                case 'delete':
                    if ($request_method === 'POST') {
                        $weeklyPlan->delete($id);
                    } else {
                        not_found();
                    }
                    break;
                case 'add-item':
                    if ($request_method === 'POST') {
                        $weeklyPlan->addItem($id);
                    } else {
                        not_found();
                    }
                    break;
                case 'add-templates':
                    if ($request_method === 'POST') {
                        $weeklyPlan->addTemplates($id);
                    } else {
                        not_found();
                    }
                    break;
                case 'copy-day':
                    if ($request_method === 'POST') {
                        $weeklyPlan->copyDay($id);
                    } else {
                        not_found();
                    }
                    break;
                case 'update-item':
                    if ($request_method === 'POST') {
                        $weeklyPlan->updateItem($id);
                    } else {
                        not_found();
                    }
                    break;
                case 'delete-item':
                    if ($request_method === 'POST') {
                        $weeklyPlan->deleteItem($id);
                    } else {
                        not_found();
                    }
                    break;
                case 'update-fact-task':
                    if ($request_method === 'POST') {
                        $weeklyPlan->updateFactTask($id);
                    } else {
                        not_found();
                    }
                    break;
                case 'import-google-calendar':
                    if ($request_method === 'POST') {
                        $weeklyPlan->importGoogleCalendar($id);
                    } else {
                        not_found();
                    }
                    break;
                default:
                    not_found();
            }
            break;

        // ========== РЕЗУЛЬТАТИ ==========
        case 'results':
            $result = new ResultController();
            switch ($action) {
                case 'index':
                    $result->index();
                    break;
                case 'create':
                    if ($request_method === 'GET') {
                        $result->create();
                    } else {
                        $result->store();
                    }
                    break;
                case 'store-ajax':
                    if ($request_method === 'POST') {
                        $result->storeAjax();
                    } else {
                        not_found();
                    }
                    break;
                case 'edit':
                    if ($request_method === 'GET') {
                        $result->edit($id);
                    } else {
                        $result->update($id);
                    }
                    break;
                case 'view':
                    $result->view($id);
                    break;
                case 'delete':
                    $result->delete($id);
                    break;
                case 'complete':
                    if ($request_method === 'POST') {
                        $result->completeAjax($id);
                    } else {
                        not_found();
                    }
                    break;
                default:
                    not_found();
            }
            break;

        // ========== КОМПАНІЯ ==========
        case 'company':
            $company = new CompanyController();
            switch ($action) {
                case 'create':
                    if ($request_method === 'GET') {
                        $company->create();
                    } else {
                        $company->store_create();
                    }
                    break;
                case 'profile':
                    if ($request_method === 'GET') {
                        $company->profile();
                    } else {
                        $company->update_profile();
                    }
                    break;
                case 'add-employee':
                    if ($request_method === 'GET') {
                        $company->add_employee();
                    } else {
                        $company->store_employee();
                    }
                    break;
                case 'edit-employee':
                    if ($request_method === 'GET') {
                        $company->edit_employee($id);
                    } else {
                        $company->update_employee($id);
                    }
                    break;
                case 'delete-employee':
                    $company->delete_employee($id);
                    break;
                case 'generate-onboarding':
                    $company->generate_onboarding($id);
                    break;
                case 'send-onboarding-email':
                    $company->send_onboarding_email($id);
                    break;
                case 'logs':
                    $company->logs($id);
                    break;
                case 'switch':
                    if ($request_method === 'POST') {
                        $company->switch_company();
                    } else {
                        not_found();
                    }
                    break;
                default:
                    not_found();
            }
            break;

        // ========== АКАУНТ ==========
        case 'account':
            $account = new AccountController();
            switch ($action) {
                case 'settings':
                case '':
                    $account->settings();
                    break;
                case 'telegram-link':
                    if ($request_method === 'POST') {
                        $account->create_telegram_link_code();
                    } else {
                        not_found();
                    }
                    break;
                case 'telegram-unlink':
                    if ($request_method === 'POST') {
                        $account->unlink_telegram();
                    } else {
                        not_found();
                    }
                    break;
                case 'password':
                    if ($request_method === 'POST') {
                        $account->update_password();
                    } else {
                        not_found();
                    }
                    break;
                case 'api-token':
                    if ($request_method === 'POST') {
                        $account->create_api_token();
                    } else {
                        not_found();
                    }
                    break;
                case 'api-token-reveal':
                    if ($request_method === 'GET') {
                        $account->reveal_api_token();
                    } else {
                        not_found();
                    }
                    break;
                case 'api-token-revoke':
                    if ($request_method === 'POST') {
                        $account->revoke_api_token();
                    } else {
                        not_found();
                    }
                    break;
                case 'integrations':
                    if ($id === 'claude' && $request_method === 'GET') {
                        $account->integrations_claude();
                    } else {
                        not_found();
                    }
                    break;
                case 'digest-settings':
                    if ($request_method === 'POST') {
                        $account->update_digest_settings();
                    } else {
                        not_found();
                    }
                    break;
                case 'subscription':
                    $subscription_ctrl = new SubscriptionController();
                    if ($id === 'pay' && $request_method === 'POST') {
                        $subscription_ctrl->pay();
                    } elseif ($id === 'cancel' && $request_method === 'POST') {
                        $subscription_ctrl->cancel();
                    } elseif ($id === 'callback') {
                        $subscription_ctrl->callback();
                    } elseif ($id === 'downgrade-members' && $request_method === 'POST') {
                        $subscription_ctrl->downgradeSave();
                    } else {
                        redirect('/account/settings');
                    }
                    break;
                default:
                    not_found();
            }
            break;

        // ========== ШАБЛОНИ ==========
        case 'templates':
            $tmpl = new TemplateController();
            switch ($action) {
                case 'index':
                case '':
                    $tmpl->index();
                    break;
                case 'create':
                    if ($request_method === 'GET') {
                        $tmpl->create();
                    } else {
                        $tmpl->store();
                    }
                    break;
                case 'edit':
                    if ($request_method === 'GET') {
                        $tmpl->edit($id);
                    } else {
                        $tmpl->update($id);
                    }
                    break;
                case 'delete':
                    $tmpl->delete($id);
                    break;
                default:
                    not_found();
            }
            break;

        // ========== TELEGRAM WEBHOOK ==========
        case 'telegram':
            $tg = new TelegramWebhookController();
            if ($action === 'webhook' && $request_method === 'POST') {
                $tg->webhook();
            } elseif ($action === 'digest-cron' && in_array($request_method, ['GET', 'POST'], true)) {
                $tg->digestCron();
            } else {
                not_found();
            }
            break;

        // ========== OAUTH 2.0 ==========
        case '.well-known':
            if ($request_method === 'OPTIONS') { header('Access-Control-Allow-Origin: *'); http_response_code(204); exit(); }
            $oauthWk = new OAuthController();
            if ($action === 'oauth-authorization-server') { $oauthWk->discovery(); }
            if ($action === 'oauth-protected-resource')   { $oauthWk->protectedResource(); }
            not_found();
            break;

        case 'oauth':
            if ($request_method === 'OPTIONS') { header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); header('Access-Control-Allow-Headers: Content-Type, Authorization'); http_response_code(204); exit(); }
            $oauth = new OAuthController();
            if ($action === 'register'   && $request_method === 'POST') { $oauth->register(); }
            elseif ($action === 'authorize')                             { $oauth->authorize(); }
            elseif ($action === 'token'  && $request_method === 'POST') { $oauth->token(); }
            elseif ($action === 'introspect' && $request_method === 'POST') { $oauth->introspect(); }
            else { not_found(); }
            break;

        // ========== HEALTH CHECK ==========
        case 'health':
            $apiState = 'reachable';
            try {
                $dbHealth = new Database();
                $dbHealth->query('SELECT 1')->fetch();
            } catch (\Throwable $e) {
                $apiState = 'unreachable';
            }

            json_response([
                'status' => $apiState === 'reachable' ? 'ok' : 'degraded',
                'version' => '1.0',
                'task_tracker_api' => $apiState,
            ], $apiState === 'reachable' ? 200 : 503);
            break;

        // ========== MCP OVER HTTP (PHP ONLY) ==========
        case 'mcp':
            $mcp = new McpController();
            if ($request_method === 'OPTIONS') {
                $mcp->options();
            }
            if ($request_method === 'HEAD') {
                $mcp->head();
            }
            if ($request_method === 'GET') {
                $mcp->info();
            }
            if ($request_method === 'POST') {
                $mcp->handle();
            }
            not_found();
            break;

        // ========== API V1 (MCP CONNECTOR) ==========
        case 'api':
            $apiVersion = $parts[1] ?? '';
            if ($apiVersion !== 'v1') {
                not_found();
            }

            $apiAuth = new ApiAuthMiddleware();
            $apiContext = $apiAuth->authenticate();

            $apiController = new ApiController();
            $apiController->handle($request_method, $parts, $apiContext);
            break;

        default:
            not_found();
    }

} catch (Exception $e) {
    if (APP_DEBUG) {
        echo '<h1>Помилка:</h1>';
        echo '<p>' . $e->getMessage() . '</p>';
        echo '<pre>' . $e->getTraceAsString() . '</pre>';
    } else {
        http_response_code(500);
        echo '500 - Внутрішня помилка сервера';
    }
}
