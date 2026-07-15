<?php
/**
 * Контролер для результатів
 */

namespace App\Controllers;

use App\Models\Result;
use App\Models\Company;

class ResultController
{
    private const ALLOWED_STATUSES = ['in-progress', 'done', 'postponed'];

    /**
     * Список всіх результатів
     */
    public function index()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            redirect('/dashboard');
        }

        $selected_tab = get_param('tab', 'my');
        $selected_status = get_param('status', 'all');
        $selected_assignee = get_param('assignee', 'all');
        $selected_reporter = get_param('reporter', 'all');
        $search_query = trim((string) get_param('q', ''));
        $drawer_mode = get_param('drawer', '');
        $drawer_result_id = (int) get_param('id', 0);

        $allowed_tabs = ['my', 'delegated', 'subordinates', 'postponed'];
        $allowed_statuses = ['all', 'in-progress', 'done', 'postponed'];

        if (!in_array($selected_tab, $allowed_tabs, true)) {
            $selected_tab = 'my';
        }

        if (!in_array($selected_status, $allowed_statuses, true)) {
            $selected_status = 'all';
        }

        $result_model = new Result();
        $all_results = $result_model->get_by_company($company_id);

        $results = array_values(array_filter($all_results, function ($result) use ($user, $selected_tab, $selected_status, $selected_assignee, $selected_reporter, $search_query) {
            $assignee_id = (int) ($result['assignee_id'] ?? 0);
            $reporter_id = (int) ($result['reporter_id'] ?? 0);
            $user_id = (int) ($user['id'] ?? 0);

            $is_done = (int) ($result['completed'] ?? 0) === 1;
            $status = (string) ($result['status'] ?? ($is_done ? 'done' : 'in-progress'));

            switch ($selected_tab) {
                case 'my':
                    if ($assignee_id !== $user_id) {
                        return false;
                    }
                    break;
                case 'delegated':
                    if (!($reporter_id === $user_id && $assignee_id !== $user_id)) {
                        return false;
                    }
                    break;
                case 'subordinates':
                    if ($assignee_id === $user_id) {
                        return false;
                    }
                    break;
                case 'postponed':
                    if ($status !== 'postponed') {
                        return false;
                    }
                    break;
            }

            if ($selected_status !== 'all' && $status !== $selected_status) {
                return false;
            }

            if ($selected_assignee !== 'all' && $assignee_id !== (int) $selected_assignee) {
                return false;
            }

            if ($selected_reporter !== 'all' && $reporter_id !== (int) $selected_reporter) {
                return false;
            }

            if ($search_query !== '') {
                $haystack = mb_strtolower(trim((string) ($result['title'] ?? '') . ' ' . (string) ($result['description'] ?? '') . ' ' . (string) ($result['expected_result'] ?? '')));
                if (!str_contains($haystack, mb_strtolower($search_query))) {
                    return false;
                }
            }

            return true;
        }));

        $company_model = new Company();
        $employees = $company_model->get_employees($company_id);

        // Завантажити підцілі для карточок
        $all_sub_results = $result_model->get_all_sub_results($company_id);
        $children_map = [];
        foreach ($all_sub_results as $sub) {
            $pid = (int) $sub['parent_id'];
            $children_map[$pid][] = $sub;
        }

        $parent_goal_options = $this->buildParentGoalOptions($all_results, $children_map);
        $result_descendants_map = $this->buildResultDescendantsMap($children_map);

        require APP_PATH . '/Views/results/index.php';
    }

    /**
     * Форма створення нового результату
     */
    public function create()
    {
        redirect('/results?drawer=create');
    }

    /**
     * Зберегти новий результат
     */
    public function store()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $title = post_param('title');
        $description = post_param('description');
        $expected_result = post_param('expected_result');
        $instruction = post_param('instruction');
        $deadline = post_param('deadline');
        $status = $this->normalizeStatus(post_param('status'), 'in-progress');
        $assignee_id = post_param('assignee_id');
        $parent_id = post_param('parent_id');
        $formPayload = $this->buildFormPayload([
            'title' => $title,
            'description' => $description,
            'expected_result' => $expected_result,
            'instruction' => $instruction,
            'deadline' => $deadline,
            'status' => $status,
            'assignee_id' => $assignee_id,
            'parent_id' => $parent_id,
        ]);

        if (!$title) {
            flash('error', 'Заповніть обов\'язкові поля');
            flash('result_form_data', json_encode($formPayload, JSON_UNESCAPED_UNICODE));
            redirect('/results?drawer=create');
        }

        $result_model = new Result();
        $normalized_parent_id = $this->resolveParentId($result_model, (int) $company_id, $parent_id);
        if ($parent_id !== null && $parent_id !== '' && $normalized_parent_id === null) {
            flash('error', 'Обрана батьківська ціль недоступна або не існує.');
            flash('result_form_data', json_encode($formPayload, JSON_UNESCAPED_UNICODE));
            redirect('/results?drawer=create');
        }

        try {
            $result_model->create([
                'title' => $title,
                'company_id' => $company_id,
                'reporter_id' => $user['id'],
                'assignee_id' => $assignee_id ?: null,
                'description' => $description,
                'expected_result' => $expected_result ?: null,
                'instruction' => $instruction ?: null,
                'deadline' => $deadline ?: null,
                'status' => $status,
                'parent_id' => $normalized_parent_id,
                'completed' => $status === 'done' ? 1 : 0,
            ]);
        } catch (\Throwable $exception) {
            flash('error', 'Не вдалося зберегти ціль. Перевірте заповнені поля і спробуйте ще раз.');
            flash('result_form_data', json_encode($formPayload, JSON_UNESCAPED_UNICODE));
            redirect('/results?drawer=create');
        }

        flash('success', 'Ціль успішно створено');
        redirect('/results');
    }

    /**
     * AJAX: швидке створення підцілі
     */
    public function storeAjax()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $title = trim((string) (post_param('title') ?? ''));
        $parent_id = post_param('parent_id');
        $assignee_id = post_param('assignee_id');

        if (!$title) {
            json_response(['error' => 'Назва обов\'язкова'], 422);
        }

        $result_model = new Result();
        $normalized_parent_id = $this->resolveParentId($result_model, (int) $company_id, $parent_id);

        try {
            $result_model->create([
                'title' => $title,
                'company_id' => $company_id,
                'reporter_id' => $user['id'],
                'assignee_id' => $assignee_id ?: null,
                'description' => null,
                'expected_result' => null,
                'instruction' => null,
                'deadline' => null,
                'status' => 'in-progress',
                'parent_id' => $normalized_parent_id,
                'completed' => 0,
            ]);
            $new_id = $result_model->lastInsertId();
        } catch (\Throwable $e) {
            json_response(['error' => 'Не вдалося зберегти'], 500);
        }

        json_response([
            'success' => true,
            'id' => $new_id,
            'title' => $title,
            'parent_id' => $normalized_parent_id,
            'status' => 'in-progress',
        ]);
    }

    /**
     * Форма редагування результату
     */
    public function edit($id)
    {
        redirect('/results?drawer=edit&id=' . (int) $id);
    }

    /**
     * Оновити результат
     */
    public function update($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $result_model = new Result();
        $result = $result_model->get_by_id($id);

        if (!$result || $result['company_id'] != $company_id) {
            json_response(['error' => 'Result not found'], 404);
        }

        $posted_status = post_param('status');
        $posted_completed = post_param('completed');
        $posted_parent_id = post_param('parent_id');

        $new_status = $this->normalizeStatus($posted_status, (string) ($result['status'] ?? ((int) ($result['completed'] ?? 0) === 1 ? 'done' : 'in-progress')));
        if ($posted_status === null && $posted_completed !== null) {
            $new_status = $posted_completed ? 'done' : 'in-progress';
        }

        $new_completed = $new_status === 'done' ? 1 : 0;
        $resolved_parent_id = $result['parent_id'] ?? null;
        if ($posted_parent_id !== null) {
            $resolved_parent_id = $this->resolveParentId($result_model, (int) $company_id, $posted_parent_id, (int) $id);
            if ($posted_parent_id !== '' && $resolved_parent_id === null) {
                flash('error', 'Не можна вибрати цю батьківську ціль. Перевірте, щоб не було циклу або самопосилання.');
                flash('result_form_data', json_encode($this->buildFormPayload([
                    'title' => post_param('title') !== null ? post_param('title') : ($result['title'] ?? ''),
                    'description' => post_param('description') !== null ? post_param('description') : ($result['description'] ?? ''),
                    'expected_result' => post_param('expected_result') !== null ? post_param('expected_result') : ($result['expected_result'] ?? ''),
                    'instruction' => post_param('instruction') !== null ? post_param('instruction') : ($result['instruction'] ?? ''),
                    'deadline' => post_param('deadline') !== null ? post_param('deadline') : ($result['deadline'] ?? ''),
                    'status' => $new_status,
                    'assignee_id' => post_param('assignee_id') !== null ? post_param('assignee_id') : ($result['assignee_id'] ?? ''),
                    'parent_id' => $posted_parent_id,
                ]), JSON_UNESCAPED_UNICODE));
                redirect('/results?drawer=edit&id=' . (int) $id);
            }
        }

        $data = [
            'title' => post_param('title') ?: $result['title'],
            'description' => post_param('description') ?: $result['description'],
            'expected_result' => post_param('expected_result') !== null ? post_param('expected_result') : ($result['expected_result'] ?? null),
            'instruction' => post_param('instruction') !== null ? post_param('instruction') : ($result['instruction'] ?? null),
            'deadline' => post_param('deadline') !== null ? post_param('deadline') : ($result['deadline'] ?? null),
            'status' => $new_status,
            'completed' => $new_completed,
            'assignee_id' => post_param('assignee_id') ?: $result['assignee_id'],
            'parent_id' => $resolved_parent_id,
        ];

        try {
            $result_model->update($id, $data);
        } catch (\Throwable $exception) {
            flash('error', 'Не вдалося зберегти зміни. Перевірте заповнені поля і спробуйте ще раз.');
            flash('result_form_data', json_encode($this->buildFormPayload([
                'title' => $data['title'],
                'description' => $data['description'],
                'expected_result' => $data['expected_result'],
                'instruction' => $data['instruction'],
                'deadline' => $data['deadline'],
                'status' => $data['status'],
                'assignee_id' => $data['assignee_id'],
                'parent_id' => $data['parent_id'],
            ]), JSON_UNESCAPED_UNICODE));
            redirect('/results?drawer=edit&id=' . (int) $id);
        }

        flash('success', 'Ціль оновлено');
        $return_url = post_param('return_url');
        if ($return_url && str_starts_with($return_url, '/results')) {
            redirect($return_url);
        }
        redirect('/results?drawer=edit&id=' . (int) $id);
    }

    /**
     * Подивитися результат
     */
    public function view($id)
    {
        redirect('/results?drawer=edit&id=' . (int) $id);
    }

    /**
     * AJAX: позначити ціль як виконану
     */
    public function completeAjax($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Not authorized'], 403);
        }

        $result_model = new Result();
        $result = $result_model->get_by_id($id);

        if (!$result || $result['company_id'] != $company_id) {
            json_response(['error' => 'Result not found'], 404);
        }

        try {
            $result_model->update($id, [
                'title' => $result['title'],
                'description' => $result['description'] ?? null,
                'expected_result' => $result['expected_result'] ?? null,
                'instruction' => $result['instruction'] ?? null,
                'deadline' => $result['deadline'] ?? null,
                'status' => 'done',
                'completed' => 1,
                'assignee_id' => $result['assignee_id'] ?? null,
                'parent_id' => $result['parent_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            json_response(['error' => 'Failed to update result'], 500);
        }

        json_response(['success' => true]);
    }

    /**
     * Видалити результат
     */
    public function delete($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $result_model = new Result();
        $result = $result_model->get_by_id($id);

        if (!$result || $result['company_id'] != $company_id) {
            json_response(['error' => 'Result not found'], 404);
        }

        $result_model->delete($id);

        flash('success', 'Ціль видалено');
        redirect('/results');
    }

    private function buildParentGoalOptions(array $topResults, array $childrenMap, int $parentId = 0, int $depth = 0): array
    {
        $source = $parentId === 0 ? $topResults : ($childrenMap[$parentId] ?? []);
        $options = [];

        foreach ($source as $result) {
            if (!is_array($result)) {
                continue;
            }

            $resultId = (int) ($result['id'] ?? 0);
            if ($resultId <= 0) {
                continue;
            }

            $title = trim((string) ($result['title'] ?? ''));
            $options[] = [
                'id' => $resultId,
                'label' => str_repeat('— ', $depth) . ($title !== '' ? $title : ('Ціль #' . $resultId)),
            ];

            if (!empty($childrenMap[$resultId])) {
                $options = array_merge($options, $this->buildParentGoalOptions($topResults, $childrenMap, $resultId, $depth + 1));
            }
        }

        return $options;
    }

    private function buildResultDescendantsMap(array $childrenMap): array
    {
        $map = [];
        foreach (array_keys($childrenMap) as $parentId) {
            $map[(int) $parentId] = $this->collectDescendantIds((int) $parentId, $childrenMap);
        }

        return $map;
    }

    private function collectDescendantIds(int $parentId, array $childrenMap): array
    {
        $descendants = [];
        foreach ($childrenMap[$parentId] ?? [] as $child) {
            $childId = (int) ($child['id'] ?? 0);
            if ($childId <= 0) {
                continue;
            }

            $descendants[] = $childId;
            $descendants = array_merge($descendants, $this->collectDescendantIds($childId, $childrenMap));
        }

        return array_values(array_unique($descendants));
    }

    private function resolveParentId(Result $resultModel, int $companyId, $rawParentId, ?int $currentResultId = null): ?int
    {
        $value = trim((string) $rawParentId);
        if ($value === '') {
            return null;
        }

        $parentId = (int) $value;
        if ($parentId <= 0) {
            return null;
        }

        if ($currentResultId !== null && $parentId === $currentResultId) {
            return null;
        }

        $parent = $resultModel->get_by_id($parentId);
        if (!$parent || (int) ($parent['company_id'] ?? 0) !== $companyId) {
            return null;
        }

        if ($currentResultId !== null) {
            $childrenMap = [];
            foreach ($resultModel->get_all_sub_results($companyId) as $subResult) {
                $parentKey = (int) ($subResult['parent_id'] ?? 0);
                $childrenMap[$parentKey][] = $subResult;
            }

            if (in_array($parentId, $this->collectDescendantIds($currentResultId, $childrenMap), true)) {
                return null;
            }
        }

        return $parentId;
    }

    private function normalizeStatus($rawStatus, string $default = 'in-progress'): string
    {
        $status = trim((string) $rawStatus);
        if ($status === '' || !in_array($status, self::ALLOWED_STATUSES, true)) {
            return in_array($default, self::ALLOWED_STATUSES, true) ? $default : 'in-progress';
        }

        return $status;
    }

    private function buildFormPayload(array $data): array
    {
        return [
            'title' => (string) ($data['title'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'expected_result' => (string) ($data['expected_result'] ?? ''),
            'instruction' => (string) ($data['instruction'] ?? ''),
            'deadline' => (string) ($data['deadline'] ?? ''),
            'status' => $this->normalizeStatus($data['status'] ?? null, 'in-progress'),
            'assignee_id' => $data['assignee_id'] ?? '',
            'parent_id' => $data['parent_id'] ?? '',
        ];
    }
}
