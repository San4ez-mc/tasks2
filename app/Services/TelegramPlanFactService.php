<?php

namespace App\Services;

use App\Models\Task;
use App\Models\WeeklyPlan;

class TelegramPlanFactService
{
    private WeeklyPlan $weeklyPlanModel;
    private Task $taskModel;

    public function __construct()
    {
        $this->weeklyPlanModel = new WeeklyPlan();
        $this->taskModel = new Task();
    }

    public function buildReply(int $companyId, int $reporterId, array $employees, array $args): string
    {
        $weekStart = $this->normalizeWeekStart((string) ($args['weekStart'] ?? ''));
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        $scope = $this->normalizeScope((string) ($args['scope'] ?? 'my'));
        $employeeQuery = trim((string) ($args['employeeQuery'] ?? ''));

        (new TemplateTaskMaterializerService())->ensureTasksForRange($companyId, $weekStart, $weekEnd);

        $visibleEmployees = $this->getScopedEmployees($employees, $reporterId, $scope);
        if ($employeeQuery !== '') {
            $visibleEmployees = $this->filterEmployeesByQuery($visibleEmployees, $employeeQuery);
        }

        if (empty($visibleEmployees)) {
            return $scope === 'subordinates'
                ? 'ℹ️ Для цього запиту не знайшов доступних підлеглих для plan-fact.'
                : 'ℹ️ Не знайшов працівника для цього plan-fact запиту.';
        }

        $plans = $this->weeklyPlanModel->getPlansByCompanyAndWeek($companyId, $weekStart);
        $plansByUserId = [];
        foreach ($plans as $plan) {
            $plansByUserId[(int) ($plan['user_id'] ?? 0)] = $plan;
        }

        $allTasks = $this->taskModel->get_by_company($companyId);
        $weekLabel = date('d.m', strtotime($weekStart)) . ' - ' . date('d.m', strtotime($weekEnd));
        $lines = ['📊 План-факт за тиждень ' . $weekLabel . ':'];

        foreach (array_slice($visibleEmployees, 0, 6) as $employee) {
            $userId = (int) ($employee['user_id'] ?? 0);
            $displayName = $this->buildEmployeeDisplayName($employee);
            $lines[] = '';
            $lines[] = '• ' . $displayName;

            $plan = $plansByUserId[$userId] ?? null;
            if (!$plan) {
                $lines[] = '  План: ще не створено на цей тиждень';
                $lines[] = '  Факт: даних plan-fact поки немає';
                continue;
            }

            $items = $this->weeklyPlanModel->getItems((int) ($plan['id'] ?? 0));
            $weekTasks = $this->filterWeekTasksForUser($allTasks, $userId, $weekStart, $weekEnd);
            $plannedTaskIds = array_values(array_filter(array_map(static function ($item) {
                return (int) ($item['linked_task_id'] ?? 0);
            }, $items)));
            $unplannedTasks = array_values(array_filter($weekTasks, static function ($task) use ($plannedTaskIds) {
                return !in_array((int) ($task['id'] ?? 0), $plannedTaskIds, true);
            }));

            $planStats = $this->buildPlanSummary($items);
            $factStats = $this->buildFactSummary($items, $weekTasks, $unplannedTasks);

            $lines[] = '  План: ' . $planStats['total_items'] . ' задач, ' . $this->formatMinutes((int) $planStats['total_minutes']) . ', виконано ' . $planStats['completion_rate'] . '%';
            $lines[] = '  Факт: ' . $factStats['completed_week_tasks'] . '/' . $factStats['week_task_count'] . ' задач, позапланових ' . $factStats['unplanned_count'] . ', факт часу ' . $this->formatMinutes((int) $factStats['actual_minutes']);

            if ((int) $factStats['variance_minutes'] !== 0) {
                $variancePrefix = (int) $factStats['variance_minutes'] > 0 ? '+' : '';
                $lines[] = '  Відхилення від плану: ' . $variancePrefix . $this->formatMinutes(abs((int) $factStats['variance_minutes']));
            }
        }

        if (count($visibleEmployees) > 6) {
            $lines[] = '';
            $lines[] = '• Ще співробітників: ' . (count($visibleEmployees) - 6);
        }

        return implode("\n", $lines);
    }

    private function filterWeekTasksForUser(array $tasks, int $userId, string $weekStart, string $weekEnd): array
    {
        return array_values(array_filter($tasks, static function ($task) use ($userId, $weekStart, $weekEnd) {
            if ((int) ($task['assignee_id'] ?? 0) !== $userId) {
                return false;
            }

            if (empty($task['due_date'])) {
                return false;
            }

            $dueDate = date('Y-m-d', strtotime((string) $task['due_date']));
            return $dueDate >= $weekStart && $dueDate <= $weekEnd;
        }));
    }

