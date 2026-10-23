# Architecture: Kafui, the JayFoods ordering agent

Status: deployed at https://jayfoods.princecaleb.dev on 23 Oct 2026, with Kafui switched off until WhatsApp is connected. Baseline 0.4.0 (CHG-003, admin roles and the error log) approved by Caleb, 23 Oct 2026
Owner: Prince Caleb (PrinceCaleb.dev)
Version: 0.4.0, matches `security-baseline.yaml` baseline_version
Last reviewed: 2026-10-23

This covers the agent added to the existing JayFoods site. The rest of the site (storefront, checkout, Paystack, admin) is described in `README.md`.

## Context and goals

JayFoods takes orders by hand on WhatsApp and phone. Kafui (said kah-FOO-ee, stress on FOO) answers customers on the business WhatsApp number and in a live chat on the website. It is one agent with one set of tools, the same way Lisa answers on princecaleb.dev and on WhatsApp.

Kafui:

- answers in plain, friendly language, and says it is JayFoods' automatic assistant;
- takes orders: flavour, bottle size, quantity, delivery or pickup, area, date, name and phone;
- gives prices, bulk prices and delivery fees only from the admin catalogue and delivery zones, through server tools;
- takes bookings (meaning set in DEC-001);
- hands off to a person when asked or when unsure;
- records each order and booking in the admin as pending, for staff to confirm;
- recognises the owner's WhatsApp number and, for the owner only, gives sales summaries, order and booking lists, and invoice PDFs.

Non-goals for this change: taking payments in the chat (DEC-005), messaging customers first (needs Meta templates), importing the old customer list (DEC-007), voice calls.

## System context

```
 Customer / owner                         Website visitor
   (WhatsApp)                                (browser)
       |                                        |
   Twilio (or Meta) --signed webhook-->  /api/v1/chat (signed session)
       |  (CMP-007)        |                    | (CMP-004)
       |        /api/v1/whatsapp/webhook        |
       |              (CMP-003)                 |
       |                  \                    /
       |                   +-- Agent engine --+        AI providers
       |                       (CMP-005)  <------->   (CMP-006, failover)
       |                           |
       |                     Tool layer: role check, validation,
       |                     prices from the database
       |                           |
       |                     SQLite (CMP-008): catalogue, zones, orders,
       |                     bookings, conversations, action log,
       |                     invoices folder outside the web root
       |                           |
       +<--- reply / document -----+----> staff email (CMP-009)
                                   |
                         Admin (CMP-002): settings, inbox,
                         take over / hand back, kill switch, log
```

Trust boundaries (TB-*) are in `security-baseline.yaml`. The one that matters most is TB-004: everything the model says is untrusted. It reaches data only through the tool layer, which checks the caller's role from the channel, not from the model.

## Components and ownership

| Component | Responsibility | Runtime/owner | Exposure | Data handled | Dependencies |
|---|---|---|---|---|---|
| CMP-003 WhatsApp webhook | `/api/v1/whatsapp/twilio` (default) or `/api/v1/whatsapp/webhook` (Meta): verify the provider's signature, drop duplicates, store the message, answer 200, then run the agent and send the reply | PHP on cPanel / Caleb | INTERNET | CONFIDENTIAL | Meta, agent engine |
| CMP-004 Website chat | Widget on the storefront; endpoint issues a signed anonymous session and runs the agent with customer tools | PHP + vanilla JS / Caleb | INTERNET | CONFIDENTIAL | Agent engine |
| CMP-005 Agent engine and tools | Build the prompt, call providers in order, run allowed tools, check money amounts, apply limits, log actions | PHP / Caleb | PRIVATE | CONFIDENTIAL | Providers, SQLite |
| CMP-006 AI providers | Generate replies and tool calls | Third party (DEC-003) | THIRD_PARTY | Conversation text | None |
| CMP-007 WhatsApp provider | Deliver messages both ways: Twilio WhatsApp sender by default, Meta Cloud API as the alternative | Twilio, Meta | THIRD_PARTY | CONFIDENTIAL | None |
| CMP-002 Admin (extended) | WhatsApp and AI settings, owner list, inbox, take over / hand back, kill switch, action log | PHP + vanilla JS / Caleb | INTERNET | CONFIDENTIAL | SQLite |
| CMP-008 SQLite + invoices folder | Store everything; invoices in `database/invoices/` | Server | PRIVATE | CONFIDENTIAL | None |

