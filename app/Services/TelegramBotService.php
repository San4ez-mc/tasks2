<?php

namespace App\Services;

use App\Models\Database;

class TelegramBotService
{
    private $db;
    private TelegramMessageClassifierService $messageClassifier;
    private TelegramIntentRouterService $intentRouter;
    private const PENDING_INTENT_PREFIX = 'TG_PENDING_INTENT:';
    private ?string $lastAudioProcessingError = null;
    private ?string $lastAudioTranscription = null;
    private ?string $lastAiRawResponse = null;
    private ?string $lastAiParsedResponse = null;
    private ?int $currentInteractionLogId = null;
    private array $currentInteractionReplies = [];
    private static bool $interactionLogSchemaReady = false;

    public function __construct()
    {
        $this->db = new Database();
        $this->messageClassifier = new TelegramMessageClassifierService();
        $this->intentRouter = new TelegramIntentRouterService($this->messageClassifier);
        $this->ensureInteractionLogTable();
        $this->ensureStorageDirectories();
    }

    public function handleUpdate(array $update): void
    {
        $this->resetInteractionState();

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
            return;
        }

        if (!isset($update['message'])) {
            return;
        }

        $message = $update['message'];
        $chat = $message['chat'] ?? [];
        $chatId = (int) ($chat['id'] ?? 0);
        $chatType = (string) ($chat['type'] ?? '');

        if ($chatId <= 0 || !in_array($chatType, ['group', 'supergroup', 'private'], true)) {
            return;
        }

        $from = $message['from'] ?? [];
        $fromTelegramId = (int) ($from['id'] ?? 0);
        if ($fromTelegramId <= 0) {
            return;
        }

        $rawText = $this->extractRawMessageText($message);
        $this->startInteractionLog($update, $message, $chatId, $chatType, $fromTelegramId, $rawText);

        // Link web-account to Telegram: /link ABCD1234
        if ($chatType === 'private' && preg_match('/^\/link\s+([a-zA-Z0-9\-]{4,40})$/', $rawText, $m)) {
            $this->handleLinkCommand($chatId, $from, $m[1]);
            return;
        }

        if ($chatType === 'private' && preg_match('/^\/start(?:@\w+)?\s+TGONB_([A-Za-z0-9]{8,40})$/u', trim($rawText), $tgOnbMatch)) {
            $this->handleTelegramOnboardingLink($chatId, $from, $tgOnbMatch[1]);
            return;
        }

        if ($chatType === 'private' && preg_match('/^\/start(?:@\w+)?\s+TGLOGIN$/iu', trim($rawText))) {
            $this->handleTelegramLoginCommand($chatId, $from);
            return;
        }

        if ($chatType === 'private' && preg_match('/^\/start(?:@\w+)?$/iu', trim($rawText))) {
            $this->handleStartCommand($chatId, $from);
            return;
        }

        if ($chatType === 'private' && $this->handlePendingOnboardingReply($chatId, $from, $rawText)) {
            return;
        }

        if ($this->isCapabilitiesRequest($rawText, $chatType)) {
            $this->sendCapabilitiesMessage($chatId, $chatType);
            return;
        }

        if ($chatType === 'private' && preg_match('/^\/(login|startlogin|restore|reset_password)$/i', trim($rawText))) {
            $this->handleTelegramLoginCommand($chatId, $from);
            return;
        }

        // 1) Resolve company and reporter by chat type
        $companyId = null;
        $companyName = '';
        $companyCount = 1;
        $reporter = null;

        if ($chatType === 'private') {
            $reporter = $this->findUserByTelegramId($fromTelegramId, (string) ($from['username'] ?? ''));
            if (!$reporter) {
                $this->sendMessage($chatId, $this->formatInfoMessage('Потрібен акаунт у FINEKO', [
                    'Спочатку зареєструйтесь у системі, а потім я зможу створювати цілі та задачі.',
                ]));
                return;
            }

            $companies = $this->findCompaniesByUser((int) $reporter['id']);
            if (empty($companies)) {
                $this->sendMessage($chatId, $this->formatInfoMessage('Компанії поки немає', [
                    'Для вашого акаунта ще не знайдено жодної компанії.',
                ]));
                return;
            }

            if (preg_match('/^\/company(?:\s+(\d+))?$/i', $rawText, $m)) {
                $requestedCompanyId = isset($m[1]) ? (int) $m[1] : null;
                $this->handlePrivateCompanyCommand($chatId, (int) $reporter['id'], $companies, $requestedCompanyId);
                return;
            }

            if ($this->handleNaturalLanguageCompanySwitch($chatId, (int) $reporter['id'], $companies, $rawText)) {
                return;
            }

            $activeCompany = $this->resolveActivePrivateCompany((int) $reporter['id'], $companies);
            $companyId = (int) ($activeCompany['id'] ?? 0);
            $companyName = (string) ($activeCompany['name'] ?? '');
            $companyCount = count($companies);
            $this->updateInteractionLog([
                'app_user_id' => (int) ($reporter['id'] ?? 0) ?: null,
                'company_id' => $companyId > 0 ? $companyId : null,
                'processing_status' => 'resolved_private_user',
            ]);

            if ($companyId <= 0) {
                $this->sendMessage($chatId, $this->formatWarningMessage('Не вдалося визначити активну компанію', [
                    'Надішліть /company або фразу: перемкни компанію на Назва.',
                ]));
                return;
            }
        } else {
            $group = $this->findTelegramGroup($chatId);
            if (!$group) {
                $this->sendMessage($chatId, $this->formatWarningMessage('Група ще не привʼязана до FINEKO'));
                return;
            }

            $companyId = (int) $group['company_id'];
            $companyName = $this->findCompanyNameById($companyId);
            $reporter = $this->findOrCreateUserByTelegram($from);
            $this->updateInteractionLog([
                'app_user_id' => (int) ($reporter['id'] ?? 0) ?: null,
                'company_id' => $companyId > 0 ? $companyId : null,
                'processing_status' => 'resolved_group_user',
            ]);
            if (!$reporter) {
                $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося визначити постановника'));
                return;
            }
        }

        // 2) Extract text or transcribe voice/audio
        $text = $rawText;
        $hadAudioAttachment = $this->hasAudioAttachment($message);
        $this->updateInteractionLog([
            'message_kind' => $hadAudioAttachment ? 'audio' : 'text',
            'processing_status' => 'received_message',
        ]);
        if ($text === '') {
            $text = $this->extractTextFromAudioMessage($message);
        }

        $this->updateInteractionLog([
            'transcribed_text' => $this->nullIfEmptyForLog($this->lastAudioTranscription),
            'normalized_text' => $this->nullIfEmptyForLog($text),
            'audio_error' => $this->nullIfEmptyForLog($this->lastAudioProcessingError),
            'processing_status' => $text !== '' ? 'text_ready' : 'text_missing',
        ]);

