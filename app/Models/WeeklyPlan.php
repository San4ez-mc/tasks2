<?php

namespace App\Models;

class WeeklyPlan
{
    private Database $db;
    private ?array $taskColumns = null;

    public function __construct()
    {
        $this->db = new Database();
        $this->ensureTables();
    }

    public function getPlansByCompanyAndWeek(int $companyId, string $weekStartDate): array
    {
        return $this->db
            ->query('SELECT wp.*, 
                            u.first_name, u.last_name,
                            cb.first_name AS created_by_first_name,
                            cb.last_name AS created_by_last_name
                     FROM weekly_plans wp
                     JOIN users u ON wp.user_id = u.id
                     LEFT JOIN users cb ON wp.created_by_id = cb.id
                     WHERE wp.company_id = :company_id AND wp.week_start_date = :week_start_date
                     ORDER BY u.first_name ASC, u.last_name ASC, wp.created_at DESC')
            ->bind(':company_id', $companyId)
            ->bind(':week_start_date', $weekStartDate)
            ->fetchAll();
    }

    public function getPlansByCompany(int $companyId): array
    {
        return $this->db
            ->query('SELECT wp.*, 
                            u.first_name, u.last_name,
                            cb.first_name AS created_by_first_name,
                            cb.last_name AS created_by_last_name
                     FROM weekly_plans wp
                     JOIN users u ON wp.user_id = u.id
                     LEFT JOIN users cb ON wp.created_by_id = cb.id
                     WHERE wp.company_id = :company_id
                     ORDER BY wp.week_start_date DESC, u.first_name ASC, u.last_name ASC, wp.created_at DESC')
            ->bind(':company_id', $companyId)
            ->fetchAll();
    }

    public function findByUserAndWeek(int $companyId, int $userId, string $weekStartDate): ?array
    {
        $plan = $this->db
            ->query('SELECT * FROM weekly_plans WHERE company_id = :company_id AND user_id = :user_id AND week_start_date = :week_start_date LIMIT 1')
            ->bind(':company_id', $companyId)
            ->bind(':user_id', $userId)
            ->bind(':week_start_date', $weekStartDate)
            ->fetch();

        return $plan ?: null;
    }

    public function getById(int $companyId, int $planId): ?array
    {
        $plan = $this->db
            ->query('SELECT wp.*, 
                            u.first_name, u.last_name,
                            cb.first_name AS created_by_first_name,
                            cb.last_name AS created_by_last_name
                     FROM weekly_plans wp
                     JOIN users u ON wp.user_id = u.id
                     LEFT JOIN users cb ON wp.created_by_id = cb.id
                     WHERE wp.company_id = :company_id AND wp.id = :id
                     LIMIT 1')
            ->bind(':company_id', $companyId)
            ->bind(':id', $planId)
            ->fetch();

        return $plan ?: null;
    }

    public function createPlan(int $companyId, int $userId, int $createdById, string $weekStartDate, string $notes = ''): int
    {
        $existing = $this->findByUserAndWeek($companyId, $userId, $weekStartDate);
        if ($existing) {
            return (int) $existing['id'];
        }

        $weekEndDate = date('Y-m-d', strtotime($weekStartDate . ' +6 days'));

        $this->db->insert('weekly_plans', [
            'company_id' => $companyId,
            'user_id' => $userId,
            'created_by_id' => $createdById,
            'week_start_date' => $weekStartDate,
            'week_end_date' => $weekEndDate,
            'notes' => $notes !== '' ? $notes : null,
            'status' => 'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function getItems(int $planId): array
    {
        return $this->db
            ->query('SELECT wpi.*, 
                            t.status AS task_status,
                            t.due_date AS task_due_date,
                            t.actual_result,
                            t.actual_time,
                            t.expected_result AS task_expected_result,
                            t.expected_time AS task_expected_time,
                            t.type AS task_type,
                            t.title AS task_title,
                            tpl.name AS template_name,
                            a.first_name AS assignee_first_name,
                            a.last_name AS assignee_last_name
                     FROM weekly_plan_items wpi
                     LEFT JOIN tasks t ON wpi.linked_task_id = t.id
                     LEFT JOIN templates tpl ON wpi.source_template_id = tpl.id
                     LEFT JOIN users a ON wpi.assignee_id = a.id
                     WHERE wpi.weekly_plan_id = :weekly_plan_id
                     ORDER BY wpi.planned_date ASC, wpi.sort_order ASC, wpi.id ASC')
            ->bind(':weekly_plan_id', $planId)
            ->fetchAll();
    }

    public function getItemById(int $planId, int $itemId): ?array
    {
        $item = $this->db
            ->query('SELECT * FROM weekly_plan_items WHERE weekly_plan_id = :weekly_plan_id AND id = :id LIMIT 1')
            ->bind(':weekly_plan_id', $planId)
            ->bind(':id', $itemId)
            ->fetch();

        return $item ?: null;
    }

    public function getItemByLinkedTaskId(int $taskId): ?array
    {
        $item = $this->db
            ->query('SELECT wpi.*, 
                            wp.company_id AS plan_company_id,
                            wp.user_id AS plan_user_id,
                            wp.week_start_date,
                            wp.week_end_date
                     FROM weekly_plan_items wpi
                     JOIN weekly_plans wp ON wp.id = wpi.weekly_plan_id
                     WHERE wpi.linked_task_id = :linked_task_id
                     LIMIT 1')
            ->bind(':linked_task_id', $taskId)
            ->fetch();

        return $item ?: null;
    }

    public function findPlanContainingDate(int $companyId, int $userId, string $date): ?array
    {
        $plan = $this->db
            ->query('SELECT *
                     FROM weekly_plans
                     WHERE company_id = :company_id
                       AND user_id = :user_id
                       AND week_start_date <= :task_date
                       AND week_end_date >= :task_date
                     ORDER BY week_start_date DESC
                     LIMIT 1')
            ->bind(':company_id', $companyId)
            ->bind(':user_id', $userId)
            ->bind(':task_date', $date)
            ->fetch();

        return $plan ?: null;
    }

    public function addManualItem(array $plan, array $data): int
    {
        $payload = [
            'planned_date' => $data['planned_date'],
            'start_time' => $data['start_time'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assignee_id' => (int) ($data['assignee_id'] ?? 0),
            'reporter_id' => (int) ($data['reporter_id'] ?? 0),
            'type' => $data['type'] ?? 'important-not-urgent',
            'expected_result' => $data['expected_result'] ?? null,
            'expected_time' => $data['expected_time'] ?? null,
            'source_template_id' => $data['source_template_id'] ?? null,
            'result_id' => $data['result_id'] ?? null,
            'project_id' => isset($data['project_id']) && (int) $data['project_id'] > 0 ? (int) $data['project_id'] : null,
            'external_source' => $data['external_source'] ?? null,
            'external_reference' => $data['external_reference'] ?? null,
        ];

        return $this->persistPlanItem($plan, $payload);
    }

    public function importExternalItems(array $plan, array $items, int $reporterId): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $itemId = $this->addManualItem($plan, [
                'planned_date' => $item['planned_date'] ?? null,
                'start_time' => $item['start_time'] ?? null,
                'title' => $item['title'] ?? '',
                'description' => $item['description'] ?? null,
                'assignee_id' => (int) ($item['assignee_id'] ?? ($plan['user_id'] ?? 0)),
                'reporter_id' => $reporterId,
                'type' => $item['type'] ?? 'important-not-urgent',
                'expected_result' => $item['expected_result'] ?? null,
                'expected_time' => $item['expected_time'] ?? null,
                'result_id' => $item['result_id'] ?? null,
                'external_source' => $item['external_source'] ?? null,
                'external_reference' => $item['external_reference'] ?? null,
            ]);

            if ($itemId > 0) {
                $created++;
            } else {
                $skipped++;
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    public function addItemsFromTemplates(array $plan, array $templates, string $plannedDate, int $reporterId): void
    {
        foreach ($templates as $template) {
            if (!is_array($template)) {
                continue;
            }

            $title = trim((string) ($template['name'] ?? ''));
            if ($title === '') {
                continue;
            }

            $this->persistPlanItem($plan, [
                'planned_date' => $plannedDate,
                'start_time' => $template['start_time'] ?? null,
                'title' => $title,
                'description' => $template['description'] ?? null,
                'assignee_id' => (int) (($template['assignee_id'] ?? 0) ?: ($plan['user_id'] ?? 0)),
                'reporter_id' => $reporterId,
                'type' => $template['type'] ?? 'important-not-urgent',
                'expected_result' => $template['expected_result'] ?? null,
                'expected_time' => $template['expected_time'] ?? null,
                'source_template_id' => (int) ($template['id'] ?? 0),
            ]);
        }
    }

    public function copyDay(array $plan, string $sourceDate, string $targetDate, int $reporterId, array $selectedItemIds = []): int
    {
        $items = array_values(array_filter($this->getItems((int) $plan['id']), static function ($item) use ($sourceDate, $selectedItemIds) {
            if ((string) ($item['planned_date'] ?? '') !== $sourceDate) {
                return false;
            }
            if (!empty($selectedItemIds) && !in_array((int) ($item['id'] ?? 0), $selectedItemIds, true)) {
                return false;
            }
            return true;
        }));

        $copied = 0;
        foreach ($items as $item) {
            $this->persistPlanItem($plan, [
                'planned_date' => $targetDate,
                'start_time' => $item['start_time'] ?? null,
                'title' => (string) ($item['title'] ?? ''),
                'description' => $item['description'] ?? null,
                'assignee_id' => (int) (($item['assignee_id'] ?? 0) ?: ($plan['user_id'] ?? 0)),
                'reporter_id' => $reporterId,
                'type' => $item['type'] ?? 'important-not-urgent',
                'expected_result' => $item['expected_result'] ?? null,
                'expected_time' => $item['expected_time'] ?? null,
                'source_template_id' => $item['source_template_id'] ?? null,
            ]);
            $copied++;
        }

        return $copied;
    }

    public function updateItem(array $plan, int $itemId, array $data): bool
    {
        $item = $this->getItemById((int) $plan['id'], $itemId);
        if (!$item) {
            return false;
        }

        $plannedDate = (string) ($data['planned_date'] ?? $item['planned_date'] ?? '');
        $title = trim((string) ($data['title'] ?? $item['title'] ?? ''));
        $assigneeId = (int) (($data['assignee_id'] ?? $item['assignee_id'] ?? 0) ?: ($plan['user_id'] ?? 0));
        $reporterId = (int) (($data['reporter_id'] ?? $item['reporter_id'] ?? 0) ?: ($plan['created_by_id'] ?? $plan['user_id'] ?? 0));

        if ($plannedDate === '' || $title === '' || $assigneeId <= 0 || $reporterId <= 0) {
            return false;
        }

        $payload = [
            'planned_date' => $plannedDate,
            'start_time' => $data['start_time'] ?? ($item['start_time'] ?? null),
            'weekday_index' => $this->weekdayIndex($plannedDate),
            'title' => mb_substr($title, 0, 255),
            'description' => $data['description'] ?? null,
            'assignee_id' => $assigneeId,
            'reporter_id' => $reporterId,
            'type' => $data['type'] ?? 'important-not-urgent',
            'expected_result' => $data['expected_result'] ?? null,
            'expected_time' => $data['expected_time'] !== null && $data['expected_time'] !== '' ? (int) $data['expected_time'] : null,
        ];

        $projectId = isset($data['project_id']) && (int) $data['project_id'] > 0 ? (int) $data['project_id'] : null;

        $this->db->beginTransaction();
        try {
            $this->db->update('weekly_plan_items', $itemId, $payload);

            $linkedTaskId = (int) ($item['linked_task_id'] ?? 0);
            if ($linkedTaskId > 0) {
                $taskPayload = $this->filterTaskColumns([
                    'title' => $payload['title'],
                    'assignee_id' => $assigneeId,
                    'reporter_id' => $reporterId,
                    'due_date' => $this->buildDueDateTime($plannedDate, $payload['start_time'] ?? null),
                    'description' => $payload['description'],
                    'expected_result' => $payload['expected_result'],
                    'type' => $payload['type'],
                    'expected_time' => $payload['expected_time'],
                    'project_id' => $projectId,
                ]);

                if (!empty($taskPayload)) {
                    $this->db->update('tasks', $linkedTaskId, $taskPayload);
                }
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function deleteItem(array $plan, int $itemId): bool
    {
        $item = $this->getItemById((int) $plan['id'], $itemId);
        if (!$item) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $linkedTaskId = (int) ($item['linked_task_id'] ?? 0);
            $this->db->delete('weekly_plan_items', $itemId);

            if ($linkedTaskId > 0) {
                $this->db->delete('tasks', $linkedTaskId);
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function deletePlan(array $plan): bool
    {
        $planId = (int) ($plan['id'] ?? 0);
        if ($planId <= 0) {
            return false;
        }

        $items = $this->getItems($planId);

        $this->db->beginTransaction();
        try {
            foreach ($items as $item) {
                $itemId = (int) ($item['id'] ?? 0);
                $linkedTaskId = (int) ($item['linked_task_id'] ?? 0);

                if ($itemId > 0) {
                    $this->db->delete('weekly_plan_items', $itemId);
                }

                if ($linkedTaskId > 0) {
                    $this->db->delete('tasks', $linkedTaskId);
                }
            }

            $this->db->delete('weekly_plans', $planId);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function ensureTemplateTasksIncludedInPlan(array $plan, array $tasks): int
    {
        $planId = (int) ($plan['id'] ?? 0);
        if ($planId <= 0) {
            return 0;
        }

        $existingItems = $this->getItems($planId);
        $existingLinkedTaskIds = [];
        $maxSortByDate = [];

        foreach ($existingItems as $item) {
            $linkedTaskId = (int) ($item['linked_task_id'] ?? 0);
            if ($linkedTaskId > 0) {
                $existingLinkedTaskIds[$linkedTaskId] = true;
            }

            $plannedDate = (string) ($item['planned_date'] ?? '');
            if ($plannedDate !== '') {
                $maxSortByDate[$plannedDate] = max((int) ($maxSortByDate[$plannedDate] ?? 0), (int) ($item['sort_order'] ?? 0));
            }
        }

        $created = 0;
        foreach ($tasks as $task) {
            if (!$this->isTemplateTaskEligibleForPlan($plan, $task)) {
                continue;
            }

            $taskId = (int) ($task['id'] ?? 0);
            if ($taskId <= 0 || isset($existingLinkedTaskIds[$taskId])) {
                continue;
            }

            $plannedDate = $this->extractTaskDate($task);
            if ($plannedDate === null) {
                continue;
            }

            $maxSortByDate[$plannedDate] = (int) ($maxSortByDate[$plannedDate] ?? 0) + 1;
            $payload = $this->buildPlanItemPayloadFromTask($plan, $task, [
                'sort_order' => $maxSortByDate[$plannedDate],
            ]);

            $this->db->insert('weekly_plan_items', $payload);
            $existingLinkedTaskIds[$taskId] = true;
            $created++;
        }

        return $created;
    }

    public function syncItemFromTask(array $task): bool
    {
        $taskId = (int) ($task['id'] ?? 0);
        if ($taskId <= 0) {
            return false;
        }

        $item = $this->getItemByLinkedTaskId($taskId);
        if (!$item) {
            $taskDate = $this->extractTaskDate($task);
            $companyId = (int) ($task['company_id'] ?? 0);
            $assigneeId = (int) ($task['assignee_id'] ?? 0);
            if ((int) ($task['template_id'] ?? 0) <= 0 || $taskDate === null || $companyId <= 0 || $assigneeId <= 0) {
                return false;
            }

            $plan = $this->findPlanContainingDate($companyId, $assigneeId, $taskDate);
            if (!$plan) {
                return false;
            }

            return $this->ensureTemplateTasksIncludedInPlan($plan, [$task]) > 0;
        }

        $resolvedTitle = $this->resolvePlanItemTitle($task, (string) ($item['title'] ?? ''));
        $payload = [
            'title' => $resolvedTitle,
            'description' => $task['description'] ?? null,
            'type' => $task['type'] ?? ($item['type'] ?? 'important-not-urgent'),
            'expected_result' => $task['expected_result'] ?? null,
            'expected_time' => $task['expected_time'] !== null && $task['expected_time'] !== '' ? (int) $task['expected_time'] : null,
        ];

        $taskDate = $this->extractTaskDate($task);
        $planUserId = (int) ($item['plan_user_id'] ?? 0);
        if (
            $taskDate !== null
            && $planUserId > 0
            && (int) ($task['assignee_id'] ?? 0) === $planUserId
            && $taskDate >= (string) ($item['week_start_date'] ?? '')
            && $taskDate <= (string) ($item['week_end_date'] ?? '')
        ) {
            $payload['planned_date'] = $taskDate;
            $payload['start_time'] = $this->normalizeStartTimeValue($this->extractTaskTime($task));
            $payload['weekday_index'] = $this->weekdayIndex($taskDate);
        }

        return $this->db->update('weekly_plan_items', (int) $item['id'], $payload);
    }

    public function deleteItemByLinkedTaskId(int $taskId): bool
    {
        $item = $this->getItemByLinkedTaskId($taskId);
        if (!$item) {
            return false;
        }

        return $this->db->delete('weekly_plan_items', (int) ($item['id'] ?? 0));
    }

    private function persistPlanItem(array $plan, array $payload): int
    {
        $assigneeId = (int) (($payload['assignee_id'] ?? 0) ?: ($plan['user_id'] ?? 0));
        $reporterId = (int) (($payload['reporter_id'] ?? 0) ?: ($plan['created_by_id'] ?? $plan['user_id'] ?? 0));
        $plannedDate = (string) ($payload['planned_date'] ?? '');
        $title = trim((string) ($payload['title'] ?? ''));
        $externalSource = trim((string) ($payload['external_source'] ?? ''));
        $externalReference = trim((string) ($payload['external_reference'] ?? ''));

        if ($assigneeId <= 0 || $reporterId <= 0 || $plannedDate === '' || $title === '') {
            return 0;
        }

        if ($externalSource !== '' && $externalReference !== '') {
            $existingItem = $this->findItemByExternalReference((int) ($plan['id'] ?? 0), $externalSource, $externalReference);
            if ($existingItem) {
                return 0;
            }
        }

        $this->db->beginTransaction();

        try {
            $sortOrderRow = $this->db
                ->query('SELECT COALESCE(MAX(sort_order), 0) AS max_sort FROM weekly_plan_items WHERE weekly_plan_id = :weekly_plan_id AND planned_date = :planned_date')
                ->bind(':weekly_plan_id', (int) $plan['id'])
                ->bind(':planned_date', $plannedDate)
                ->fetch();

            $sortOrder = (int) ($sortOrderRow['max_sort'] ?? 0) + 1;

            $taskId = $this->createLinkedTask($plan, [
                'title' => $title,
                'description' => $payload['description'] ?? null,
                'assignee_id' => $assigneeId,
                'reporter_id' => $reporterId,
                'planned_date' => $plannedDate,
                'start_time' => $payload['start_time'] ?? null,
                'type' => $payload['type'] ?? 'important-not-urgent',
                'expected_result' => $payload['expected_result'] ?? null,
                'expected_time' => $payload['expected_time'] ?? null,
                'source_template_id' => $payload['source_template_id'] ?? null,
                'result_id' => $payload['result_id'] ?? null,
                'project_id' => $payload['project_id'] ?? null,
            ]);

            $this->db->insert('weekly_plan_items', [
                'weekly_plan_id' => (int) $plan['id'],
                'linked_task_id' => $taskId > 0 ? $taskId : null,
                'source_template_id' => !empty($payload['source_template_id']) ? (int) $payload['source_template_id'] : null,
                'external_source' => $externalSource !== '' ? $externalSource : null,
                'external_reference' => $externalReference !== '' ? $externalReference : null,
                'planned_date' => $plannedDate,
                'start_time' => $this->normalizeStartTimeValue($payload['start_time'] ?? null),
                'weekday_index' => $this->weekdayIndex($plannedDate),
                'sort_order' => $sortOrder,
                'title' => mb_substr($title, 0, 255),
                'description' => $payload['description'] ?? null,
                'assignee_id' => $assigneeId,
                'reporter_id' => $reporterId,
                'type' => $payload['type'] ?? 'important-not-urgent',
                'expected_result' => $payload['expected_result'] ?? null,
                'expected_time' => $payload['expected_time'] !== null && $payload['expected_time'] !== '' ? (int) $payload['expected_time'] : null,
            ]);

            $itemId = (int) $this->db->lastInsertId();
            $this->db->commit();

            return $itemId;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function findItemByExternalReference(int $planId, string $source, string $reference): ?array
    {
        if ($planId <= 0 || $source === '' || $reference === '') {
            return null;
        }

        $item = $this->db
            ->query('SELECT *
                     FROM weekly_plan_items
                     WHERE weekly_plan_id = :weekly_plan_id
                       AND external_source = :external_source
                       AND external_reference = :external_reference
                     LIMIT 1')
            ->bind(':weekly_plan_id', $planId)
            ->bind(':external_source', $source)
            ->bind(':external_reference', $reference)
            ->fetch();

        return $item ?: null;
    }

    private function createLinkedTask(array $plan, array $payload): int
    {
        $taskPayload = [
            'title' => mb_substr((string) $payload['title'], 0, 255),
            'company_id' => (int) $plan['company_id'],
            'assignee_id' => (int) $payload['assignee_id'],
            'reporter_id' => (int) $payload['reporter_id'],
            'status' => 'todo',
            'due_date' => $this->buildDueDateTime((string) $payload['planned_date'], $payload['start_time'] ?? null),
            'description' => $payload['description'] ?? null,
            'expected_result' => $payload['expected_result'] ?? null,
            'type' => $payload['type'] ?: 'important-not-urgent',
            'expected_time' => $payload['expected_time'] !== null && $payload['expected_time'] !== '' ? (int) $payload['expected_time'] : null,
            'template_id' => !empty($payload['source_template_id']) ? (int) $payload['source_template_id'] : null,
            'result_id' => $payload['result_id'] ?? null,
            'project_id' => isset($payload['project_id']) && (int) $payload['project_id'] > 0 ? (int) $payload['project_id'] : null,
        ];

        $filtered = $this->filterTaskColumns($taskPayload);
        if (empty($filtered)) {
            return 0;
        }

        $this->db->insert('tasks', $filtered);
        return (int) $this->db->lastInsertId();
    }

    private function isTemplateTaskEligibleForPlan(array $plan, array $task): bool
    {
        $taskId = (int) ($task['id'] ?? 0);
        $templateId = (int) ($task['template_id'] ?? 0);
        $assigneeId = (int) ($task['assignee_id'] ?? 0);
        $taskDate = $this->extractTaskDate($task);

        if ($taskId <= 0 || $templateId <= 0 || $assigneeId <= 0 || $taskDate === null) {
            return false;
        }

        return $assigneeId === (int) ($plan['user_id'] ?? 0)
            && $taskDate >= (string) ($plan['week_start_date'] ?? '')
            && $taskDate <= (string) ($plan['week_end_date'] ?? '');
    }

    private function buildPlanItemPayloadFromTask(array $plan, array $task, array $overrides = []): array
    {
        $plannedDate = $this->extractTaskDate($task) ?? (string) ($plan['week_start_date'] ?? date('Y-m-d'));
        $startTime = $this->normalizeStartTimeValue($this->extractTaskTime($task));

        return array_merge([
            'weekly_plan_id' => (int) $plan['id'],
            'linked_task_id' => (int) ($task['id'] ?? 0),
            'source_template_id' => !empty($task['template_id']) ? (int) $task['template_id'] : null,
            'planned_date' => $plannedDate,
            'start_time' => $startTime,
            'weekday_index' => $this->weekdayIndex($plannedDate),
            'sort_order' => 1,
            'title' => $this->resolvePlanItemTitle($task),
            'description' => $task['description'] ?? null,
            'assignee_id' => (int) (($task['assignee_id'] ?? 0) ?: ($plan['user_id'] ?? 0)),
            'reporter_id' => (int) (($task['reporter_id'] ?? 0) ?: ($plan['created_by_id'] ?? $plan['user_id'] ?? 0)),
            'type' => $task['type'] ?? 'important-not-urgent',
            'expected_result' => $task['expected_result'] ?? null,
            'expected_time' => $task['expected_time'] !== null && $task['expected_time'] !== '' ? (int) $task['expected_time'] : null,
        ], $overrides);
    }

    private function resolvePlanItemTitle(array $task, string $existingTitle = ''): string
    {
        $existingTitle = trim($existingTitle);
        $taskTitle = trim((string) ($task['title'] ?? ''));
        $templateName = trim((string) ($task['template_name'] ?? ''));
        $expectedResult = trim((string) ($task['expected_result'] ?? ''));

        $resolved = $taskTitle;
        if ($resolved === '') {
            $resolved = $existingTitle !== '' ? $existingTitle : $templateName;
        }
        if ($resolved === '') {
            $resolved = $expectedResult;
        }
        if ($resolved === '') {
            $taskId = (int) ($task['id'] ?? 0);
            $resolved = $taskId > 0 ? ('Задача #' . $taskId) : 'Задача';
        }

        return mb_substr($resolved, 0, 255);
    }

    private function extractTaskDate(array $task): ?string
    {
        $dueDate = trim((string) ($task['due_date'] ?? ''));
        if ($dueDate === '') {
            return null;
        }

        $timestamp = strtotime($dueDate);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function extractTaskTime(array $task): ?string
    {
        $dueDate = trim((string) ($task['due_date'] ?? ''));
        if ($dueDate === '' || !preg_match('/\b(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\b/', $dueDate, $matches)) {
            return null;
        }

        return $matches[0];
    }

    private function ensureTables(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS weekly_plans (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_by_id INT UNSIGNED NOT NULL,
            week_start_date DATE NOT NULL,
            week_end_date DATE NOT NULL,
            notes TEXT NULL,
            status VARCHAR(40) NOT NULL DEFAULT "active",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_weekly_plan_user_week (company_id, user_id, week_start_date),
            KEY idx_weekly_plans_company_week (company_id, week_start_date),
            KEY idx_weekly_plans_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

        $this->db->query('CREATE TABLE IF NOT EXISTS weekly_plan_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            weekly_plan_id INT UNSIGNED NOT NULL,
            linked_task_id INT UNSIGNED NULL,
            source_template_id INT UNSIGNED NULL,
            external_source VARCHAR(80) NULL,
            external_reference VARCHAR(255) NULL,
            planned_date DATE NOT NULL,
            start_time TIME NULL,
            weekday_index TINYINT UNSIGNED NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            assignee_id INT UNSIGNED NOT NULL,
            reporter_id INT UNSIGNED NOT NULL,
            type VARCHAR(80) NOT NULL DEFAULT "important-not-urgent",
            expected_result TEXT NULL,
            expected_time INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_weekly_plan_items_plan (weekly_plan_id),
            KEY idx_weekly_plan_items_date (planned_date),
            KEY idx_weekly_plan_items_task (linked_task_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

        $weeklyPlanItemColumns = $this->db->query('SHOW COLUMNS FROM weekly_plan_items')->fetchAll();
        $weeklyPlanItemColumnNames = array_map(static fn($row) => $row['Field'], $weeklyPlanItemColumns);
        if (!in_array('start_time', $weeklyPlanItemColumnNames, true)) {
            $this->db->query('ALTER TABLE weekly_plan_items ADD COLUMN start_time TIME NULL AFTER planned_date')->execute();
        }
        if (!in_array('external_source', $weeklyPlanItemColumnNames, true)) {
            $this->db->query('ALTER TABLE weekly_plan_items ADD COLUMN external_source VARCHAR(80) NULL AFTER source_template_id')->execute();
        }
        if (!in_array('external_reference', $weeklyPlanItemColumnNames, true)) {
            $this->db->query('ALTER TABLE weekly_plan_items ADD COLUMN external_reference VARCHAR(255) NULL AFTER external_source')->execute();
        }
    }

    private function normalizeStartTimeValue($value): ?string
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

    private function buildDueDateTime(string $plannedDate, $startTime): string
    {
        $normalizedTime = $this->normalizeStartTimeValue($startTime);
        if ($normalizedTime === null) {
            return $plannedDate;
        }

        return $plannedDate . ' ' . $normalizedTime;
    }

    private function filterTaskColumns(array $data): array
    {
        $columns = $this->getTaskColumns();
        $filtered = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $columns, true)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function getTaskColumns(): array
    {
        if ($this->taskColumns !== null) {
            return $this->taskColumns;
        }

        $rows = $this->db->query('SHOW COLUMNS FROM tasks')->fetchAll();
        $this->taskColumns = array_map(static fn($row) => $row['Field'], $rows);

        return $this->taskColumns;
    }

    private function weekdayIndex(string $date): int
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return 1;
        }

        $day = (int) date('N', $timestamp);
        return max(1, min(7, $day));
    }
}