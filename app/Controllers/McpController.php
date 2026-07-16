<?php

namespace App\Controllers;

class McpController
{
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
    private const DEFAULT_PROTOCOL_VERSION = '2025-06-18';

    public function options(): void
    {
        $this->corsHeaders();
        http_response_code(204);
        exit();
    }

    public function info(): void
    {
        $this->corsHeaders();
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');

        $protocolVersion = $this->resolveProtocolVersion();
        header('MCP-Protocol-Version: ' . $protocolVersion);
        json_response([
            'ok' => true,
            'name' => 'FINEKO Task Tracker MCP',
            'protocol_version' => $protocolVersion,
            'supported_protocol_versions' => self::SUPPORTED_PROTOCOL_VERSIONS,
            'transport' => 'http-post-only',
            'methods' => ['tools/list', 'tools/call'],
            'hint' => 'Use POST /mcp for MCP calls.',
        ], 200);
    }

    public function head(): void
    {
        $this->corsHeaders();
        header('MCP-Protocol-Version: ' . $this->resolveProtocolVersion());
        http_response_code(200);
        exit();
    }

    public function handle(): void
    {
        $this->corsHeaders();
        $payload = $this->jsonBody();
        $logFile = defined('ROOT_PATH') ? ROOT_PATH . '/oauth_debug.log' : __DIR__ . '/../../oauth_debug.log';
        $auth = substr((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 'NONE'), 0, 20);
        @file_put_contents($logFile, date('Y-m-d H:i:s') . ' MCP method=' . ($payload['method'] ?? '?') . ' auth=' . $auth . "\n", FILE_APPEND | LOCK_EX);

        $requestedProtocol = $this->extractRequestedProtocol($payload);
        $protocolVersion = $this->resolveProtocolVersion($requestedProtocol);
        header('MCP-Protocol-Version: ' . $protocolVersion);

        $jsonrpc = (string) ($payload['jsonrpc'] ?? '');
        $id = $payload['id'] ?? null;
        $method = (string) ($payload['method'] ?? '');

        if ($jsonrpc !== '2.0' || $method === '') {
            $this->respondJsonRpcError($id, -32600, 'Invalid Request');
        }

        if ($method === 'notifications/initialized') {
            http_response_code(204);
            exit();
        }

        if ($method === 'initialize') {
            if ($requestedProtocol !== '' && !in_array($requestedProtocol, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
                $this->respondJsonRpcError(
                    $id,
                    -32602,
                    'Unsupported protocolVersion: ' . $requestedProtocol . '. Supported: ' . implode(', ', self::SUPPORTED_PROTOCOL_VERSIONS)
                );
            }

            $this->respondJsonRpcResult($id, [
                'protocolVersion' => $protocolVersion,
                'capabilities' => [
                    'tools' => [
                        'listChanged' => false,
                    ],
                ],
                'serverInfo' => [
                    'name'    => 'fineko-task-tracker-mcp',
                    'version' => '1.0.0',
                ],
            ]);
        }

        if ($method === 'tools/list') {
            $this->respondJsonRpcResult($id, [
                'tools' => $this->tools(),
            ]);
        }

        if ($method !== 'tools/call') {
            $this->respondJsonRpcError($id, -32601, 'Method not found');
        }

        $toolName = (string) ($payload['params']['name'] ?? '');
        $args = $payload['params']['arguments'] ?? [];
        if ($toolName === '' || !is_array($args)) {
            $this->respondJsonRpcError($id, -32602, 'Invalid params');
        }

        if ($toolName === 'get_system_description') {
            $this->respondJsonRpcResult($id, [
                'isError' => false,
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $this->systemDescriptionText(),
                    ],
                ],
            ]);
        }

        $route = $this->resolveToolRoute($toolName, $args);
        if ($route === null) {
            $this->respondJsonRpcError($id, -32602, 'Unknown tool: ' . $toolName);
        }

        $upstream = $this->forwardToApi($route['method'], $route['path'], $route['query'], $route['body']);

