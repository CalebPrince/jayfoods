# GUI: interface handoff for Kafui

Status: built on branch `claude/whatsapp-agent`; checked in a browser against a local throwaway copy (23 Oct 2026). Not on the live site yet.
Owner: Prince Caleb (PrinceCaleb.dev)
Last reviewed: 2026-10-23
Applies to: the website chat widget on the JayFoods storefront, and the new Kafui section of the admin control center. WhatsApp itself has no interface of ours; its text rules are in "Message copy".

## Experience intent

- **Customers** (phone first, often on mobile data) want a quick, friendly answer, real prices and an order reference without filling a form.
- **Staff** want every chat in one place, to see what Kafui did, to step in, and to switch Kafui off.
- **Tone:** warm, short, plain English. Greetings or phrases in Twi or Pidgin only where the owner approves them (asked for in the client's business brief). No em dashes in any customer-facing text.
- **Non-goals:** payment in the widget, file uploads, voice, customer accounts.

## Capability matrix

| Capability | Route/surface | Roles | Backend contract | Status | States covered | Evidence | Limitation/next step |
|---|---|---|---|---|---|---|---|
| Chat with Kafui on the website | Floating button and panel on index, order, cart and track pages | Visitor | `POST /api/v1/chat/session`, `POST /api/v1/chat/message`, `GET /api/v1/chat/history` | PARTIAL | closed, open, sending, typing, reply, error, Kafui off, handed off, staff reply | Browser check (EVD-007) | Real AI replies not seen yet: no provider key in the local copy |
| Continue on WhatsApp | Link in the chat panel header | Visitor | `whatsapp_number` from site content | WORKING | default | Browser check | |
| Kafui settings | `#/kafui/settings` | Admin | `GET/PUT /api/v1/admin/agent/settings` | WORKING | loading, saved, validation error, secret saved/not saved | Browser check: saved, owner number normalised, secrets never returned | Locally only |
| Owner numbers | `#/kafui/settings` (section) | Admin | same as above | WORKING | empty, list, invalid number | Browser check, tests | Real numbers from Caleb |
| Kill switch | Header of every Kafui screen | Admin | `PUT /api/v1/admin/agent/enabled` | WORKING | on, off, confirm, refused until setup is complete | Tests, browser | |
| Conversations inbox | `#/kafui` | Admin | `GET /api/v1/admin/agent/conversations` | WORKING | loading, empty, list, unread, filter, needs a person | Browser check | |
| Conversation detail and reply | `#/kafui/c/{id}` | Admin | `GET .../conversations/{id}`, `POST .../reply`, `POST .../take-over`, `POST .../hand-back` | PARTIAL | thread, sending, failed, 24-hour window closed, staff mode, Kafui mode | Browser: website reply reached the widget; WhatsApp reply refused while not connected | WhatsApp send not seen with a real token |
| Agent action log | `#/kafui/log` | Admin | `GET /api/v1/admin/agent/actions` | WORKING | empty, list | Browser check | |
| Try Kafui (test chat) | `#/kafui/test` | Admin | `POST /api/v1/admin/agent/test` | PARTIAL | chat as customer or owner, tools used, error | Code and tests | Needs a provider key to see real replies. Orders and bookings made here are not saved and nothing is sent |
| WhatsApp templates | `#/kafui/templates` | Admin | `GET .../agent/templates`, `POST .../templates/{key}/submit`, `POST .../templates/{key}/refresh`, `POST .../templates/refresh-all`, `DELETE .../templates/{key}` | PARTIAL | not submitted, pending, approved, rejected with WhatsApp's reason, paused, disabled, working, error | Tests with a fake Twilio | Not yet seen against the real Twilio account |
| Send a template from a chat | `#/kafui/c/{id}`, under the reply box | Admin | `POST .../conversations/{id}/template` | PARTIAL | none approved, fill-in fields, live preview, sending, refused | Tests | Only approved templates are offered |
| Agent orders flagged | Existing `#/orders` | Admin | existing orders API plus `source` | WORKING | Kafui badge, taken-by and wanted-on rows | Code | |
| Bookings list | `#/kafui/templates` | Submit WhatsApp templates and follow their approval | Kafui sub-nav, after Settings | Admin | submit, check status, check all, delete and rebuild | templates with status | Cards | Template wording is fixed in code, not edited here |
| `#/kafui/bookings` | Admin | `GET/PATCH /api/v1/admin/bookings` | WORKING | empty, list, confirm, decline, done, staff note | Browser check | |

## Route and screen inventory

| Route/screen | Purpose | Entry | Roles | Primary actions | Data | Responsive and accessibility | Exclusions |
|---|---|---|---|---|---|---|---|
| Chat widget | Ask, order, book | Button bottom right, "Chat with Kafui" | Visitor | type, send, open WhatsApp | messages | Full screen under 480px; focus moves into the panel on open and back to the button on close; Esc closes; new replies announced politely to screen readers | No uploads, no emoji pickers |
| `#/kafui` inbox | See all chats | New nav item "Kafui" with unread badge | Admin | open, filter (needs a person, WhatsApp, website) | conversation list | Table becomes cards under 720px | No delete from the list |
| `#/kafui/c/{id}` | Read a chat, reply, take over, hand back | Inbox row | Admin | reply, take over, hand back | thread, linked orders and bookings | Reply box stays visible; send by button or Ctrl+Enter | No editing of past messages |
| `#/kafui/settings` | Connect Twilio (or Meta) and AI providers, owner numbers, limits | Kafui sub-nav | Admin | save, generate verify token, copy webhook URL | settings with has_* flags | Single column on mobile | Secrets never displayed |
| `#/kafui/log` | Audit what Kafui did | Kafui sub-nav | Admin | filter by tool and role | action rows | Horizontal scroll inside the table only | No export in this change |
| `#/kafui/test` | Try Kafui safely | Kafui sub-nav | Admin | chat as a customer or as the owner | test conversation | As the widget | Never sends WhatsApp messages |
| `#/kafui/bookings` | Confirm or decline bookings | Kafui sub-nav | Admin | confirm, decline, add note | bookings | Cards under 720px | |

Unauthorized behaviour: every admin route already sends a signed-out admin to the login page. The widget has no signed-in state. Its session expires after 24 hours and a new one starts on its own.

## Backend contracts consumed by the UI

| Operation | Request | Success | Expected errors | UI behaviour |
|---|---|---|---|---|
| Start chat | none | `{session, greeting}` | 429, 503 (Kafui off) | Off: show "Kafui is resting. Chat with us on WhatsApp" with the link |
| Send message | `{session, text <= 1000}` | `{reply, handed_off}` | 400 too long, 401 bad session (start a new one), 429 limit, 503 off | Keep the typed text on error; show a retry button |
| Inbox | filter, page | `{items, next}` | 401 | Login redirect |
| Reply | `{text}` | `{sent}` | 409 window closed, 502 Twilio or Meta refused the message | Window closed: explain the 24-hour rule and offer to call the customer |
| Settings save | fields; blank secret keeps the old one | settings with has_* flags | 422 field errors | Show errors next to fields |

The server is authoritative for all validation, roles and prices.

## UI-relevant data models

| Field | Type | Classification | Display rule |
|---|---|---|---|
| Message.text | plain text | CONFIDENTIAL | Always rendered with textContent; line breaks kept |
| Message.sender | customer, kafui, staff, system | INTERNAL | Labels: customer name or number, "Kafui", staff name, "Note" |
| Conversation.mode | kafui, staff | INTERNAL | Badge "Kafui is answering" or "Staff is answering" |
| Conversation.phone | E.164 | CONFIDENTIAL | Full in the admin only; never in the widget |
| Settings secrets | write-only | RESTRICTED | "Saved" or "Not set"; blank keeps the saved value |

## Message copy (WhatsApp and widget)

Kafui's first message (draft by PrinceCaleb.dev, owner to approve): "Hi, I'm Kafui, the Jay foods customer assistant. I can help you order fresh juice, book for an event or check an order. Want a person instead? Just say so."

Rules: short messages, no em dashes, prices only as the tools give them, always "pending until our team confirms" on orders. The name is said kah-FOO-ee, stress on FOO. If Kafui ever gets a voice, add it to `D:\Tools\chatterbox\ghana-lexicon.json`.

## Design and interface tokens

The widget follows the storefront, and the admin screens follow the existing admin. These tokens are taken from `public/index.html` and `public/admin/admin.css`. They are the site's own brand, not new choices.

```yaml
color:
  brand: "#1f7a3d"        # --green, buttons and Kafui's bubble edge
  brand_dark: "#12351f"   # --green-dk, panel header
  brand_light: "#eafaef"  # --green-lt, Kafui's bubbles
  accent: "#f5871f"       # --orange, the chat button and focus ring
  accent_dark: "#d96f0c"
  highlight: "#ffc233"    # --mango, unread badge
  canvas: "#fff8ec"       # --cream, panel background
  text: "#16241b"         # --ink
  text_muted: "#5f7166"
  border: "#e6ede8"
  danger: "#c0392b"
typography:
  family: "system-ui, -apple-system, Segoe UI, Roboto, sans-serif"   # as the site
  size: { sm: "0.875rem", md: "1rem", lg: "1.125rem" }
radius: { bubble: "18px", panel: "18px", button: "999px" }
elevation: { panel: "0 18px 40px rgba(18, 53, 31, .10)" }
motion: { open: "200ms ease-out", typing_dots: "1.2s loop, off under prefers-reduced-motion" }
focus: { ring: "0 0 0 3px #f5871f" }
control: { min_target: "44px" }
```

The chat button is a round orange button with a speech-bubble SVG icon and the label "Chat with Kafui" (visible on wide screens, aria-label on narrow ones). No emoji icons. Status is never shown by colour alone: the mode badges carry words.

## Responsive and accessibility requirements

- Usable from 320px wide; the panel goes full screen under 480px.
- Keyboard: Tab order is header, thread, input, send. Esc closes. Focus returns to the button.
- The thread is a log region (`role="log"`, `aria-live="polite"`).
- Contrast at least 4.5:1 for text.
- Typing dots respect reduced motion.
- Target: WCAG 2.2 AA for these screens, checked by hand before go-live. No conformance is claimed until then.

## Analytics, audit and privacy

No analytics scripts. Audit is server-side in the agent action log and the existing admin activity log. The widget stores only the signed session id in `sessionStorage`, never message text.

## Hard boundaries for implementers

Presentation and local interaction may be refined within these tokens. Do not change endpoints, validation, the role rules, how prices are computed, retention, the 24-hour rule, logging, or approval state without a change proposal (old rule, new rule, reason, impact, approver).
