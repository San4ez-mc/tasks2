<?php

namespace App\Services;

class TelegramIntentRouterService
{
    private TelegramMessageClassifierService $messageClassifier;

    public function __construct(?TelegramMessageClassifierService $messageClassifier = null)
    {
        $this->messageClassifier = $messageClassifier ?? new TelegramMessageClassifierService();
    }

    public function routeMessage(string $text): array
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return $this->buildDecision('unknown', 'low', 'empty_text');
        }

        $matchedRoutes = $this->collectDeterministicRouteMatches($normalized);
        if (count($matchedRoutes) > 1) {
            return $this->buildDecision('planner', 'high', 'multi_intent_request');
        }

        if ($this->messageClassifier->isMarkDoneRequest($normalized)) {
            $entityType = $this->messageClassifier->detectMarkDoneEntityType($normalized);
            $route = $entityType === 'goal' ? 'mark_goal_done' : 'mark_task_done';
            return $this->buildDecision($route, 'high', 'deterministic_mark_done', [
                'name' => $route,
                'args' => $this->buildMarkDoneArgsFromText($normalized, $entityType),
            ]);
        }

        if ($this->messageClassifier->isDeleteRequest($normalized)) {
            $deleteEntity = $this->messageClassifier->detectDeleteEntityType($normalized);
            $route = $deleteEntity === 'goal' ? 'delete_goals' : 'delete_tasks';
            return $this->buildDecision($route, 'high', 'deterministic_delete_request', [
                'name' => $route,
                'args' => $this->buildDeleteArgsFromText($normalized),
            ]);
        }

        if ($this->isCompanyCurrentRequest($normalized)) {
            return $this->buildDecision('company_current', 'high', 'deterministic_company_current');
        }

        if ($this->isEmployeeListRequest($normalized)) {
            return $this->buildDecision('employee_list', 'high', 'deterministic_employee_list', [
                'args' => [
                    'scope' => str_contains($normalized, 'підлегл') ? 'subordinates' : 'company',
                ],
            ]);
        }

        if ($this->isCompanyListRequest($normalized)) {
            return $this->buildDecision('company_list', 'high', 'deterministic_company_list');
        }

        if ($this->isCompanySwitchRequest($normalized)) {
            return $this->buildDecision('company_list', 'medium', 'ambiguous_company_switch');
        }

        if ($this->isTemplateListRequest($normalized)) {
            return $this->buildDecision('template_list', 'high', 'deterministic_template_list');
        }

        if ($this->isPlanFactRequest($normalized)) {
            return $this->buildDecision('plan_fact', 'high', 'deterministic_plan_fact', [
                'name' => 'show_plan_fact',
                'args' => $this->buildPlanFactArgsFromText($normalized),
            ]);
        }

        if ($this->isPasswordHelpRequest($normalized)) {
            return $this->buildDecision('password_help', 'high', 'deterministic_password_help');
        }

        if ($this->isLoginLinkRequest($normalized)) {
            return $this->buildDecision('login_link', 'high', 'deterministic_login_link');
        }

        if ($this->messageClassifier->isTaskListRequest($normalized)) {
            return $this->buildDecision('task_list', 'high', 'deterministic_task_list', [
                'name' => 'manage_tasks',
                'args' => $this->buildTaskListArgsFromText($normalized),
            ]);
        }

        if ($this->messageClassifier->isGoalListRequest($normalized)) {
            return $this->buildDecision('goal_list', 'high', 'deterministic_goal_list', [
                'name' => 'list_goals',
                'args' => ['status' => 'all'],
            ]);
        }

        if ($this->looksLikeCorrectionOnlyMessage($normalized)) {
            return $this->buildDecision('correction_only', 'high', 'followup_correction_without_action');
        }

        if ($this->messageClassifier->isProjectCreateRequest($normalized)) {
            return $this->buildDecision('planner', 'high', 'project_create_request');
        }

        if ($this->messageClassifier->isProjectListRequest($normalized)) {
            return $this->buildDecision('project_list', 'high', 'deterministic_project_list');
        }

        if ($this->looksLikeTemplateCreateRequest($normalized)) {
            return $this->buildDecision('planner', 'high', 'template_create_request');
        }

        if ($this->looksLikeExplicitActionRequest($normalized)) {
            return $this->buildDecision('planner', 'high', 'explicit_action_request');
        }

        if (!$this->looksLikeRecognizableRequest($normalized)) {
            return $this->buildDecision('unknown', 'low', 'unrecognizable_text');
        }

        return $this->buildDecision('planner', 'medium', 'recognized_freeform_request');
    }

    private function collectDeterministicRouteMatches(string $text): array
    {
        $matches = [];

        if ($this->messageClassifier->isMarkDoneRequest($text)) {
            $matches[] = 'mark_done';
        }

        if ($this->messageClassifier->isDeleteRequest($text)) {
            $matches[] = 'delete';
        }

        if ($this->isCompanyCurrentRequest($text)) {
            $matches[] = 'company_current';
        }

        if ($this->isEmployeeListRequest($text)) {
            $matches[] = 'employee_list';
        }

        if ($this->isCompanyListRequest($text) || $this->isCompanySwitchRequest($text)) {
            $matches[] = 'company_list';
        }

        if ($this->isTemplateListRequest($text)) {
            $matches[] = 'template_list';
        }

        if ($this->isPlanFactRequest($text)) {
            $matches[] = 'plan_fact';
        }

        if ($this->isPasswordHelpRequest($text)) {
            $matches[] = 'password_help';
        }

        if ($this->isLoginLinkRequest($text)) {
            $matches[] = 'login_link';
        }

        if ($this->messageClassifier->isTaskListRequest($text)) {
            $matches[] = 'task_list';
        }

        if ($this->messageClassifier->isGoalListRequest($text)) {
            $matches[] = 'goal_list';
        }

        if ($this->messageClassifier->isProjectListRequest($text)) {
            $matches[] = 'project_list';
        }

        return array_values(array_unique($matches));
    }

    private function buildDeleteArgsFromText(string $text): array
    {
        $scope = 'my';
        if (str_contains($text, 'делегован')) {
            $scope = 'delegated';
        } elseif (str_contains($text, 'підлегл')) {
            $scope = 'subordinates';
        }

        $status = 'all';
        if (str_contains($text, 'заверш') || str_contains($text, 'done') || str_contains($text, 'виконан')) {
            $status = 'done';
        } elseif (str_contains($text, 'актив') || str_contains($text, 'відкрит')) {
            $status = 'active';
        }

        $date = 'today';
        if (str_contains($text, 'завтра')) {
            $date = 'tomorrow';
        } elseif (str_contains($text, 'вчора')) {
            $date = 'yesterday';
        }

        return [
            'scope' => $scope,
            'status' => $status,
            'date' => $date,
        ];
    }

    private function buildMarkDoneArgsFromText(string $text, string $entityType): array
    {
        $scope = 'my';
        if (str_contains($text, 'делегован')) {
            $scope = 'delegated';
        } elseif (str_contains($text, 'підлегл')) {
            $scope = 'subordinates';
        }

        $date = 'today';
        if (str_contains($text, 'завтра')) {
            $date = 'tomorrow';
        } elseif (str_contains($text, 'вчора')) {
            $date = 'yesterday';
        }

        return [
            'entity_type' => $entityType,
            'scope' => $scope,
            'date' => $date,
        ];
    }

    private function buildDecision(string $route, string $confidence, string $reason, ?array $command = null): array
    {
        return [
            'route' => $route,
            'confidence' => $confidence,
            'reason' => $reason,
            'command' => $command,
        ];
    }

    private function buildTaskListArgsFromText(string $text): array
    {
        $scope = 'my';
        if (str_contains($text, 'делегован')) {
            $scope = 'delegated';
        } elseif (str_contains($text, 'підлегл')) {
            $scope = 'subordinates';
        }

        $status = 'active';
        if (str_contains($text, 'всі')) {
            $status = 'all';
        } elseif (str_contains($text, 'заверш')) {
            $status = 'done';
        } elseif (str_contains($text, 'відклад')) {
            $status = 'postponed';
        }

        $date = 'today';
        if (str_contains($text, 'завтра')) {
            $date = 'tomorrow';
        }

        return [
            'action' => 'list',
            'scope' => $scope,
            'status' => $status,
            'date' => $date,
        ];
    }

    private function buildPlanFactArgsFromText(string $text): array
    {
        $scope = str_contains($text, 'підлегл') || str_contains($text, 'команд') ? 'subordinates' : 'my';

        if (str_contains($text, 'все') || str_contains($text, 'всіх')) {
            $scope = 'all';
        }

        $employeeQuery = null;
        if (preg_match('/\b(?:по|для)\s+([\p{L}\s\-]+)$/u', $text, $match)) {
            $candidate = trim((string) $match[1]);
            if ($candidate !== '' && !preg_match('/^(мені|мене|собі|себе|підлегл(?:их|ому|ими)?|команд[аи]|всіх)$/ui', $candidate)) {
                $employeeQuery = $candidate;
            }
        }

        $weekStart = date('Y-m-d', strtotime('monday this week'));
        if (str_contains($text, 'наступн') && str_contains($text, 'тиж')) {
            $weekStart = date('Y-m-d', strtotime('monday next week'));
        }

        return array_filter([
            'scope' => $scope,
            'employeeQuery' => $employeeQuery,
            'weekStart' => $weekStart,
        ], static fn($value) => $value !== null && $value !== '');
    }

    private function looksLikeRecognizableRequest(string $text): bool
    {
        if ($text === '' || mb_strlen($text) < 3) {
            return false;
        }

        preg_match_all('/[\p{L}\p{N}]/u', $text, $lettersAndNumbers);
        if (count($lettersAndNumbers[0]) < 3) {
            return false;
        }

        preg_match_all('/[\p{L}]{2,}/u', $text, $words);
        if (count($words[0]) === 0) {
            return false;
        }

        preg_match_all('/[^\p{L}\p{N}\s]/u', $text, $symbols);
        if (count($symbols[0]) > count($lettersAndNumbers[0])) {
            return false;
        }

        if (preg_match('/^(.)\1{3,}$/u', $text)) {
            return false;
        }

        return true;
    }

    private function looksLikeCorrectionOnlyMessage(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        if (!preg_match('/^не\s+.+?,\s*а\s+.+$/ui', $text)) {
            return false;
        }

        $actionMarkers = ['створи', 'додай', 'задач', 'таск', 'ціл', 'підціл', 'шаблон', 'покажи', 'виведи'];
        foreach ($actionMarkers as $marker) {
            if (str_contains($text, $marker)) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeTemplateCreateRequest(string $text): bool
    {
        $hasTemplateWord = str_contains($text, 'шаблон') || str_contains($text, 'template');
        if (!$hasTemplateWord) {
            return false;
        }

        $createMarkers = ['створи', 'створити', 'додай', 'додати', 'заведи', 'зроби', 'новий'];
        foreach ($createMarkers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeExplicitActionRequest(string $text): bool
    {
        $markers = [
            'створи',
            'створити',
            'додай',
            'додати',
            'заведи',
            'зроби',
            'постав',
            'онови',
            'зміни',
            'відредагуй',
            'перенеси',
            'познач',
            'заверши',
            'виконай',
            'признач',
            'прикріпи',
            'створимо',
            'створи мені',
            'додай мені',
        ];

        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isTemplateListRequest(string $text): bool
    {
        $hasTemplateWord = str_contains($text, 'шаблон') || str_contains($text, 'template');
        if (!$hasTemplateWord) {
            return false;
        }

        if ($this->looksLikeTemplateCreateRequest($text)) {
            return false;
        }

        $listMarkers = ['список', 'покажи', 'показати', 'дай', 'які', 'доступн', 'виведи', 'відобрази'];
        foreach ($listMarkers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isCompanyCurrentRequest(string $text): bool
    {
        if (!str_contains($text, 'компан')) {
            return false;
        }

        $markers = ['поточн', 'активн', 'зараз', 'якій', 'в якій', 'в якой', 'працюю', 'сиджу'];
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isCompanyListRequest(string $text): bool
    {
        if (!str_contains($text, 'компан')) {
            return false;
        }

        if (str_contains($text, 'люд') || str_contains($text, 'співроб') || str_contains($text, 'працівн') || str_contains($text, 'команд')) {
            return false;
        }

        $markers = ['список', 'які', 'мої', 'доступн', 'покажи', 'показати', 'дай', 'обрати', 'вибрати'];
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isCompanySwitchRequest(string $text): bool
    {
        if (!str_contains($text, 'компан')) {
            return false;
        }

        $markers = ['перемкни', 'переключи', 'переключай', 'зміни', 'обери', 'вибери', 'працюй з'];
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isPlanFactRequest(string $text): bool
    {
        $hasPlanWord = str_contains($text, 'план');
        $hasFactWord = str_contains($text, 'факт') || str_contains($text, 'plan-fact') || str_contains($text, 'план-факт');

        return $hasPlanWord && $hasFactWord;
    }

    private function isEmployeeListRequest(string $text): bool
    {
        $nonEmployeeEntityWords = ['задач', 'задачу', 'таск', 'ціл', 'план', 'факт', 'шаблон', 'template'];
        foreach ($nonEmployeeEntityWords as $word) {
            if (str_contains($text, $word)) {
                return false;
            }
        }

        $employeeWords = ['підлегл', 'співроб', 'працівн', 'людей', 'люди', 'команд', 'користувач'];
        $hasEmployeeWord = false;
        foreach ($employeeWords as $word) {
            if (str_contains($text, $word)) {
                $hasEmployeeWord = true;
                break;
            }
        }

        if (!$hasEmployeeWord) {
            return false;
        }

        $listMarkers = ['список', 'покажи', 'показати', 'дай', 'виведи', 'хто', 'які'];
        foreach ($listMarkers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return str_contains($text, 'мої підлегл') || str_contains($text, 'в моїй компан') || str_contains($text, 'у моїй компан');
    }

    private function isLoginLinkRequest(string $text): bool
    {
        $markers = ['посилання на вхід', 'посилання для входу', 'посилання на систему', 'посилання на платформу', 'увійти', 'вхід', 'login', 'log in', 'зайти в систему', 'зайти на платформу', 'відновити доступ'];
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isPasswordHelpRequest(string $text): bool
    {
        $markers = ['змінити пароль', 'зміни пароль', 'поміняти пароль', 'оновити пароль', 'скинути пароль', 'відновити пароль'];
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}