        $status = (int) ($upstream['status'] ?? 500);
        $ok = $status >= 200 && $status < 300;
        $data = $upstream['data'] ?? [];
        $dataText = is_array($data)
            ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $data;
        if ($dataText === false || $dataText === '') {
            $dataText = '{}';
        }

        $toolResult = [
            'isError' => !$ok,
            'content' => [
                [
                    'type' => 'text',
                    'text' => $dataText,
                ]
            ],
            'structuredContent' => is_array($data) ? $data : ['raw' => $data],
        ];

        $this->respondJsonRpcResult($id, $toolResult);
    }

    private function extractRequestedProtocol(array $payload): string
    {
        $headerVersion = trim((string) ($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''));
        if ($headerVersion !== '') {
            return $headerVersion;
        }

        return trim((string) ($payload['params']['protocolVersion'] ?? ''));
    }

    private function resolveProtocolVersion(string $requestedProtocol = ''): string
    {
        if ($requestedProtocol !== '' && in_array($requestedProtocol, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            return $requestedProtocol;
        }

        return self::DEFAULT_PROTOCOL_VERSION;
    }

    private function respondJsonRpcResult($id, array $result): void
    {
        json_response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], 200);
    }

    private function respondJsonRpcError($id, int $code, string $message): void
    {
        json_response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 200);
    }

    private function forwardToApi(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $baseUrl = rtrim(APP_URL, '/') . '/api/v1';
        $url = $baseUrl . $path;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($authHeader === '') {
            $urlToken = trim((string) ($_GET['token'] ?? $_GET['api_token'] ?? ''));
            if ($urlToken !== '') {
                $authHeader = 'Bearer ' . $urlToken;
            }
        }

        $headers = [
            'Content-Type: application/json',
        ];
        if ($authHeader !== '') {
            $headers[] = 'Authorization: ' . $authHeader;
        }

        $raw = false;
        $httpCode = 0;
        $curlErr = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
            }

            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'content' => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : '',
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($url, false, $context);
            $statusLine = $http_response_header[0] ?? '';
            if (preg_match('/\s(\d{3})\s/', (string) $statusLine, $m)) {
                $httpCode = (int) $m[1];
            }
            if ($raw === false) {
                $curlErr = 'Upstream request failed.';
            }
        }

        if ($raw === false) {
            return [
                'status' => 503,
                'data' => ['ok' => false, 'error' => $curlErr !== '' ? $curlErr : 'Upstream request failed.'],
            ];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => $raw];
        }

        return [
            'status' => $httpCode > 0 ? $httpCode : 500,
            'data' => $decoded,
        ];
    }

    private function resolveToolRoute(string $toolName, array $args): ?array
    {
        return match ($toolName) {
            'create_task' => ['method' => 'POST', 'path' => '/tasks', 'query' => [], 'body' => $args],
            'list_tasks' => ['method' => 'GET', 'path' => '/tasks', 'query' => $args, 'body' => null],
            'get_task' => ['method' => 'GET', 'path' => '/tasks/' . (int) ($args['task_id'] ?? 0), 'query' => [], 'body' => null],
            'update_task' => ['method' => 'PATCH', 'path' => '/tasks/' . (int) ($args['task_id'] ?? 0), 'query' => [], 'body' => ['fields' => $args['fields'] ?? [], 'dry_run' => (bool) ($args['dry_run'] ?? false)]],
            'delete_task' => ['method' => 'DELETE', 'path' => '/tasks/' . (int) ($args['task_id'] ?? 0), 'query' => [], 'body' => ['confirm' => (bool) ($args['confirm'] ?? false), 'dry_run' => (bool) ($args['dry_run'] ?? false)]],

            'list_results' => ['method' => 'GET', 'path' => '/results', 'query' => $args, 'body' => null],
            'get_result' => ['method' => 'GET', 'path' => '/results/' . (int) ($args['result_id'] ?? 0), 'query' => [], 'body' => null],
            'read_result' => ['method' => 'GET', 'path' => '/results/' . (int) ($args['result_id'] ?? 0), 'query' => [], 'body' => null],
            'create_result' => ['method' => 'POST', 'path' => '/results', 'query' => [], 'body' => $args],
            'update_result' => ['method' => 'PATCH', 'path' => '/results/' . (int) ($args['result_id'] ?? 0), 'query' => [], 'body' => ['fields' => $args['fields'] ?? []]],
            'delete_result' => ['method' => 'DELETE', 'path' => '/results/' . (int) ($args['result_id'] ?? 0), 'query' => [], 'body' => ['confirm' => (bool) ($args['confirm'] ?? false), 'dry_run' => (bool) ($args['dry_run'] ?? false)]],

            'list_templates' => ['method' => 'GET', 'path' => '/templates', 'query' => $args, 'body' => null],
            'create_template' => ['method' => 'POST', 'path' => '/templates', 'query' => [], 'body' => $args],
            'update_template' => ['method' => 'PATCH', 'path' => '/templates/' . (int) ($args['template_id'] ?? 0), 'query' => [], 'body' => ['fields' => $args['fields'] ?? []]],
            'delete_template' => ['method' => 'DELETE', 'path' => '/templates/' . (int) ($args['template_id'] ?? 0), 'query' => [], 'body' => ['confirm' => (bool) ($args['confirm'] ?? false), 'dry_run' => (bool) ($args['dry_run'] ?? false)]],

            'get_weekly_plan' => ['method' => 'GET', 'path' => '/weekly-plans', 'query' => $args, 'body' => null],
            'add_plan_item' => ['method' => 'POST', 'path' => '/weekly-plans/items', 'query' => [], 'body' => $args],
            'update_plan_item' => ['method' => 'PATCH', 'path' => '/weekly-plans/items/' . (int) ($args['item_id'] ?? 0), 'query' => [], 'body' => ['fields' => $args['fields'] ?? [], 'dry_run' => (bool) ($args['dry_run'] ?? false)]],
            'delete_plan_item' => ['method' => 'DELETE', 'path' => '/weekly-plans/items/' . (int) ($args['item_id'] ?? 0), 'query' => [], 'body' => ['confirm' => (bool) ($args['confirm'] ?? false), 'dry_run' => (bool) ($args['dry_run'] ?? false)]],
            'get_plan_summary' => ['method' => 'GET', 'path' => '/weekly-plans/summary', 'query' => $args, 'body' => null],

            'list_team_members' => ['method' => 'GET', 'path' => '/company/members', 'query' => [], 'body' => null],
            'get_dashboard_summary' => ['method' => 'GET', 'path' => '/dashboard/summary', 'query' => $args, 'body' => null],

            'list_projects' => ['method' => 'GET', 'path' => '/projects', 'query' => [], 'body' => null],
            'get_project' => ['method' => 'GET', 'path' => '/projects/' . (int) ($args['project_id'] ?? 0), 'query' => [], 'body' => null],
            'create_project' => ['method' => 'POST', 'path' => '/projects', 'query' => [], 'body' => $args],
            'update_project' => ['method' => 'PATCH', 'path' => '/projects/' . (int) ($args['project_id'] ?? 0), 'query' => [], 'body' => ['fields' => $args['fields'] ?? [], 'dry_run' => (bool) ($args['dry_run'] ?? false)]],
            'delete_project' => ['method' => 'DELETE', 'path' => '/projects/' . (int) ($args['project_id'] ?? 0), 'query' => [], 'body' => null],
            default => null,
        };
    }

    private function tools(): array
    {
        return [
            [
                'name' => 'create_task',
                'description' => 'Create a new task. IMPORTANT: if the user did not specify expected_result or expected_time — do NOT ask them to provide these values. Instead, suggest your own reasonable values based on the task title and context (e.g. expected_result: "Task completed", expected_time: 30), show them to the user in the confirmation message, and let the user simply reply "ok" or correct them. Only call this tool after the user confirms.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['title', 'expected_result', 'expected_time'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'assignee_id' => ['type' => 'integer'],
                        'result_id' => ['type' => 'integer'],
                        'due_date' => ['type' => 'string'],
                        'expected_result' => ['type' => 'string', 'description' => 'Expected outcome of the task. Suggest a value yourself if user did not specify.'],
                        'expected_time' => ['type' => 'integer', 'description' => 'Planned time in minutes. Suggest a reasonable value yourself if user did not specify.'],
                        'idempotency_key' => ['type' => 'string'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'list_tasks',
                'description' => 'List tasks. Show to user with calculations of how much summary expected time for all list of tasks',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['open', 'in_progress', 'done', 'cancelled']],
                        'assignee_id' => ['type' => 'integer'],
                        'result_id' => ['type' => 'integer'],
                        'date_from' => ['type' => 'string'],
                        'date_to' => ['type' => 'string'],
                        'limit' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'get_task',
                'description' => 'Get task by id',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['task_id'],
                    'properties' => ['task_id' => ['type' => 'integer']],
                ],
            ],
            [
                'name' => 'update_task',
                'description' => 'Update task fields. Only pass fields you want to change.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['task_id', 'fields'],
                    'properties' => [
                        'task_id' => ['type' => 'integer'],
                        'fields' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'status' => ['type' => 'string', 'enum' => ['todo', 'in-progress', 'done', 'postponed']],
                                'due_date' => ['type' => 'string', 'description' => 'Date in Y-m-d format'],
                                'assignee_id' => ['type' => 'integer'],
                                'expected_result' => ['type' => 'string', 'description' => 'Expected result of the task (required when creating)'],
                                'expected_time' => ['type' => 'integer', 'description' => 'Planned time in minutes (required when creating)'],
                                'actual_result' => ['type' => 'string', 'description' => 'Required when setting status to done'],
                            ],
                        ],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'delete_task',
                'description' => 'Delete task',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['task_id', 'confirm'],
                    'properties' => [
                        'task_id' => ['type' => 'integer'],
                        'confirm' => ['type' => 'boolean'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'list_results',
                'description' => 'List goals/results',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'include_children' => ['type' => 'boolean'],
                        'status' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name' => 'get_result',
                'description' => 'Get result by id',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['result_id'],
                    'properties' => [
                        'result_id' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'read_result',
                'description' => 'Read result by id (alias of get_result)',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['result_id'],
                    'properties' => [
                        'result_id' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'create_result',
                'description' => 'Create result',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['title'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'parent_id' => ['type' => 'integer'],
                        'idempotency_key' => ['type' => 'string'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'update_result',
                'description' => 'Update result',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['result_id', 'fields'],
                    'properties' => [
                        'result_id' => ['type' => 'integer'],
                        'fields' => ['type' => 'object'],
                    ],
                ],
            ],
            [
                'name' => 'delete_result',
                'description' => 'Delete result after confirmation',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['result_id', 'confirm'],
                    'properties' => [
                        'result_id' => ['type' => 'integer'],
                        'confirm' => ['type' => 'boolean'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'list_templates',
                'description' => 'List templates',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'create_template',
                'description' => 'Create template',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['title', 'repeat_type'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'repeat_type' => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly', 'none']],
                        'repeat_day' => ['type' => 'integer'],
                        'start_time' => ['type' => 'string'],
                        'assignee_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'idempotency_key' => ['type' => 'string'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'update_template',
                'description' => 'Update template',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['template_id', 'fields'],
                    'properties' => [
                        'template_id' => ['type' => 'integer'],
                        'fields' => ['type' => 'object'],
                    ],
                ],
            ],
            [
                'name' => 'delete_template',
                'description' => 'Delete template after confirmation',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['template_id', 'confirm'],
                    'properties' => [
                        'template_id' => ['type' => 'integer'],
                        'confirm' => ['type' => 'boolean'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'get_weekly_plan',
                'description' => 'Get weekly plan',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['week_start_date' => ['type' => 'string']],
                ],
            ],
            [
                'name' => 'add_plan_item',
                'description' => 'Add weekly plan item',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['title', 'day_of_week'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'day_of_week' => ['type' => 'integer'],
                        'week_start_date' => ['type' => 'string'],
                        'time' => ['type' => 'string'],
                        'linked_task_id' => ['type' => 'integer'],
                        'idempotency_key' => ['type' => 'string'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'update_plan_item',
                'description' => 'Update weekly plan item',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['item_id', 'fields'],
                    'properties' => [
                        'item_id' => ['type' => 'integer'],
                        'fields' => ['type' => 'object'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'delete_plan_item',
                'description' => 'Delete weekly plan item after confirmation',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['item_id', 'confirm'],
                    'properties' => [
                        'item_id' => ['type' => 'integer'],
                        'confirm' => ['type' => 'boolean'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'get_plan_summary',
                'description' => 'Get weekly summary',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['week_start_date' => ['type' => 'string']],
                ],
            ],
            [
                'name' => 'list_team_members',
                'description' => 'List company members',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'list_projects',
                'description' => 'List all projects in the company',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'get_project',
                'description' => 'Get project by id including members list',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['project_id'],
                    'properties' => [
                        'project_id' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'create_project',
                'description' => 'Create a new project',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name'        => ['type' => 'string', 'description' => 'Project name'],
                        'description' => ['type' => 'string', 'description' => 'Optional description'],
                        'dry_run'     => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'update_project',
                'description' => 'Update project fields (name, description, status)',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['project_id', 'fields'],
                    'properties' => [
                        'project_id' => ['type' => 'integer'],
                        'fields' => [
                            'type' => 'object',
                            'properties' => [
                                'name'        => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'status'      => ['type' => 'string', 'enum' => ['active', 'archived']],
                            ],
                        ],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'name' => 'delete_project',
                'description' => 'Delete a project by id',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['project_id'],
                    'properties' => [
                        'project_id' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'get_dashboard_summary',
                'description' => 'Get dashboard summary',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'period' => ['type' => 'string', 'enum' => ['today', 'week', 'month']],
                    ],
                ],
            ],
            [
                'name' => 'get_system_description',
                'description' => 'Returns a detailed description of the Task Tracker system: what it is, what problems it solves, all features, how it works step by step, and pricing. Call this when the user asks "what can you do?", "what is this system?", "describe yourself", or similar questions about system capabilities.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
        ];
    }

    private function systemDescriptionText(): string
    {
        return <<<'TEXT'
# Task Tracker — система планування і контролю для вашої команди

## Яку проблему вирішує система

Задачі ставляться, дедлайни є, люди бігають цілий день — а в кінці тижня головне знову не зроблено. Дрібниці закриті, важливе перенесено. Ви знову пояснюєте, нагадуєте, контролюєте вручну.

**Симптоми:**
- Не розумієте, чим команда займається прямо зараз
- Один і той самий результат доводиться пояснювати по кілька разів
- Важливі задачі переносяться з тижня на тиждень — а дрібниці якось завжди виконуються
- Ви досі «нагадувач» — смикаєте кожного: «Ну що там?»

**Причина:** більшість систем показують список задач з дедлайнами. Але дедлайн не відповідає на питання: коли саме людина це зробить? Скільки часу це займе? Чи встигне вона взагалі сьогодні?

---

## Що таке Task Tracker і чим він відрізняється

Task Tracker — це не черговий список задач. Це інструмент, який змушує кожного працівника самостійно скласти **реалістичний план** — і взяти за нього відповідальність. Ви більше не розподіляєте задачі вручну і не нагадуєте. Система робить це за вас.

---

## Що ви отримуєте

✅ **Денний і тижневий план для кожного** — кожен працівник бачить свої задачі на конкретний день з часом виконання, не просто дедлайном

✅ **Прозорість без дзвінків** — ви бачите, хто чим займається і чи встигне до кінця дня, без запитань і нарад

✅ **Очікуваний результат у кожній задачі** — не «зробити звіт», а «звіт у такому форматі, надісланий керівнику до п'ятниці»

✅ **Щотижневий план-факт** — одразу видно, хто імітує зайнятість, а хто реально перевантажений

✅ **AI-бот у Telegram** — можна надиктувати задачу голосом на ходу, бот сам запитає результат і час, збереже в систему і відправить виконавцю

✅ **Шаблони для повторюваних задач** — щоденні та щотижневі рутини додаються автоматично

✅ **Нагадування при прострочуванні** — виконавець отримує автоматичне нагадування, а не ви

✅ **Проекти** — задачі можна групувати по проектах для зручності

---

## Як це працює — 4 кроки

1. **Працівник складає план** — сам вносить задачі на тиждень з часом виконання і очікуваним результатом. Якщо задач більше ніж часу — система одразу це покаже.

2. **Ви затверджуєте за 5 хвилин** — переглядаєте плани, розставляєте пріоритети де треба — і займаєтеся своїм. Без годинних планерок.

3. **Система контролює без вас** — якщо задача не виконана, працівник отримує нагадування. Наприкінці тижня автоматично формується звіт план-факт.

4. **З кожним тижнем точніше** — система накопичує факти: хто планує реалістично, хто переносить. Хаосу стає менше — системності більше.

---

## Повний список функцій системи

### Задачі
- Створення задачі з назвою, описом, очікуваним результатом і часом виконання
- Призначення виконавця
- Прив'язка до результату/цілі
- Дедлайн і статуси: відкрита, в роботі, виконана, скасована
- Фіксація фактичного результату при закритті

### Тижневий план
- Денний і тижневий вигляд задач для кожного
- Підрахунок загального запланованого часу
- Копіювання дня (перенесення невиконаних задач)
- Автоматичне підвантаження шаблонів
- Імпорт з Google Calendar

### План-факт
- Автоматичне порівняння запланованого і виконаного
- Щотижнева звітність команди
- Видно хто перевантажений, а хто недовантажений

### Результати (цілі)
- Ієрархія результатів (батьківські і дочірні)
- Прив'язка задач до результатів
- Статус виконання

### Шаблони
- Щоденні, щотижневі, щомісячні повторювані задачі
- Масове додавання в план

### AI-бот у Telegram
- Голосове або текстове додавання задач
- Бот сам запитає очікуваний результат і час
- Збереження в систему і відправка виконавцю
- Нагадування про прострочені задачі

### Команда та проекти
- Управління учасниками компанії
- Групування задач по проектах
- Перегляд навантаження команди

---

## Приклад з практики

Компанія з теплоізоляції — власник роздавав задачі усно, прямо в коридорі. Ніхто не знав пріоритетів, важливі речі губились, дедлайни зривались.

Після впровадження системи нецільові витрати часу скоротились, команда стала самостійнішою — а власник вперше отримав чітку картину: хто що робить і чи встигає.

**Результат:** замість щоденного «де що і як» — затвердження плану раз на тиждень за 5 хвилин.

---

## Тарифи

| Тариф | Учасники | Ціна/міс |
|-------|----------|----------|
| Старт | 1–3 особи | $150 |
| Команда 🔥 | 3–9 осіб | $300 |
| Масштаб | 10+ осіб | $500 |

У підписку входить: система + Telegram-бот + інструкції для команди + щотижневий план-факт

---

## Контакти

Олександр Мацук
- Telegram: https://t.me/olexandrmatsuk
- Instagram: https://www.instagram.com/matsukoleksandr/
TEXT;
    }

    private function corsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, HEAD');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, MCP-Protocol-Version');
        header('Access-Control-Expose-Headers: MCP-Protocol-Version');
        header('Access-Control-Max-Age: 86400');
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
}
