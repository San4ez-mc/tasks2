<?php
/**
 * Контролер для шаблонів задач
 */

namespace App\Controllers;

use App\Models\Template;
use App\Models\Company;

class TemplateController
{

    /**
     * Список всіх шаблонів
     */
    public function index()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            redirect('/dashboard');
        }

        $company_model = new Company();
        $employees = $company_model->get_employees($company_id);
        $is_owner = $this->isOwnerInCompany($employees, (int) ($user['id'] ?? 0));
        $scope = $this->normalizeScope((string) get_param('scope', 'my'), $is_owner);

        $template_model = new Template();
        $templates = $template_model->get_visible_by_user($company_id, (int) ($user['id'] ?? 0), $is_owner && $scope === 'all');

        require APP_PATH . '/Views/templates/index.php';
    }

    public function create()
    {
        redirect('/templates?drawer=create');
    }

    public function edit($id)
    {
        redirect('/templates?drawer=edit&id=' . (int) $id);
    }

    /**
     * Зберегти новий шаблон (POST)
     */
    public function store()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            flash('error', 'Компанія не знайдена');
            redirect('/templates');
        }

        $name = trim((string) post_param('name'));

        if (!$name) {
            flash('error', 'Назва шаблону є обов\'язковою');
            redirect('/templates');
        }

        $expected_time_raw = post_param('expected_time');
        $expected_time = ($expected_time_raw !== null && $expected_time_raw !== '') ? (int) $expected_time_raw : null;

        $employees = (new Company())->get_employees($company_id);
        $allowed_assignee_ids = $this->getAllowedAssigneeIds($employees);
        [$assignee_id, $assignee_ids_csv] = $this->resolveTemplateAssignees(
            $_POST['assignee_ids'] ?? [],
            post_param('assignee_id'),
            $allowed_assignee_ids
        );

        $repeat_type = post_param('repeat_type') ?: 'none';
        $repeat_day = $this->resolveRepeatDay(
            $repeat_type,
            $_POST['repeat_days'] ?? [],
            $_POST['repeat_month_days'] ?? [],
            post_param('repeat_day')
        );

        $template_model = new Template();
        $template_model->create([
            'company_id' => $company_id,
            'name' => $name,
            'type' => post_param('type') ?: null,
            'description' => post_param('description') ?: null,
            'expected_result' => post_param('expected_result') ?: null,
            'assignee_id' => $assignee_id,
            'assignee_ids' => $assignee_ids_csv,
            'reporter_id' => (int) $user['id'],
            'expected_time' => $expected_time,
            'repeat_type' => $repeat_type,
            'repeat_day' => $repeat_day,
            'start_time' => post_param('start_time') ?: null,
        ]);

        flash('success', 'Шаблон успішно створено');
        redirect('/templates');
    }

    /**
     * Оновити шаблон (POST)
     */
    public function update($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            flash('error', 'Компанія не знайдена');
            redirect('/templates');
        }

        $employees = (new Company())->get_employees($company_id);
        $is_owner = $this->isOwnerInCompany($employees, (int) ($user['id'] ?? 0));

        $template_model = new Template();
        $template = $template_model->get_by_id($id);

        if (!$template || $template['company_id'] != $company_id || !$template_model->is_visible_to_user($template, (int) ($user['id'] ?? 0), $is_owner)) {
            flash('error', 'Шаблон не знайдено');
            redirect('/templates');
        }

        $name = trim((string) post_param('name'));

        if (!$name) {
            flash('error', 'Назва шаблону є обов\'язковою');
            redirect('/templates');
        }

        $expected_time_raw = post_param('expected_time');
        $expected_time = ($expected_time_raw !== null && $expected_time_raw !== '') ? (int) $expected_time_raw : null;

        $allowed_assignee_ids = $this->getAllowedAssigneeIds($employees);
        [$assignee_id, $assignee_ids_csv] = $this->resolveTemplateAssignees(
            $_POST['assignee_ids'] ?? [],
            post_param('assignee_id'),
            $allowed_assignee_ids
        );

        $repeat_type = post_param('repeat_type') ?: 'none';
        $repeat_day = $this->resolveRepeatDay(
            $repeat_type,
            $_POST['repeat_days'] ?? [],
            $_POST['repeat_month_days'] ?? [],
            post_param('repeat_day')
        );

        $template_model->update($id, [
            'name' => $name,
            'type' => post_param('type') ?: null,
            'description' => post_param('description') ?: null,
            'expected_result' => post_param('expected_result') ?: null,
            'assignee_id' => $assignee_id,
            'assignee_ids' => $assignee_ids_csv,
            'expected_time' => $expected_time,
            'repeat_type' => $repeat_type,
            'repeat_day' => $repeat_day,
            'start_time' => post_param('start_time') ?: null,
        ]);

        flash('success', 'Шаблон оновлено');
        redirect('/templates');
    }

    /**
     * Видалити шаблон (POST)
     */
    public function delete($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            redirect('/templates');
        }

        $employees = (new Company())->get_employees($company_id);
        $is_owner = $this->isOwnerInCompany($employees, (int) ($user['id'] ?? 0));

        $template_model = new Template();
        $template = $template_model->get_by_id($id);

        if (!$template || $template['company_id'] != $company_id || !$template_model->is_visible_to_user($template, (int) ($user['id'] ?? 0), $is_owner)) {
            flash('error', 'Шаблон не знайдено');
            redirect('/templates');
        }

        $template_model->delete($id);

        flash('success', 'Шаблон видалено');
        redirect('/templates');
    }

    private function normalizeScope(string $scope, bool $isOwner): string
    {
        $scope = trim($scope);
        $allowed = ['my'];
        if ($isOwner) {
            $allowed[] = 'all';
        }

        return in_array($scope, $allowed, true) ? $scope : 'my';
    }

    private function isOwnerInCompany(array $employees, int $currentUserId): bool
    {
        foreach ($employees as $employee) {
            if ((int) ($employee['user_id'] ?? 0) !== $currentUserId) {
                continue;
            }

            return strtolower(trim((string) ($employee['role'] ?? ''))) === 'owner';
        }

        return false;
    }

    private function getAllowedAssigneeIds(array $employees): array
    {
        $allowed = [];
        foreach ($employees as $employee) {
            $employee_user_id = (int) ($employee['user_id'] ?? 0);
            if ($employee_user_id > 0) {
                $allowed[$employee_user_id] = true;
            }
        }

        return $allowed;
    }

    private function resolveTemplateAssignees($rawAssigneeIds, $rawSingleAssignee, array $allowedAssigneeIds): array
    {
        $ids = [];

        if (is_array($rawAssigneeIds)) {
            foreach ($rawAssigneeIds as $rawId) {
                $candidate = (int) $rawId;
                if ($candidate > 0 && isset($allowedAssigneeIds[$candidate])) {
                    $ids[$candidate] = $candidate;
                }
            }
        }

        $singleCandidate = (int) $rawSingleAssignee;
        if (empty($ids) && $singleCandidate > 0 && isset($allowedAssigneeIds[$singleCandidate])) {
            $ids[$singleCandidate] = $singleCandidate;
        }

        if (empty($ids)) {
            return [null, null];
        }

        $ids = array_values($ids);
        return [$ids[0], implode(',', $ids)];
    }

    private function resolveRepeatDay(string $repeatType, $rawRepeatDays, $rawRepeatMonthDays, $rawRepeatDay): ?string
    {
        if (!in_array($repeatType, ['weekly', 'monthly'], true)) {
            return null;
        }

        $allowedDays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'];
        $selected = [];

        if ($repeatType === 'weekly') {
            if (is_array($rawRepeatDays)) {
                foreach ($rawRepeatDays as $day) {
                    $normalized = trim((string) $day);
                    if ($normalized !== '' && in_array($normalized, $allowedDays, true)) {
                        $selected[$normalized] = $normalized;
                    }
                }
            }

            if (empty($selected)) {
                $single = trim((string) $rawRepeatDay);
                if ($single !== '' && in_array($single, $allowedDays, true)) {
                    $selected[$single] = $single;
                }
            }

            if (empty($selected)) {
                return null;
            }

            $ordered = [];
            foreach ($allowedDays as $day) {
                if (isset($selected[$day])) {
                    $ordered[] = $day;
                }
            }

            return implode(',', $ordered);
        }

        if (is_array($rawRepeatMonthDays)) {
            foreach ($rawRepeatMonthDays as $dayNumber) {
                $normalized = (int) $dayNumber;
                if ($normalized >= 1 && $normalized <= 31) {
                    $selected[$normalized] = $normalized;
                }
            }
        }

        if (empty($selected)) {
            $single = (int) $rawRepeatDay;
            if ($single >= 1 && $single <= 31) {
                $selected[$single] = $single;
            }
        }

        if (empty($selected)) {
            return null;
        }

        $ordered = array_keys($selected);
        sort($ordered, SORT_NUMERIC);

        return implode(',', $ordered);
    }
}
