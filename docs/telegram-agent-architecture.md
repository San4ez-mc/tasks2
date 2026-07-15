# Telegram Agent Architecture

## Goal

Move the Telegram bot from a single-pass parser with aggressive fallback heuristics to a staged agent workflow that:

- understands multi-turn context;
- separates classification, planning, validation, and execution;
- prefers clarification over guessing;
- produces safer actions for create, update, list, delete, and company-switch flows.

## Current Problems

The current bot in `app/Services/TelegramBotService.php` mixes several responsibilities in one pipeline:

- transport handling;
- context loading;
- AI intent parsing;
- regex fallback parsing;
- command execution;
- user-facing formatting.

That leads to predictable failure modes:

- list requests can be confused with create requests;
- delete requests fall into create fallback;
- fallback regexes overfit surface words like `дай` inside `додай`;
- the bot executes too early when confidence is low;
- behavior is difficult to audit and extend safely.

## Target Pipeline

The bot should evolve toward this staged flow:

1. Transport layer
- Telegram webhook normalization.
- Extract message text, audio transcription, callback actions.

2. Context layer
- Resolve user.
- Resolve active company.
- Load recent conversation turns.
- Load relevant entities: tasks, goals, templates, employees.

3. Classification layer
- Determine the top-level intent first.
- Examples: `company_switch`, `task_list`, `goal_list`, `create`, `update`, `delete`, `help`, `clarification`, `unknown`.
- This layer must be conservative.

4. Planning layer
- Convert the user request into a structured plan.
- Example: `update task`, `targetTitle=...`, `fields={date, expectedTime}`.
- Use AI here only after the top-level action is classified.

5. Validation layer
- Check whether the plan is executable.
- If any required fields or targets are missing, reply with clarification.
- If the action is risky, create a preview instead of executing immediately.

6. Execution layer
- Call `TelegramIntentCommandService` or specialized services.
- Keep execution side effects isolated from parsing logic.

7. Response layer
- Build consistent bot replies.
- Reuse central formatting helpers for success, warning, error, and info messages.

## Phase Plan

### Phase 1: Stabilize Heuristics

Status: started.

Changes introduced:

- `app/Services/TelegramMessageClassifierService.php`
- dedicated whole-word marker detection for task list / goal list / delete requests;
- delete requests no longer fall into create fallback;
- list detection no longer matches substrings like `дай` inside `додай`.

Expected outcome:

- fewer false positives in fallback routing;
- cleaner separation between list and create flows.

### Phase 2: Introduce a Router Service

Add a `TelegramIntentRouterService` that returns a route object:

```php
[
    'route' => 'task_list|goal_list|company_switch|planner|delete_not_supported|unknown',
    'confidence' => 'high|medium|low',
    'reason' => '...'
]
```

`TelegramBotService` should use this router before any AI parsing.

### Phase 3: Introduce a Planner Service

Add a `TelegramActionPlannerService` that receives:

- normalized user text;
- active company;
- recent conversation;
- candidate entities.

It should return a structured action plan, not raw executor commands.

Example:

```php
[
    'intent' => 'task_update',
    'target' => ['type' => 'task', 'title' => 'Підготувати КП'],
    'changes' => ['date' => '2026-04-15', 'expectedTime' => 120],
    'needs_confirmation' => false,
    'missing_fields' => []
]
```

### Phase 4: Add an Executor Boundary

Add an adapter that converts planner output into executor commands for `TelegramIntentCommandService`.

This keeps planning independent from execution and makes testing easier.

### Phase 5: Add Safe Delete Flow

Delete should never go through create fallback.

When delete support is implemented:

- classify delete first;
- resolve target entity;
- show preview with confirmation;
- only then execute deletion.

### Phase 6: Add Confidence-Based Clarification

If route or plan confidence is low:

- do not execute;
- show a compact clarification prompt;
- reuse previous turn context to complete the action.

## Recommended Service Split

Target service boundaries:

- `TelegramBotService`
  - transport orchestration only;
- `TelegramMessageClassifierService`
  - cheap deterministic intent detection;
- `TelegramIntentRouterService`
  - choose the pipeline path;
- `TelegramActionPlannerService`
  - AI-assisted structured planning;
- `TelegramPlanValidatorService`
  - required fields, ambiguity, safe-guards;
- `TelegramIntentCommandService`
  - execution of validated commands;
- `TelegramResponseFormatterService`
  - consistent user-facing replies.

## Acceptance Criteria

The migration is successful when:

- `додай задачу ...` never routes to list;
- `видали ...` never routes to create;
- low-confidence requests produce clarification, not execution;
- active company switching is deterministic;
- bot logs show explicit route decisions and validation outcomes.

## Logging Recommendations

Extend `telegram_ai_interaction_logs` over time with:

- `route_name`;
- `route_confidence`;
- `planner_json`;
- `validator_result`;
- `executor_result`.

That will make failures diagnosable without guessing from `execution_path` alone.