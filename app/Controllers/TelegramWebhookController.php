<?php

namespace App\Controllers;

use App\Services\TelegramBotService;
use App\Services\TelegramDigestService;

class TelegramWebhookController
{
    public function webhook(): void
    {
        $input = file_get_contents('php://input');
        $update = json_decode($input, true);

        if (!is_array($update)) {
            json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
        }

        $service = new TelegramBotService();
        $service->handleUpdate($update);

        json_response(['ok' => true]);
    }

    public function digestCron(): void
    {
        $configuredKey = (string) getenv('TELEGRAM_DIGEST_CRON_KEY');
        if ($configuredKey === '') {
            json_response(['ok' => false, 'error' => 'TELEGRAM_DIGEST_CRON_KEY is not configured.'], 503);
        }

        $passedKey = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '');
        if (!hash_equals($configuredKey, $passedKey)) {
            json_response(['ok' => false, 'error' => 'Invalid cron key.'], 401);
        }

        $service = new TelegramDigestService();
        $result = $service->runScheduled();
        json_response($result, 200);
    }
}