        if ($text === '') {
            if ($hadAudioAttachment) {
                $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося розпізнати аудіо', [
                    'Надішліть його ще раз або продублюйте текстом.',
                ]));
            } else {
                $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося прочитати повідомлення', [
                    'Надішліть текст або голосове ще раз.',
                ]));
            }
            return;
        }

        // 3) Group mode guardrails: require mention or command
        if ($chatType !== 'private') {
            $botUsername = (string) TELEGRAM_BOT_USERNAME;
            if ($botUsername === '') {
                return;
            }

            $hasMention = stripos($text, '@' . $botUsername) !== false;
            $hasTaskCommand = stripos($text, '/task') === 0;
            $hasGoalCommand = stripos($text, '/goal') === 0 || stripos($text, '/goals') === 0;

            if (!$hasMention && !$hasTaskCommand && !$hasGoalCommand) {
                $this->updateInteractionLog(['processing_status' => 'ignored_group_message']);
                return;
            }

            $text = str_ireplace('@' . $botUsername, '', $text);
            $text = preg_replace('/^\/(task|goal|goals)\s*/i', '', (string) $text);
            $text = trim((string) $text);

            if ($text === '') {
                $this->sendMessage($chatId, $this->formatInfoMessage('Потрібен текст після команди', [
                    'Додайте текст після команди або згадки бота.',
                ]));
                return;
            }
        }

        $routeDecision = $this->intentRouter->routeMessage($text);
        $this->updateInteractionLog([
            'route_name' => $routeDecision['route'] ?? null,
            'route_confidence' => $routeDecision['confidence'] ?? null,
            'route_reason' => $routeDecision['reason'] ?? null,
            'processing_status' => 'route_decided',
        ]);

        if (($routeDecision['route'] ?? '') === 'planner' && $this->tryHandlePendingTaskClarificationReply($chatId, (int) $companyId, $companyName, $companyCount, $reporter, $text)) {
            return;
        }

        if ($this->handleRoutedDecision($routeDecision, $chatId, (int) $companyId, $companyName, $companyCount, $reporter)) {
            return;
        }

        $deterministicPlannerCommands = $this->buildDeterministicPlannerCommands($text);
        if (!empty($deterministicPlannerCommands)) {
            $this->updateInteractionLog([
                'execution_path' => 'planner_deterministic_commands',
                'command_names' => $this->extractCommandNames($deterministicPlannerCommands),
            ]);
            $commandService = new TelegramIntentCommandService();
            $commandResult = $commandService->executeCommands((int) $companyId, $reporter, $deterministicPlannerCommands);
            if ($commandResult && !empty($commandResult['reply'])) {
                $this->sendMessage($chatId, (string) $commandResult['reply']);
                return;
            }
        }

        // 4) Build context and parse with Claude
        $employees = $this->findUsersByCompany((int) $companyId);
        $templates = $this->findTemplatesByCompany((int) $companyId);
        $recentConversation = $this->getRecentConversationTurns($chatId, 6);
        $this->appendConversationTurn($chatId, 'user', $text);
        $this->updateInteractionLog([
            'ai_recent_context' => $this->nullIfEmptyForLog($this->formatConversationTurnsForPrompt($recentConversation)),
            'processing_status' => 'calling_ai',
        ]);

        $parsed = $this->parseIntentWithClaude($text, $reporter, $employees, $templates, $recentConversation);
        $this->updateInteractionLog([
            'ai_raw_response' => $this->nullIfEmptyForLog($this->lastAiRawResponse),
            'ai_parsed_json' => $this->nullIfEmptyForLog($this->lastAiParsedResponse),
            'processing_status' => $parsed ? 'ai_parsed' : 'ai_failed',
        ]);

        $taskClarificationReply = $this->buildTaskClarificationReply($parsed);
        if ($taskClarificationReply !== null) {
            $taskCandidates = $this->extractTaskCandidatesFromParsed($parsed);
            $this->updateInteractionLog([
                'execution_path' => 'needs_task_clarification',
                'command_names' => $this->extractCommandNames(is_array($parsed['commands'] ?? null) ? $parsed['commands'] : []),
            ]);
            $this->sendMessage($chatId, $this->prepareTaskClarificationReply($taskClarificationReply, $taskCandidates, (int) ($reporter['id'] ?? 0), (int) $companyId));
            return;
        }

        $templateClarificationReply = $this->buildTemplateClarificationReply($parsed);
        if ($templateClarificationReply !== null) {
            $this->updateInteractionLog([
                'execution_path' => 'needs_template_clarification',
                'command_names' => $this->extractCommandNames(is_array($parsed['commands'] ?? null) ? $parsed['commands'] : []),
            ]);
            $this->sendMessage($chatId, $templateClarificationReply);
            return;
        }

        if (is_array($parsed) && array_key_exists('recognized', $parsed) && !$parsed['recognized']) {
            if ($this->tryHandleFallbackTemplateRequest($text, (int) $companyId, $reporter, $chatId)) {
                return;
            }

            $this->updateInteractionLog([
                'execution_path' => 'ai_unrecognized',
                'command_names' => null,
            ]);
            $this->sendMessage($chatId, $this->buildUnrecognizedMessage());
            return;
        }

        if (is_array($parsed) && !empty($parsed['commands']) && is_array($parsed['commands'])) {
            $this->updateInteractionLog([
                'execution_path' => 'ai_commands',
                'command_names' => $this->extractCommandNames($parsed['commands']),
            ]);
            $commandService = new TelegramIntentCommandService();
            $commandResult = $commandService->executeCommands((int) $companyId, $reporter, $parsed['commands']);
            if ($commandResult) {
                if (!empty($commandResult['reply'])) {
                    $this->sendMessage($chatId, (string) $commandResult['reply']);
                }
                return;
            }
        }

        if (!$parsed || empty($parsed['items']) || !is_array($parsed['items'])) {
            if ($this->looksLikeDeleteRequest($text)) {
                $this->updateInteractionLog([
                    'execution_path' => 'fallback_delete_tasks',
                    'command_names' => 'delete_tasks',
                ]);
                $this->handleDeleteTasksRequest($chatId, (int) $companyId, $reporter, ['scope' => 'my', 'status' => 'all', 'date' => 'today']);
                return;
            }

            if ($this->looksLikeTaskListRequest($text)) {
                $this->updateInteractionLog([
                    'execution_path' => 'forced_task_list',
                    'command_names' => 'manage_tasks:list',
                ]);
                $commandService = new TelegramIntentCommandService();
                $commandResult = $commandService->executeCommands((int) $companyId, $reporter, [
                    [
                        'name' => 'manage_tasks',
                        'args' => $this->buildTaskListArgsFromText($text),
                    ]
                ]);

                if ($commandResult && !empty($commandResult['reply'])) {
                    $this->sendMessage($chatId, (string) $commandResult['reply']);
                    return;
                }
            }

            if ($this->looksLikeGoalListRequest($text)) {
                $this->updateInteractionLog([
                    'execution_path' => 'forced_goal_list',
                    'command_names' => 'list_goals',
                ]);
                $commandService = new TelegramIntentCommandService();
                $commandResult = $commandService->executeCommands((int) $companyId, $reporter, [
                    [
                        'name' => 'list_goals',
                        'args' => ['status' => 'all'],
                    ]
                ]);

                if ($commandResult && !empty($commandResult['reply'])) {
                    $this->sendMessage($chatId, (string) $commandResult['reply']);
                    return;
                }
            }

            if (!$this->looksLikeRecognizableRequest($text)) {
                $this->sendMessage($chatId, $this->buildUnrecognizedMessage());
                return;
            }

            if ($this->looksLikeCorrectionOnlyMessage($text)) {
                $this->updateInteractionLog([
                    'execution_path' => 'followup_correction',
                    'command_names' => 'clarify_previous',
                ]);
                $this->sendMessage($chatId, $this->formatInfoMessage('Схоже на уточнення до попереднього повідомлення', [
                    'Надішліть повну задачу або ціль одним повідомленням уже з правильним виконавцем.',
                ]));
                return;
            }

            if ($this->tryHandleFallbackTemplateRequest($text, (int) $companyId, $reporter, $chatId)) {
                return;
            }

            $fallbackItems = $this->buildFallbackItemsFromText($text);
            if (empty($fallbackItems)) {
                $this->updateInteractionLog([
                    'execution_path' => 'fallback_empty',
                    'command_names' => null,
                ]);
                $this->sendMessage($chatId, $this->buildUnrecognizedMessage());
                return;
            }

            $fallbackClarificationReply = $this->buildTaskClarificationReply(['items' => $fallbackItems]);
            if ($fallbackClarificationReply !== null) {
                $this->updateInteractionLog([
                    'execution_path' => 'fallback_task_clarification',
                    'command_names' => 'task_missing_fields',
                ]);
                $this->sendMessage($chatId, $this->prepareTaskClarificationReply($fallbackClarificationReply, $fallbackItems, (int) ($reporter['id'] ?? 0), (int) $companyId));
                return;
            }

            if ($this->canCreateFallbackTasksDirectly($fallbackItems)) {
                $this->updateInteractionLog([
                    'execution_path' => 'fallback_task_create',
                    'command_names' => 'create_tasks',
                ]);
                $commandService = new TelegramIntentCommandService();
                $commandResult = $commandService->executeCommands((int) $companyId, $reporter, [
                    [
                        'name' => 'create_tasks',
                        'args' => ['tasks' => $fallbackItems],
                    ],
                ]);

                if ($commandResult && !empty($commandResult['reply'])) {
                    $this->sendMessage($chatId, (string) $commandResult['reply']);
                    return;
                }
            }

            $this->updateInteractionLog([
                'execution_path' => 'fallback_draft',
                'command_names' => 'draft_preview',
            ]);
            $this->createIntentFallbackDraft((int) $companyId, (int) $reporter['id'], $fallbackItems, $chatId, $companyName, $companyCount);
            return;
        }

        $this->updateInteractionLog([
            'execution_path' => 'ai_items_persist',
            'command_names' => 'items[]',
        ]);
        $summary = $this->persistParsedItems((int) $companyId, (int) $reporter['id'], $parsed['items']);

        if ($summary['goals'] === 0 && $summary['tasks'] === 0) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося створити цілі або задачі', [
                'Спробуйте уточнити формулювання повідомлення.',
            ]));
            return;
        }

        $this->sendMessage($chatId, $this->buildCreatedItemsReply($summary, $companyName, $companyCount));
    }

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $data = trim((string) ($callbackQuery['data'] ?? ''));
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $message = $callbackQuery['message'] ?? [];
        $chatId = (int) (($message['chat']['id'] ?? 0));
        $messageId = (int) ($message['message_id'] ?? 0);
        $from = $callbackQuery['from'] ?? [];

        if ($data === '' || $callbackId === '' || $chatId <= 0 || $messageId <= 0 || !is_array($from)) {
            return;
        }

        if (preg_match('/^tg_company_switch:(\d+)$/', $data, $companyMatch)) {
            $actor = $this->resolveCallbackActor($message, $from);
            if (!$actor) {
                $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('⚠️', 'Не вдалося визначити користувача'));
                return;
            }

            $companies = $this->findCompaniesByUser((int) ($actor['id'] ?? 0));
            $this->handlePrivateCompanySwitchRequest($chatId, $callbackId, $messageId, (int) ($actor['id'] ?? 0), $companies, (int) $companyMatch[1], true);
            return;
        }

        if (preg_match('/^tg_(confirm|cancel)_delete:([a-f0-9]{16})$/', $data, $deleteMatches)) {
            $deleteAction = $deleteMatches[1];
            $deleteDraftKey = (string) $deleteMatches[2];
            $actor = $this->resolveCallbackActor($message, $from);

            if (!$actor) {
                $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('⚠️', 'Не вдалося визначити користувача'));
                return;
            }

            $draft = $this->findPendingIntentDraft($deleteDraftKey, (int) ($actor['id'] ?? 0));
            if (!$draft) {
                $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('ℹ️', 'Чернетка вже неактуальна'));
                $this->editMessageText($chatId, $messageId, $this->formatInfoMessage('Чернетка вже неактуальна'));
                return;
            }

            if ($deleteAction === 'cancel') {
                $this->deletePendingIntentDraft((string) ($draft['id'] ?? ''));
                $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('ℹ️', 'Видалення скасовано'));
                $this->editMessageText($chatId, $messageId, $this->formatInfoMessage('Видалення скасовано'));
                return;
            }

            $payload = $draft['payload'] ?? null;
            $items = is_array($payload) ? ($payload['items'] ?? []) : [];
            $taskIds = [];
            $resultIds = [];
            foreach ($items as $item) {
                if (($item['type'] ?? '') === 'delete_tasks' && !empty($item['task_ids']) && is_array($item['task_ids'])) {
                    $taskIds = array_merge($taskIds, $item['task_ids']);
                }
                if (($item['type'] ?? '') === 'delete_goals' && !empty($item['result_ids']) && is_array($item['result_ids'])) {
                    $resultIds = array_merge($resultIds, $item['result_ids']);
                }
            }

            if (empty($taskIds) && empty($resultIds)) {
                $this->deletePendingIntentDraft((string) ($draft['id'] ?? ''));
                $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('⚠️', 'Немає елементів для видалення'));
                $this->editMessageText($chatId, $messageId, $this->formatErrorMessage('Немає елементів для видалення'));
                return;
            }

            $deleted = 0;
            if (!empty($taskIds)) {
                $taskModel = new \App\Models\Task();
                foreach ($taskIds as $taskId) {
                    try {
                        $taskModel->delete((int) $taskId);
                        $deleted++;
                    } catch (\Throwable $e) {
                        error_log("[TelegramBot] delete task {$taskId} failed: " . $e->getMessage());
                    }
                }
            }
            if (!empty($resultIds)) {
                $resultModel = new \App\Models\Result();
                foreach ($resultIds as $resultId) {
                    try {
                        $resultModel->delete((int) $resultId);
                        $deleted++;
                    } catch (\Throwable $e) {
                        error_log("[TelegramBot] delete goal {$resultId} failed: " . $e->getMessage());
                    }
                }
            }

            $entityLabel = !empty($resultIds) ? 'цілей' : 'задач';
            $this->deletePendingIntentDraft((string) ($draft['id'] ?? ''));
            $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('✅', "Видалено {$deleted} {$entityLabel}"));
            $this->editMessageText($chatId, $messageId, $this->formatSuccessMessage("Видалено {$deleted} {$entityLabel}"));
            return;
        }

        if (preg_match('/^tg_pick_assignee:([a-f0-9]{16}):(\d+)$/', $data, $pickMatches)) {
            $pickDraftKey = (string) $pickMatches[1];
            $pickedUserId = (int) $pickMatches[2];
            $actor = $this->resolveCallbackActor($message, $from);

            if (!$actor) {
                $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('⚠️', 'Не вдалося визначити користувача'));
                return;
            }

            $draft = $this->findPendingIntentDraft($pickDraftKey, (int) ($actor['id'] ?? 0));
            if (!$draft) {
                $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('ℹ️', 'Чернетка вже неактуальна'));
                $this->editMessageText($chatId, $messageId, $this->formatInfoMessage('Чернетка вже неактуальна'));
                return;
            }

            $payload = $draft['payload'] ?? [];
            $items = is_array($payload) ? ($payload['items'] ?? []) : [];
            $companyId = (int) ($draft['company_id'] ?? 0);
            $users = $this->findUsersByCompany($companyId);
            $pickedName = $this->findUserDisplayNameById($users, $pickedUserId);

            // Update unresolved assignees in draft items to picked user
            $reporterId = (int) ($draft['user_id'] ?? 0);
            foreach ($items as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                $assigneeText = trim((string) ($item['assignee'] ?? ''));
                if ($assigneeText !== '') {
                    $resolvedId = $this->resolveAssigneeId($users, $assigneeText, 0);
                    if ($resolvedId === 0) {
                        $item['assignee'] = $pickedName;
                    }
                }
            }
            unset($item);

            // Persist updated draft
            $this->deletePendingIntentDraft($pickDraftKey);
            $newDraftKey = $this->storePendingIntentDraft($reporterId, $companyId, $items);
            if ($newDraftKey === '') {
                $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('⚠️', 'Помилка збереження'));
                return;
            }

            // Re-show preview with updated assignee
            $reply = $this->buildDraftPreview($items);
            $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('✅', 'Виконавець: ' . mb_substr($pickedName, 0, 40)));
            $this->editMessageText($chatId, $messageId, implode("\n", $reply), [
                'inline_keyboard' => [
                    [
                        ['text' => 'Підтвердити', 'callback_data' => 'tg_confirm_intent:' . $newDraftKey],
                        ['text' => 'Скасувати', 'callback_data' => 'tg_cancel_intent:' . $newDraftKey],
                    ],
                ],
            ]);
            return;
        }

        if (!preg_match('/^tg_(confirm|cancel)_intent:([a-f0-9]{16})$/', $data, $matches)) {
            return;
        }

        $action = $matches[1];
        $draftKey = (string) $matches[2];
        $actor = $this->resolveCallbackActor($message, $from);

        if (!$actor) {
            $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('⚠️', 'Не вдалося визначити користувача'));
            return;
        }

        $draft = $this->findPendingIntentDraft($draftKey, (int) ($actor['id'] ?? 0));
        if (!$draft) {
            $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('ℹ️', 'Чернетка вже неактуальна'));
            $this->editMessageText($chatId, $messageId, $this->formatInfoMessage('Чернетка вже неактуальна', [
                'Надішліть повідомлення ще раз.',
            ]));
            return;
        }

        if ($action === 'cancel') {
            $this->deletePendingIntentDraft((string) ($draft['id'] ?? ''));
            $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('ℹ️', 'Створення скасовано'));
            $this->editMessageText($chatId, $messageId, $this->formatInfoMessage('Створення скасовано'));
            return;
        }

        $payload = $draft['payload'] ?? null;
        if (!is_array($payload) || empty($payload['items']) || !is_array($payload['items'])) {
            $this->deletePendingIntentDraft((string) ($draft['id'] ?? ''));
            $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('⚠️', 'Не вдалося прочитати чернетку'));
            $this->editMessageText($chatId, $messageId, $this->formatErrorMessage('Не вдалося прочитати чернетку', [
                'Надішліть повідомлення ще раз.',
            ]));
            return;
        }

        $userId = (int) ($draft['user_id'] ?? 0);
        $companyId = (int) ($draft['company_id'] ?? 0);

        if ($userId <= 0 || $companyId <= 0) {
            $this->deletePendingIntentDraft((string) ($draft['id'] ?? ''));
            $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('⚠️', 'Чернетка пошкоджена'));
            $this->editMessageText($chatId, $messageId, $this->formatErrorMessage('Чернетка пошкоджена', [
                'Надішліть повідомлення ще раз.',
            ]));
            return;
        }

        $summary = $this->persistParsedItems($companyId, $userId, $payload['items']);

        $this->deletePendingIntentDraft((string) ($draft['id'] ?? ''));

        $companyName = $this->findCompanyNameById($companyId);
        $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('✅', 'Створення виконано'));
        $this->editMessageText($chatId, $messageId, $this->buildCreatedItemsReply($summary, $companyName, 2));
    }

    private function extractTextFromAudioMessage(array $message): string
    {
        $this->lastAudioProcessingError = null;
        $this->lastAudioTranscription = null;

        // Voice note
        if (!empty($message['voice']['file_id'])) {
            return $this->transcribeTelegramFile((string) $message['voice']['file_id']);
        }

        // Audio file
        if (!empty($message['audio']['file_id'])) {
            return $this->transcribeTelegramFile((string) $message['audio']['file_id']);
        }

        // Optional: document with audio mime
        if (!empty($message['document']['file_id'])) {
            $mime = (string) ($message['document']['mime_type'] ?? '');
            if (stripos($mime, 'audio/') === 0) {
                return $this->transcribeTelegramFile((string) $message['document']['file_id']);
            }
        }

        return '';
    }

    private function hasAudioAttachment(array $message): bool
    {
        if (!empty($message['voice']['file_id'])) {
            return true;
        }

        if (!empty($message['audio']['file_id'])) {
            return true;
        }

        if (!empty($message['document']['file_id'])) {
            $mime = (string) ($message['document']['mime_type'] ?? '');
            return stripos($mime, 'audio/') === 0;
        }

        return false;
    }

    private function extractRawMessageText(array $message): string
    {
        $text = trim((string) ($message['text'] ?? ''));
        if ($text !== '') {
            return $text;
        }

        $caption = trim((string) ($message['caption'] ?? ''));
        if ($caption !== '') {
            return $caption;
        }

        return '';
    }

    private function transcribeTelegramFile(string $fileId): string
    {
        if ($fileId === '' || OPENAI_API_KEY === '' || TELEGRAM_BOT_TOKEN === '') {
            $this->setAudioProcessingError('Missing configuration or file id for audio transcription.');
            return '';
        }

        $fileMetaUrl = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/getFile?file_id=' . rawurlencode($fileId);
        $metaRaw = $this->fetchRemoteContent($fileMetaUrl, 30);
        if ($metaRaw === null) {
            $this->setAudioProcessingError('Unable to fetch Telegram file metadata.');
            return '';
        }

        $meta = json_decode($metaRaw, true);
        $filePath = (string) ($meta['result']['file_path'] ?? '');
        if ($filePath === '') {
            $this->setAudioProcessingError('Telegram metadata response does not include file path.');
            return '';
        }

        $downloadUrl = 'https://api.telegram.org/file/bot' . TELEGRAM_BOT_TOKEN . '/' . $filePath;
        $audioData = $this->fetchRemoteContent($downloadUrl, 60);
        if ($audioData === null || $audioData === '') {
            $this->setAudioProcessingError('Unable to download Telegram audio file.');
            return '';
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'tg_audio_');
        if (!$tmpFile) {
            $this->setAudioProcessingError('Unable to create temporary file for audio transcription.');
            return '';
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        if ($extension === '') {
            $extension = 'ogg';
        }
        // Convert .oga to .ogg for OpenAI compatibility (VERSION_CHECK: 2026-04-16_oga_to_ogg_v1)
        if (strtolower($extension) === 'oga') {
            $extension = 'ogg';
        }

        $audioPath = $tmpFile . '.' . $extension;
        if (file_put_contents($audioPath, $audioData) === false) {
            @unlink($tmpFile);
            $this->setAudioProcessingError('Unable to write temporary audio file.');
            return '';
        }

        $mime = 'audio/ogg';
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($audioPath);
            if ($detected) {
                $mime = $detected;
            }
        }

        $response = '';
        $models = ['gpt-4o-mini-transcribe', 'whisper-1'];

        foreach ($models as $model) {
            $response = $this->transcribeWithOpenAiModel($audioPath, $mime, $model);
            if ($response !== '') {
                break;
            }
        }

        @unlink($audioPath);
        @unlink($tmpFile);

        if ($response === '') {
            return '';
        }

        $this->lastAudioTranscription = trim($response);

        return $this->lastAudioTranscription;
    }

    private function transcribeWithOpenAiModel(string $audioPath, string $mime, string $model): string
    {
        $url = 'https://api.openai.com/v1/audio/transcriptions';

        $postFields = [
            'model' => $model,
            'language' => 'uk',
            'response_format' => 'text',
            'file' => new \CURLFile($audioPath, $mime, basename($audioPath)),
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . OPENAI_API_KEY,
            ],
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $details = $response !== false ? trim((string) $response) : $curlError;
            $this->setAudioProcessingError('OpenAI transcription request failed for model ' . $model, $httpCode, $details);
            return '';
        }

        $text = trim((string) $response);
        if ($text === '') {
            $this->setAudioProcessingError('OpenAI transcription returned empty text for model ' . $model);
            return '';
        }

        return $text;
    }

    private function fetchRemoteContent(string $url, int $timeoutSeconds = 30): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $this->setAudioProcessingError('Remote fetch failed: ' . $url, $httpCode, $curlError);
            return null;
        }

        return (string) $response;
    }

    private function setAudioProcessingError(string $message, int $httpCode = 0, string $details = ''): void
    {
        $parts = [$message];
        if ($httpCode > 0) {
            $parts[] = 'HTTP ' . $httpCode;
        }
        if ($details !== '') {
            $parts[] = $details;
        }

        $this->lastAudioProcessingError = implode(' | ', $parts);
        error_log('[TelegramBotService] ' . $this->lastAudioProcessingError);
    }

    private function parseIntentWithClaude(string $text, array $reporter, array $employees, array $templates, array $recentConversation = []): ?array
    {
        if ((string) getenv('TELEGRAM_SKIP_AI') === '1') {
            return null;
        }

        if (ANTHROPIC_API_KEY === '') {
            return null;
        }

        $this->lastAiRawResponse = null;
        $this->lastAiParsedResponse = null;

        $employeeHints = array_map(function ($u) {
            return [
                'id' => (int) ($u['id'] ?? 0),
                'name' => trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')),
                'username' => (string) ($u['username'] ?? ''),
            ];
        }, $employees);

        $templateHints = array_map(function ($t) {
            return [
                'id' => (int) ($t['id'] ?? 0),
                'name' => (string) ($t['name'] ?? ''),
            ];
        }, $templates);

        $recentConversationText = $this->formatConversationTurnsForPrompt($recentConversation);

        $schema = [
            'recognized' => 'boolean',
            'commands' => [
                [
                    'name' => 'manage_tasks|manage_results|manage_templates|manage_projects|create_tasks|create_goal|create_subgoal|create_template|create_project|list_tasks|list_goals|list_projects|list_subordinate_tasks|list_delegated_tasks|show_plan_fact',
                    'args' => [
                        'action' => 'create|list|update|null',
                        'scope' => 'my|delegated|subordinates|all|null',
                        'employeeQuery' => 'string|null',
                        'weekStart' => 'YYYY-MM-DD|null',
                        'targetTitle' => 'string|null',
                        'currentTitle' => 'string|null',
                        'existingTitle' => 'string|null',
                        'newTitle' => 'string|null',
                        'targetId' => 'number|null',
                        'tasks' => [
                            [
                                'title' => 'string',
                                'targetTitle' => 'string|null',
                                'currentTitle' => 'string|null',
                                'existingTitle' => 'string|null',
                                'newTitle' => 'string|null',
                                'assignee' => 'name_or_username_or_empty',
                                'date' => 'YYYY-MM-DD|today|tomorrow|null',
                                'startTime' => 'HH:MM|null',
                                'description' => 'string|null',
                                'expectedResult' => 'string|null',
                                'expectedTime' => 'minutes_number|"2 hours"|"90 min"|null',
                                'status' => 'todo|in-progress|done|postponed|null',
                                'type' => 'important-urgent|important-not-urgent|not-important-urgent|not-important-not-urgent|null',
                            ],
                        ],
                        'templates' => [
                            [
                                'name' => 'string',
                                'targetTitle' => 'string|null',
                                'currentTitle' => 'string|null',
                                'existingTitle' => 'string|null',
                                'newTitle' => 'string|null',
                                'description' => 'string|null',
                                'assignee' => 'name_or_username_or_empty',
                                'expectedResult' => 'string|null',
                                'expectedTime' => 'minutes_number|"2 hours"|"90 min"|null',
                                'repeatType' => 'daily|weekly|monthly|none|null',
                                'repeatDay' => 'Пн|Вт|Ср|Чт|Пт|Сб|Нд|null',
                                'startTime' => 'HH:MM|null',
                                'type' => 'important-urgent|important-not-urgent|not-important-urgent|not-important-not-urgent|null',
                            ],
                        ],
                        'name' => 'string|null',
                        'title' => 'string|null',
                        'description' => 'string|null',
                        'assignee' => 'name_or_username_or_empty',
                        'expectedResult' => 'string|null',
                        'expectedTime' => 'minutes_number|"2 hours"|"90 min"|null',
                        'repeatType' => 'daily|weekly|monthly|none|null',
                        'repeatDay' => 'Пн|Вт|Ср|Чт|Пт|Сб|Нд|null',
                        'startTime' => 'HH:MM|null',
                        'results' => [
                            [
                                'title' => 'string',
                                'targetTitle' => 'string|null',
                                'currentTitle' => 'string|null',
                                'existingTitle' => 'string|null',
                                'newTitle' => 'string|null',
                                'description' => 'string|null',
                                'assignee' => 'name_or_username_or_empty',
                                'status' => 'active|done|completed|null',
                                'children' => 'array_of_the_same_result_nodes|null',
                            ],
                        ],
                        'children' => 'array_of_the_same_result_nodes|null',
                        'subGoals' => [
                            [
                                'title' => 'string',
                                'description' => 'string|null',
                                'assignee' => 'name_or_username_or_empty',
                            ],
                        ],
                        'parentTitle' => 'string|null',
                        'parentGoalTitle' => 'string|null',
                        'parentGoalId' => 'number|null',
                        'parentId' => 'number|null',
                        'date' => 'YYYY-MM-DD|today|tomorrow|null',
                        'status' => 'active|todo|in-progress|done|postponed|all|null',
                    ],
                ],
            ],
            'items' => [
                [
                    'kind' => 'goal|task',
                    'title' => 'string',
                    'description' => 'string|null',
                    'assignee' => 'name_or_username_or_empty',
                    'date' => 'YYYY-MM-DD|today|tomorrow|null',
                    'startTime' => 'HH:MM|null',
                    'children' => 'array_of_the_same_goal_nodes|null',
                    'subGoals' => [
                        [
                            'title' => 'string',
                            'description' => 'string|null',
                            'assignee' => 'name_or_username_or_empty',
                        ],
                    ],
                    'expectedResult' => 'string|null',
                    'expectedTime' => 'minutes_number|"2 hours"|"90 min"|null',
                    'type' => 'important-urgent|important-not-urgent|not-important-urgent|not-important-not-urgent|null',
                ],
            ],
        ];

        $prompt = "You are an intent parser for a Ukrainian task/goal manager.\n"
            . "User can dictate one or multiple goals and tasks in free text.\n"
            . "Return ONLY strict JSON and nothing else.\n\n"
            . "Current user (reporter): " . json_encode([
                'id' => (int) ($reporter['id'] ?? 0),
                'name' => trim((string) ($reporter['first_name'] ?? '') . ' ' . (string) ($reporter['last_name'] ?? '')),
                'username' => (string) ($reporter['username'] ?? ''),
            ], JSON_UNESCAPED_UNICODE) . "\n\n"
            . "Employees: " . json_encode($employeeHints, JSON_UNESCAPED_UNICODE) . "\n"
            . "Templates: " . json_encode($templateHints, JSON_UNESCAPED_UNICODE) . "\n"
            . "Target schema: " . json_encode($schema, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "Current date: " . date('Y-m-d') . "\n\n"
            . ($recentConversationText !== '' ? "Recent conversation (oldest to newest):\n" . $recentConversationText . "\n\n" : '')
            . "Available backend commands:\n"
            . "- manage_tasks: generic command for task requests. action=create, list, or update. scope=my|delegated|subordinates.\n"
            . "- manage_results: generic command for goal/subgoal/result requests. action=create, list, or update. scope=my|delegated|subordinates|all.\n"
            . "- manage_templates: task template requests. action=create, update, or delete. Use templates[] or a single name/title payload. For delete, set action=delete and targetTitle=template name.\n"
            . "- manage_projects: project requests. action=list (show all company projects) or create (new project). For create use name and optionally description.\n"
            . "- create_project: shorthand for creating a project. Use args.name and optionally args.description.\n"
            . "- list_projects: shorthand for listing company projects.\n"
            . "- show_plan_fact: return weekly plan/fact summary for me or my subordinates. Use scope=my|subordinates|all and optionally employeeQuery and weekStart.\n"
            . "- Legacy commands still exist, but prefer the generic manage_* commands whenever possible.\n\n"
            . "Rules:\n"
            . "1) Prefer commands[] for all recognized requests. Let the model decide which generic command to use based on intent. You may return multiple commands in one response.\n"
            . "2) Prefer manage_results for any goal, subgoal, nested subgoal, or result-tree request. Use manage_tasks for task requests. Use manage_templates when the user explicitly asks to create, edit, or delete a reusable template. Use manage_projects for any project-related request.\n"
            . "3) For create requests around goals/subgoals, return manage_results with action=create. Put new nodes into results[]. If the user wants to add children under an existing goal or subgoal, set parentTitle and put new nested nodes into results[].\n"
            . "4) Results may be nested recursively using children[]. This is how you represent subgoals inside subgoals.\n"
            . "5) For retrieval requests like 'виведи мої цілі', 'покажи цілі', 'які в мене цілі', use manage_results with action=list and scope=my.\n"
            . "6) For task retrieval use manage_tasks with action=list and the appropriate scope.\n"
            . "7) For task creation, expectedResult, date, and expectedTime are mandatory. If any of them is missing, keep the task as a create intent, but leave the missing fields null. Never invent these values.\n"
            . "8) Do not use create actions unless the user explicitly asks to create, add, append, plan, record, or put something under an existing item.\n"
            . "9) If the user says something like 'до підцілі X додай ще підцілі A, B', the correct output is manage_results with action=create, parentTitle='X', and results containing A and B, optionally with their own children.\n"
            . "10) For phrases like 'ок, виведи тепер мої цілі' the correct action is list, not create.\n"
            . "11) Keep titles concise and structured.\n"
            . "12) If the user asks to create a template, use manage_templates with template name and any recognized repeat settings (daily/weekly/monthly), repeat day, start time, assignee, expectedResult, expectedTime.\n"
            . "13) If the user asks to edit, rename, reschedule, mark complete, change assignee, or otherwise modify an existing task/goal/template, use action=update. Put the current item name into targetTitle and changed fields into newTitle/date/status/assignee/expectedTime/etc. Do not use create for edits.\n"
            . "14) For goal/result updates, set status=done or completed when the user wants to mark it completed.\n"
            . "15) If the user asks for plan-fact, weekly plan status, or fact summary for me or subordinates, use show_plan_fact instead of task or goal commands.\n"
            . "16) If the user asks to show, list, or create projects, use manage_projects with action=list or action=create (and args.name for create).\n"
            . "17) If the user asks to delete a template, use manage_templates with action=delete and set targetTitle to the template name.\n"
            . "18) If the message is nonsense, unreadable, or not a supported request -> return recognized=false, commands=[], items=[].\n"
            . "19) Keep output valid JSON.\n\n"
            . "User text:\n" . $text;

        $payload = [
            'model' => 'claude-3-5-sonnet-20241022',  // VERSION_CHECK: 2026-04-16_claude_model_v1
            'max_tokens' => 1800,
            'temperature' => 0,
            'system' => 'You only output strict JSON.',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->lastAiRawResponse = $raw !== false ? (string) $raw : null;

        if ($raw === false || $http >= 400) {
            return null;
        }

        $decoded = json_decode($raw, true);
        $textOut = (string) ($decoded['content'][0]['text'] ?? '');
        if ($textOut === '') {
            return null;
        }

        $jsonString = $this->extractJsonBlock($textOut);
        $parsed = json_decode($jsonString, true);

        if (!is_array($parsed)) {
            return null;
        }

        if (!isset($parsed['commands']) || !is_array($parsed['commands'])) {
            $parsed['commands'] = [];
        }

        if (!isset($parsed['items']) || !is_array($parsed['items'])) {
            $parsed['items'] = [];
        }

        if (!array_key_exists('recognized', $parsed)) {
            $parsed['recognized'] = !empty($parsed['commands']) || !empty($parsed['items']);
        }

        $this->lastAiParsedResponse = json_encode($parsed, JSON_UNESCAPED_UNICODE);

        return $parsed;
    }

    private function extractJsonBlock(string $text): string
    {
        $trimmed = trim($text);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?/i', '', $trimmed);
            $trimmed = preg_replace('/```$/', '', (string) $trimmed);
            $trimmed = trim((string) $trimmed);
        }

        $firstBrace = strpos($trimmed, '{');
        $lastBrace = strrpos($trimmed, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            return substr($trimmed, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        return $trimmed;
    }

    private function persistParsedItems(int $companyId, int $reporterId, array $items): array
    {
        $summary = ['goals' => 0, 'subGoals' => 0, 'tasks' => 0, 'created' => [], 'task_blocks' => []];
        $users = $this->findUsersByCompany($companyId);

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $kind = strtolower(trim((string) ($item['kind'] ?? '')));
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $description = trim((string) ($item['description'] ?? ''));
            $assigneeText = trim((string) ($item['assignee'] ?? ''));
            $assigneeId = $this->resolveAssigneeId($users, $assigneeText, $reporterId);

            if ($kind === 'goal') {
                $this->persistGoalNode($companyId, $reporterId, $users, $item, null, $assigneeId, $summary);

                continue;
            }

            // default: task
            $expectedResult = trim((string) ($item['expectedResult'] ?? ''));
            $type = (string) ($item['type'] ?? 'important-not-urgent');
            $allowedTypes = ['important-urgent', 'important-not-urgent', 'not-important-urgent', 'not-important-not-urgent'];
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'important-not-urgent';
            }

            $this->db->insert('tasks', [
                'title' => mb_substr($title, 0, 255),
                'company_id' => $companyId,
                'assignee_id' => $assigneeId,
                'reporter_id' => $reporterId,
                'status' => 'todo',
                'type' => $type,
                'description' => $description !== '' ? $description : null,
                'expected_result' => $expectedResult !== '' ? $expectedResult : null,
                'actual_result' => null,
                'due_date' => $this->normalizePersistedTaskDateTime($item['date'] ?? $item['dueDate'] ?? null, $item['startTime'] ?? $item['start_time'] ?? null),
                'expected_time' => $this->normalizePersistedTaskExpectedTime($item['expectedTime'] ?? $item['expected_time'] ?? null),
                'actual_time' => null,
            ]);

            $summary['tasks']++;
            $summary['created'][] = 'Задача: ' . mb_substr($title, 0, 255);
            $summary['task_blocks'][] = $this->buildTaskCreatedReplyBlock([
                'title' => $title,
                'assignee_name' => $this->findUserDisplayNameById($users, $assigneeId),
                'due_date' => $this->normalizePersistedTaskDateTime($item['date'] ?? $item['dueDate'] ?? null, $item['startTime'] ?? $item['start_time'] ?? null),
                'start_time' => $this->normalizePersistedTaskStartTime($item['startTime'] ?? $item['start_time'] ?? null),
                'description' => $description !== '' ? $description : null,
                'expected_result' => $expectedResult !== '' ? $expectedResult : null,
                'expected_time' => $this->normalizePersistedTaskExpectedTime($item['expectedTime'] ?? $item['expected_time'] ?? null),
                'type' => $type,
            ]);
        }

        return $summary;
    }

    private function persistGoalNode(int $companyId, int $reporterId, array $users, array $node, ?int $parentId, int $defaultAssigneeId, array &$summary): void
    {
        $title = trim((string) ($node['title'] ?? ''));
        if ($title === '') {
            return;
        }

        $description = trim((string) ($node['description'] ?? ''));
        $assigneeText = trim((string) ($node['assignee'] ?? ''));
        $assigneeId = $this->resolveAssigneeId($users, $assigneeText, $defaultAssigneeId ?: $reporterId);

        $this->db->insert('results', [
            'company_id' => $companyId,
            'parent_id' => $parentId,
            'title' => mb_substr($title, 0, 255),
            'description' => $description !== '' ? $description : null,
            'reporter_id' => $reporterId,
            'assignee_id' => $assigneeId,
            'completed' => 0,
        ]);

        $resultId = (int) $this->db->lastInsertId();
        if ($parentId === null) {
            $summary['goals']++;
            $summary['created'][] = 'Ціль: ' . mb_substr($title, 0, 255);
        } else {
            $summary['subGoals']++;
            $summary['created'][] = 'Підціль: ' . mb_substr($title, 0, 255);
        }

        foreach ($this->extractIntentChildNodes($node) as $childNode) {
            $this->persistGoalNode($companyId, $reporterId, $users, $childNode, $resultId, $assigneeId ?: $defaultAssigneeId, $summary);
        }
    }

    private function extractIntentChildNodes(array $node): array
    {
        $children = $node['children'] ?? $node['subGoals'] ?? [];
        return is_array($children) ? array_values(array_filter($children, 'is_array')) : [];
    }

    private function resolveAssigneeId(array $users, string $assigneeText, int $defaultId): int
    {
        $needle = mb_strtolower(trim($assigneeText));
        if ($needle === '') {
            return $defaultId;
        }

        foreach ($users as $u) {
            $first = mb_strtolower((string) ($u['first_name'] ?? ''));
            $last = mb_strtolower((string) ($u['last_name'] ?? ''));
            $full = trim($first . ' ' . $last);
            $username = mb_strtolower((string) ($u['username'] ?? ''));

            if ($needle === $first || $needle === $last || $needle === $username || $needle === $full) {
                return (int) ($u['id'] ?? $defaultId);
            }

            if ($first !== '' && str_contains($needle, $first)) {
                return (int) ($u['id'] ?? $defaultId);
            }

            if ($last !== '' && str_contains($needle, $last)) {
                return (int) ($u['id'] ?? $defaultId);
            }
        }

        return $defaultId;
    }

    private function createIntentFallbackDraft(int $companyId, int $reporterId, array $items, int $chatId, string $companyName = '', int $companyCount = 1): void
    {
        if (empty($items)) {
            $this->sendMessage($chatId, $this->buildUnrecognizedMessage());
            return;
        }

        $draftKey = $this->storePendingIntentDraft($reporterId, $companyId, $items);
        if ($draftKey === '') {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося підготувати підтвердження', [
                'Спробуйте ще раз.',
            ]));
            return;
        }

        $reply = $this->buildDraftPreview($items);

        if ($companyCount > 1 && $companyName !== '') {
            $reply[] = '• Компанія: ' . $companyName;
        }

        // Check for unresolved assignees and offer employee picker
        $users = $this->findUsersByCompany($companyId);
        $hasUnresolved = false;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $assigneeText = trim((string) ($item['assignee'] ?? ''));
            if ($assigneeText !== '') {
                $resolvedId = $this->resolveAssigneeId($users, $assigneeText, 0);
                if ($resolvedId === 0) {
                    $hasUnresolved = true;
                    break;
                }
            }
        }

        $keyboard = [];

        if ($hasUnresolved) {
            $reply[] = '';
            $reply[] = '⚠️ Не вдалось визначити виконавця. Оберіть зі списку:';

            $employeeRow = [];
            foreach ($users as $u) {
                $uid = (int) ($u['id'] ?? 0);
                if ($uid <= 0 || $uid === $reporterId) {
                    continue;
                }
                $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                if ($name === '') {
                    $name = (string) ($u['username'] ?? 'User #' . $uid);
                }
                $employeeRow[] = ['text' => $name, 'callback_data' => 'tg_pick_assignee:' . $draftKey . ':' . $uid];
                if (count($employeeRow) === 2) {
                    $keyboard[] = $employeeRow;
                    $employeeRow = [];
                }
            }
            if (!empty($employeeRow)) {
                $keyboard[] = $employeeRow;
            }
        }

        $keyboard[] = [
            ['text' => $hasUnresolved ? '📌 Залишити на мені' : 'Підтвердити', 'callback_data' => 'tg_confirm_intent:' . $draftKey],
            ['text' => 'Скасувати', 'callback_data' => 'tg_cancel_intent:' . $draftKey],
        ];

        $this->sendMessage($chatId, implode("\n", $reply), [
            'inline_keyboard' => $keyboard,
        ]);
    }

    private function buildFallbackItemsFromText(string $text): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '') {
            return [];
        }

        if ($this->looksLikeDeleteRequest($normalized)) {
            return [];
        }

        if ($this->looksLikeGoalListRequest($normalized)) {
            return [];
        }

        $nonTaskRequestMarkers = ['посилан', 'платформ', 'систем', 'список', 'покажи', 'виведи', 'праців', 'співроб', 'користувач', 'компан', 'шаблон', 'план-факт', 'логін', 'login', 'парол'];
        foreach ($nonTaskRequestMarkers as $marker) {
            if (str_contains(mb_strtolower($normalized), $marker)) {
                return [];
            }
        }

        $lower = mb_strtolower($normalized);
        $hasGoalLanguage = mb_strpos($lower, 'ціль') !== false || mb_strpos($lower, 'цілі') !== false || mb_strpos($lower, 'підціль') !== false || mb_strpos($lower, 'під ціл') !== false;

        if (!$hasGoalLanguage) {
            return $this->buildFallbackTaskItemsFromText($normalized);
        }

        return $this->extractGoalItemsFromText($normalized);
    }

    private function buildFallbackTaskItemFromText(string $text): ?array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '') {
            return null;
        }

        $date = $this->extractFallbackTaskDate($normalized);
        $startTime = $this->extractFallbackTaskStartTime($normalized);
        $expectedResult = $this->extractFallbackTaskExpectedResult($normalized);
        $expectedTime = $this->extractFallbackTaskExpectedTime($normalized);
        $title = $this->cleanupTaskFallbackTitle($normalized);

        if ($title === '') {
            return null;
        }

        return [
            'kind' => 'task',
            'title' => mb_substr($title, 0, 160),
            'description' => null,
            'assignee' => '',
            'expectedResult' => $expectedResult,
            'expectedTime' => $expectedTime,
            'date' => $date,
            'startTime' => $startTime,
            'type' => 'important-not-urgent',
        ];
    }

    private function buildFallbackTaskItemsFromText(string $text): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '') {
            return [];
        }

        $repeatedTaskItems = $this->extractRepeatedTaskCreationItemsFromText($normalized);
        if (!empty($repeatedTaskItems)) {
            return $repeatedTaskItems;
        }

        if (!preg_match('/одна\s+задача|перша\s+задача|друга\s+задача|третя\s+задача/ui', $normalized)) {
            $taskItem = $this->buildFallbackTaskItemFromText($normalized);
            return $taskItem ? [$taskItem] : [];
        }

        $date = $this->extractFallbackTaskDate($normalized);
        $startTime = $this->extractFallbackTaskStartTime($normalized);
        $expectedResult = $this->extractFallbackTaskExpectedResult($normalized);
        $expectedTime = $this->extractFallbackTaskExpectedTime($normalized);
        preg_match_all('/([^\-–—,.]+?)\s*[\-–—]\s*(?:одна|перша|друга|третя|четверта)\s+задача/iu', $normalized, $matches);

        $items = [];
        foreach ($matches[1] ?? [] as $fragment) {
            $title = $this->cleanupTaskFallbackTitle((string) $fragment);
            if ($title === '') {
                continue;
            }

            $items[] = [
                'kind' => 'task',
                'title' => mb_substr($title, 0, 160),
                'description' => null,
                'assignee' => '',
                'expectedResult' => $expectedResult,
                'expectedTime' => $expectedTime,
                'date' => $date,
                'startTime' => $startTime,
                'type' => 'important-not-urgent',
            ];
        }

        return $items;
    }

    private function buildDeterministicPlannerCommands(string $text): array
    {
        $commands = [];

        if ($this->looksLikeTaskListRequest($text) && $this->looksLikeGoalListRequest($text)) {
            $commands[] = [
                'name' => 'manage_tasks',
                'args' => $this->buildTaskListArgsFromText($text),
            ];
            $commands[] = [
                'name' => 'list_goals',
                'args' => ['status' => 'all'],
            ];

            return $commands;
        }

        $repeatedTaskItems = $this->extractRepeatedTaskCreationItemsFromText($text);
        if (!empty($repeatedTaskItems)) {
            if ($this->tasksRequireClarification($repeatedTaskItems)) {
                return [];
            }

            $commands[] = [
                'name' => 'create_tasks',
                'args' => ['tasks' => $repeatedTaskItems],
            ];
        }

        return $commands;
    }

    private function extractRepeatedTaskCreationItemsFromText(string $text): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '') {
            return [];
        }

        if (preg_match_all('/(?:додай|додати|створи|створити|заведи|зроби|зробити|постав|поставити)\s+(?:мені\s+|нам\s+)?(?:задач(?:у|і|а)?|таск(?:и|а|у)?)/ui', $normalized, $matches) < 2) {
            return [];
        }

        $segments = preg_split('/\s+(?:і|та)\s+(?=(?:додай|додати|створи|створити|заведи|зроби|зробити|постав|поставити)\s+(?:мені\s+|нам\s+)?(?:задач(?:у|і|а)?|таск(?:и|а|у)?))/ui', $normalized);
        if (!is_array($segments) || count($segments) < 2) {
            return [];
        }

        $items = [];
        foreach ($segments as $segment) {
            $item = $this->buildFallbackTaskItemFromText((string) $segment);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return count($items) >= 2 ? $items : [];
    }

    private function canCreateFallbackTasksDirectly(array $items): bool
    {
        if (empty($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!is_array($item) || strtolower(trim((string) ($item['kind'] ?? 'task'))) !== 'task') {
                return false;
            }
        }

        return !$this->tasksRequireClarification($items);
    }

    private function tasksRequireClarification(array $tasks): bool
    {
        return $this->buildTaskClarificationReply(['items' => $tasks]) !== null;
    }

    private function extractGoalItemsFromText(string $text): array
    {
        $goalTitle = '';
        $subGoals = [];

        if (preg_match('/(?:ціл(?:ь|і)\s+(?:постав(?:ити)?|мені|нам|треба|щоб|вийти|досягти)?\s*)(.+?)(?=(?:[\.!?]\s*(?:і\s+)?під\s*ціл|$))/ui', $text, $match)) {
            $goalTitle = $this->cleanupIntentFragment($match[1]);
        }

        if (preg_match_all('/під\s*ціл(?:ь|і)\s+(?:постав(?:ити)?|додати|зробити)?\s*(.+?)(?=(?:[\.!?]\s*(?:а\s+)?(?:всередині|і\s+ще)|$))/ui', $text, $matches)) {
            foreach ($matches[1] as $fragment) {
                $clean = $this->cleanupIntentFragment($fragment);
                if ($clean !== '') {
                    $subGoals[] = [
                        'title' => mb_substr($clean, 0, 255),
                        'description' => null,
                        'assignee' => '',
                    ];
                }
            }
        }

        if ($goalTitle === '') {
            $goalTitle = $this->cleanupIntentFragment($text);
        }

        if ($goalTitle === '') {
            return [];
        }

        return [
            [
                'kind' => 'goal',
                'title' => mb_substr($goalTitle, 0, 255),
                'description' => null,
                'assignee' => '',
                'subGoals' => $subGoals,
                'expectedResult' => null,
                'type' => null,
            ]
        ];
    }

    private function cleanupIntentFragment(string $fragment): string
    {
        $clean = trim($fragment, " \t\n\r\0\x0B.,:;!?");
        $clean = preg_replace('/^(мені|нам|будь ласка|треба|щоб|і)\s+/ui', '', (string) $clean);
        $clean = preg_replace('/^(постав(?:ити)?|додати|зробити)\s+/ui', '', (string) $clean);
        return trim((string) $clean, " \t\n\r\0\x0B.,:;!?");
    }

    private function cleanupTaskFallbackTitle(string $text): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($title === '') {
            return '';
        }

        $title = preg_replace('/^(так|ок|добре|гаразд|будь\s+ласка)[,\s-]+/ui', '', (string) $title);
        $title = preg_replace('/^(створи|створити|додай|додати|заведи|зроби|зробити|постав|поставити)\s+(?:мені\s+|нам\s+)?(?:задач(?:у|і|а|і)|таск(?:и|а|у)?)\s*/ui', '', (string) $title);
        $title = preg_replace('/^(створи|створити|додай|додати|заведи|зроби|зробити|постав|поставити)\s+/ui', '', (string) $title);
        $title = preg_replace('/^(?:в|для)\s+[^,.;!?\-]{2,80}\s*[,\-]\s*/ui', '', (string) $title);
        $title = preg_replace('/\bна\s+сьогодні\b|\bсьогодні\b|\bна\s+завтра\b|\bзавтра\b/ui', '', (string) $title);
        $title = preg_replace('/\bочікуван(?:ий|ого)\s+результат\b\s*[:\-]?\s*.+$/ui', '', (string) $title);
        $title = preg_replace('/\bочікуван(?:ий|ого)\s+час(?:\s+виконання)?\b\s*[:\-]?\s*.+$/ui', '', (string) $title);
        $title = preg_replace('/\bна\s+коли\b\s*[:\-]?\s*.+$/ui', '', (string) $title);
        $title = preg_replace('/[,.;!?-]?\s*(?:і\s+)?(?:постав|виділи|заклади|дай|зроби)\s+(?:на\s+це\s+)?\d+\s*(?:год|годин|година|хв|хвилин|хвилина|min|minutes?).*$/ui', '', (string) $title);
        $title = preg_replace('/[,.;!?-]?\s*(?:на\s+це|це\s+займе|займе)\s+\d+\s*(?:год|годин|година|хв|хвилин|хвилина|min|minutes?).*$/ui', '', (string) $title);

        if (preg_match('/[,\-]\s*(.+)$/u', $title, $match)) {
            $tail = trim((string) $match[1]);
            if ($tail !== '' && mb_strlen($tail) >= 6 && preg_match('/[\p{L}]{3,}/u', $tail)) {
                $title = $tail;
            }
        }

        return trim((string) $title, " \t\n\r\0\x0B.,:;!?");
    }

    private function extractFallbackTaskDate(string $text): ?string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\bна\s+сьогодні\b|\bсьогодні\b/ui', $normalized)) {
            return 'today';
        }

        if (preg_match('/\bна\s+завтра\b|\bзавтра\b/ui', $normalized)) {
            return 'tomorrow';
        }

        if (preg_match('/\b(\d{1,2})[\.\/](\d{1,2})(?:[\.\/](\d{2,4}))?\b/u', $normalized, $match)) {
            $day = (int) $match[1];
            $month = (int) $match[2];
            $year = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : (int) date('Y');

            if ($year < 100) {
                $year += 2000;
            }

            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }

    private function extractFallbackTaskExpectedResult(string $text): ?string
    {
        if (!preg_match('/очікуван(?:ий|ого)\s+результат\s*[:\-]?\s*(.+?)(?=(?:\s+очікуван(?:ий|ого)\s+час|\s+на\s+коли|$))/ui', $text, $match)) {
            return null;
        }

        $value = trim((string) $match[1], " \t\n\r\0\x0B.,:;!?");
        $value = preg_replace('/[,.;!?-]?\s*задач(?:а|у|і|и)?\s*[—:-]?.*$/ui', '', (string) $value);
        return $value !== '' ? mb_substr($value, 0, 500) : null;
    }

    private function extractFallbackTaskExpectedTime(string $text): ?string
    {
        if (preg_match('/очікуван(?:ий|ого)\s+час(?:\s+виконання)?\s*[:\-]?\s*(.+?)(?=(?:\s+очікуван(?:ий|ого)\s+результат|\s+на\s+коли|$))/ui', $text, $match)) {
            $value = trim((string) $match[1], " \t\n\r\0\x0B.,:;!?");
            return $value !== '' ? mb_substr($value, 0, 120) : null;
        }

        if (preg_match('/\b(?:це\s+)?(?:одна|одну|один)\s+год(?:ина|ину|ини)?\b/ui', $text)) {
            return '1 година';
        }

        if (preg_match('/\b(?:це\s+)?година(?:\s+часу)?\b/ui', $text)) {
            return '1 година';
        }

        if (preg_match('/(?:постав|виділи|заклади|дай|зроби)?\s*(?:на\s+це\s+|це\s+займе\s+|займе\s+)?(\d+)\s*(год|годин|година|хв|хвилин|хвилина|min|minutes?)/ui', $text, $match)) {
            return trim((string) ($match[1] . ' ' . $match[2]));
        }

        if (preg_match('/\b(\d+[\.,]?\d*)\s*(год|годин|година|години)\b/ui', $text, $match)) {
            return trim(str_replace(',', '.', (string) $match[1]) . ' ' . $match[2]);
        }

        if (preg_match('/\b(\d+)\s*(хв|хвилин|хвилина|хвилини)\b/ui', $text, $match)) {
            return trim((string) ($match[1] . ' ' . $match[2]));
        }

        if (preg_match('/\bпів\s*години\b|\bпівгодини\b/ui', $text)) {
            return '30 хв';
        }

        if (preg_match('/\bпів\s*тори\s*години\b|\bпівтори\s*години\b/ui', $text)) {
            return '1.5 години';
        }

        return null;
    }

    private function extractFallbackTaskStartTime(string $text): ?string
    {
        if (preg_match('/\b(?:о|в)\s*(\d{1,2})[:\.](\d{2})\b/u', $text, $match)) {
            $hours = (int) $match[1];
            $minutes = (int) $match[2];
            if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
                return sprintf('%02d:%02d', $hours, $minutes);
            }
        }

        if (preg_match('/\b(?:о|в)\s*(\d{1,2})\b/u', $text, $match)) {
            $hours = (int) $match[1];
            if ($hours >= 0 && $hours <= 23) {
                return sprintf('%02d:00', $hours);
            }
        }

        if (preg_match('/\b(\d{1,2})[:\.](\d{2})\b/u', $text, $match)) {
            $hours = (int) $match[1];
            $minutes = (int) $match[2];
            if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
                return sprintf('%02d:%02d', $hours, $minutes);
            }
        }

        return null;
    }

    private function buildDraftPreview(array $items): array
    {
        $lines = ['Я підготував чернетку:'];

        foreach (array_slice($items, 0, 3) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $kind = strtolower(trim((string) ($item['kind'] ?? 'task')));
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $assignee = trim((string) ($item['assignee'] ?? ''));

            if ($kind === 'goal') {
                $lines[] = '🌳 Ціль';
                $lines[] = '└─ ' . $title;
                if ($assignee !== '') {
                    $lines[] = '   👤 ' . $assignee;
                }
                $remaining = 10;
                $children = $this->extractIntentChildNodes($item);
                $this->appendDraftTreeLines($lines, $children, '', $remaining);
            } else {
                $lines[] = '📝 Задача';
                $lines[] = '└─ ' . $title;
                if ($assignee !== '') {
                    $lines[] = '   👤 Виконавець: ' . $assignee;
                }
                $dueDate = trim((string) ($item['date'] ?? $item['dueDate'] ?? ''));
                if ($dueDate !== '') {
                    $lines[] = '   📅 ' . $dueDate;
                }
            }

            $lines[] = '';
        }

        while (!empty($lines) && end($lines) === '') {
            array_pop($lines);
        }

        $lines[] = '';
        $lines[] = 'Підтвердьте створення, якщо все правильно.';

        return $lines;
    }

    private function buildTaskClarificationReply(?array $parsed): ?string
    {
        if (!is_array($parsed)) {
            return null;
        }

        $taskLabels = [];

        foreach ($this->extractTaskCandidatesFromParsed($parsed) as $task) {
            if (!is_array($task)) {
                continue;
            }

            $missing = [];
            if (trim((string) ($task['expectedResult'] ?? '')) === '') {
                $missing[] = 'очікуваний результат';
            }

            $dateValue = trim((string) ($task['date'] ?? $task['dueDate'] ?? ''));
            if ($dateValue === '') {
                $missing[] = 'на коли';
            }

            $expectedTimeValue = trim((string) ($task['expectedTime'] ?? $task['expected_time'] ?? ''));
            if ($expectedTimeValue === '') {
                $missing[] = 'очікуваний час виконання';
            }

            if (empty($missing)) {
                continue;
            }

            $title = trim((string) ($task['title'] ?? 'Нова задача'));
            $taskLabels[] = '• ' . $title . ' — бракує: ' . implode(', ', $missing);
        }

        if (empty($taskLabels)) {
            return null;
        }

        return implode("\n", array_merge(
            ['Щоб створити задачу, мені треба ще уточнити обовʼязкові поля:'],
            $taskLabels,
            ['', 'Надішліть одним повідомленням у довільній формі, наприклад:', 'Очікуваний результат: ...', 'На коли: 15.04.26', 'Очікуваний час виконання: 2 години']
        ));
    }

    private function prepareTaskClarificationReply(string $reply, array $items, int $userId, int $companyId): string
    {
        if ($userId <= 0 || $companyId <= 0 || empty($items)) {
            return $reply;
        }

        $draftKey = $this->storePendingIntentDraft($userId, $companyId, $items, 'task_clarification');
        if ($draftKey === '') {
            return $reply;
        }

        return $reply . "\n\n" . 'Можете просто відповісти наступним повідомленням тільки з відсутніми полями, наприклад: на завтра, 1 година, очікуваний результат ...';
    }

    private function buildTemplateClarificationReply(?array $parsed): ?string
    {
        if (!is_array($parsed)) {
            return null;
        }

        $templateLabels = [];
        foreach ($this->extractTemplateCandidatesFromParsed($parsed) as $template) {
            if (!is_array($template)) {
                continue;
            }

            $expectedTimeValue = trim((string) ($template['expectedTime'] ?? $template['expected_time'] ?? ''));
            if ($expectedTimeValue !== '') {
                continue;
            }

            $name = trim((string) ($template['name'] ?? $template['title'] ?? 'Новий шаблон'));
            $templateLabels[] = '• ' . $name . ' — бракує: очікуваний час виконання';
        }

        if (empty($templateLabels)) {
            return null;
        }

        return implode("\n", array_merge(
            ['Щоб створити шаблон, мені треба ще вказати очікуваний час виконання:'],
            $templateLabels,
            ['', 'Надішліть одним повідомленням, наприклад:', 'Очікуваний час виконання: 2 години']
        ));
    }

    private function extractTaskCandidatesFromParsed(array $parsed): array
    {
        $tasks = [];

        foreach (($parsed['commands'] ?? []) as $command) {
            if (!is_array($command)) {
                continue;
            }

            $name = strtolower(trim((string) ($command['name'] ?? $command['command'] ?? '')));
            $args = $command['args'] ?? [];
            if (!is_array($args)) {
                continue;
            }

            if ($name === 'manage_tasks') {
                $action = strtolower(trim((string) ($args['action'] ?? '')));
                if ($action === '' || $action === 'create') {
                    $commandTasks = $args['tasks'] ?? [];
                    if (is_array($commandTasks) && !empty($commandTasks)) {
                        foreach ($commandTasks as $task) {
                            if (is_array($task)) {
                                $tasks[] = $task;
                            }
                        }
                    } else {
                        $title = trim((string) ($args['title'] ?? ''));
                        if ($title !== '') {
                            $tasks[] = $args;
                        }
                    }
                }
            }

            if ($name === 'create_tasks') {
                foreach (($args['tasks'] ?? []) as $task) {
                    if (is_array($task)) {
                        $tasks[] = $task;
                    }
                }
            }
        }

        foreach (($parsed['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (strtolower(trim((string) ($item['kind'] ?? ''))) === 'task') {
                $tasks[] = $item;
            }
        }

        return $tasks;
    }

    private function extractTemplateCandidatesFromParsed(array $parsed): array
    {
        $templates = [];

        foreach (($parsed['commands'] ?? []) as $command) {
            if (!is_array($command)) {
                continue;
            }

            $name = strtolower(trim((string) ($command['name'] ?? $command['command'] ?? '')));
            if ($name !== 'manage_templates' && $name !== 'create_template') {
                continue;
            }

            $args = $command['args'] ?? [];
            if (!is_array($args)) {
                continue;
            }

            $action = strtolower(trim((string) ($args['action'] ?? 'create')));
            if ($action !== '' && $action !== 'create') {
                continue;
            }

            $commandTemplates = $args['templates'] ?? [];
            if (is_array($commandTemplates) && !empty($commandTemplates)) {
                foreach ($commandTemplates as $template) {
                    if (is_array($template)) {
                        $templates[] = $template;
                    }
                }
                continue;
            }

            $templateName = trim((string) ($args['name'] ?? $args['title'] ?? ''));
            if ($templateName !== '') {
                $templates[] = $args;
            }
        }

        return $templates;
    }

    private function appendDraftTreeLines(array &$lines, array $children, string $prefix, int &$remaining): void
    {
        if ($remaining <= 0) {
            return;
        }

        $validChildren = array_values(array_filter($children, 'is_array'));
        $childrenCount = count($validChildren);

        foreach ($validChildren as $index => $child) {
            if ($remaining <= 0) {
                return;
            }

            $title = trim((string) ($child['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $isLast = $index === $childrenCount - 1;
            $branch = $isLast ? '└─ ' : '├─ ';
            $lines[] = $prefix . $branch . $title;
            $remaining--;

            $childPrefix = $prefix . ($isLast ? '   ' : '│  ');
            $this->appendDraftTreeLines($lines, $this->extractIntentChildNodes($child), $childPrefix, $remaining);
        }

        if ($remaining <= 0) {
            $lines[] = $prefix . '└─ ...';
        }
    }

    private function buildCreatedItemsReply(array $summary, string $companyName = '', int $companyCount = 1): string
    {
        $lines = ['✅ Готово, збережено у FINEKO:'];

        foreach (array_slice($summary['created'] ?? [], 0, 5) as $itemLabel) {
            $lines[] = '• ' . $itemLabel;
        }

        $createdCount = count($summary['created'] ?? []);
        if ($createdCount > 5) {
            $lines[] = '• Ще елементів: ' . ($createdCount - 5);
        }

        if ($summary['goals'] > 0) {
            $lines[] = '📌 Цілей: ' . $summary['goals'];
        }
        if ($summary['subGoals'] > 0) {
            $lines[] = '🧩 Підцілей: ' . $summary['subGoals'];
        }
        if ($summary['tasks'] > 0) {
            $lines[] = '📝 Задач: ' . $summary['tasks'];
        }
        foreach (array_slice($summary['task_blocks'] ?? [], 0, 5) as $taskBlock) {
            $lines[] = '';
            $lines[] = (string) $taskBlock;
        }
        if ($companyCount > 1 && $companyName !== '') {
            $lines[] = '🏢 Компанія: ' . $companyName;
        }

        return implode("\n", $lines);
    }

    private function looksLikeRecognizableRequest(string $text): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '' || mb_strlen($normalized) < 3) {
            return false;
        }

        preg_match_all('/[\p{L}\p{N}]/u', $normalized, $lettersAndNumbers);
        if (count($lettersAndNumbers[0]) < 3) {
            return false;
        }

        preg_match_all('/[\p{L}]{2,}/u', $normalized, $words);
        if (count($words[0]) === 0) {
            return false;
        }

        preg_match_all('/[^\p{L}\p{N}\s]/u', $normalized, $symbols);
        if (count($symbols[0]) > count($lettersAndNumbers[0])) {
            return false;
        }

        if (preg_match('/^(.)\1{3,}$/u', $normalized)) {
            return false;
        }

        return true;
    }

    private function looksLikeCorrectionOnlyMessage(string $text): bool
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        if ($normalized === '') {
            return false;
        }

        if (!preg_match('/^не\s+.+?,\s*а\s+.+$/ui', $normalized)) {
            return false;
        }

        $actionMarkers = ['створи', 'додай', 'задач', 'таск', 'ціл', 'підціл', 'шаблон', 'покажи', 'виведи'];
        foreach ($actionMarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeTemplateCreateRequest(string $text): bool
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        if ($normalized === '') {
            return false;
        }

        $hasTemplateWord = str_contains($normalized, 'шаблон') || str_contains($normalized, 'template');
        if (!$hasTemplateWord) {
            return false;
        }

        $createMarkers = ['створи', 'створити', 'додай', 'додати', 'заведи', 'зроби', 'новий'];
        foreach ($createMarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function tryHandleFallbackTemplateRequest(string $text, int $companyId, array $reporter, int $chatId): bool
    {
        $command = $this->buildFallbackTemplateCommandFromText($text);
        if ($command === null) {
            return false;
        }

        $clarificationReply = $this->buildTemplateClarificationReply(['commands' => [$command]]);
        if ($clarificationReply !== null) {
            $this->updateInteractionLog([
                'execution_path' => 'fallback_template_clarification',
                'command_names' => 'manage_templates:create',
            ]);
            $this->sendMessage($chatId, $clarificationReply);
            return true;
        }

        $this->updateInteractionLog([
            'execution_path' => 'fallback_template_command',
            'command_names' => 'manage_templates',
        ]);

        $commandService = new TelegramIntentCommandService();
        $commandResult = $commandService->executeCommands($companyId, $reporter, [$command]);
        if (!$commandResult || empty($commandResult['reply'])) {
            return false;
        }

        $this->sendMessage($chatId, (string) $commandResult['reply']);
        return true;
    }

    private function buildFallbackTemplateCommandFromText(string $text): ?array
    {
        if (!$this->looksLikeTemplateCreateRequest($text)) {
            return null;
        }

        $name = $this->extractFallbackTemplateName($text);
        if ($name === '') {
            return null;
        }

        return [
            'name' => 'manage_templates',
            'args' => [
                'action' => 'create',
                'templates' => [
                    [
                        'name' => $name,
                        'description' => null,
                        'assignee' => '',
                        'expectedResult' => $this->extractFallbackTaskExpectedResult($text),
                        'expectedTime' => $this->extractFallbackTaskExpectedTime($text),
                        'repeatType' => $this->extractFallbackTemplateRepeatType($text),
                        'repeatDay' => $this->extractFallbackTemplateRepeatDay($text),
                        'startTime' => $this->extractFallbackTemplateStartTime($text),
                        'type' => null,
                    ]
                ],
            ],
        ];
    }

    private function extractFallbackTemplateName(string $text): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($name === '') {
            return '';
        }

        $name = preg_replace('/^(так|ок|добре|гаразд|будь\s+ласка)[,\s-]+/ui', '', (string) $name);
        $name = preg_replace('/^(?:давай\s+спробуємо\s+відреагувати\s+цей\s+шаблон[,\s-]*)/ui', '', (string) $name);
        $name = preg_replace('/^(створи|створити|додай|додати|заведи|зроби|зробити)(?:\s+для\s+мене)?\s+/ui', '', (string) $name);
        $name = preg_replace('/^(?:новий\s+)?шаблон(?:\s+задачі)?[\s,:-]*/ui', '', (string) $name);
        if (preg_match('/\bшаблон(?:у|ом|и|ів)?\b[\s,:-]*(.+)$/ui', $name, $match)) {
            $name = trim((string) $match[1]);
        }

        $name = preg_replace('/^(?:для\s+мене\s+)?(?:новий\s+)?/ui', '', (string) $name);
        $name = preg_replace('/[,.;!?-]?\s*шаблон\s+зі\s+.+$/ui', '', (string) $name);
        $name = preg_replace('/[,.;!?-]?\s*(зі|з)\s+(щоден|щотиж|щомісяч|повторенн|повторюван).+$/ui', '', (string) $name);
        $name = preg_replace('/\bо\s*\d{1,2}[:\.]\d{2}\b.*$/ui', '', (string) $name);
        $name = preg_replace('/\bочікуван(?:ий|ого)\s+результат\b\s*[:\-]?.*$/ui', '', (string) $name);
        $name = preg_replace('/\bочікуван(?:ий|ого)\s+час(?:\s+виконання)?\b\s*[:\-]?.*$/ui', '', (string) $name);
        $name = preg_replace('/[,.;!?-]?\s*(?:і\s+)?(?:зроби|зробити|постав|додай)\s+(?:його\s+)?(?:щоденним|щотижневим|щомісячним).*$|[,.;!?-]?\s*(?:щодня|щоденним|щотижня|щотижневим|щомісяця|щомісячним).*$/ui', '', (string) $name);

        if (preg_match('/^([^,.;!?]+)[,.;!?]/u', $name, $match)) {
            $firstSegment = trim((string) $match[1]);
            if ($firstSegment !== '') {
                $name = $firstSegment;
            }
        }

        return trim((string) $name, " \t\n\r\0\x0B.,:;!?");
    }

    private function extractFallbackTemplateRepeatType(string $text): ?string
    {
        $normalized = mb_strtolower($text);
        if (preg_match('/щоден|щодня|кожен\s+день|кожного\s+дня/ui', $normalized)) {
            return 'daily';
        }

        if (preg_match('/щотиж|кожного\s+тижня/ui', $normalized)) {
            return 'weekly';
        }

        if (preg_match('/щомісяч|щомісяця|кожного\s+місяця/ui', $normalized)) {
            return 'monthly';
        }

        return 'none';
    }

    private function extractFallbackTemplateRepeatDay(string $text): ?string
    {
        $normalized = mb_strtolower($text);

        return match (true) {
            preg_match('/понеділ/ui', $normalized) === 1 => 'Пн',
            preg_match('/вівтор|вiвтор/ui', $normalized) === 1 => 'Вт',
            preg_match('/серед/ui', $normalized) === 1 => 'Ср',
            preg_match('/четвер/ui', $normalized) === 1 => 'Чт',
            preg_match("/п['’`]?(?:ятниц)|пятниц/ui", $normalized) === 1 => 'Пт',
            preg_match('/субот/ui', $normalized) === 1 => 'Сб',
            preg_match('/неділ/ui', $normalized) === 1 => 'Нд',
            default => null,
        };
    }

    private function extractFallbackTemplateStartTime(string $text): ?string
    {
        if (!preg_match('/\b(?:о|на)\s*(\d{1,2})[:\.](\d{2})\b/u', $text, $match)) {
            return null;
        }

        $hours = (int) $match[1];
        $minutes = (int) $match[2];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    private function looksLikeGoalListRequest(string $text): bool
    {
        return $this->messageClassifier->isGoalListRequest($text);
    }

    private function looksLikeTaskListRequest(string $text): bool
    {
        return $this->messageClassifier->isTaskListRequest($text);
    }

    private function looksLikeDeleteRequest(string $text): bool
    {
        return $this->messageClassifier->isDeleteRequest($text);
    }

    private function handleRoutedDecision(array $routeDecision, int $chatId, int $companyId, string $companyName, int $companyCount, array $reporter): bool
    {
        $route = trim((string) ($routeDecision['route'] ?? ''));
        if ($route === '' || $route === 'planner') {
            return false;
        }

        if ($route === 'company_current') {
            $this->updateInteractionLog([
                'execution_path' => 'router_company_current',
                'command_names' => 'company:current',
            ]);
            $this->sendMessage($chatId, $this->buildCurrentCompanyReply($companyId, $companyName, $companyCount));
            return true;
        }

        if ($route === 'company_list') {
            $this->updateInteractionLog([
                'execution_path' => 'router_company_list',
                'command_names' => 'company:list',
            ]);
            $userId = (int) ($reporter['id'] ?? 0);
            $companies = $userId > 0 ? $this->findCompaniesByUser($userId) : [];
            $this->handlePrivateCompanyCommand($chatId, $userId, $companies, null);
            return true;
        }

        if ($route === 'employee_list') {
            $this->updateInteractionLog([
                'execution_path' => 'router_employee_list',
                'command_names' => 'employees:list',
            ]);
            $scope = (string) (($routeDecision['command']['args']['scope'] ?? 'company'));
            $this->sendMessage($chatId, $this->buildEmployeeListReply($companyId, (int) ($reporter['id'] ?? 0), $scope, $companyName));
            return true;
        }

        if ($route === 'template_list') {
            $this->updateInteractionLog([
                'execution_path' => 'router_template_list',
                'command_names' => 'templates:list',
            ]);
            $this->sendMessage($chatId, $this->buildTemplateListReply($companyId, $companyName));
            return true;
        }

        if ($route === 'login_link') {
            $this->updateInteractionLog([
                'execution_path' => 'router_login_link',
                'command_names' => 'auth:login_link',
            ]);
            $this->sendTelegramLoginLinkReply($chatId, $reporter, false);
            return true;
        }

        if ($route === 'password_help') {
            $this->updateInteractionLog([
                'execution_path' => 'router_password_help',
                'command_names' => 'auth:password_help',
            ]);
            $this->sendTelegramLoginLinkReply($chatId, $reporter, true);
            return true;
        }

        if ($route === 'delete_tasks') {
            $this->updateInteractionLog([
                'execution_path' => 'router_delete_tasks',
                'command_names' => 'delete_tasks',
            ]);
            $this->handleDeleteTasksRequest($chatId, $companyId, $reporter, $routeDecision['command']['args'] ?? []);
            return true;
        }

        if ($route === 'delete_goals') {
            $this->updateInteractionLog([
                'execution_path' => 'router_delete_goals',
                'command_names' => 'delete_goals',
            ]);
            $this->handleDeleteGoalsRequest($chatId, $companyId, $reporter, $routeDecision['command']['args'] ?? []);
            return true;
        }

        if ($route === 'mark_task_done') {
            $this->updateInteractionLog([
                'execution_path' => 'router_mark_task_done',
                'command_names' => 'mark_task_done',
            ]);
            $this->handleMarkTaskDoneRequest($chatId, $companyId, $reporter, $routeDecision['command']['args'] ?? []);
            return true;
        }

        if ($route === 'mark_goal_done') {
            $this->updateInteractionLog([
                'execution_path' => 'router_mark_goal_done',
                'command_names' => 'mark_goal_done',
            ]);
            $this->handleMarkGoalDoneRequest($chatId, $companyId, $reporter, $routeDecision['command']['args'] ?? []);
            return true;
        }

        if ($route === 'correction_only') {
            $this->updateInteractionLog([
                'execution_path' => 'router_followup_correction',
                'command_names' => 'clarify_previous',
            ]);
            $this->sendMessage($chatId, $this->formatInfoMessage('Схоже на уточнення до попереднього повідомлення', [
                'Надішліть повну задачу або ціль одним повідомленням уже з правильним виконавцем.',
            ]));
            return true;
        }

        if ($route === 'project_list') {
            $this->updateInteractionLog([
                'execution_path' => 'router_project_list',
                'command_names' => 'list_projects',
            ]);
            $commandService = new TelegramIntentCommandService();
            $commandResult = $commandService->executeCommands($companyId, $reporter, [
                ['name' => 'list_projects', 'args' => []],
            ]);
            if ($commandResult && !empty($commandResult['reply'])) {
                $this->sendMessage($chatId, (string) $commandResult['reply']);
            }
            return true;
        }

        if ($route === 'unknown') {
            $this->updateInteractionLog([
                'execution_path' => 'router_unknown',
                'command_names' => null,
            ]);
            $this->sendMessage($chatId, $this->buildUnrecognizedMessage());
            return true;
        }

        $command = $routeDecision['command'] ?? null;
        if (!is_array($command)) {
            return false;
        }

        $executionPath = match ($route) {
            'task_list' => 'router_task_list',
            'goal_list' => 'router_goal_list',
            'project_list' => 'router_project_list',
            default => 'router_command',
        };

        $commandName = trim((string) ($command['name'] ?? $command['command'] ?? ''));
        $action = trim((string) (($command['args'] ?? [])['action'] ?? ''));
        $loggedCommandName = $action !== '' ? $commandName . ':' . $action : $commandName;

        $this->updateInteractionLog([
            'execution_path' => $executionPath,
            'command_names' => $loggedCommandName !== '' ? $loggedCommandName : null,
        ]);

        $commandService = new TelegramIntentCommandService();
        $commandResult = $commandService->executeCommands($companyId, $reporter, [$command]);
        if ($commandResult && !empty($commandResult['reply'])) {
            $this->sendMessage($chatId, (string) $commandResult['reply']);
            return true;
        }

        return false;
    }

    private function handleDeleteTasksRequest(int $chatId, int $companyId, array $reporter, array $args): void
    {
        $reporterId = (int) ($reporter['id'] ?? 0);
        if ($reporterId <= 0 || $companyId <= 0) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося визначити користувача або компанію'));
            return;
        }

        $scope = (string) ($args['scope'] ?? 'my');
        $status = (string) ($args['status'] ?? 'all');
        $dateRaw = (string) ($args['date'] ?? 'today');

        $date = $this->resolveDeleteDate($dateRaw);
        $dateLabel = $this->formatDeleteDateLabel($date);

        $taskModel = new \App\Models\Task();
        $allTasks = $taskModel->get_by_company($companyId);

        $employees = $this->findUsersByCompany($companyId);
        $subordinateIds = [];
        foreach ($employees as $emp) {
            if ((int) ($emp['reports_to'] ?? 0) === $reporterId) {
                $subordinateIds[] = (int) ($emp['id'] ?? 0);
            }
        }

        $filtered = array_values(array_filter($allTasks, function ($task) use ($reporterId, $date, $status, $scope, $subordinateIds) {
            $reporterTaskId = (int) ($task['reporter_id'] ?? 0);
            if ($reporterTaskId !== $reporterId) {
                return false;
            }

            $taskDueDate = !empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : null;
            if ($date !== '' && $taskDueDate !== $date) {
                return false;
            }

            $taskStatus = strtolower((string) ($task['status'] ?? 'todo'));
            if ($status !== 'all') {
                if ($status === 'active' && !in_array($taskStatus, ['todo', 'in-progress'], true)) {
                    return false;
                }
                if ($status !== 'active' && $taskStatus !== $status) {
                    return false;
                }
            }

            $assigneeId = (int) ($task['assignee_id'] ?? 0);

            if ($scope === 'my' && $assigneeId !== $reporterId) {
                return false;
            }
            if ($scope === 'delegated' && !($reporterTaskId === $reporterId && $assigneeId !== $reporterId)) {
                return false;
            }
            if ($scope === 'subordinates' && !in_array($assigneeId, $subordinateIds, true)) {
                return false;
            }

            return true;
        }));

        if (empty($filtered)) {
            $this->sendMessage($chatId, $this->formatInfoMessage('Немає задач для видалення', [
                'На ' . $dateLabel . ' задач не знайдено.',
            ]));
            return;
        }

        $taskIds = array_map(fn($t) => (int) $t['id'], $filtered);

        $draftKey = $this->storePendingIntentDraft($reporterId, $companyId, [
            ['type' => 'delete_tasks', 'task_ids' => $taskIds],
        ], 'delete');

        if ($draftKey === '') {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося зберегти чернетку видалення'));
            return;
        }

        $lines = ['🗑️ Видалити ' . count($filtered) . ' задач на ' . $dateLabel . '?'];
        $lines[] = '';
        foreach (array_slice($filtered, 0, 15) as $task) {
            $assigneeName = trim(($task['assignee_first_name'] ?? '') . ' ' . ($task['assignee_last_name'] ?? ''));
            $statusMarker = match (strtolower((string) ($task['status'] ?? 'todo'))) {
                'done' => '✅',
                'in-progress' => '🔄',
                default => '⬜',
            };
            $line = '• ' . $statusMarker . ' ' . ($task['title'] ?? '—');
            if ($assigneeName !== '') {
                $line .= ' — ' . $assigneeName;
            }
            $lines[] = $line;
        }

        if (count($filtered) > 15) {
            $lines[] = '• ➕ Ще: ' . (count($filtered) - 15);
        }

        $lines[] = '';
        $lines[] = '⚠️ Цю дію неможливо скасувати!';

        $this->sendMessage($chatId, implode("\n", $lines), [
            'inline_keyboard' => [
                [
                    ['text' => '🗑️ Підтвердити видалення', 'callback_data' => 'tg_confirm_delete:' . $draftKey],
                    ['text' => '❌ Скасувати', 'callback_data' => 'tg_cancel_delete:' . $draftKey],
                ],
            ],
        ]);
    }

    private function resolveDeleteDate(string $raw): string
    {
        $lower = mb_strtolower(trim($raw));
        if (in_array($lower, ['today', 'сьогодні', ''], true)) {
            return date('Y-m-d');
        }
        if (in_array($lower, ['tomorrow', 'завтра'], true)) {
            return date('Y-m-d', strtotime('+1 day'));
        }
        if (in_array($lower, ['yesterday', 'вчора'], true)) {
            return date('Y-m-d', strtotime('-1 day'));
        }
        $ts = strtotime($raw);
        return $ts !== false ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    private function formatDeleteDateLabel(string $date): string
    {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        if ($date === $today)
            return 'сьогодні (' . date('d.m', strtotime($date)) . ')';
        if ($date === $tomorrow)
            return 'завтра (' . date('d.m', strtotime($date)) . ')';
        if ($date === $yesterday)
            return 'вчора (' . date('d.m', strtotime($date)) . ')';
        return date('d.m.Y', strtotime($date));
    }

    private function handleMarkTaskDoneRequest(int $chatId, int $companyId, array $reporter, array $args): void
    {
        $reporterId = (int) ($reporter['id'] ?? 0);
        if ($reporterId <= 0 || $companyId <= 0) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося визначити користувача або компанію'));
            return;
        }

        $scope = (string) ($args['scope'] ?? 'my');
        $dateRaw = (string) ($args['date'] ?? 'today');
        $date = $this->resolveDeleteDate($dateRaw);
        $dateLabel = $this->formatDeleteDateLabel($date);

        $taskModel = new \App\Models\Task();
        $allTasks = $taskModel->get_by_company($companyId);

        $employees = $this->findUsersByCompany($companyId);
        $subordinateIds = [];
        foreach ($employees as $emp) {
            if ((int) ($emp['reports_to'] ?? 0) === $reporterId) {
                $subordinateIds[] = (int) ($emp['id'] ?? 0);
            }
        }

        $filtered = array_values(array_filter($allTasks, function ($task) use ($reporterId, $date, $scope, $subordinateIds) {
            $taskDueDate = !empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : null;
            if ($date !== '' && $taskDueDate !== $date) {
                return false;
            }

            $taskStatus = strtolower((string) ($task['status'] ?? 'todo'));
            if ($taskStatus === 'done') {
                return false;
            }

            $assigneeId = (int) ($task['assignee_id'] ?? 0);
            $reporterTaskId = (int) ($task['reporter_id'] ?? 0);

            if ($scope === 'my' && $assigneeId !== $reporterId) {
                return false;
            }
            if ($scope === 'delegated' && !($reporterTaskId === $reporterId && $assigneeId !== $reporterId)) {
                return false;
            }
            if ($scope === 'subordinates' && !in_array($assigneeId, $subordinateIds, true)) {
                return false;
            }

            return true;
        }));

        if (empty($filtered)) {
            $this->sendMessage($chatId, $this->formatInfoMessage('Немає задач для позначення', [
                'На ' . $dateLabel . ' активних задач не знайдено.',
            ]));
            return;
        }

        $marked = 0;
        foreach ($filtered as $task) {
            try {
                $taskModel->update((int) $task['id'], ['status' => 'done']);
                $marked++;
            } catch (\Throwable $e) {
                error_log("[TelegramBot] mark task done {$task['id']} failed: " . $e->getMessage());
            }
        }

        $lines = ['✅ Позначено виконаними: ' . $marked . ' задач на ' . $dateLabel];
        $lines[] = '';
        foreach (array_slice($filtered, 0, 15) as $task) {
            $assigneeName = trim(($task['assignee_first_name'] ?? '') . ' ' . ($task['assignee_last_name'] ?? ''));
            $line = '• ✅ ' . ($task['title'] ?? '—');
            if ($assigneeName !== '') {
                $line .= ' — ' . $assigneeName;
            }
            $lines[] = $line;
        }
        if (count($filtered) > 15) {
            $lines[] = '• ➕ Ще: ' . (count($filtered) - 15);
        }

        $this->sendMessage($chatId, implode("\n", $lines));
    }

    private function handleMarkGoalDoneRequest(int $chatId, int $companyId, array $reporter, array $args): void
    {
        $reporterId = (int) ($reporter['id'] ?? 0);
        if ($reporterId <= 0 || $companyId <= 0) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося визначити користувача або компанію'));
            return;
        }

        $scope = (string) ($args['scope'] ?? 'my');
        $dateRaw = (string) ($args['date'] ?? 'today');
        $date = $this->resolveDeleteDate($dateRaw);
        $dateLabel = $this->formatDeleteDateLabel($date);

        $resultModel = new \App\Models\Result();
        $allResults = $resultModel->get_by_company($companyId);

        $employees = $this->findUsersByCompany($companyId);
        $subordinateIds = [];
        foreach ($employees as $emp) {
            if ((int) ($emp['reports_to'] ?? 0) === $reporterId) {
                $subordinateIds[] = (int) ($emp['id'] ?? 0);
            }
        }

        $filtered = array_values(array_filter($allResults, function ($result) use ($reporterId, $date, $scope, $subordinateIds) {
            if ((int) ($result['completed'] ?? 0) === 1) {
                return false;
            }

            $resultDate = !empty($result['created_at']) ? date('Y-m-d', strtotime((string) $result['created_at'])) : null;
            if ($date !== '' && $resultDate !== $date) {
                return false;
            }

            $assigneeId = (int) ($result['assignee_id'] ?? 0);
            $reporterResultId = (int) ($result['reporter_id'] ?? 0);

            if ($scope === 'my' && $assigneeId !== $reporterId) {
                return false;
            }
            if ($scope === 'delegated' && !($reporterResultId === $reporterId && $assigneeId !== $reporterId)) {
                return false;
            }
            if ($scope === 'subordinates' && !in_array($assigneeId, $subordinateIds, true)) {
                return false;
            }

            return true;
        }));

        if (empty($filtered)) {
            $this->sendMessage($chatId, $this->formatInfoMessage('Немає цілей для позначення', [
                'На ' . $dateLabel . ' незавершених цілей не знайдено.',
            ]));
            return;
        }

        $marked = 0;
        foreach ($filtered as $result) {
            try {
                $resultModel->update((int) $result['id'], ['completed' => 1]);
                $marked++;
            } catch (\Throwable $e) {
                error_log("[TelegramBot] mark goal done {$result['id']} failed: " . $e->getMessage());
            }
        }

        $lines = ['✅ Позначено виконаними: ' . $marked . ' цілей на ' . $dateLabel];
        $lines[] = '';
        foreach (array_slice($filtered, 0, 15) as $result) {
            $assigneeName = trim(($result['assignee_first_name'] ?? '') . ' ' . ($result['assignee_last_name'] ?? ''));
            $line = '• ✅ ' . ($result['title'] ?? '—');
            if ($assigneeName !== '') {
                $line .= ' — ' . $assigneeName;
            }
            $lines[] = $line;
        }
        if (count($filtered) > 15) {
            $lines[] = '• ➕ Ще: ' . (count($filtered) - 15);
        }

        $this->sendMessage($chatId, implode("\n", $lines));
    }

    private function handleDeleteGoalsRequest(int $chatId, int $companyId, array $reporter, array $args): void
    {
        $reporterId = (int) ($reporter['id'] ?? 0);
        if ($reporterId <= 0 || $companyId <= 0) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося визначити користувача або компанію'));
            return;
        }

        $scope = (string) ($args['scope'] ?? 'my');
        $dateRaw = (string) ($args['date'] ?? 'today');
        $date = $this->resolveDeleteDate($dateRaw);
        $dateLabel = $this->formatDeleteDateLabel($date);

        $resultModel = new \App\Models\Result();
        $allResults = $resultModel->get_by_company($companyId);

        $employees = $this->findUsersByCompany($companyId);
        $subordinateIds = [];
        foreach ($employees as $emp) {
            if ((int) ($emp['reports_to'] ?? 0) === $reporterId) {
                $subordinateIds[] = (int) ($emp['id'] ?? 0);
            }
        }

        $filtered = array_values(array_filter($allResults, function ($result) use ($reporterId, $date, $scope, $subordinateIds) {
            $resultDate = !empty($result['created_at']) ? date('Y-m-d', strtotime((string) $result['created_at'])) : null;
            if ($date !== '' && $resultDate !== $date) {
                return false;
            }

            $reporterResultId = (int) ($result['reporter_id'] ?? 0);
            if ($reporterResultId !== $reporterId) {
                return false;
            }

            $assigneeId = (int) ($result['assignee_id'] ?? 0);

            if ($scope === 'my' && $assigneeId !== $reporterId) {
                return false;
            }
            if ($scope === 'delegated' && $assigneeId === $reporterId) {
                return false;
            }
            if ($scope === 'subordinates' && !in_array($assigneeId, $subordinateIds, true)) {
                return false;
            }

            return true;
        }));

        if (empty($filtered)) {
            $this->sendMessage($chatId, $this->formatInfoMessage('Немає цілей для видалення', [
                'На ' . $dateLabel . ' цілей не знайдено.',
            ]));
            return;
        }

        $resultIds = array_map(fn($r) => (int) $r['id'], $filtered);

        $draftKey = $this->storePendingIntentDraft($reporterId, $companyId, [
            ['type' => 'delete_goals', 'result_ids' => $resultIds],
        ], 'delete');

        if ($draftKey === '') {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося зберегти чернетку видалення'));
            return;
        }

        $lines = ['🗑️ Видалити ' . count($filtered) . ' цілей на ' . $dateLabel . '?'];
        $lines[] = '';
        foreach (array_slice($filtered, 0, 15) as $result) {
            $assigneeName = trim(($result['assignee_first_name'] ?? '') . ' ' . ($result['assignee_last_name'] ?? ''));
            $completedMarker = (int) ($result['completed'] ?? 0) === 1 ? '✅' : '⬜';
            $line = '• ' . $completedMarker . ' ' . ($result['title'] ?? '—');
            if ($assigneeName !== '') {
                $line .= ' — ' . $assigneeName;
            }
            $lines[] = $line;
        }

        if (count($filtered) > 15) {
            $lines[] = '• ➕ Ще: ' . (count($filtered) - 15);
        }

        $lines[] = '';
        $lines[] = '⚠️ Цю дію неможливо скасувати!';

        $this->sendMessage($chatId, implode("\n", $lines), [
            'inline_keyboard' => [
                [
                    ['text' => '🗑️ Підтвердити видалення', 'callback_data' => 'tg_confirm_delete:' . $draftKey],
                    ['text' => '❌ Скасувати', 'callback_data' => 'tg_cancel_delete:' . $draftKey],
                ],
            ],
        ]);
    }

    private function buildTaskListArgsFromText(string $text): array
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));

        $scope = 'my';
        if (str_contains($normalized, 'делегован')) {
            $scope = 'delegated';
        } elseif (str_contains($normalized, 'підлегл')) {
            $scope = 'subordinates';
        }

        $status = 'active';
        if (str_contains($normalized, 'всі')) {
            $status = 'all';
        } elseif (str_contains($normalized, 'заверш')) {
            $status = 'done';
        } elseif (str_contains($normalized, 'відклад')) {
            $status = 'postponed';
        }

        $date = 'today';
        if (str_contains($normalized, 'завтра')) {
            $date = 'tomorrow';
        } elseif (!str_contains($normalized, 'сьогодні') && !str_contains($normalized, 'на сьогодні')) {
            $date = 'today';
        }

        return [
            'action' => 'list',
            'scope' => $scope,
            'status' => $status,
            'date' => $date,
        ];
    }

    private function buildCurrentCompanyReply(int $companyId, string $companyName, int $companyCount): string
    {
        if ($companyId <= 0) {
            return $this->formatWarningMessage('Не вдалося визначити активну компанію', [
                'Надішліть /company і виберіть компанію зі списку.',
            ]);
        }

        $lines = [
            '🏢 Зараз активна компанія: ' . ($companyName !== '' ? $companyName : ('#' . $companyId)),
            '🆔 ID: #' . $companyId,
        ];

        if ($companyCount > 1) {
            $lines[] = 'Щоб перемкнути компанію, надішліть /company.';
        }

        return implode("\n", $lines);
    }

    private function buildTemplateListReply(int $companyId, string $companyName): string
    {
        $templates = $this->findTemplatesByCompany($companyId);
        if (empty($templates)) {
            return $this->formatInfoMessage('Шаблонів поки немає', [
                'Створіть перший шаблон у боті або веб-кабінеті.',
            ]);
        }

        $lines = ['🧩 Доступні шаблони:'];
        foreach (array_slice($templates, 0, 12) as $template) {
            $name = trim((string) ($template['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $lines[] = '• ' . $name;
        }

        if (count($templates) > 12) {
            $lines[] = '• Ще шаблонів: ' . (count($templates) - 12);
        }

        if ($companyName !== '') {
            $lines[] = '';
            $lines[] = '🏢 Компанія: ' . $companyName;
        }

        return implode("\n", $lines);
    }

    private function buildEmployeeListReply(int $companyId, int $reporterId, string $scope, string $companyName): string
    {
        if ($companyId <= 0) {
            return $this->formatWarningMessage('Не вдалося визначити компанію', [
                'Спробуйте ще раз після вибору активної компанії через /company.',
            ]);
        }

        $employees = $this->db
            ->query('SELECT cm.user_id, cm.title, cm.role, cm.reports_to AS reports_to_member_id, mgr_cm.user_id AS reports_to, u.first_name, u.last_name, u.email, mgr.first_name AS manager_first_name, mgr.last_name AS manager_last_name FROM company_members cm JOIN users u ON u.id = cm.user_id LEFT JOIN company_members mgr_cm ON mgr_cm.id = cm.reports_to LEFT JOIN users mgr ON mgr.id = mgr_cm.user_id WHERE cm.company_id = :company_id ORDER BY u.first_name ASC, u.last_name ASC')
            ->bind(':company_id', $companyId)
            ->fetchAll();

        if ($scope === 'subordinates') {
            $employees = array_values(array_filter($employees, static fn($employee) => (int) ($employee['reports_to'] ?? 0) === $reporterId));
        }

        if (empty($employees)) {
            return $scope === 'subordinates'
                ? $this->formatInfoMessage('Підлеглих не знайдено', ['У поточній компанії за вами поки нікого не закріплено.'])
                : $this->formatInfoMessage('Співробітників не знайдено', ['У поточній компанії ще немає доступних учасників.']);
        }

        $title = $scope === 'subordinates' ? '👥 Ваші підлеглі:' : '👥 Люди в компанії:';
        $lines = [$title];
        foreach (array_slice($employees, 0, 15) as $employee) {
            $name = trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? ''));
            $name = $name !== '' ? $name : ('Користувач #' . (int) ($employee['user_id'] ?? 0));
            $suffix = [];

            $jobTitle = trim((string) ($employee['title'] ?? ''));
            if ($jobTitle !== '') {
                $suffix[] = $jobTitle;
            }

            $managerName = trim((string) ($employee['manager_first_name'] ?? '') . ' ' . (string) ($employee['manager_last_name'] ?? ''));
            if ($scope !== 'subordinates' && $managerName !== '') {
                $suffix[] = 'керівник: ' . $managerName;
            }

            $lines[] = '• ' . $name . ($suffix ? ' — ' . implode(', ', $suffix) : '');
        }

        if (count($employees) > 15) {
            $lines[] = '• Ще співробітників: ' . (count($employees) - 15);
        }

        if ($companyName !== '') {
            $lines[] = '';
            $lines[] = '🏢 Компанія: ' . $companyName;
        }

        return implode("\n", $lines);
    }

    private function storePendingIntentDraft(int $userId, int $companyId, array $items, string $mode = 'confirmation'): string
    {
        $draftKey = bin2hex(random_bytes(8));

        $payload = [
            'items' => $items,
            'user_id' => $userId,
            'company_id' => $companyId,
            'mode' => $mode,
            'expires_at' => time() + 1800,
        ];

        $draftDir = $this->getPendingIntentDraftDirectory();
        if (!is_dir($draftDir) && !@mkdir($draftDir, 0777, true) && !is_dir($draftDir)) {
            return '';
        }

        foreach (glob($draftDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $existingFile) {
            $raw = @file_get_contents($existingFile);
            if ($raw === false || $raw === '') {
                continue;
            }

            $existingPayload = json_decode($raw, true);
            if (!is_array($existingPayload)) {
                continue;
            }

            if ((int) ($existingPayload['user_id'] ?? 0) === $userId) {
                @unlink($existingFile);
            }
        }

        $written = @file_put_contents($this->getPendingIntentDraftPath($draftKey), json_encode($payload, JSON_UNESCAPED_UNICODE));
        if ($written === false) {
            return '';
        }

        return $draftKey;
    }

    private function findPendingIntentDraft(string $draftKey, int $userId): ?array
    {
        $path = $this->getPendingIntentDraftPath($draftKey);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            @unlink($path);
            return null;
        }

        $expiresAt = (int) ($payload['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            @unlink($path);
            return null;
        }

        return [
            'id' => $draftKey,
            'user_id' => (int) ($payload['user_id'] ?? $userId),
            'company_id' => (int) ($payload['company_id'] ?? 0),
            'payload' => $payload,
        ];
    }

    private function findLatestPendingIntentDraftByUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $draftDir = $this->getPendingIntentDraftDirectory();
        if (!is_dir($draftDir)) {
            return null;
        }

        $latestDraft = null;
        $latestTimestamp = 0;

        foreach (glob($draftDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') {
                continue;
            }

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                @unlink($path);
                continue;
            }

            if ((int) ($payload['user_id'] ?? 0) !== $userId) {
                continue;
            }

            $expiresAt = (int) ($payload['expires_at'] ?? 0);
            if ($expiresAt > 0 && $expiresAt < time()) {
                @unlink($path);
                continue;
            }

            $draftTimestamp = (int) @filemtime($path);
            if ($draftTimestamp <= $latestTimestamp) {
                continue;
            }

            $latestTimestamp = $draftTimestamp;
            $latestDraft = [
                'id' => (string) pathinfo($path, PATHINFO_FILENAME),
                'user_id' => $userId,
                'company_id' => (int) ($payload['company_id'] ?? 0),
                'payload' => $payload,
            ];
        }

        return $latestDraft;
    }

    private function tryHandlePendingTaskClarificationReply(int $chatId, int $companyId, string $companyName, int $companyCount, array $reporter, string $text): bool
    {
        $userId = (int) ($reporter['id'] ?? 0);
        if ($userId <= 0 || $companyId <= 0) {
            return false;
        }

        $draft = $this->findLatestPendingIntentDraftByUser($userId);
        if (!$draft) {
            return false;
        }

        $payload = $draft['payload'] ?? [];
        if (($payload['mode'] ?? '') !== 'task_clarification') {
            return false;
        }

        if ((int) ($draft['company_id'] ?? 0) !== $companyId) {
            return false;
        }

        if (!$this->looksLikeTaskClarificationReply($text)) {
            return false;
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (empty($items)) {
            $this->deletePendingIntentDraft((string) ($draft['id'] ?? ''));
            return false;
        }

        $mergedItems = $this->mergeTaskClarificationIntoItems($items, $text);
        $reply = $this->buildTaskClarificationReply(['items' => $mergedItems]);

        if ($reply !== null) {
            $this->updateInteractionLog([
                'route_name' => 'task_clarification',
                'route_confidence' => 'high',
                'route_reason' => 'pending_task_clarification',
                'execution_path' => 'pending_task_clarification_updated',
                'command_names' => 'task_missing_fields',
            ]);
            $this->sendMessage($chatId, $this->prepareTaskClarificationReply($reply, $mergedItems, $userId, $companyId));
            return true;
        }

        $summary = $this->persistParsedItems($companyId, $userId, $mergedItems);
        $this->deletePendingIntentDraft((string) ($draft['id'] ?? ''));
        $this->updateInteractionLog([
            'route_name' => 'task_clarification',
            'route_confidence' => 'high',
            'route_reason' => 'pending_task_clarification_completed',
            'execution_path' => 'pending_task_clarification_completed',
            'command_names' => 'items[]',
        ]);
        $this->sendMessage($chatId, $this->buildCreatedItemsReply($summary, $companyName, $companyCount));
        return true;
    }

    private function mergeTaskClarificationIntoItems(array $items, string $text): array
    {
        $date = $this->extractFallbackTaskDate($text);
        $startTime = $this->extractFallbackTaskStartTime($text);
        $expectedResult = $this->extractFallbackTaskExpectedResult($text);
        $expectedTime = $this->extractFallbackTaskExpectedTime($text);

        foreach ($items as $index => $item) {
            if (!is_array($item) || strtolower(trim((string) ($item['kind'] ?? 'task'))) !== 'task') {
                continue;
            }

            if (trim((string) ($item['date'] ?? '')) === '' && $date !== null) {
                $items[$index]['date'] = $date;
            }

            if (trim((string) ($item['expectedResult'] ?? '')) === '' && $expectedResult !== null) {
                $items[$index]['expectedResult'] = $expectedResult;
            }

            if (trim((string) ($item['expectedTime'] ?? '')) === '' && $expectedTime !== null) {
                $items[$index]['expectedTime'] = $expectedTime;
            }

            if (trim((string) ($item['startTime'] ?? $item['start_time'] ?? '')) === '' && $startTime !== null) {
                $items[$index]['startTime'] = $startTime;
            }
        }

        return $items;
    }

    private function looksLikeTaskClarificationReply(string $text): bool
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        if ($normalized === '') {
            return false;
        }

        $newActionMarkers = ['створи', 'додай', 'постав', 'покажи', 'виведи', 'список', 'компан', 'шаблон', 'ціл', 'план-факт', 'посилан', 'login', '/'];
        foreach ($newActionMarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                return false;
            }
        }

        $markers = ['очікуваний результат', 'очікуваного результат', 'очікуваний час', 'очікуваного час', 'на коли', 'сьогодні', 'завтра', 'півгодини', 'пів години', 'година часу', 'це година', 'час старту', 'о '];
        foreach ($markers as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        if (preg_match('/^\d+\s*(?:год|годин|година|хв|хвилин|хвилина)\b/ui', $normalized)) {
            return true;
        }

        if (preg_match('/\b\d{1,2}[:\.]\d{2}\b/u', $normalized)) {
            return true;
        }

        if (preg_match('/\b\d{1,2}[\.\/]\d{1,2}(?:[\.\/]\d{2,4})?\b/u', $normalized)) {
            return true;
        }

        return false;
    }

    private function normalizePersistedTaskDate(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $lower = mb_strtolower($raw);
        if (in_array($lower, ['today', 'сьогодні'], true)) {
            return date('Y-m-d');
        }

        if (in_array($lower, ['tomorrow', 'завтра'], true)) {
            return date('Y-m-d', strtotime('+1 day'));
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
    }

    private function normalizePersistedTaskStartTime(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})[\.:](\d{2})$/', $raw, $match)) {
            $hours = (int) $match[1];
            $minutes = (int) $match[2];
            if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
                return sprintf('%02d:%02d', $hours, $minutes);
            }
        }

        if (preg_match('/^(\d{1,2})$/', $raw, $match)) {
            $hours = (int) $match[1];
            if ($hours >= 0 && $hours <= 23) {
                return sprintf('%02d:00', $hours);
            }
        }

        return null;
    }

    private function normalizePersistedTaskDateTime(mixed $dateValue, mixed $startTimeValue): ?string
    {
        $date = $this->normalizePersistedTaskDate($dateValue);
        if ($date === null) {
            return null;
        }

        $startTime = $this->normalizePersistedTaskStartTime($startTimeValue);
        if ($startTime === null) {
            return $date;
        }

        return $date . ' ' . $startTime . ':00';
    }

    private function normalizePersistedTaskExpectedTime(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_numeric($value)) {
            $minutes = (int) $value;
            return $minutes > 0 ? $minutes : null;
        }

        $raw = mb_strtolower(trim((string) $value));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/(\d+)\s*(год|годин|година)/u', $raw, $match)) {
            return ((int) $match[1]) * 60;
        }

        if (preg_match('/(\d+)\s*(хв|хвилин|хвилина|min|minutes?)/u', $raw, $match)) {
            return (int) $match[1];
        }

        if (str_contains($raw, 'півгодини') || str_contains($raw, 'пів години')) {
            return 30;
        }

        return null;
    }

    private function findUserDisplayNameById(array $users, int $userId): string
    {
        foreach ($users as $user) {
            if ((int) ($user['id'] ?? 0) !== $userId) {
                continue;
            }

            return trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        }

        return '';
    }

    private function buildTaskCreatedReplyBlock(array $task): string
    {
        $lines = ['📝 Задачу створено:'];
        $lines[] = '• Назва: ' . (string) ($task['title'] ?? '—');
        $lines[] = '• На коли: ' . (!empty($task['due_date']) ? $this->formatShortTaskDate((string) $task['due_date']) : '—');

        $startTime = trim((string) ($task['start_time'] ?? ''));
        if ($startTime === '' && !empty($task['due_date'])) {
            $timestamp = strtotime((string) $task['due_date']);
            if ($timestamp !== false) {
                $derivedStartTime = date('H:i', $timestamp);
                if ($derivedStartTime !== '00:00') {
                    $startTime = $derivedStartTime;
                }
            }
        }
        $lines[] = '• Час старту: ' . ($startTime !== '' ? $startTime : '—');

        $assigneeName = trim((string) ($task['assignee_name'] ?? ''));
        if ($assigneeName !== '') {
            $lines[] = '• Виконавець: ' . $assigneeName;
        }

        $description = trim((string) ($task['description'] ?? ''));
        $lines[] = '• Опис: ' . ($description !== '' ? $description : '—');

        $expectedResult = trim((string) ($task['expected_result'] ?? ''));
        $lines[] = '• Очікуваний результат: ' . ($expectedResult !== '' ? $expectedResult : '—');

        $expectedTime = (int) ($task['expected_time'] ?? 0);
        $lines[] = '• Очікуваний час: ' . ($expectedTime > 0 ? $expectedTime . ' хв' : '—');
        $lines[] = '• Тип: ' . $this->formatTaskTypeLabel((string) ($task['type'] ?? ''));

        return implode("\n", $lines);
    }

    private function formatShortTaskDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        $formatted = date('d.m.y', $timestamp);
        $timePart = date('H:i', $timestamp);
        if ($timePart !== '00:00') {
            $formatted .= ' ' . $timePart;
        }

        return $formatted;
    }

    private function formatTaskTypeLabel(string $type): string
    {
        return match (trim($type)) {
            'important-urgent' => 'Важлива і термінова',
            'important-not-urgent' => 'Важлива і нетермінова',
            'not-important-urgent' => 'Неважлива і термінова',
            'not-important-not-urgent' => 'Неважлива і нетермінова',
            default => '—',
        };
    }

    private function deletePendingIntentDraft(string $draftKey): void
    {
        if ($draftKey === '') {
            return;
        }

        $path = $this->getPendingIntentDraftPath($draftKey);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function getPendingIntentDraftDirectory(): string
    {
        return ROOT_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telegram-drafts';
    }

    private function resetInteractionState(): void
    {
        $this->lastAudioProcessingError = null;
        $this->lastAudioTranscription = null;
        $this->lastAiRawResponse = null;
        $this->lastAiParsedResponse = null;
        $this->currentInteractionLogId = null;
        $this->currentInteractionReplies = [];
    }

    private function ensureStorageDirectories(): void
    {
        $draftDir = $this->getPendingIntentDraftDirectory();
        if (!is_dir($draftDir)) {
            @mkdir($draftDir, 0777, true);
        }
    }

    private function ensureInteractionLogTable(): void
    {
        if (self::$interactionLogSchemaReady) {
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS telegram_ai_interaction_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            chat_id BIGINT NOT NULL,
            telegram_message_id BIGINT NULL,
            telegram_user_id BIGINT NULL,
            app_user_id INT NULL,
            company_id INT NULL,
            chat_type VARCHAR(20) NOT NULL DEFAULT 'private',
            message_kind VARCHAR(20) NOT NULL DEFAULT 'text',
            raw_text MEDIUMTEXT NULL,
            transcribed_text MEDIUMTEXT NULL,
            normalized_text MEDIUMTEXT NULL,
            ai_recent_context MEDIUMTEXT NULL,
            ai_raw_response LONGTEXT NULL,
            ai_parsed_json LONGTEXT NULL,
            route_name VARCHAR(80) NULL,
            route_confidence VARCHAR(20) NULL,
            route_reason VARCHAR(255) NULL,
            execution_path VARCHAR(80) NULL,
            command_names TEXT NULL,
            bot_reply LONGTEXT NULL,
            audio_error TEXT NULL,
            raw_update_json LONGTEXT NULL,
            processing_status VARCHAR(50) NOT NULL DEFAULT 'received',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_telegram_ai_logs_chat_created (chat_id, created_at),
            INDEX idx_telegram_ai_logs_company_created (company_id, created_at),
            INDEX idx_telegram_ai_logs_app_user_created (app_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        try {
            $this->db->query($sql)->execute();

            $columns = $this->db->query('SHOW COLUMNS FROM telegram_ai_interaction_logs')->fetchAll(\PDO::FETCH_ASSOC);
            $existing = array_map(static function ($row) {
                return (string) ($row['Field'] ?? '');
            }, $columns);

            if (!in_array('execution_path', $existing, true)) {
                $this->db->query('ALTER TABLE telegram_ai_interaction_logs ADD COLUMN execution_path VARCHAR(80) NULL AFTER ai_parsed_json')->execute();
            }

            if (!in_array('route_name', $existing, true)) {
                $this->db->query('ALTER TABLE telegram_ai_interaction_logs ADD COLUMN route_name VARCHAR(80) NULL AFTER ai_parsed_json')->execute();
            }

            if (!in_array('route_confidence', $existing, true)) {
                $this->db->query('ALTER TABLE telegram_ai_interaction_logs ADD COLUMN route_confidence VARCHAR(20) NULL AFTER route_name')->execute();
            }

            if (!in_array('route_reason', $existing, true)) {
                $this->db->query('ALTER TABLE telegram_ai_interaction_logs ADD COLUMN route_reason VARCHAR(255) NULL AFTER route_confidence')->execute();
            }

            if (!in_array('command_names', $existing, true)) {
                $this->db->query('ALTER TABLE telegram_ai_interaction_logs ADD COLUMN command_names TEXT NULL AFTER execution_path')->execute();
            }

            self::$interactionLogSchemaReady = true;
        } catch (\Throwable $e) {
            error_log('[TelegramBotService] Failed to ensure interaction log table: ' . $e->getMessage());
        }
    }

    private function extractCommandNames(array $commands): ?string
    {
        $names = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $name = trim((string) ($command['name'] ?? $command['command'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        return empty($names) ? null : implode(', ', $names);
    }

    private function startInteractionLog(array $update, array $message, int $chatId, string $chatType, int $telegramUserId, string $rawText): void
    {
        if ($chatId <= 0) {
            return;
        }

        try {
            $this->db->insert('telegram_ai_interaction_logs', [
                'chat_id' => $chatId,
                'telegram_message_id' => isset($message['message_id']) ? (int) $message['message_id'] : null,
                'telegram_user_id' => $telegramUserId > 0 ? $telegramUserId : null,
                'chat_type' => $chatType !== '' ? $chatType : 'private',
                'message_kind' => 'text',
                'raw_text' => $this->nullIfEmptyForLog($rawText),
                'raw_update_json' => json_encode($update, JSON_UNESCAPED_UNICODE),
                'processing_status' => 'received',
            ]);

            $this->currentInteractionLogId = (int) $this->db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[TelegramBotService] Failed to create interaction log: ' . $e->getMessage());
        }
    }

    private function updateInteractionLog(array $data): void
    {
        if (($this->currentInteractionLogId ?? 0) <= 0 || empty($data)) {
            return;
        }

        try {
            $this->db->update('telegram_ai_interaction_logs', $this->currentInteractionLogId, $data);
        } catch (\Throwable $e) {
            error_log('[TelegramBotService] Failed to update interaction log: ' . $e->getMessage());
        }
    }

    private function appendInteractionReply(string $text): void
    {
        $normalized = $this->normalizeConversationText($text);
        if ($normalized === '') {
            return;
        }

        $this->currentInteractionReplies[] = $normalized;
        $this->currentInteractionReplies = array_slice($this->currentInteractionReplies, -6);

        $this->updateInteractionLog([
            'bot_reply' => implode("\n\n---\n\n", $this->currentInteractionReplies),
            'processing_status' => 'replied',
        ]);
    }

    private function nullIfEmptyForLog(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function appendConversationTurn(int $chatId, string $role, string $text): void
    {
        $normalizedText = $this->normalizeConversationText($text);
        if ($chatId <= 0 || $normalizedText === '' || !in_array($role, ['user', 'assistant'], true)) {
            return;
        }

        $historyDir = $this->getConversationHistoryDirectory();
        if (!is_dir($historyDir) && !@mkdir($historyDir, 0777, true) && !is_dir($historyDir)) {
            return;
        }

        $path = $this->getConversationHistoryPath($chatId);
        $history = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $history = array_values(array_filter($decoded, 'is_array'));
            }
        }

        $history[] = [
            'role' => $role,
            'text' => $normalizedText,
            'created_at' => time(),
        ];

        $history = array_slice($history, -12);
        @file_put_contents($path, json_encode($history, JSON_UNESCAPED_UNICODE));
    }

    private function getRecentConversationTurns(int $chatId, int $limit = 6): array
    {
        if ($chatId <= 0 || $limit <= 0) {
            return [];
        }

        $path = $this->getConversationHistoryPath($chatId);
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $turns = [];
        foreach (array_slice($decoded, -$limit) as $turn) {
            if (!is_array($turn)) {
                continue;
            }

            $role = (string) ($turn['role'] ?? '');
            $text = $this->normalizeConversationText((string) ($turn['text'] ?? ''));
            if (!in_array($role, ['user', 'assistant'], true) || $text === '') {
                continue;
            }

            $turns[] = [
                'role' => $role,
                'text' => $text,
            ];
        }

        return $turns;
    }

    private function formatConversationTurnsForPrompt(array $turns): string
    {
        $lines = [];
        foreach ($turns as $turn) {
            if (!is_array($turn)) {
                continue;
            }

            $role = (string) ($turn['role'] ?? '');
            $text = $this->normalizeConversationText((string) ($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $label = $role === 'assistant' ? 'assistant' : 'user';
            $lines[] = '- ' . $label . ': ' . $text;
        }

        return implode("\n", $lines);
    }

    private function normalizeConversationText(string $text): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '') {
            return '';
        }

        return mb_substr($normalized, 0, 1200);
    }

    private function getConversationHistoryDirectory(): string
    {
        return ROOT_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telegram-history';
    }

    private function getConversationHistoryPath(int $chatId): string
    {
        return $this->getConversationHistoryDirectory() . DIRECTORY_SEPARATOR . 'chat-' . $chatId . '.json';
    }

    private function getPendingIntentDraftPath(string $draftKey): string
    {
        return $this->getPendingIntentDraftDirectory() . DIRECTORY_SEPARATOR . $draftKey . '.json';
    }

    private function resolveCallbackActor(array $message, array $from): ?array
    {
        $chatType = (string) (($message['chat']['type'] ?? ''));
        $telegramId = (int) ($from['id'] ?? 0);
        if ($telegramId <= 0) {
            return null;
        }

        if ($chatType === 'private') {
            return $this->findUserByTelegramId($telegramId, (string) ($from['username'] ?? ''));
        }

        return $this->findOrCreateUserByTelegram($from);
    }

    private function findTemplatesByCompany(int $companyId): array
    {
        return $this->db
            ->query('SELECT id, name FROM templates WHERE company_id = :company_id ORDER BY id DESC')
            ->bind(':company_id', $companyId)
            ->fetchAll();
    }

    private function findTelegramGroup(int $chatId): ?array
    {
        $row = $this->db
            ->query('SELECT * FROM telegram_groups WHERE telegram_chat_id = :chat_id LIMIT 1')
            ->bind(':chat_id', $chatId)
            ->fetch();

        return $row ?: null;
    }

    private function findUsersByCompany(int $companyId): array
    {
        return $this->db
            ->query('SELECT u.* FROM users u JOIN company_members cm ON cm.user_id = u.id WHERE cm.company_id = :company_id')
            ->bind(':company_id', $companyId)
            ->fetchAll();
    }

    private function findUserByTelegramId(int $telegramId, string $telegramUsername = ''): ?array
    {
        $row = $this->db
            ->query('SELECT * FROM users WHERE telegram_id = :telegram_id LIMIT 1')
            ->bind(':telegram_id', $telegramId)
            ->fetch();

        if ($row) {
            return $row;
        }

        // Fallback: try to find by username if manually added
        $username = strtolower(ltrim(trim($telegramUsername), '@'));
        if ($username === '') {
            return null;
        }

        $row = $this->db
            ->query('SELECT * FROM users WHERE LOWER(username) = :username AND telegram_id IS NULL LIMIT 1')
            ->bind(':username', $username)
            ->fetch();

        if (!$row) {
            return null;
        }

        // Auto-link telegram_id so future lookups are instant
        $this->db
            ->query('UPDATE users SET telegram_id = :telegram_id WHERE id = :user_id AND telegram_id IS NULL')
            ->bind(':telegram_id', $telegramId)
            ->bind(':user_id', (int) $row['id'])
            ->execute();

        $row['telegram_id'] = $telegramId;
        return $row;
    }

    private function findCompaniesByUser(int $userId): array
    {
        return $this->db
            ->query('SELECT c.id, c.name FROM companies c JOIN company_members cm ON cm.company_id = c.id WHERE cm.user_id = :user_id ORDER BY c.name ASC')
            ->bind(':user_id', $userId)
            ->fetchAll();
    }

    private function findCompanyNameById(int $companyId): string
    {
        $row = $this->db
            ->query('SELECT name FROM companies WHERE id = :id LIMIT 1')
            ->bind(':id', $companyId)
            ->fetch();

        return (string) ($row['name'] ?? '');
    }

    private function resolveActivePrivateCompany(int $userId, array $companies): array
    {
        $savedCompanyId = $this->getSavedPrivateCompanyId($userId);
        if ($savedCompanyId) {
            foreach ($companies as $company) {
                if ((int) ($company['id'] ?? 0) === $savedCompanyId) {
                    return $company;
                }
            }
        }

        $first = $companies[0] ?? null;
        if ($first) {
            $this->savePrivateCompanyId($userId, (int) $first['id']);
            return $first;
        }

        return ['id' => 0, 'name' => ''];
    }

    private function handlePrivateCompanyCommand(int $chatId, int $userId, array $companies, ?int $requestedCompanyId): void
    {
        $active = $this->resolveActivePrivateCompany($userId, $companies);

        if ($requestedCompanyId === null || $requestedCompanyId <= 0) {
            $lines = [
                '🏢 Ваші компанії:',
            ];

            $keyboardRows = [];

            foreach ($companies as $company) {
                $id = (int) ($company['id'] ?? 0);
                $name = (string) ($company['name'] ?? ('Компанія #' . $id));
                $isActive = (int) ($active['id'] ?? 0) === $id;
                $marker = $isActive ? ' ✅ активна' : '';
                $lines[] = '• #' . $id . ' — ' . $name . $marker;
                $keyboardRows[] = [
                    [
                        'text' => ($isActive ? '✅ ' : '🏢 ') . $name,
                        'callback_data' => 'tg_company_switch:' . $id,
                    ]
                ];
            }

            $lines[] = '';
            $lines[] = 'Щоб перемкнути компанію:';
            $lines[] = '• натисніть кнопку нижче,';
            $lines[] = '• або надішліть /company ID,';
            $lines[] = '• або напишіть: перемкни компанію на Назва.';
            $this->sendMessage($chatId, implode("\n", $lines), [
                'inline_keyboard' => $keyboardRows,
            ]);
            return;
        }

        $this->handlePrivateCompanySwitchRequest($chatId, null, null, $userId, $companies, $requestedCompanyId, false);
    }

    private function handleNaturalLanguageCompanySwitch(int $chatId, int $userId, array $companies, string $rawText): bool
    {
        $request = $this->extractRequestedCompanyFromText($rawText);
        if ($request === null) {
            return false;
        }

        $requestedCompanyId = null;
        if (preg_match('/^\d+$/', $request)) {
            $requestedCompanyId = (int) $request;
        } else {
            foreach ($companies as $company) {
                $name = mb_strtolower(trim((string) ($company['name'] ?? '')));
                if ($name === '') {
                    continue;
                }

                $needle = mb_strtolower(trim($request));
                if ($needle === $name || str_contains($name, $needle) || str_contains($needle, $name)) {
                    $requestedCompanyId = (int) ($company['id'] ?? 0);
                    break;
                }
            }
        }

        if ($requestedCompanyId === null || $requestedCompanyId <= 0) {
            $this->sendMessage($chatId, $this->formatWarningMessage('Не знайшов таку компанію', [
                'Надішліть /company і виберіть зі списку.',
            ]));
            return true;
        }

        $this->handlePrivateCompanySwitchRequest($chatId, null, null, $userId, $companies, $requestedCompanyId, false);
        return true;
    }

    private function handlePrivateCompanySwitchRequest(int $chatId, ?string $callbackId, ?int $messageId, int $userId, array $companies, int $requestedCompanyId, bool $editSourceMessage): void
    {
        foreach ($companies as $company) {
            if ((int) ($company['id'] ?? 0) !== $requestedCompanyId) {
                continue;
            }

            $this->savePrivateCompanyId($userId, $requestedCompanyId);
            $reply = $this->buildCompanySwitchedMessage($company);
            if ($callbackId !== null) {
                $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('✅', 'Компанію перемкнуто'));
            }

            if ($editSourceMessage && $messageId !== null) {
                $this->editMessageText($chatId, $messageId, $reply);
                return;
            }

            $this->sendMessage($chatId, $reply);
            return;
        }

        $notFoundMessage = '⚠️ Компанію з таким ID не знайдено у вашому акаунті. Надішліть /company для списку.';
        if ($callbackId !== null) {
            $this->answerCallbackQuery($callbackId, $this->formatCallbackStatus('⚠️', 'Компанію не знайдено'));
        }

        if ($editSourceMessage && $messageId !== null) {
            $this->editMessageText($chatId, $messageId, $notFoundMessage);
            return;
        }

        $this->sendMessage($chatId, $notFoundMessage);
    }

    private function extractRequestedCompanyFromText(string $rawText): ?string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $rawText));
        if ($normalized === '') {
            return null;
        }

        $patterns = [
            '/^(?:перемкни|переключи|переключай|зміни|обери|вибери)\s+компан(?:ію|и|ія)\s+(?:на|до)?\s+(.+)$/ui',
            '/^компан(?:ія|ію)\s+(\d+|.+)$/ui',
            '/^(?:працюй|працюємо)\s+з\s+компані(?:єю|я)\s+(.+)$/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $match)) {
                $candidate = trim((string) ($match[1] ?? ''));
                $candidate = trim($candidate, " \t\n\r\0\x0B.,:;!?");
                return $candidate !== '' ? $candidate : null;
            }
        }

        return null;
    }

    private function buildCompanySwitchedMessage(array $company): string
    {
        $id = (int) ($company['id'] ?? 0);
        $name = (string) ($company['name'] ?? ('#' . $id));

        return implode("\n", [
            '✅ Активну компанію перемкнуто.',
            '🏢 Поточна компанія: ' . $name,
            '🆔 ID: #' . $id,
            '',
            'Тепер усі задачі, цілі та шаблони в боті працюватимуть у цій компанії.',
        ]);
    }

    private function getSavedPrivateCompanyId(int $userId): ?int
    {
        $row = $this->db
            ->query("SELECT company_id FROM auth_tokens WHERE user_id = :user_id AND type = 'permanent' AND (token = 'TG_ACTIVE_COMPANY' OR token = :scoped_token) ORDER BY id DESC LIMIT 1")
            ->bind(':user_id', $userId)
            ->bind(':scoped_token', $this->getActiveCompanyTokenKey($userId))
            ->fetch();

        if (!$row || empty($row['company_id'])) {
            return null;
        }

        return (int) $row['company_id'];
    }

    private function savePrivateCompanyId(int $userId, int $companyId): void
    {
        if ($userId <= 0 || $companyId <= 0) {
            return;
        }

        try {
            $this->db
                ->query("DELETE FROM auth_tokens WHERE user_id = :user_id AND type = 'permanent' AND (token = 'TG_ACTIVE_COMPANY' OR token = :scoped_token)")
                ->bind(':user_id', $userId)
                ->bind(':scoped_token', $this->getActiveCompanyTokenKey($userId))
                ->execute();

            $this->db->insert('auth_tokens', [
                'token' => $this->getActiveCompanyTokenKey($userId),
                'user_id' => $userId,
                'company_id' => $companyId,
                'type' => 'permanent',
                'expires_at' => '2099-12-31 23:59:59',
            ]);
        } catch (\Throwable $e) {
            error_log('[TelegramBot] savePrivateCompanyId failed: ' . $e->getMessage() . ' userId=' . $userId . ' companyId=' . $companyId);
        }
    }

    private function handleLinkCommand(int $chatId, array $from, string $inputCode): void
    {
        $code = strtoupper(trim($inputCode));
        if ($code === '') {
            $this->sendMessage($chatId, $this->formatWarningMessage('Невірний код', [
                'Спробуйте ще раз у форматі: /link ABCD1234',
            ]));
            return;
        }

        if (!str_starts_with($code, 'TGLINK-')) {
            $code = 'TGLINK-' . $code;
        }

        $tokenRow = $this->db
            ->query("SELECT * FROM auth_tokens WHERE token = :token AND type = 'temp' AND expires_at > UTC_TIMESTAMP() LIMIT 1")
            ->bind(':token', $code)
            ->fetch();

        if (!$tokenRow) {
            $this->sendMessage($chatId, $this->formatWarningMessage('Код не знайдено або він уже протермінований', [
                'Згенеруйте новий у налаштуваннях акаунта.',
            ]));
            return;
        }

        $userId = (int) ($tokenRow['user_id'] ?? 0);
        $telegramId = (int) ($from['id'] ?? 0);
        if ($userId <= 0 || $telegramId <= 0) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося завершити привʼязку', [
                'Спробуйте ще раз.',
            ]));
            return;
        }

        // Avoid collisions if telegram id was linked elsewhere.
        $this->db
            ->query('UPDATE users SET telegram_id = NULL WHERE telegram_id = :telegram_id AND id <> :user_id')
            ->bind(':telegram_id', $telegramId)
            ->bind(':user_id', $userId)
            ->execute();

        $this->db
            ->query('UPDATE users SET telegram_id = :telegram_id, username = :username WHERE id = :user_id')
            ->bind(':telegram_id', $telegramId)
            ->bind(':username', (string) ($from['username'] ?? null))
            ->bind(':user_id', $userId)
            ->execute();

        $this->db
            ->query("DELETE FROM auth_tokens WHERE user_id = :user_id AND type = 'temp' AND token LIKE 'TGLINK-%'")
            ->bind(':user_id', $userId)
            ->execute();

        $this->sendMessage($chatId, $this->formatSuccessMessage('Telegram успішно привʼязано до вашого акаунта FINEKO'));
    }

    private function handleTelegramOnboardingLink(int $chatId, array $from, string $code): void
    {
        $telegramId = (int) ($from['id'] ?? 0);
        if ($telegramId <= 0) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося визначити Telegram акаунт'));
            return;
        }

        $storedToken = 'TGONB-' . $code;

        $tokenRow = $this->db
            ->query("SELECT * FROM auth_tokens WHERE token = :token AND type = 'tg_onboarding' AND expires_at > UTC_TIMESTAMP() LIMIT 1")  // VERSION_CHECK: 2026-04-16_utc_timestamp_v1
            ->bind(':token', $storedToken)
            ->fetch();

        if (!$tokenRow) {
            $this->sendMessage($chatId, $this->formatWarningMessage('Посилання для онбордінгу не знайдено або протерміноване', [
                'Попросіть адміністратора згенерувати нове.',
            ]));
            return;
        }

        $userId = (int) ($tokenRow['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося знайти користувача'));
            return;
        }

        // Avoid collisions — clear telegram_id if it was linked to another user
        $this->db
            ->query('UPDATE users SET telegram_id = NULL WHERE telegram_id = :telegram_id AND id <> :user_id')
            ->bind(':telegram_id', $telegramId)
            ->bind(':user_id', $userId)
            ->execute();

        // Link telegram_id and username to user
        $this->db
            ->query('UPDATE users SET telegram_id = :telegram_id, username = :username WHERE id = :user_id')
            ->bind(':telegram_id', $telegramId)
            ->bind(':username', (string) ($from['username'] ?? ''))
            ->bind(':user_id', $userId)
            ->execute();

        // Delete used token
        $this->db
            ->query("DELETE FROM auth_tokens WHERE id = :id")
            ->bind(':id', (int) ($tokenRow['id'] ?? 0))
            ->execute();

        // Find user's company
        $companies = $this->findCompaniesByUser($userId);
        $companyName = !empty($companies) ? (string) ($companies[0]['name'] ?? '') : '';

        $lines = ['Telegram успішно привʼязано до вашого акаунта FINEKO!'];
        if ($companyName !== '') {
            $lines[] = 'Компанія: ' . $companyName;
        }
        $lines[] = '';
        $lines[] = 'Тепер ви можете створювати задачі та цілі прямо з Telegram.';

        $this->sendMessage($chatId, $this->formatSuccessMessage($lines[0], array_slice($lines, 1)));
    }

    private function handleTelegramLoginCommand(int $chatId, array $from): void
    {
        $this->sendTelegramLoginLinkReply($chatId, $from, false);
    }

    private function sendTelegramLoginLinkReply(int $chatId, array $fromOrUser, bool $forPasswordReset): void
    {
        $telegramId = (int) ($fromOrUser['telegram_id'] ?? $fromOrUser['id'] ?? 0);
        if ($telegramId <= 0) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося визначити Telegram акаунт', [
                'Спробуйте ще раз.',
            ]));
            return;
        }

        $user = !empty($fromOrUser['password']) || !empty($fromOrUser['email'])
            ? $fromOrUser
            : $this->findUserByTelegramId($telegramId);

        if (!$user && isset($fromOrUser['first_name'])) {
            $user = $this->findOrCreateUserByTelegram($fromOrUser);
        }

        if (!$user) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося підготувати вхід через Telegram', [
                'Спробуйте ще раз трохи пізніше.',
            ]));
            return;
        }

        $url = $this->createTelegramLoginUrl((int) ($user['id'] ?? 0));
        if ($url === null) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося створити посилання для входу', [
                'Спробуйте ще раз трохи пізніше.',
            ]));
            return;
        }

        if ($forPasswordReset) {
            $this->sendMessage($chatId, $this->formatInfoMessage('Посилання для входу в налаштування пароля', [
                $url,
                'Після відкриття ви одразу потрапите в налаштування акаунта, де можна змінити пароль.',
                'Посилання діє 5 хвилин.',
            ]));
            return;
        }

        $this->sendMessage($chatId, $this->formatInfoMessage('Тимчасове посилання для входу у FINEKO', [
            $url,
            'Посилання діє 5 хвилин і підходить для швидкого входу або відновлення доступу.',
        ]));
    }

    private function createTelegramLoginUrl(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $this->ensureAuthTokensTable();

        $this->db->query("DELETE FROM auth_tokens WHERE user_id = :user_id AND type = 'temp' AND token LIKE 'TGLOGIN-%'")
            ->bind(':user_id', $userId)
            ->execute();

        $token = 'TGLOGIN-' . $this->generateMagicLoginToken();
        $expiresAt = date('Y-m-d H:i:s', time() + 300);

        $this->db->insert('auth_tokens', [
            'token' => $token,
            'user_id' => $userId,
            'company_id' => null,
            'type' => 'temp',
            'expires_at' => $expiresAt,
        ]);

        return rtrim(APP_URL, '/') . '/auth/telegram-token/' . rawurlencode(str_replace('TGLOGIN-', '', $token));
    }

    private function handleStartCommand(int $chatId, array $from): void
    {
        // VERSION_CHECK: 2026-04-16_09:50:00_handleStartCommand_v2
        $user = $this->findOrCreateUserByTelegram($from);
        if (!$user) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалось підготувати акаунт', [
                'Спробуйте ще раз трохи пізніше.',
            ]));
            return;
        }

        $userId = (int) ($user['id'] ?? 0);
        $this->updateInteractionLog([
            'app_user_id' => $userId > 0 ? $userId : null,
            'processing_status' => 'resolved_onboarding_start',
        ]);
        $companies = $this->findCompaniesByUser($userId);

        if (empty($companies)) {
            $this->storeOnboardingDraft($userId, ['step' => 'company_name', 'expires_at' => time() + 1800]);
            $this->sendMessage($chatId, $this->formatInfoMessage('Вітаю у FINEKO', [
                'У вашого акаунта ще немає компанії.',
                'Надішліть однією відповіддю назву компанії, і я одразу її створю.',
            ]));
            return;
        }

        $activeCompany = $this->resolveActivePrivateCompany($userId, $companies);
        $companyName = (string) ($activeCompany['name'] ?? '');
        $intro = $companyName !== '' ? 'Працюємо з компанією: ' . $companyName . "\n\n" : '';
        $this->sendCapabilitiesMessage($chatId, 'private', $intro);
    }

    private function handlePendingOnboardingReply(int $chatId, array $from, string $rawText): bool
    {
        $telegramId = (int) ($from['id'] ?? 0);
        if ($telegramId <= 0) {
            return false;
        }

        $user = $this->findOrCreateUserByTelegram($from);
        if (!$user) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        $this->updateInteractionLog([
            'app_user_id' => $userId > 0 ? $userId : null,
            'processing_status' => 'resolved_onboarding_reply',
        ]);

        $draft = $this->findOnboardingDraft($userId);
        if (!$draft) {
            return false;
        }

        $text = trim($rawText);
        if ($text === '') {
            $this->sendMessage($chatId, $this->formatInfoMessage('Чекаю назву компанії', [
                'Надішліть її одним повідомленням.',
            ]));
            return true;
        }

        if (preg_match('/^\/cancel$/iu', $text)) {
            $this->deleteOnboardingDraft($userId);
            $this->sendMessage($chatId, $this->formatInfoMessage('Створення компанії скасовано', [
                'Для старту знову надішліть /start.',
            ]));
            return true;
        }

        if (str_starts_with($text, '/')) {
            $this->sendMessage($chatId, $this->formatInfoMessage('Зараз я чекаю назву компанії', [
                'Надішліть її одним повідомленням або /cancel для скасування.',
            ]));
            return true;
        }

        $companyName = mb_substr($text, 0, 255);
        $companyId = $this->createCompanyForTelegramUser($userId, $companyName);
        if ($companyId <= 0) {
            $this->sendMessage($chatId, $this->formatErrorMessage('Не вдалося створити компанію', [
                'Спробуйте ще раз трохи пізніше.',
            ]));
            return true;
        }

        $this->deleteOnboardingDraft($userId);
        $this->savePrivateCompanyId($userId, $companyId);
        $this->sendCapabilitiesMessage($chatId, 'private', $this->formatSuccessMessage('Компанію "' . $companyName . '" створено') . "\n\n");
        return true;
    }

    private function buildUnrecognizedMessage(): string
    {
        return $this->formatWarningMessage('Не розпізнав повідомлення', [
            'Спробуйте сформулювати задачу або ціль простіше і конкретніше.',
        ]);
    }

    private function formatSuccessMessage(string $title, array $lines = []): string
    {
        return $this->formatStructuredMessage('✅', $title, $lines);
    }

    private function formatErrorMessage(string $title, array $lines = []): string
    {
        return $this->formatStructuredMessage('⚠️', $title, $lines);
    }

    private function formatWarningMessage(string $title, array $lines = []): string
    {
        return $this->formatStructuredMessage('⚠️', $title, $lines);
    }

    private function formatInfoMessage(string $title, array $lines = []): string
    {
        return $this->formatStructuredMessage('ℹ️', $title, $lines);
    }

    private function formatCallbackStatus(string $icon, string $text): string
    {
        return trim($icon . ' ' . $text);
    }

    private function formatStructuredMessage(string $icon, string $title, array $lines = []): string
    {
        $messageLines = [trim($icon . ' ' . $title)];

        foreach ($lines as $line) {
            $normalized = trim((string) $line);
            if ($normalized === '') {
                continue;
            }

            $messageLines[] = $normalized;
        }

        return implode("\n", $messageLines);
    }

    private function isCapabilitiesRequest(string $rawText, string $chatType): bool
    {
        $trimmed = trim($rawText);
        if ($trimmed === '') {
            return false;
        }

        $canReplyWithoutMention = $chatType === 'private';
        $hasBotMention = TELEGRAM_BOT_USERNAME !== '' && stripos($trimmed, '@' . TELEGRAM_BOT_USERNAME) !== false;
        $cleaned = preg_replace('/@\w+/u', '', $trimmed);
        $normalized = mb_strtolower(trim((string) $cleaned));

        $isCommand = (bool) preg_match('/^\/(help|start|abilities|capabilities)$/iu', $normalized);
        $isPhrase = false;
        foreach (['що ти можеш', 'що ти вмієш', 'які в тебе можливості', 'які твої можливості', 'що вмієш', 'твої можливості'] as $phrase) {
            if (mb_strpos($normalized, $phrase) !== false) {
                $isPhrase = true;
                break;
            }
        }

        if (!$isCommand && !$isPhrase) {
            return false;
        }

        return $canReplyWithoutMention || $hasBotMention || $isCommand;
    }

    private function sendCapabilitiesMessage(int $chatId, string $chatType, string $prefix = ''): void
    {
        $lines = [
            '🤖 Що я можу у FINEKO:',
            '• 📝 Створювати задачі з тексту або голосових повідомлень.',
            '• 🎯 Допомагати з цілями, результатами й шаблонами через звичайні фрази.',
            '• 📁 Показувати та створювати проекти.',
            '• ✏️ Редагувати задачі, цілі, шаблони та проекти через бот.',
            '• 🗑️ Видаляти задачі, цілі та шаблони.',
            '• 📊 Показувати план-факт по мені або по підлеглих за поточний тиждень.',
            '• 👥 Показувати список підлеглих або людей у поточній компанії.',
            '• ❓ Уточнювати обовʼязкові поля, якщо в повідомленні не вистачає деталей.',
            '• 🏢 Працювати з кількома компаніями через /company або фразу `перемкни компанію на ...`.',
            '• 🔗 Привʼязувати Telegram до акаунта через /link КОД.',
            '• 🔐 Давати тимчасове посилання для входу через /login.',
            '• 🔒 Підказати, як швидко зайти в налаштування і змінити пароль.',
            '',
            '⚡ Корисні команди:',
            '/company — показати або перемкнути активну компанію',
            '/link КОД — привʼязати Telegram до акаунта',
            '/login — отримати тимчасове посилання для входу',
            'план-факт по мені — короткий summary за поточний тиждень',
            'план-факт по підлеглих — короткий summary по підлеглих',
            'покажи моїх підлеглих — список прямих підлеглих',
            'покажи людей у моїй компанії — список учасників активної компанії',
            'змінити пароль — посилання на вхід у налаштування пароля',
            '',
            '💬 Приклади фраз:',
            '• створи задачу на завтра — підготувати комерційну пропозицію',
            '• перемкни компанію на Fineko Sales',
            '• покажи мої задачі',
            '• покажи план-факт по мені',
            '• покажи план-факт по підлеглих',
            '• покажи мої проекти',
            '• створи проект — Новий продукт',
        ];

        if ($chatType !== 'private') {
            $lines[] = '';
            $lines[] = '👥 У групі краще звертатись через згадку бота або команди.';
        }

        $message = ($prefix !== '' ? rtrim($prefix) . "\n" : '') . implode("\n", $lines);
        $this->sendMessage($chatId, $message);
    }

    private function createCompanyForTelegramUser(int $userId, string $companyName): int
    {
        $companyName = trim($companyName);
        if ($userId <= 0 || $companyName === '') {
            error_log("[TelegramBot] createCompany: invalid args userId={$userId} name='{$companyName}'");
            return 0;
        }

        $userCheck = $this->db
            ->query('SELECT id FROM users WHERE id = :id LIMIT 1')
            ->bind(':id', $userId)
            ->fetch();

        if (!$userCheck) {
            error_log("[TelegramBot] createCompany: user {$userId} does not exist in users table");
            return 0;
        }

        $this->db->beginTransaction();
        try {
            $this->db->insert('companies', [
                'name' => $companyName,
                'description' => null,
            ]);

            $companyId = (int) $this->db->lastInsertId();
            if ($companyId <= 0) {
                error_log("[TelegramBot] createCompany: lastInsertId returned {$companyId}");
                $this->db->rollback();
                return 0;
            }

            $this->db->insert('company_members', [
                'user_id' => $userId,
                'company_id' => $companyId,
                'department_id' => null,
                'title' => 'Owner',
                'role' => 'owner',
                'reports_to' => null,
            ]);

            $this->db->commit();
            return $companyId;
        } catch (\Throwable $e) {
            error_log("[TelegramBot] createCompany FAILED: " . $e->getMessage() . " | userId={$userId} name='{$companyName}'");
            try {
                $this->db->rollback();
            } catch (\Throwable $rollbackError) {
                error_log('[TelegramBot] createCompany rollback failed: ' . $rollbackError->getMessage());
            }
            return 0;
        }
    }

    private function getActiveCompanyTokenKey(int $userId): string
    {
        return 'TG_ACTIVE_COMPANY:' . $userId;
    }

    private function storeOnboardingDraft(int $userId, array $payload): void
    {
        $draftDir = $this->getPendingIntentDraftDirectory();
        if (!is_dir($draftDir)) {
            if (!@mkdir($draftDir, 0777, true) && !is_dir($draftDir)) {
                error_log("[TelegramBot] storeOnboardingDraft: cannot create dir {$draftDir}");
                return;
            }
        }

        $path = $this->getOnboardingDraftPath($userId);
        $result = @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE));
        if ($result === false) {
            error_log("[TelegramBot] storeOnboardingDraft: failed to write {$path}");
        }
    }

    private function findOnboardingDraft(int $userId): ?array
    {
        $path = $this->getOnboardingDraftPath($userId);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            @unlink($path);
            return null;
        }

        $expiresAt = (int) ($payload['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            @unlink($path);
            return null;
        }

        return $payload;
    }

    private function deleteOnboardingDraft(int $userId): void
    {
        $path = $this->getOnboardingDraftPath($userId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function getOnboardingDraftPath(int $userId): string
    {
        return $this->getPendingIntentDraftDirectory() . DIRECTORY_SEPARATOR . 'onboarding-' . $userId . '.json';
    }

    private function findOrCreateUserByTelegram(array $from): ?array
    {
        $telegramId = (int) ($from['id'] ?? 0);
        if ($telegramId <= 0) {
            return null;
        }

        $existing = $this->findUserByTelegramId($telegramId, (string) ($from['username'] ?? ''));
        if ($existing) {
            return $existing;
        }

        try {
            $this->db->insert('users', [
                'first_name' => (string) ($from['first_name'] ?? 'Telegram'),
                'last_name' => (string) ($from['last_name'] ?? ''),
                'email' => null,
                'password' => null,
                'phone_number' => null,
                'photo_url' => null,
                'telegram_id' => $telegramId,
                'username' => (string) ($from['username'] ?? null),
            ]);
        } catch (\Throwable $e) {
            error_log("[TelegramBot] findOrCreateUserByTelegram INSERT failed: " . $e->getMessage() . " telegramId={$telegramId}");
            // Try to find user again in case of race condition (duplicate insert)
            $retryFind = $this->findUserByTelegramId($telegramId, (string) ($from['username'] ?? ''));
            if ($retryFind) {
                return $retryFind;
            }
            return null;
        }

        return $this->findUserByTelegramId($telegramId, (string) ($from['username'] ?? ''));
    }

    private function ensureAuthTokensTable(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS auth_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(255) NOT NULL,
            user_id INT NOT NULL,
            company_id INT NULL,
            type VARCHAR(40) NOT NULL DEFAULT 'temp',
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_auth_token (token),
            KEY idx_auth_tokens_user (user_id),
            KEY idx_auth_tokens_type_exp (type, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();
    }

    private function generateMagicLoginToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    private function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): void
    {
        $this->appendConversationTurn($chatId, 'assistant', $text);
        $this->appendInteractionReply($text);

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $this->sendTelegramRequest('sendMessage', $payload);
    }

    private function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $this->sendTelegramRequest('editMessageText', $payload);
    }

    private function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text !== '') {
            $payload['text'] = $text;
        }

        $this->sendTelegramRequest('answerCallbackQuery', $payload);
    }

    private function sendTelegramRequest(string $method, array $payload): void
    {
        if (TELEGRAM_BOT_TOKEN === '') {
            return;
        }

        if ((string) getenv('TELEGRAM_SKIP_NETWORK') === '1') {
            return;
        }

        $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method;

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        curl_exec($ch);
        curl_close($ch);
    }
}
