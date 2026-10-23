# Security

Status: APPROVED. Baseline 0.2.0 approved by Prince Caleb on 23 Oct 2026 for development, staging and production (digest `bf6e6f806b72a527ee1b64355c04b06a80cb50d83a4f370e64bd2bff45c451eb`). 0.1.0 was approved earlier the same day.
Baseline version: 0.2.0
Canonical record: `security-baseline.yaml`
Owner: Prince Caleb (PrinceCaleb.dev)
Last reviewed: 2026-10-23

This file is the readable view. `security-baseline.yaml` is the record for approval, scope, locks, controls, gates and evidence.

## Posture and scope

- **What this covers:** Kafui, the AI ordering agent on the JayFoods WhatsApp number and the website chat (see `ARCHITECTURE.md`).
- **Risk:** HIGH, full mode. The agent writes orders from public messages, owner tools expose all orders and sales, and customer data goes to Meta and AI providers.
- **Approval:** scope is development, staging (a test number and a local copy) and production (the live site and the business number).
- **Latest change:** CHG-001 moved the AI providers to Lisa's chain (Anthropic, OpenAI, OpenRouter, Groq), each with a backup model and its own time limit. It was approved with 0.2.0. The pull-request check needs this digest stored in the repository variable `APPROVED_BASELINE_DIGEST`.
- **Out of scope:** payments in the chat, business-initiated messages, the old customer list import, voice.

## Assurance targets and standards

ASVS level 2, SLSA build level 2, SLSA source level 3. Controls carry references to OWASP Top 10:2025, OWASP LLM Top 10 2025, OWASP Agentic Top 10 2026, NIST SSDF 1.1, SLSA 1.2, CISA Secure by Design and ASVS 5.0. These references show which topics are covered. They are not conformance claims.

## Supply chain and vulnerability management

- No package manager and no third-party packages. `sbom.cdx.json` lists the PHP runtime and extensions.
- `.github/workflows/kafui-checks.yml` runs PHP lint, the agent tests, a secret scan (`tests/ci/secret-scan.sh`) and the baseline digest check on every pull request. It is written, but has not run on GitHub yet.
- `.github/workflows/release.yml` builds tagged releases with a build provenance attestation. Not run yet.
- Actions are pinned to commit SHAs. `.github/CODEOWNERS` puts the gate files under Caleb's review.
- HIGH findings are fixed within 14 days, with Kafui switched off meanwhile if it is exposed.
- Report a vulnerability privately through GitHub's private vulnerability reporting (see Reporting and response).

## Regulatory context

| Regime | Status | Addressed by |
|---|---|---|
| Ghana Data Protection Act, 2012 (Act 843) | APPLICABLE (Caleb for the owner, DEC-004). Data Protection Commission registration is the owner's action | CTL-DATA-001, CTL-DATA-002 |
| WhatsApp Business Terms and Messaging and Commerce policies | APPLICABLE | CTL-API-003, CTL-AI-005, CTL-AI-006 |
| AI provider terms | APPLICABLE | CTL-DATA-002, DEC-003 |
| PCI DSS | NOT_APPLICABLE: no card data in the agent | none |

This is not legal advice.

## Baseline controls

Evidence:

- **EVD-006** is the local test run: 80 passed, 0 failed.
- **EVD-007** is the browser check on a local throwaway copy.
- **EVD-008** is the local secret scan.

None of it ran on the live site or on GitHub.

| Control | Requirement (short) | Status | Evidence | Next step |
|---|---|---|---|---|
| CTL-GOV-001 | No build or deploy without approval; changes reapproved | IMPLEMENTED | | CI digest check runs on GitHub once the variable is set |
| CTL-AI-001 | Tool allow-list per role, set in code from the channel; arguments validated | VERIFIED | EVD-006 | |
| CTL-AI-002 | Money only from database tools; orders only from a quote shown in an earlier message | VERIFIED | EVD-006 | |
| CTL-AI-003 | Wrong money amounts in replies are replaced | VERIFIED | EVD-006 | |
| CTL-AI-004 | Caps on tool calls, rounds, time per attempt, tokens, messages, orders and bookings | VERIFIED | EVD-006 | |
| CTL-AI-005 | Handoff silences Kafui until staff hand back | VERIFIED | EVD-006, EVD-007 | |
| CTL-AI-006 | Kafui says it is automatic, offers a person, says orders are pending | IMPLEMENTED | | Read real transcripts after go-live |
| CTL-AI-007 | Off switch for both channels; a log of every tool call | VERIFIED | EVD-006 | |
| CTL-AUTHZ-001 | Customers see only their own orders | VERIFIED | EVD-006 | |
| CTL-AUTHZ-002 | Owner role only for listed numbers on WhatsApp | VERIFIED | EVD-006 | |
| CTL-AUTHZ-003 | Owner-tool use emails the owner | IMPLEMENTED | | Needs working SMTP to observe |
| CTL-AUTH-001 | Random first-run admin password; old published password changed on the live site | PLANNED | | Seeding fixed; Caleb must check the live admin password |
| CTL-SES-001 | Admin routes refuse cross-site requests; signed, expiring chat sessions | VERIFIED | EVD-006, EVD-007 | |
| CTL-API-001 | Webhook signature check and verify-token handshake | VERIFIED | EVD-006, EVD-007 | |
| CTL-API-002 | Each message processed once; fast 200 to Meta | VERIFIED | EVD-006 | |
| CTL-API-003 | 24-hour window, no first contact, size and rate limits | IMPLEMENTED | | |
| CTL-INP-001 | Prepared statements, escaping, argument checks | VERIFIED | EVD-006, EVD-007 | |
| CTL-FE-001 | Chat text rendered as text only; no third-party scripts | VERIFIED | EVD-007 | |
| CTL-FILE-001 | Invoice PDFs outside the web root, signed 72-hour links, deleted after 30 days | VERIFIED | EVD-006 | |
| CTL-CRY-001 | Per-purpose HMAC keys, AES-256-GCM at rest, HTTPS | VERIFIED | EVD-006 | HTTPS checked at go-live |
| CTL-SEC-001 | Secrets typed into the admin, encrypted, never returned, logged or committed | VERIFIED | EVD-006, EVD-008 | |
| CTL-SEC-002 | Meta token limited to WhatsApp on one account; AI spend caps; rotation steps | PLANNED | | Caleb, in Meta and at each provider (docs/kafui-setup.md) |
| CTL-CFG-001 | Kafui off by default; security headers; errors not displayed | PLANNED | | Off by default, API headers and error hiding are built. Admin frame-blocking and a storefront content policy need server (`.htaccess`) settings on cPanel |
| CTL-DATA-001 | Prompts carry public facts and this chat only; 90-day and 30-day deletion | VERIFIED | EVD-006 | Add the cleanup cron on cPanel |
| CTL-DATA-002 | Privacy notice updated; providers set not to train; deletion on request | PLANNED | | Privacy page section written; provider settings at go-live |
| CTL-ERR-001 | All providers failing gives a fixed message and a handoff | VERIFIED | EVD-006 | |
| CTL-LOG-001 | No secrets or full messages in logs; phone numbers masked | IMPLEMENTED | | |
| CTL-LOG-002 | Counters and alert emails | IMPLEMENTED | | Needs working SMTP to observe |
| CTL-SC-001 | No new packages; CI lint, tests, secret scan; SBOM | IMPLEMENTED | | First GitHub run |
| CTL-DEP-001 | Protect `main`; deploy only merged, tagged releases with attestation | PLANNED | | Caleb turns on branch protection and required checks |
| CTL-REC-001 | Written incident steps | IMPLEMENTED | | Tabletop run |
| CTL-REC-002 | Daily off-server backup and a rehearsed restore | PLANNED | | Before go-live |