    private function buildPlanSummary(array $items): array
    {
        $totalMinutes = 0;
        $completed = 0;

        foreach ($items as $item) {
            $totalMinutes += (int) ($item['expected_time'] ?? 0);
            $status = strtolower(trim((string) ($item['task_status'] ?? 'todo')));
            if (in_array($status, ['done', 'completed'], true)) {
                $completed++;
            }
        }

        $totalItems = count($items);
        return [
            'total_items' => $totalItems,
            'total_minutes' => $totalMinutes,
            'completion_rate' => $totalItems > 0 ? (int) round(($completed / $totalItems) * 100) : 0,
        ];
    }

    private function buildFactSummary(array $items, array $weekTasks, array $unplannedTasks): array
    {
        $completedWeekTasks = 0;
        $actualMinutes = 0;
        $plannedActualMinutes = 0;
        $plannedExpectedMinutes = 0;

        foreach ($items as $item) {
            $plannedExpectedMinutes += (int) ($item['expected_time'] ?? 0);
            $plannedActualMinutes += (int) ($item['actual_time'] ?? 0);
        }

        foreach ($weekTasks as $task) {
            $status = strtolower(trim((string) ($task['status'] ?? 'todo')));
            if (in_array($status, ['done', 'completed'], true)) {
                $completedWeekTasks++;
            }

            $actualMinutes += (int) ($task['actual_time'] ?? 0);
        }

        return [
            'week_task_count' => count($weekTasks),
            'completed_week_tasks' => $completedWeekTasks,
            'unplanned_count' => count($unplannedTasks),
            'actual_minutes' => $actualMinutes,
            'variance_minutes' => $plannedActualMinutes - $plannedExpectedMinutes,
        ];
    }

    private function getScopedEmployees(array $employees, int $currentUserId, string $scope): array
    {
        $visibleEmployees = array_values(array_filter($employees, static function ($employee) use ($currentUserId) {
            $employeeUserId = (int) ($employee['user_id'] ?? 0);
            $managerId = (int) ($employee['reports_to'] ?? 0);
            return $employeeUserId === $currentUserId || $managerId === $currentUserId;
        }));

        return array_values(array_filter($visibleEmployees, static function ($employee) use ($currentUserId, $scope) {
            $employeeUserId = (int) ($employee['user_id'] ?? 0);
            $managerId = (int) ($employee['reports_to'] ?? 0);

            return match ($scope) {
                'my' => $employeeUserId === $currentUserId,
                'subordinates' => $managerId === $currentUserId,
                default => true,
            };
        }));
    }

    private function filterEmployeesByQuery(array $employees, string $query): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return $employees;
        }

        return array_values(array_filter($employees, static function ($employee) use ($needle) {
            $firstName = mb_strtolower(trim((string) ($employee['first_name'] ?? '')));
            $lastName = mb_strtolower(trim((string) ($employee['last_name'] ?? '')));
            $fullName = trim($firstName . ' ' . $lastName);
            return $firstName === $needle
                || $lastName === $needle
                || $fullName === $needle
                || ($firstName !== '' && str_contains($firstName, $needle))
                || ($lastName !== '' && str_contains($lastName, $needle))
                || ($fullName !== '' && str_contains($fullName, $needle));
        }));
    }

    private function buildEmployeeDisplayName(array $employee): string
    {
        $name = trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? ''));
        return $name !== '' ? $name : ('User #' . (int) ($employee['user_id'] ?? 0));
    }

    private function normalizeScope(string $scope): string
    {
        $normalized = strtolower(trim($scope));
        return in_array($normalized, ['all', 'my', 'subordinates'], true) ? $normalized : 'my';
    }

    private function normalizeWeekStart(string $rawWeekStart): string
    {
        $rawWeekStart = trim($rawWeekStart);
        $timestamp = $rawWeekStart !== '' ? strtotime($rawWeekStart) : time();
        if ($timestamp === false) {
            $timestamp = time();
        }

        return date('Y-m-d', strtotime('monday this week', $timestamp));
    }

    private function formatMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0 хв';
        }

        $hours = intdiv($minutes, 60);
        $restMinutes = $minutes % 60;
        if ($hours <= 0) {
            return $restMinutes . ' хв';
        }

        if ($restMinutes <= 0) {
            return $hours . ' год';
        }

        return $hours . ' год ' . $restMinutes . ' хв';
    }
}