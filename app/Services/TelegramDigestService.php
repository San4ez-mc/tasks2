<?php

namespace App\Services;

use App\Models\Database;

class TelegramDigestService
{
    private Database $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->ensureDigestSettingsTable();
    }

    public function runScheduled(?int $hour = null): array
    {
        $currentHour = $hour ?? (int) gmdate('G');
        $today = gmdate('Y-m-d');

        $users = $this->db->query("SELECT u.id, u.first_name, u.last_name, u.telegram_id,
            s.morning_enabled, s.morning_hour, s.evening_enabled, s.evening_hour,
            s.last_morning_sent_at, s.last_evening_sent_at
            FROM users u
            LEFT JOIN user_telegram_digest_settings s ON s.user_id = u.id
            WHERE u.telegram_id IS NOT NULL AND u.telegram_id <> ''")
            ->fetchAll();

        $sentMorning = 0;
        $sentEvening = 0;
        $errors = 0;

        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            $telegramId = (int) ($user['telegram_id'] ?? 0);
            if ($userId <= 0 || $telegramId <= 0) {
                continue;
            }

            $companyIds = $this->resolveCompanyIds($userId);
            if (empty($companyIds)) {
                continue;
            }

            $morningEnabled = (int) ($user['morning_enabled'] ?? 1) === 1;
            $morningHour = isset($user['morning_hour']) ? (int) $user['morning_hour'] : 9;
            $eveningEnabled = (int) ($user['evening_enabled'] ?? 1) === 1;
            $eveningHour = isset($user['evening_hour']) ? (int) $user['evening_hour'] : 18;

            $lastMorningDate = !empty($user['last_morning_sent_at']) ? gmdate('Y-m-d', strtotime((string) $user['last_morning_sent_at'])) : null;
            $lastEveningDate = !empty($user['last_evening_sent_at']) ? gmdate('Y-m-d', strtotime((string) $user['last_evening_sent_at'])) : null;

            if ($morningEnabled && $morningHour === $currentHour && $lastMorningDate !== $today) {
                $text = $this->buildMorningDigest($userId, $companyIds, $today);
                if ($this->sendTelegramMessage($telegramId, $text)) {
                    $this->markSent($userId, 'morning');
                    $sentMorning++;
                } else {
                    $errors++;
                }
            }

            if ($eveningEnabled && $eveningHour === $currentHour && $lastEveningDate !== $today) {
                $text = $this->buildEveningDigest($userId, $companyIds, $today);
                if ($this->sendTelegramMessage($telegramId, $text)) {
                    $this->markSent($userId, 'evening');
                    $sentEvening++;
                } else {
                    $errors++;
                }
            }
        }

        return [
            'ok' => true,
            'hour' => $currentHour,
            'sent_morning' => $sentMorning,
            'sent_evening' => $sentEvening,
            'errors' => $errors,
        ];
    }

    private function buildMorningDigest(int $userId, array $companyIds, string $date): string
    {
        $myTasks = $this->getTasksForDate($companyIds, $date, 'assignee', $userId);
        $myResults = $this->getResultsForDate($companyIds, $date, 'assignee', $userId);
        $delegatedTasks = $this->getTasksForDate($companyIds, $date, 'reporter', $userId);
        $delegatedResults = $this->getResultsForDate($companyIds, $date, 'reporter', $userId);

        $lines = [];
        $lines[] = "☀️ Ранковий план на {$date}";
        $lines[] = '';
        $lines[] = 'Ваші задачі на сьогодні:';
        $lines = array_merge($lines, $this->formatTaskLines($myTasks));
        $lines[] = '';
        $lines[] = 'Ваші цілі/підцілі з дедлайном сьогодні:';
        $lines = array_merge($lines, $this->formatResultLines($myResults));
        $lines[] = '';
        $lines[] = 'Делеговані вами задачі на сьогодні:';
        $lines = array_merge($lines, $this->formatTaskLines($delegatedTasks));
        $lines[] = '';
        $lines[] = 'Делеговані вами цілі/підцілі з дедлайном сьогодні:';
        $lines = array_merge($lines, $this->formatResultLines($delegatedResults));

        return implode("\n", $lines);
    }

    private function buildEveningDigest(int $userId, array $companyIds, string $date): string
    {
        $myTasks = $this->getTasksForDate($companyIds, $date, 'assignee', $userId);
        $myResults = $this->getResultsForDate($companyIds, $date, 'assignee', $userId);
        $delegatedTasks = $this->getTasksForDate($companyIds, $date, 'reporter', $userId);
        $delegatedResults = $this->getResultsForDate($companyIds, $date, 'reporter', $userId);

        $lines = [];
        $lines[] = "🌙 Вечірній звіт за {$date}";
        $lines[] = '';
        $lines[] = 'Ваші задачі:';
        $lines = array_merge($lines, $this->formatTaskLines($myTasks, true));
        $lines[] = '';
        $lines[] = 'Ваші цілі/підцілі:';
        $lines = array_merge($lines, $this->formatResultLines($myResults, true));
        $lines[] = '';
        $lines[] = 'Делеговані вами задачі:';
        $lines = array_merge($lines, $this->formatTaskLines($delegatedTasks, true));
        $lines[] = '';
        $lines[] = 'Делеговані вами цілі/підцілі:';
        $lines = array_merge($lines, $this->formatResultLines($delegatedResults, true));

        return implode("\n", $lines);
    }

    private function getTasksForDate(array $companyIds, string $date, string $mode, int $userId): array
    {
        if (empty($companyIds)) {
            return [];
        }

        $companyPlaceholders = [];
        foreach (array_values($companyIds) as $idx => $companyId) {
            $companyPlaceholders[] = ':company_id_' . $idx;
        }
        $inSql = implode(', ', $companyPlaceholders);

        if ($mode === 'reporter') {
            $stmt = $this->db->query("SELECT t.id, t.title, t.status, t.assignee_id, t.company_id,
                a.first_name AS assignee_first_name, a.last_name AS assignee_last_name
                FROM tasks t
                LEFT JOIN users a ON a.id = t.assignee_id
                WHERE t.company_id IN ({$inSql})
                  AND DATE(t.due_date) = :date
                  AND t.reporter_id = :user_id
                  AND (t.assignee_id <> :user_id OR t.assignee_id IS NULL)")
                ->bind(':date', $date)
                ->bind(':user_id', $userId);

            foreach (array_values($companyIds) as $idx => $companyId) {
                $stmt->bind(':company_id_' . $idx, (int) $companyId);
            }

            return $stmt->fetchAll();
        }

        $stmt = $this->db->query("SELECT t.id, t.title, t.status, t.assignee_id, t.company_id,
            a.first_name AS assignee_first_name, a.last_name AS assignee_last_name
            FROM tasks t
            LEFT JOIN users a ON a.id = t.assignee_id
            WHERE t.company_id IN ({$inSql})
              AND DATE(t.due_date) = :date
              AND t.assignee_id = :user_id")
            ->bind(':date', $date)
            ->bind(':user_id', $userId);

        foreach (array_values($companyIds) as $idx => $companyId) {
            $stmt->bind(':company_id_' . $idx, (int) $companyId);
        }

        return $stmt->fetchAll();
    }

    private function getResultsForDate(array $companyIds, string $date, string $mode, int $userId): array
    {
        if (empty($companyIds)) {
            return [];
        }

        $companyPlaceholders = [];
        foreach (array_values($companyIds) as $idx => $companyId) {
            $companyPlaceholders[] = ':company_id_' . $idx;
        }
        $inSql = implode(', ', $companyPlaceholders);

        if ($mode === 'reporter') {
            $stmt = $this->db->query("SELECT r.id, r.title, r.status, r.completed, r.assignee_id, r.company_id,
                a.first_name AS assignee_first_name, a.last_name AS assignee_last_name
                FROM results r
                LEFT JOIN users a ON a.id = r.assignee_id
                WHERE r.company_id IN ({$inSql})
                  AND DATE(r.deadline) = :date
                  AND r.reporter_id = :user_id
                  AND (r.assignee_id <> :user_id OR r.assignee_id IS NULL)")
                ->bind(':date', $date)
                ->bind(':user_id', $userId);

            foreach (array_values($companyIds) as $idx => $companyId) {
                $stmt->bind(':company_id_' . $idx, (int) $companyId);
            }

            return $stmt->fetchAll();
        }

        $stmt = $this->db->query("SELECT r.id, r.title, r.status, r.completed, r.assignee_id, r.company_id,
            a.first_name AS assignee_first_name, a.last_name AS assignee_last_name
            FROM results r
            LEFT JOIN users a ON a.id = r.assignee_id
            WHERE r.company_id IN ({$inSql})
              AND DATE(r.deadline) = :date
              AND r.assignee_id = :user_id")
            ->bind(':date', $date)
            ->bind(':user_id', $userId);

        foreach (array_values($companyIds) as $idx => $companyId) {
            $stmt->bind(':company_id_' . $idx, (int) $companyId);
        }

        return $stmt->fetchAll();
    }

    private function formatTaskLines(array $tasks, bool $withStatus = false): array
    {
        if (empty($tasks)) {
            return ['- Немає'];
        }

        $lines = [];
        foreach ($tasks as $task) {
            $status = strtolower(trim((string) ($task['status'] ?? 'todo')));
            $isDone = $status === 'done';
            $marker = $withStatus ? ($isDone ? '✅' : '❌') : '•';
            $assignee = trim((string) (($task['assignee_first_name'] ?? '') . ' ' . ($task['assignee_last_name'] ?? '')));
            $suffix = $assignee !== '' ? ' (' . $assignee . ')' : '';
            $lines[] = $marker . ' ' . (string) ($task['title'] ?? 'Без назви') . $suffix;
        }

        return $lines;
    }

    private function formatResultLines(array $results, bool $withStatus = false): array
    {
        if (empty($results)) {
            return ['- Немає'];
        }

        $lines = [];
        foreach ($results as $result) {
            $status = strtolower(trim((string) ($result['status'] ?? 'in-progress')));
            $completed = (int) ($result['completed'] ?? 0) === 1 || $status === 'done';
            $marker = $withStatus ? ($completed ? '✅' : '❌') : '•';
            $assignee = trim((string) (($result['assignee_first_name'] ?? '') . ' ' . ($result['assignee_last_name'] ?? '')));
            $suffix = $assignee !== '' ? ' (' . $assignee . ')' : '';
            $lines[] = $marker . ' ' . (string) ($result['title'] ?? 'Без назви') . $suffix;
        }

        return $lines;
    }

    private function resolveCompanyIds(int $userId): array
    {
        $rows = $this->db->query('SELECT company_id FROM company_members WHERE user_id = :user_id')
            ->bind(':user_id', $userId)
            ->fetchAll();

        $ids = [];
        foreach ($rows as $row) {
            $cid = (int) ($row['company_id'] ?? 0);
            if ($cid > 0) {
                $ids[$cid] = $cid;
            }
        }

        return array_values($ids);
    }

    private function markSent(int $userId, string $type): void
    {
        $field = $type === 'morning' ? 'last_morning_sent_at' : 'last_evening_sent_at';

        $row = $this->db->query('SELECT id FROM user_telegram_digest_settings WHERE user_id = :user_id LIMIT 1')
            ->bind(':user_id', $userId)
            ->fetch();

        if ($row) {
            $this->db->query('UPDATE user_telegram_digest_settings SET ' . $field . ' = UTC_TIMESTAMP() WHERE id = :id')
                ->bind(':id', (int) $row['id'])
                ->execute();
            return;
        }

        $this->db->insert('user_telegram_digest_settings', [
            'user_id' => $userId,
            'morning_enabled' => 1,
            'morning_hour' => 9,
            'evening_enabled' => 1,
            'evening_hour' => 18,
            'last_morning_sent_at' => $type === 'morning' ? gmdate('Y-m-d H:i:s') : null,
            'last_evening_sent_at' => $type === 'evening' ? gmdate('Y-m-d H:i:s') : null,
        ]);
    }

    private function sendTelegramMessage(int $chatId, string $text): bool
    {
        if ($chatId <= 0 || TELEGRAM_BOT_TOKEN === '') {
            return false;
        }

        if ((string) getenv('TELEGRAM_SKIP_NETWORK') === '1') {
            return true;
        }

        $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $result = curl_exec($ch);
            curl_close($ch);
            return $result !== false;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query($payload),
                'timeout' => 10,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result !== false;
    }

    private function ensureDigestSettingsTable(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS user_telegram_digest_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            morning_enabled TINYINT(1) NOT NULL DEFAULT 1,
            morning_hour TINYINT UNSIGNED NOT NULL DEFAULT 9,
            evening_enabled TINYINT(1) NOT NULL DEFAULT 1,
            evening_hour TINYINT UNSIGNED NOT NULL DEFAULT 18,
            last_morning_sent_at DATETIME NULL,
            last_evening_sent_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_digest_user (user_id),
            KEY idx_digest_morning (morning_enabled, morning_hour),
            KEY idx_digest_evening (evening_enabled, evening_hour)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();
    }
}
