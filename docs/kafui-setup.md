# Connecting Kafui to the JayFoods WhatsApp number

Kafui answers the JayFoods WhatsApp number and the chat on the website. This is the list of steps to put it live, in order. It assumes the number is already added and connected in WhatsApp Manager, with its profile set (done on 23 Oct 2026).

Keep every token and secret out of chats, email and the repository. Type them only into the admin (Kafui, Settings), where they are stored encrypted and never shown again.

## 1. Put the code on the live site

1. Merge the Kafui pull request, then deploy `main` to the cPanel site as you deploy the rest of JayFoods (DEC-006 is still open: cPanel Git pull of `main`, or upload of a tagged release from the `release` workflow).
2. Run the migration once on the server: `php database/migrate.php` (or `python server.py` locally). It adds Kafui's tables and the order columns, and is safe to run again.
3. Add a second cron job next to the stock one, for the daily clean-up (chats deleted after 90 days, invoice files after 30):

   ```cron
   0 3 * * * /usr/local/bin/php /home/spribvtm/public_html/jayfoods/database/kafui-cleanup.php >/dev/null 2>&1
   ```

4. Check that `https://<live domain>/api/v1/whatsapp/webhook` answers. A plain GET should return `403`, which is correct because the verify token is missing.
5. Make sure PHP on the host has `curl`, `openssl`, `pdo_sqlite`, `mbstring` and `iconv` (cPanel, Select PHP Version).

## 2. Meta: the app and the token

Done in Meta, signed in as the JayFoods Business portfolio admin.

1. **App.** At developers.facebook.com, open My Apps, then Create app, type Business, and link it to the JayFoods Business portfolio. Add the **WhatsApp** product. When asked, choose the existing WhatsApp Business account that holds the JayFoods number.
2. **Privacy policy and Live mode.** In App settings, Basic, set the privacy policy URL to `https://<live domain>/privacy.html`. Then switch the app from Development to **Live**. Messages from real customers only reach the webhook when the app is Live.
3. **App secret.** In App settings, Basic, click Show next to App secret. You will paste it into the admin in step 3.
4. **System user and permanent token.**
   - In business.facebook.com, open Settings, Users, System users, Add. Name it `kafui`, role Employee.
   - Assign assets: the app (Develop app) and the JayFoods WhatsApp account (Manage WhatsApp Business account, partial access is enough).
   - Click Generate new token, choose the app, set expiry to **Never**, and tick only `whatsapp_business_messaging` and `whatsapp_business_management`. Nothing else.
   - Copy the token straight into the admin (step 3).
5. **Phone number ID and API version.** In the app, open WhatsApp, API Setup. Under "From", pick the JayFoods number and copy its **Phone number ID** (digits, not the phone number). The sample request on the same page shows the Graph API version, for example `v23.0`. Use that version.
6. **Number status.** In WhatsApp Manager, Phone numbers, the JayFoods number must show **Connected**. If it shows Pending, finish registration there (it asks for a 6-digit PIN; keep the PIN somewhere safe, it is needed to move the number later).

## 3. The admin: Kafui, Settings

Sign in to the JayFoods admin and open **Kafui**, then **Settings**.

1. **Live site address:** `https://<live domain>`, with no trailing slash.
2. **WhatsApp:** the Phone number ID, the Graph API version, the permanent token and the app secret.
3. Click **Generate new token** under "Verify token for Meta" and copy it. It is shown once.
4. **AI providers** (DEC-003, CHG-001). Kafui uses the same chain as Lisa: **Anthropic, then OpenAI, then OpenRouter, then Groq**. For each provider you turn on, enter its API key, a **main model** and, optionally, a **backup model**. Model names come from each provider's own site.
   - **How the fallback works.** If the main model fails or takes too long (20 seconds, or 30 for OpenRouter), Kafui tries that provider's backup model, then the next provider. If every one fails, the customer is told a person will reply, and the chat goes to staff.
   - **No double orders.** A provider that already placed an order or handed the chat over is never retried, so a failure cannot cause a second order.
   - Turn on at least two providers for a real fallback.
   - Set a **monthly spend limit** at each provider's billing page before going live.
   - Where the provider offers it, turn off training on API data.
5. **Owner numbers:** the owner's WhatsApp number with country code, for example `233XXXXXXXXX`. Up to three. Only these numbers get sales summaries, order and booking lists and invoices, and only on WhatsApp.
6. **Owner email** and **alert email:** where invoices, owner-request notices and warnings go. Blank uses the SMTP notification email, so check Settings, Gmail SMTP works first (Send test email).
7. **Orders:** leave pickup off unless JayFoods confirms a pickup place and times. Check the payment sentence (placeholder, see below).
8. Save.

## 4. Meta: the webhook

