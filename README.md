# Jay foods

Jay foods is a lightweight juice catalogue, ordering, payment, and administration website built for a Ghanaian fruit-juice business.

## Features

- Responsive public storefront and individual product pages
- Multiple bottle sizes with size-specific pricing and stock
- Retail cart with delivery details and Paystack checkout
- Private customer order tracking using an order reference and phone number
- Bulk-order enquiries through prefilled WhatsApp messages
- Server-side price, stock, currency, and payment verification
- Paystack callback and signed webhook handling
- Customer and administrator email notifications through Gmail SMTP
- Admin dashboard for products, orders, messages, payments, and account settings
- Admin order search, status/payment filters, and CSV export
- Print-ready invoices with customer, payment, delivery, item, discount, and total details
- Private administrator notes for delivery and payment follow-ups on each order
- Timestamped order-status history for administrators and customer tracking
- Customer directory with order counts, paid lifetime value, calling, and WhatsApp actions
- Authenticated timestamped SQLite database backups from the admin area
- Multi-admin account management with password resets and active/inactive access controls
- Protected administrator activity log for successful data-changing actions
- Server-enforced store open/closed control with an editable customer message
- Consolidated low-stock email alerts with configurable thresholds and recovery detection
- Sales analytics for daily, 7-day and 30-day revenue, average orders, and best sellers
- Server-validated percentage and fixed-amount promotional codes with usage limits
- Product image uploads and bulk-availability controls
- Searchable product inventory with low-stock, out-of-stock, active, and hidden filters
- Privacy, cookie, and terms-of-use pages

## Kafui, the ordering agent

Kafui (said kah-FOO-ee) is the AI assistant on the JayFoods WhatsApp number and in the live chat on this site: one agent with the same tools on both channels, set up like Lisa on princecaleb.dev.

- Answers customers, takes orders and event bookings, and prices them only from the admin catalogue, bulk prices and delivery zones.
- Records orders as pending and unpaid, marked "Kafui", for staff to confirm.
- Hands a chat to staff when asked or when unsure. Staff reply from **Kafui, Inbox** in the admin.
- Recognises the owner's WhatsApp number, and gives the owner sales summaries, order and booking lists, and invoice PDFs.
- Runs on Lisa's provider chain (Anthropic, OpenAI, OpenRouter, Groq), with a backup model per provider and a fixed "a person will reply" message if all of them fail.

Where things are:

- [docs/kafui-setup.md](docs/kafui-setup.md): every step to connect the number in Meta and go live, plus what is still a placeholder.
- Code: `src/Agent/` (engine, tools, prompt, providers, WhatsApp sender, invoices), `src/Controllers/WhatsAppWebhookController.php`, `ChatController.php`, `AdminAgentController.php`, `public/js/kafui-chat.js`, `public/admin/kafui.js`.
- Tests: `php tests/agent/run.php` (fake model and fake WhatsApp, no network). `php tests/agent/send-test-webhook.php` posts a signed test message to a running site.
- Security: [SECURITY.md](SECURITY.md) and [security-baseline.yaml](security-baseline.yaml). Design: [ARCHITECTURE.md](ARCHITECTURE.md) and [GUI.md](GUI.md).
- Daily clean-up cron: `database/kafui-cleanup.php` (chats deleted after 90 days, invoices after 30).

## Technology

- PHP 8.1+
- SQLite with PDO
- Vanilla HTML, CSS, and JavaScript
- Python development-server launcher
- Paystack transaction API
- Gmail SMTP

The application intentionally uses no PHP framework, Composer packages, Node dependencies, or frontend build tooling.

## Local setup

### Requirements

- Python 3
- PHP 8.1 or newer with `pdo_sqlite` and OpenSSL enabled

### Start the application

```bash
python server.py
```

The launcher applies database migrations and starts the website at:

```text
http://localhost:8010
```

The administration area is available at:

```text
http://localhost:8010/admin/login.html
```

On the first run, the migration prints the seeded administrator email and a random password, once. Sign in and change the password from the admin Settings page.

## Configuration

Use the protected admin Settings page to configure:

- Administrator name, email, and password
- Gmail SMTP address, App Password, and notification recipient
- Paystack public key, secret key, and webhook URL

Set the Paystack webhook in the Paystack dashboard to:

```text
https://your-domain.example/api/v1/payments/webhook
```

Use Paystack test keys until the complete checkout and webhook flow has been tested in the deployment environment.

## Stock reservations

Checkout reserves each selected bottle size for 30 minutes. Successful Paystack payments permanently commit the stock; cancelled or expired unpaid orders return it automatically.

On production hosting, add this cPanel cron job to release expired reservations even when the storefront has no visitors:

```cron
*/10 * * * * /usr/local/bin/php /home/spribvtm/public_html/jayfoods/database/release-expired-stock.php >/dev/null 2>&1
```

If cPanel shows a different PHP command, replace `/usr/local/bin/php` with the PHP executable shown by the hosting account.

## Project structure

```text
database/       SQLite schema and idempotent migrations
public/         Public pages, assets, admin SPA, and API front controller
src/            Controllers, router, authentication, email, and payment support
server.py       Migration and local-server entry point
```

## Security notes

- Paystack and SMTP secret keys are encrypted before database storage.
- Paystack secret keys are never exposed to browser code.
- Payment success is verified server-side for status, GHS currency, and exact amount.
- Webhook requests are authenticated with Paystack's HMAC-SHA512 signature.
- Admin authentication uses an HttpOnly JWT cookie.
- Runtime databases, application keys, and uploaded files are excluded from Git.

## License

All rights reserved. This repository contains proprietary Jay foods project code and brand assets unless otherwise stated.