Files:

- `src/Agent/Kafui.php`: one message from start to finish. Kill switch, staff mode, limits, handoff words, the provider chain, the money check on replies.
- `src/Agent/KafuiTools.php`: the tools, the role allow-list, argument checks and all pricing. This is the security boundary between the model and the data.
- `src/Agent/KafuiPrompt.php`: the system prompt, built from the admin data on every message.
- `src/Agent/ModelProviders.php`: Anthropic and the OpenAI-style API (OpenAI, OpenRouter, Groq), with tool calls. Ported from Lisa's `AiAgentEngine`.
- `src/Agent/AgentSettings.php`: settings and encrypted secrets, the provider chain, owner numbers.
- `src/Agent/WhatsAppCloud.php`: send text and documents, verify signatures. The only provider-specific class, so Twilio could replace it later.
- `src/Agent/InvoicePdf.php` and `Invoices.php`: built-in PDF writer and signed invoice links.
- `src/Agent/KafuiStore.php`, `AgentMonitor.php`, `KafuiNotify.php`, `KafuiContext.php`, `KafuiText.php`, `AgentHttp.php`, `bootstrap.php`.
- `src/Support/Signer.php`: signed, expiring tokens for chat sessions and invoice links.
- `src/Controllers/WhatsAppWebhookController.php`, `ChatController.php`, `AdminAgentController.php`.
- `public/js/kafui-chat.js` (website widget) and `public/admin/kafui.js` (admin screens).
- Schema: `database/schema.sql` and `migrate.php` add `agent_settings`, `agent_owner_numbers`, `conversations`, `conversation_messages`, `agent_quotes`, `bookings`, `agent_actions`, `agent_events`, `agent_rate_events` and `agent_invoices`, plus `orders.source`, `orders.fulfilment` and `orders.requested_date`.
- `database/kafui-cleanup.php`: the daily clean-up for cron.
- `tests/agent/run.php` (80 tests, fake model and fake WhatsApp) and `tests/agent/send-test-webhook.php` (signed test message to a running site).

## Data flows and deployment

| Flow | Source to destination | Protocol and auth | Class | Storage and retention | Failure behaviour |
|---|---|---|---|---|---|
| FLW-001 WhatsApp in/out | Twilio to webhook to Twilio | HTTPS; X-Twilio-Signature (HMAC-SHA1, auth token, exact URL) in; Account SID and auth token out (Meta: app-secret signature in, system-user token out) | CONFIDENTIAL | Transcript 90 days | Bad signature: 401. Duplicate: ignored. Send failure: logged, retried once |
| FLW-002 Website chat | Browser to `/api/v1/chat` | HTTPS; signed 24-hour session; per-session and per-IP limits | CONFIDENTIAL | Transcript 90 days | Over limit: fixed message, no model call |
| FLW-003 Order or booking | Tool to database | In-process; quote id bound to the conversation | CONFIDENTIAL | With orders | Price changed: new quote, no order |
| FLW-004 Prompt | Engine to provider | HTTPS with the provider key | CONFIDENTIAL | Provider's retention (DEC-003) | All providers fail: fixed message and staff handoff |
| FLW-005 Handoff | Conversation to staff | Email plus admin inbox | CONFIDENTIAL | Transcript 90 days | Staff replies only inside 24 hours of the customer's last message |
| FLW-006 Owner and invoices | Owner's WhatsApp to tools to PDF | Owner number check; signed link valid 72 hours | CONFIDENTIAL | PDF 30 days | Link expired: 403 |

