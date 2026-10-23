# Connecting Kafui to the JayFoods WhatsApp number

Kafui answers the JayFoods WhatsApp number and the chat on the website. This is the list of steps to put it live, in order. WhatsApp runs through a **Twilio WhatsApp sender**, as Lisa does (CHG-002). Connecting Meta's Cloud API directly is kept as an alternative at the end.

Keep every token and secret out of chats, email and the repository. Type them only into the admin (Kafui, Settings), where they are stored encrypted and never shown again.

## 1. Put the code on the live site

1. Deploy `main` with cPanel, Git Version Control: clone `https://github.com/CalebPrince/jayfoods.git` into a folder **outside** `public_html` (done 23 Oct 2026: `/home/spribvtm/jayfoods-app`). Then, in Domains, set the document root of `jayfoods.princecaleb.dev` to that folder's `public` subfolder. Only `public/` is then reachable from the web. `public/.htaccess` sends `/api/v1/...` requests to `index.php` and adds the security headers. If the document root can only be the project folder, the root `.htaccess` still serves `public/` and refuses `database/`, `src/` and the other private folders. To update later, use Pull or Deploy on the same Git repository page.
2. Run the migration once on the server: `php database/migrate.php` (or `python server.py` locally). It adds Kafui's tables and the order columns, and is safe to run again.
3. Add a second cron job next to the stock one, for the daily clean-up (chats deleted after 90 days, invoice files after 30):

   ```cron
   0 3 * * * /usr/local/bin/php /home/spribvtm/jayfoods-app/database/kafui-cleanup.php >/dev/null 2>&1
   ```

4. Check that `https://<live domain>/api/v1/whatsapp/webhook` answers. A plain GET should return `403`, which is correct because the verify token is missing.
5. Make sure PHP on the host has `curl`, `openssl`, `pdo_sqlite`, `mbstring` and `iconv` (cPanel, Select PHP Version).

## 2. Twilio: register the number as a WhatsApp sender

Kafui uses Twilio, as Lisa does (CHG-002). Twilio's sign-up screen does the Meta side, so you don't create a Meta app.

1. **Account.** Sign in to the Twilio Console (console.twilio.com). Use a separate **subaccount for JayFoods** (Account, Subaccounts, Create), so its auth token can't send from Lisa's numbers (DEC-008). The account must be upgraded from trial (add billing) to register a WhatsApp sender.
2. **Start the sender.** In the JayFoods subaccount, open Messaging, Senders, WhatsApp senders, then **Create new sender**. Enter the JayFoods number, `+233 24 632 8461`.
3. **Meta sign-up (inside Twilio).** Click **Continue with Facebook**. In Meta's window:
   - sign in;
   - choose or create the **JayFoods business** and its WhatsApp Business account;
   - set the display name (**Jay foods**);
   - verify the number with the code Meta sends by SMS or call.
   You don't need a Meta app or a developer account for this.
4. **If the number is refused as already in use.** It was added directly in WhatsApp Manager earlier, so Meta may say it's taken.
   - In WhatsApp Manager, Phone numbers, open the number's settings and **turn off two-step verification**, then try again.
   - If Twilio still refuses it, remove the number from the WhatsApp account in WhatsApp Manager and register it again through Twilio. The business profile text has to be entered again afterwards.
5. **Business verification.** Meta may limit how many new customers the number can message each day, and review the display name, until JayFoods' business is verified (Business settings, Security centre). Customer-started chats, which is all Kafui does, still work meanwhile. Verify once the business registration documents are ready.
6. **Wait for "Online".** The sender shows **Online** in the Twilio Console when Meta has approved it. This can take from minutes to a day.
7. **Keys for the admin.** On the subaccount's Console home, under Account info, copy the **Account SID** (starts with `AC`) and the **Auth token**.

## 3. The admin: Kafui, Settings

Sign in to the JayFoods admin and open **Kafui**, then **Settings**.

1. **Live site address:** `https://<live domain>`, with no trailing slash.
2. **WhatsApp:** choose **Twilio**, then enter the Account SID, the auth token and the WhatsApp sender number (`233246328461`).
3. Note the **Webhook URL for Twilio** shown under the WhatsApp fields; it goes into Twilio in step 4.
4. **AI providers** (DEC-003, CHG-001). Kafui uses the same chain as Lisa: **Anthropic, then OpenAI, then OpenRouter, then Groq**. For each provider you turn on, enter its API key, a **main model** and, optionally, a **backup model**. Model names come from each provider's own site.
   - **How the fallback works.** If the main model fails or takes too long (20 seconds, or 30 for OpenRouter), Kafui tries that provider's backup model, then the next provider. If every one fails, the customer is told a person will reply, and the chat goes to staff.
   - **No double orders.** A provider that already placed an order or handed the chat over is never retried, so a failure cannot cause a second order.
   - Turn on at least two providers for a real fallback.
   - Set a **monthly spend limit** at each provider's billing page before going live.
   - Where the provider offers it, turn off training on API data.