## Identity, data and boundaries

- **Customers on WhatsApp** are identified by the sender number inside Meta's signed webhook.
- **Website visitors** are anonymous, with a signed 24-hour session. To look up an order they need its reference and phone number.
- **The owner** is a listed number on WhatsApp only. Owner tools are read-only plus invoices.
- **Staff** use the existing admin login.
- **Kafui** holds no credentials. It can call only the tools its caller's role allows, and the server prices everything.
- **Secrets** (Meta token, app secret, verify token, AI keys) are typed into the admin and stored encrypted with the app key.
- **Data minimisation:** prompts hold public business facts and the last 20 turns of one conversation.
- **Retention:** transcripts are deleted after 90 days and invoice files after 30.
- **Processors:** Meta, and the AI providers Caleb turns on (Anthropic, OpenAI, OpenRouter, Groq).

## Gates and evidence

| Gate | Stage | Blocking | Status |
|---|---|---|---|
| GATE-001 Baseline approval | Before implementation | Yes | CONFIGURED: 0.2.0 approved (EVD-001) |
| GATE-002 PR checks: lint, agent tests, secret scan, digest | Pull request | Yes | NOT_YET_BUILT on GitHub (workflow written, branch protection off) |
| GATE-003 Go-live checklist on the live number | Before deploy | Yes | NOT_YET_BUILT (checklist in docs/kafui-setup.md) |
| GATE-004 Runtime limits and kill switch | Runtime | Yes | NOT_YET_BUILT on the live site (built and tested locally) |

## Known risks and open decisions

- **Blocking:** none.
- **Open, not blocking:**
  - **DEC-005:** payment for chat orders. The default sentence is a placeholder.
  - **DEC-006:** deploy method and live domain.
  - **DEC-007:** importing the client's customer list. Not built.
- **Residual risks:**
  - **MEDIUM:** message flooding cost, now up to five model attempts per message under CHG-001 (THR-005).
  - **MEDIUM:** owner tools on a lost or borrowed phone (THR-007).
  - **MEDIUM:** provider data use (THR-012).
  - **MEDIUM:** an unreviewed deploy while branch protection is off (THR-013).
- **Known limitation:** event bookings are accepted only up to 60 days ahead (CTL-AI-001 date rule). Later dates go to staff. Widening this is a design change.
- **Found during design:**
  - The repository is public.
  - `main` has no branch protection.
  - The old first-run password `changeme123` was published in `database/migrate.php`. New installs now get a random one.
  - The git history holds no secrets or databases (checked 2026-10-23).

## Reporting and response

### Reporting a vulnerability

Please use GitHub's private vulnerability reporting or open a private security advisory for this repository (or contact Prince Caleb through princecaleb.dev). Do not include exploit details, credentials, or personal data in a public issue.

Include the affected version, reproduction steps, impact, and any suggested mitigation. The maintainers will acknowledge the report and provide status updates through the private advisory.

### Incident steps

1. In the admin (Kafui), click **Turn Kafui off**. Both channels stop. Messages are still stored for staff.
2. In Meta, rotate the system-user token, and the app secret if the webhook may have been forged. Enter the new values in Kafui, Settings.
3. Rotate the AI provider keys at each provider and enter them again.
4. If the server itself is compromised, replace `database/app.key`. Every stored secret must then be entered again, and admins must sign in again.
5. Review **Kafui, Action log** and the Kafui orders for the period. Cancel fake orders in the admin.
6. Restore from backup if data was changed. Tell the JayFoods owner what happened.
7. Write a short root-cause note here and add a test to `tests/agent/run.php` that would have caught it.
