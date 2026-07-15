<?php

namespace App\Services;

use App\Models\Task;
use App\Models\Template;

class TemplateTaskMaterializerService
{
    private Template $templateModel;
    private Task $taskModel;

    public function __construct()
    {
        $this->templateModel = new Template();
        $this->taskModel = new Task();
    }

    public function ensureTasksForDate(int $companyId, string $date): int
    {
        return $this->ensureTasksForRange($companyId, $date, $date);
    }

    public function ensureTasksForRange(int $companyId, string $startDate, string $endDate): int
    {
        if ($companyId <= 0) {
            return 0;
        }

        $start = $this->normalizeDate($startDate);
        $end = $this->normalizeDate($endDate);
        if ($start === null || $end === null) {
            return 0;
        }

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        $templates = $this->templateModel->get_by_company($companyId);
        if (empty($templates)) {
            return 0;
        }

        $tasks = $this->taskModel->get_by_company($companyId);
        $existingKeys = [];
        foreach ($tasks as $task) {
            $dueDate = $this->normalizeDate((string) ($task['due_date'] ?? ''));
            $templateId = (int) ($task['template_id'] ?? 0);
            $assigneeId = (int) ($task['assignee_id'] ?? 0);
            if ($dueDate === null || $templateId <= 0 || $assigneeId <= 0) {
                continue;
            }

            $existingKeys[$this->buildExistingKey($templateId, $assigneeId, $dueDate)] = true;
        }

        $created = 0;
        $cursor = strtotime($start);
        $endTs = strtotime($end);

        while ($cursor !== false && $endTs !== false && $cursor <= $endTs) {
            $date = date('Y-m-d', $cursor);

            foreach ($templates as $template) {
                if (!$this->shouldMaterializeTemplateOnDate($template, $date)) {
                    continue;
                }

                $templateId = (int) ($template['id'] ?? 0);
                $assigneeIds = $this->resolveTemplateAssigneeIds($template);
                $reporterId = (int) (($template['reporter_id'] ?? 0) ?: ($assigneeIds[0] ?? 0));
                $title = trim((string) ($template['name'] ?? ''));
                if ($templateId <= 0 || empty($assigneeIds) || $reporterId <= 0 || $title === '') {
                    continue;
                }

                foreach ($assigneeIds as $assigneeId) {
                    $key = $this->buildExistingKey($templateId, $assigneeId, $date);
                    if (isset($existingKeys[$key])) {
                        continue;
                    }

                    $this->taskModel->create([
                        'title' => mb_substr($title, 0, 255),
                        'company_id' => $companyId,
                        'assignee_id' => $assigneeId,
                        'reporter_id' => $reporterId,
                        'status' => 'todo',
                        'due_date' => $this->buildDueDateTime($date, $template['start_time'] ?? null),
                        'description' => $template['description'] ?? null,
                        'expected_result' => $template['expected_result'] ?? null,
                        'expected_time' => $template['expected_time'] ?? null,
                        'type' => $template['type'] ?? 'important-not-urgent',
                        'template_id' => $templateId,
                    ]);

                    $this->templateModel->increment_count($templateId);
                    $existingKeys[$key] = true;
                    $created++;
                }
            }

            $cursor = strtotime('+1 day', $cursor);
        }

        return $created;
    }

    private function shouldMaterializeTemplateOnDate(array $template, string $date): bool
    {
        $repeatType = strtolower(trim((string) ($template['repeat_type'] ?? 'none')));
        if (!in_array($repeatType, ['daily', 'weekly', 'monthly'], true)) {
            return false;
        }

        return match ($repeatType) {
            'daily' => true,
            'weekly' => $this->matchesWeeklyRepeatDay((string) ($template['repeat_day'] ?? ''), $date),
            'monthly' => $this->matchesMonthlyRepeatDay((string) ($template['repeat_day'] ?? ''), $date),
            default => false,
        };
    }

    private function matchesWeeklyRepeatDay(string $repeatDay, string $date): bool
    {
        $normalizedRepeatDays = array_filter(array_map('trim', explode(',', $repeatDay)));
        if (empty($normalizedRepeatDays)) {
            return false;
        }

        $weekday = match ((int) date('N', strtotime($date))) {
            1 => 'Пн',
            2 => 'Вт',
            3 => 'Ср',
            4 => 'Чт',
            5 => 'Пт',
            6 => 'Сб',
            7 => 'Нд',
        };

        return in_array($weekday, $normalizedRepeatDays, true);
    }

    private function matchesMonthlyRepeatDay(string $repeatDay, string $date): bool
    {
        $normalizedDays = array_filter(array_map('trim', explode(',', $repeatDay)));
        if (empty($normalizedDays)) {
            return false;
        }

        $dayOfMonth = (int) date('j', strtotime($date));
        foreach ($normalizedDays as $day) {
            if ((int) $day === $dayOfMonth) {
                return true;
            }
        }

        return false;
    }

    private function resolveTemplateAssigneeIds(array $template): array
    {
        $resolved = [];

        $assigneeIdsRaw = trim((string) ($template['assignee_ids'] ?? ''));
        if ($assigneeIdsRaw !== '') {
            foreach (explode(',', $assigneeIdsRaw) as $part) {
                $id = (int) trim((string) $part);
                if ($id > 0) {
                    $resolved[$id] = $id;
                }
            }
        }

        $singleAssigneeId = (int) ($template['assignee_id'] ?? 0);
        if ($singleAssigneeId > 0) {
            $resolved[$singleAssigneeId] = $singleAssigneeId;
        }

        $reporterId = (int) ($template['reporter_id'] ?? 0);
        if (empty($resolved) && $reporterId > 0) {
            $resolved[$reporterId] = $reporterId;
        }

        return array_values($resolved);
    }

    private function buildExistingKey(int $templateId, int $assigneeId, string $date): string
    {
        return $templateId . ':' . $assigneeId . ':' . $date;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);
        if ($date && $date->format('Y-m-d') === $value) {
            return $value;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function buildDueDateTime(string $date, $startTime): string
    {
        $normalizedStartTime = $this->normalizeStartTime($startTime);
        if ($normalizedStartTime === null) {
            return $date;
        }

        return $date . ' ' . $normalizedStartTime;
    }

    private function normalizeStartTime($value): ?string
    {
        $time = trim((string) $value);
        if ($time === '') {
            return null;
        }

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return $time . ':00';
        }

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $time)) {
            return $time;
        }

        return null;
    }
}