Deployment: the existing cPanel PHP host. The method (cPanel Git pull or uploading a tagged release) and the live domain are open (DEC-006). Secrets are typed into the admin and stored encrypted with the app key, as Paystack and SMTP are today. Rollback: turn Kafui off in the admin, then redeploy the previous release. The new tables are additive.

## Interfaces and integrations

- **Twilio (default, CHG-002).** Webhook `POST /api/v1/whatsapp/twilio`, form-encoded and signed with `X-Twilio-Signature` over the exact URL saved in Twilio, which must equal the admin's Live site address. Replies go to `POST https://api.twilio.com/2010-04-01/Accounts/<SID>/Messages.json`; replies over 1,500 characters go out in parts, PDFs as `MediaUrl`. Twilio gets empty TwiML at once and Kafui runs after the response is sent (`litespeed_finish_request` on the cPanel host). Only the chosen provider's webhook accepts messages.
- **Meta Cloud API (alternative).** Webhook `GET/POST /api/v1/whatsapp/webhook`. Send with `POST https://graph.facebook.com/<version>/<phone-number-id>/messages`. The Graph API version is a setting, not hard-coded. The webhook answers in under a second and the agent runs after the response is flushed. Message ids are unique, so Meta's retries do nothing.
- **AI providers.** Lisa's chain: Anthropic, then OpenAI, then OpenRouter, then Groq. Each provider runs its main model and then its optional backup model, and each attempt is a fresh turn with its own time limit (20 s, OpenRouter 30 s). The whole chain stops at 90 s. An attempt that errors, times out, is cut short or returns nothing passes to the next. An attempt that already placed an order or handed over is never repeated. If all fail, the customer gets a fixed message and the chat goes to staff.
- **Website chat.** `POST /api/v1/chat/session`, `POST /api/v1/chat/message`, `GET /api/v1/chat/history`. JSON only, and requests over 4 KB are refused. The widget polls the history every 6 seconds while staff are answering.
- **Admin.** `/api/v1/admin/agent/*` behind the existing admin login.
- **Invoices.** `GET /api/v1/invoices/{token}`, where the token is signed and expires.

## Decisions and change triggers

| Decision ID | Decision | Rationale | Alternatives | Security impact | Status |
|---|---|---|---|---|---|
| ARCH-1 | One agent engine and one tool set for WhatsApp and the website chat | Caleb's rule: the live chat is the same agent; one place to fix | Separate bots per channel | Role is set per channel; the website never gets owner tools | DECIDED (Caleb, 23 Oct 2026) |
| ARCH-2 | WhatsApp through a Twilio WhatsApp sender, our server runs the brain, as Lisa (CHG-002) | Caleb's choice; Twilio's sign-up does the Meta side, and a Meta app was blocked until the business is registered | Meta Cloud API directly (kept as an alternative provider) | Twilio auth token is account-wide: JayFoods-only subaccount (DEC-008) | DECIDED (Caleb, 23 Oct 2026, baseline 0.3.0) |
| ARCH-3 | Prices only from the admin catalogue through tools | No invented prices; one price list | A separate WhatsApp price list | Money never comes from model output | DECIDED (Caleb, 23 Oct 2026) |
| ARCH-4 | Lisa's provider chain with a backup model per provider (CHG-001) | One slow or failing provider never stops a reply | One shared time budget; Gemini and DeepSeek | Up to five attempts per message; spend caps per provider | DECIDED (Caleb, 23 Oct 2026, baseline 0.2.0) |
| DEC-001 to DEC-007 | See `security-baseline.yaml` | | | | OPEN |

Reassess this document and the baseline before changing: the tool list or roles, the owner rule, the providers, the WhatsApp provider, what goes into prompts, retention, or adding payments to the chat.