5. **Owner numbers:** the owner's WhatsApp number with country code, for example `233XXXXXXXXX`. Up to five. If an owner's WhatsApp hides their number, Kafui, Inbox shows a "WhatsApp ID" for them instead: add that ID here. Only these numbers get sales summaries, order and booking lists and invoices, and only on WhatsApp.
6. **Invoices** use the contact details in admin, **Site content**: headquarters name, address, phone, WhatsApp number and **Public email address**, plus the Live site address. Fill them in before sending invoices.
7. **Owner email** and **alert email:** where invoices, owner-request notices and warnings go. Blank uses the SMTP notification email, so check Settings, Email sending (SMTP) works first (Send test email).
8. **Orders:** leave pickup off unless JayFoods confirms a pickup place and times. Check the payment sentence (placeholder, see below).
8. Save.

## 4. Twilio: point the sender at Kafui

1. In the Twilio Console, open Messaging, Senders, WhatsApp senders, and click the JayFoods number.
2. Under the messaging endpoint settings, set **Webhook URL for incoming messages** to the address shown in the admin under "Webhook URL for Twilio": `https://jayfoods.princecaleb.dev/api/v1/whatsapp/twilio`, method **POST**. Leave the status callback empty.
3. Save. The URL must match the admin's **Live site address** exactly (same `https`, no `www`, no trailing slash), because Twilio signs it. If messages are rejected, the admin's Kafui counters show rejected webhooks and the server error log names the URL it checked.

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
- [ ] The repository variable `APPROVED_BASELINE_DIGEST` is set to the `record_digest` of the approved baseline in `security-baseline.yaml` (Settings, Secrets and variables, Actions, Variables). After any later approved change, update it to the new `record_digest`.
- [ ] The Twilio sender is Online, in a JayFoods-only subaccount, and the auth token in the admin is that subaccount's.
- [ ] Every AI provider has a spend limit.
- [ ] The privacy page shows the "WhatsApp and website chat with Kafui" section.
- [ ] A database backup has been downloaded (Backups) and restored once on a copy, with Kafui orders in it.
- [ ] The test script in step 5 passed on the live number.

## WhatsApp templates (Kafui, Templates)

WhatsApp only lets Jay foods send free text within 24 hours of a person's last message. After that, only a template WhatsApp has approved goes through. Kafui, Templates lists twelve, written for a juice business:

- **Owner alert**: tells the owner numbers about a handover, a new order or a booking. Until it is approved, owners get plain text, which only arrives within 24 hours of their own last message to Kafui. Submit this one first.
- **Order confirmed**: sent to the customer when staff set a Kafui WhatsApp order to Confirmed. Within the 24 hours it goes as plain text; after that it needs this template, or the chat notes that nothing was sent.
- **Payment reminder, Payment received, Out for delivery, Ready for pickup, Invoice ready, Booking confirmed, Bulk quote**: staff send these from a chat (Send a template, under the reply box).
- **Feedback request, Re-order reminder, Flavour back in stock**: marketing templates. WhatsApp charges more for these and they may only go to customers who agreed to hear from Jay foods.

Steps: Twilio must be the WhatsApp provider with the Account SID, auth token and sender number saved, and the Live site address set (two templates have buttons that link to it). Press **Submit to WhatsApp** on each template. It shows as Pending; press **Check status** (or Check all statuses) later. If WhatsApp rejects one, its reason shows: press **Delete and rebuild**, ask PrinceCaleb.dev to change the wording in `src/Agent/WhatsAppTemplates.php`, then submit again. If the site moves domain, delete and resubmit the two templates with buttons.

## Moving to the permanent domain

The site runs on `jayfoods.princecaleb.dev` for now. When it moves to its own domain:

1. **cPanel, Domains:** add the new domain with the same document root, `jayfoods-app/public`. The code, database and cron jobs stay as they are.
2. **Admin, Kafui, Settings:** set **Live site address** to `https://<new domain>`. Invoice links are built from it.
3. **Twilio:** change the sender's Webhook URL for incoming messages to `https://<new domain>/api/v1/whatsapp/twilio`. It must match the new Live site address exactly.
4. **Paystack dashboard:** change the webhook URL to `https://<new domain>/api/v1/payments/webhook`, and save the same URL in admin, Settings, Paystack.
5. **Old address:** redirect `jayfoods.princecaleb.dev` to the new domain (cPanel, Domains, Redirects) so old links keep working.
6. **Everywhere the web address is shown:** the WhatsApp business profile, social profiles, and printed or video material.

**Email does not change** unless you also move the mailbox. The SMTP settings belong to the mailbox, not the website. To send from an address on the new domain:
- Create the mailbox in cPanel, Email Accounts.
- Run cPanel, Email Deliverability, for the new domain (SPF and DKIM).
- Enter the new address, its password and `mail.<new domain>` in admin, Settings, Email sending (SMTP), then Send test email.

## Alternative: Meta Cloud API directly

Only if JayFoods later moves off Twilio. It needs a Meta developer app, which Meta allows once the business is registered. In Kafui, Settings, choose **Meta Cloud API directly**; only one provider receives messages at a time.

### Meta: the app and the token

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

### Meta: the webhook

1. In the app, open WhatsApp, Configuration, then Webhook, Edit.
2. **Callback URL:** the address shown in the admin under "Webhook URL for Meta" (`https://<live domain>/api/v1/whatsapp/webhook`).
3. **Verify token:** the one you generated in step 3.3. Click **Verify and save**. If Meta says it could not validate, check the live site address and that the migration ran.
4. Under Webhook fields, **Subscribe** to `messages`. Leave the others off.
5. If test messages still do not arrive, subscribe the app to the WhatsApp account once (replace the two values):

   ```bash
   curl -X POST "https://graph.facebook.com/v23.0/<WHATSAPP_BUSINESS_ACCOUNT_ID>/subscribed_apps" -H "Authorization: Bearer <SYSTEM_USER_TOKEN>"
   ```

## Rotating secrets (incident steps in SECURITY.md)

- **Twilio auth token:** in the JayFoods subaccount, Account info, request a secondary token, paste it into the admin, then promote it to primary (this retires the old one). Twilio signs webhooks with the primary token, so do both steps together.
- **Meta token** (only if Meta is the chosen provider): generate a new token for the `kafui` system user, paste it into the admin, then revoke the old one.
- **App secret:** App settings, Basic, Reset. Paste the new one into the admin straight away, because webhooks fail until you do.
- **Verify token:** generate a new one in the admin and enter it in Meta, Configuration.
- **AI keys:** create a new key at the provider, paste it, delete the old one.
- **App key** (`database/app.key`, server only): replacing it signs everyone out and makes every stored secret unreadable. Enter all secrets again afterwards, including Paystack and SMTP.

## Testing locally without a phone

- `php tests/agent/run.php` runs the 80 tests with a fake model and a fake WhatsApp. Nothing leaves the machine.
- `php tests/agent/send-test-webhook.php --url=<Live site address> --secret=<Twilio auth token> --from=233XXXXXXXXX --to=233246328461 --text="Hi"` posts a message signed exactly as Twilio signs it, to the running site. Add `--bad-signature` to see it rejected (403). `--provider=meta` does the same for the Meta webhook.

## Placeholders to confirm with JayFoods

Kafui only says what the admin holds, so these are edited there, not in code:

- **Prices, flavours, sizes and bulk tiers** (Products). The current values are the site's starting values: Orange, Watermelon and Orapine at GHS 20 and GHS 22 for 500ml, Detox Delight at GHS 28 for 350ml, and bulk prices from 24 bottles. The Meta profile also mentions mango and mixed fruit, which are not on the menu.
- **Delivery areas and fees** (Delivery zones): Greater Accra GHS 20, Tema GHS 25, Other GHS 40.
- **Opening hours and contact details** (Site content): Monday to Sunday, 8:30am to 6:00pm.
- **Payment sentence** (Kafui, Settings): "Our team will confirm your order and tell you how to pay (Mobile Money or cash on delivery)." This is DEC-005; the wording is a draft by PrinceCaleb.dev.
- **Pickup:** off until a place and times are confirmed.
- **Kafui's greeting and fixed messages** (in `src/Agent/Kafui.php` and `ChatController.php`) are drafts by PrinceCaleb.dev for JayFoods to approve.
