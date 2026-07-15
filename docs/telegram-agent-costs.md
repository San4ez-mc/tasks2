# Telegram Agent Cost Scenarios

## Usage assumption

Base scenario for estimation:

- 5 users
- up to 15 messages per user per day
- total: 75 messages per day
- total: about 2,250 messages per month

Assumption for context:

- only 2-3 previous messages are included
- not the full chat history

Assumption for routing:

- a good router should keep some messages out of the LLM path
- examples: company switching, callbacks, confirm/cancel, some list requests, some help requests

Because of that, there are two practical traffic bands:

- conservative: 100% of messages go through the model
- realistic: 60-70% of messages go through the model

## Pricing references used

- GPT-5.4 mini: $0.75 / 1M input, $4.50 / 1M output
- GPT-5.4: $2.50 / 1M input, $15.00 / 1M output
- Claude Sonnet 4.6: $3.00 / 1M input, $15.00 / 1M output

## Scenario A: Mini Only

Model strategy:

- router + rules first
- GPT-5.4 mini as the main planner
- no strong-model escalation

Per AI request estimate:

- input: 8,000 tokens
- output: 1,500 tokens

Cost per AI request:

- input: 8,000 x 0.75 / 1,000,000 = $0.006
- output: 1,500 x 4.50 / 1,000,000 = $0.00675
- total: $0.01275

Monthly estimate:

- 2,250 AI requests: $28.69
- 1,575 AI requests: $20.08
- 1,350 AI requests: $17.21

Expected real monthly range:

- about $17 to $29

## Scenario B: Mini + 10% Strong Fallback

Model strategy:

- rules/router first
- GPT-5.4 mini for most requests
- GPT-5.4 for difficult requests only
- strong fallback share: 10%

Per AI request estimate:

- mini request: 10,000 input + 2,000 output
- strong request: 18,000 input + 3,000 output

Costs:

- mini: 10,000 x 0.75 / 1,000,000 + 2,000 x 4.50 / 1,000,000 = $0.0165
- strong: 18,000 x 2.50 / 1,000,000 + 3,000 x 15.00 / 1,000,000 = $0.09

Weighted average:

- 90% mini + 10% strong
- 0.9 x 0.0165 + 0.1 x 0.09 = $0.02385 per AI request

Monthly estimate:

- 2,250 AI requests: $53.66
- 1,575 AI requests: $37.56
- 1,350 AI requests: $32.20

Expected real monthly range:

- about $32 to $54

## Scenario C: Mini + 20% Strong Fallback

Model strategy:

- rules/router first
- GPT-5.4 mini for most requests
- GPT-5.4 for difficult requests
- strong fallback share: 20%

Weighted average:

- 80% mini + 20% strong
- 0.8 x 0.0165 + 0.2 x 0.09 = $0.0312 per AI request

Monthly estimate:

- 2,250 AI requests: $70.20
- 1,575 AI requests: $49.14
- 1,350 AI requests: $42.12

Expected real monthly range:

- about $42 to $70

## Optional Claude Sonnet fallback

If strong fallback uses Claude Sonnet 4.6 instead of GPT-5.4:

- strong request cost becomes about $0.099
- 10% fallback weighted average becomes about $0.02475
- 20% fallback weighted average becomes about $0.033

That keeps the monthly totals very close to the GPT-5.4 strong-fallback scenarios, just slightly higher.

## Recommended stack for this project

Recommended choice:

- Mini + 10% strong fallback

Reasoning:

- it is still cheap for the current team size;
- it gives a much better safety margin for ambiguous Ukrainian requests;
- it avoids paying strong-model prices on every message;
- it fits the staged architecture already documented in `docs/telegram-agent-architecture.md`.

Expected real budget for current usage:

- about $32 to $54 per month

Most likely practical target after router improvements:

- about $35 to $45 per month

## Practical routing recommendation

Use deterministic handling first for:

- `/company`
- callbacks
- confirm/cancel
- help and capability requests
- obvious company-switch phrases
- obvious task-list and goal-list phrases

Use GPT-5.4 mini for:

- ordinary create and update requests
- clarification requests
- template creation and editing
- most result and task planning

Escalate to the strong model only for:

- low-confidence planner output
- conflicting user follow-ups
- ambiguous multi-intent messages
- complex nested goal/result creation
- cases where the validator detects uncertainty

## Implementation direction

For the next coding step, the project should introduce:

- `TelegramIntentRouterService`
- a model-selection policy
- confidence-based escalation from mini to strong
- logging of route and escalation decisions in `telegram_ai_interaction_logs`