1. In the app, open WhatsApp, Configuration, then Webhook, Edit.
2. **Callback URL:** the address shown in the admin under "Webhook URL for Meta" (`https://<live domain>/api/v1/whatsapp/webhook`).
3. **Verify token:** the one you generated in step 3.3. Click **Verify and save**. If Meta says it could not validate, check the live site address and that the migration ran.
4. Under Webhook fields, **Subscribe** to `messages`. Leave the others off.
5. If test messages still do not arrive, subscribe the app to the WhatsApp account once (replace the two values):

   ```bash
   curl -X POST "https://graph.facebook.com/v23.0/<WHATSAPP_BUSINESS_ACCOUNT_ID>/subscribed_apps" -H "Authorization: Bearer <SYSTEM_USER_TOKEN>"
   ```

## 5. Test, then turn Kafui on

1. In the admin, **Kafui, Try Kafui**: chat as a customer (ask for prices, order 30 bottles for an office, book an event) and as the owner (ask for this week's sales, an invoice for an order). Nothing is saved or sent from this screen.
2. Click **Turn Kafui on**. It refuses until steps 3 and 4 are complete and lists what is missing.
3. From your own phone, message the JayFoods number: "Hi, how much is orange juice?". The reply should arrive within a few seconds, and the chat appears under Kafui, Inbox.
4. Ask for a person ("Can I talk to a person?"). Kafui hands over and goes quiet. Reply from the inbox, then click **Hand back to Kafui**.
5. Place a small test order, find it under Orders (marked Kafui), and cancel it.
6. From the owner's number, ask for an invoice for that order. The PDF should arrive in WhatsApp and by email.
7. Open the website, click **Chat with Kafui** and repeat a question.

## 6. Go-live checks (baseline GATE-003)

- [ ] The admin password on the live site is not the old first-run password (it used to be published in this public repository). Change it under Settings if in doubt.
- [ ] `main` is protected on GitHub: pull request required, and the `kafui-checks` jobs (`php-lint`, `agent-tests`, `secret-scan`, `baseline-digest`) are required checks. Turn on "Require review from Code Owners".
- [ ] The repository variable `APPROVED_BASELINE_DIGEST` is set to `bf6e6f806b72a527ee1b64355c04b06a80cb50d83a4f370e64bd2bff45c451eb`, the approved baseline 0.2.0 (Settings, Secrets and variables, Actions, Variables). After any later approved change, update it to the new `record_digest`.
- [ ] The Meta token has only the two WhatsApp permissions, on this one account.
- [ ] Every AI provider has a spend limit.
- [ ] The privacy page shows the "WhatsApp and website chat with Kafui" section.
- [ ] A database backup has been downloaded (Backups) and restored once on a copy, with Kafui orders in it.
- [ ] The test script in step 5 passed on the live number.

## Rotating secrets (incident steps in SECURITY.md)

- **Meta token:** generate a new token for the `kafui` system user, paste it into the admin, then revoke the old one.
- **App secret:** App settings, Basic, Reset. Paste the new one into the admin straight away, because webhooks fail until you do.
- **Verify token:** generate a new one in the admin and enter it in Meta, Configuration.
- **AI keys:** create a new key at the provider, paste it, delete the old one.
- **App key** (`database/app.key`, server only): replacing it signs everyone out and makes every stored secret unreadable. Enter all secrets again afterwards, including Paystack and SMTP.

## Testing locally without a phone

- `php tests/agent/run.php` runs the 80 tests with a fake model and a fake WhatsApp. Nothing leaves the machine.
- With the site running (`python server.py`), `php tests/agent/send-test-webhook.php --url=http://localhost:8010 --secret=<app secret saved in the admin> --from=233XXXXXXXXX --text="Hi"` posts a message signed exactly as Meta signs it. Add `--bad-signature` to see it rejected.

## Placeholders to confirm with JayFoods

Kafui only says what the admin holds, so these are edited there, not in code:

- **Prices, flavours, sizes and bulk tiers** (Products). The current values are the site's starting values: Orange, Watermelon and Orapine at GHS 20 and GHS 22 for 500ml, Detox Delight at GHS 28 for 350ml, and bulk prices from 24 bottles. The Meta profile also mentions mango and mixed fruit, which are not on the menu.
- **Delivery areas and fees** (Delivery zones): Greater Accra GHS 20, Tema GHS 25, Other GHS 40.
- **Opening hours and contact details** (Site content): Monday to Sunday, 8:30am to 6:00pm.
- **Payment sentence** (Kafui, Settings): "Our team will confirm your order and tell you how to pay (Mobile Money or cash on delivery)." This is DEC-005; the wording is a draft by PrinceCaleb.dev.
- **Pickup:** off until a place and times are confirmed.
- **Kafui's greeting and fixed messages** (in `src/Agent/Kafui.php` and `ChatController.php`) are drafts by PrinceCaleb.dev for JayFoods to approve.
