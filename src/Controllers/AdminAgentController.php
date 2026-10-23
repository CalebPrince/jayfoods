<?php

declare(strict_types=1);

/**
 * Admin side of Kafui (all routes sit behind Auth::requireAdmin() in index.php).
 * State-changing routes also refuse cross-site requests (CTL-SES-001).
 */
final class AdminAgentController
{
    private PDO $db;

    public function __construct() { $this->db = Database::connection(); }

    // ------------------------------------------------------------------ settings

    public function settings(): void
    {
        $plain = [];
        foreach (array_keys(AgentSettings::PLAIN) as $k) $plain[$k] = AgentSettings::get($k);
        $has = [];
        foreach (AgentSettings::SECRETS as $k) $has[$k] = AgentSettings::hasSecret($k);
        Response::json(['data' => [
            'settings' => $plain,
            'has' => $has,
            'providers' => AgentSettings::PROVIDERS,
            'owner_numbers' => AgentSettings::ownerNumbers(),
            'webhook_path' => '/api/v1/whatsapp/webhook',
            'twilio_webhook_path' => '/api/v1/whatsapp/twilio',
            'smtp_notification_email' => (new SmtpMailer())->notificationEmail(),
        ]]);
    }

    public function saveSettings(): void
    {
        if (!self::guard()) return;
        $in = self::input();
        $s = is_array($in['settings'] ?? null) ? $in['settings'] : [];
        $errors = [];
        $siteUrl = rtrim(trim((string) ($s['site_url'] ?? '')), '/');
        if ($siteUrl !== '' && (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !str_starts_with($siteUrl, 'https://'))) $errors['site_url'] = 'Use the live https:// address, for example https://jayfoods.com.gh';
        $provider = (string) ($s['whatsapp_provider'] ?? AgentSettings::get('whatsapp_provider'));
        if (!in_array($provider, ['twilio', 'meta'], true)) $errors['whatsapp_provider'] = 'Choose Twilio or Meta.';
        $twilioSid = trim((string) ($s['twilio_account_sid'] ?? ''));
        if ($twilioSid !== '' && !preg_match('/^AC[0-9a-fA-F]{32}$/', $twilioSid)) $errors['twilio_account_sid'] = 'The Account SID starts with AC followed by 32 letters and digits (Twilio Console, Account info).';
        $twilioNumber = KafuiText::digits((string) ($s['twilio_whatsapp_number'] ?? ''));
        if ($twilioNumber !== '' && str_starts_with($twilioNumber, '0') && strlen($twilioNumber) === 10) $twilioNumber = '233' . substr($twilioNumber, 1);
        if ($twilioNumber !== '' && !preg_match('/^[1-9]\d{9,14}$/', $twilioNumber)) $errors['twilio_whatsapp_number'] = 'Use the WhatsApp sender number with country code, for example 233246328461.';
        if (array_key_exists('twilio_whatsapp_number', $s)) $s['twilio_whatsapp_number'] = $twilioNumber;
        $phoneId = trim((string) ($s['wa_phone_number_id'] ?? ''));
        if ($phoneId !== '' && !ctype_digit($phoneId)) $errors['wa_phone_number_id'] = 'The phone number ID is digits only (from WhatsApp Manager, API setup).';
        $version = trim((string) ($s['wa_graph_version'] ?? ''));
        if ($version !== '' && !preg_match('/^v\d{1,3}\.\d$/', $version)) $errors['wa_graph_version'] = 'Use the form v23.0, as shown in your Meta app.';
        foreach (['owner_email', 'alert_email'] as $k) {
            $v = trim((string) ($s[$k] ?? ''));
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[$k] = 'Enter a valid email address or leave it blank.';
        }
        foreach (array_keys(AgentSettings::PROVIDERS) as $p) {
            foreach (['_model', '_fallback_model'] as $suffix) {
                $model = trim((string) ($s[$p . $suffix] ?? ''));
                if ($model !== '' && !preg_match('/^[A-Za-z0-9._:\/-]{1,100}$/', $model)) $errors[$p . $suffix] = 'Model names use letters, digits, dots, dashes, slashes or colons.';
            }
        }
        $owners = [];
        foreach ((array) ($in['owner_numbers'] ?? []) as $o) {
            $n = KafuiText::digits((string) ($o['number'] ?? ''));
            if ($n === '') continue;
            if (str_starts_with($n, '0') && strlen($n) === 10) $n = '233' . substr($n, 1);
            if (!preg_match('/^[1-9]\d{9,14}$/', $n)) { $errors['owner_numbers'] = 'Owner numbers need the country code, for example 233246328461.'; break; }
            $owners[$n] = KafuiText::field($o['label'] ?? '', 40);
        }
        if (count($owners) > 3) $errors['owner_numbers'] = 'Add at most three owner numbers.';
        if ($errors) { Response::json(['error' => 'Please correct the highlighted fields.', 'fields' => $errors], 422); return; }

        foreach (AgentSettings::PLAIN as $k => $default) {
            if ($k === 'agent_enabled' || !array_key_exists($k, $s)) continue;
            $v = is_bool($s[$k]) ? ($s[$k] ? '1' : '0') : trim((string) $s[$k]);
            if (str_ends_with($k, '_enabled')) $v = $v === '1' ? '1' : '0';
            if ($k === 'site_url') $v = $siteUrl;
            AgentSettings::set($k, mb_substr($v, 0, 500));
        }
        $secrets = is_array($in['secrets'] ?? null) ? $in['secrets'] : [];
        foreach (AgentSettings::SECRETS as $k) {
            if ($k === 'wa_verify_token') continue; // generated by the server only
            $v = trim((string) ($secrets[$k] ?? ''));
            if ($v !== '') AgentSettings::set($k, mb_substr($v, 0, 1000)); // blank keeps the saved value
        }
        $this->db->beginTransaction();
        $this->db->exec('DELETE FROM agent_owner_numbers');
        $ins = $this->db->prepare('INSERT INTO agent_owner_numbers(number,label) VALUES(:n,:l)');
        foreach ($owners as $n => $l) $ins->execute([':n' => $n, ':l' => $l]);
        $this->db->commit();
        $this->settings();
    }

    /** Makes a new random verify token and shows it once, to paste into Meta. */
    public function newVerifyToken(): void
    {
        if (!self::guard()) return;
        $token = bin2hex(random_bytes(24));
        AgentSettings::set('wa_verify_token', $token);
        Response::json(['data' => ['verify_token' => $token]]);
    }

    public function setEnabled(): void
    {
        if (!self::guard()) return;
        $on = !empty(self::input()['enabled']);
        if ($on) {
            $missing = self::missingForLaunch();
            if ($missing) { Response::json(['error' => 'Finish the setup first: ' . implode('; ', $missing) . '.'], 422); return; }
        }
        AgentSettings::set('agent_enabled', $on ? '1' : '0');
        Response::json(['data' => ['enabled' => $on]]);
    }

    /** @return list<string> */
    private static function missingForLaunch(): array
    {
        $missing = [];
        if (!AgentSettings::providers()) $missing[] = 'turn on at least one AI provider with a key and a model';
        if (WhatsAppSender::provider() === 'twilio') {
            if (!TwilioWhatsApp::isConfigured()) $missing[] = 'add the Twilio Account SID, auth token and WhatsApp sender number';
        } else {
            if (!WhatsAppCloud::isConfigured()) $missing[] = 'add the WhatsApp phone number ID, Graph API version and access token';
            if (!AgentSettings::hasSecret('wa_app_secret')) $missing[] = 'add the Meta app secret';
            if (!AgentSettings::hasSecret('wa_verify_token')) $missing[] = 'generate the webhook verify token';
        }
        if (AgentSettings::siteUrl() === '') $missing[] = 'add the live site address';
        return $missing;
    }

    public function overview(): void
    {
        Response::json(['data' => [
            'enabled' => AgentSettings::enabled(),
            'counters' => AgentMonitor::counters(),
            'missing' => self::missingForLaunch(),
            'owner_numbers' => count(AgentSettings::ownerNumbers()),
        ]]);
    }

    // ------------------------------------------------------------------ inbox

    public function conversations(): void
    {
        $filter = (string) ($_GET['filter'] ?? '');
        $where = "channel!='test'";
        if ($filter === 'attention') $where .= ' AND needs_attention=1';
        if ($filter === 'whatsapp' || $filter === 'web') $where .= " AND channel='" . $filter . "'";
        $rows = $this->db->query("SELECT c.*, (SELECT body FROM conversation_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_body FROM conversations c WHERE $where ORDER BY needs_attention DESC, last_message_at DESC LIMIT 200")->fetchAll();
        Response::json(['data' => array_map(static fn($c) => [
            'id' => (int) $c['id'], 'channel' => $c['channel'], 'name' => $c['contact_name'], 'phone' => $c['phone'], 'mode' => $c['mode'],
            'needs_attention' => (bool) $c['needs_attention'], 'unread' => (int) $c['unread'], 'last_message_at' => $c['last_message_at'],
            'last_body' => mb_substr((string) $c['last_body'], 0, 120), 'window_open' => $c['channel'] === 'whatsapp' ? KafuiStore::windowOpen($c) : true,
        ], $rows)]);
    }

    public function conversation(int $id): void
    {
        $c = KafuiStore::get($id);
        if (!$c || $c['channel'] === 'test') { Response::json(['error' => 'Chat not found.'], 404); return; }
        $this->db->prepare('UPDATE conversations SET unread=0 WHERE id=:id')->execute([':id' => $id]);
        $msgs = $this->db->prepare('SELECT id,sender,body,staff_name,created_at FROM conversation_messages WHERE conversation_id=:c ORDER BY id DESC LIMIT 300');
        $msgs->execute([':c' => $id]);
        $orders = $this->db->prepare("SELECT id,reference,total_pesewas,status,payment_status,created_at FROM orders WHERE notes LIKE :tag ORDER BY created_at DESC LIMIT 20");
        $orders->execute([':tag' => '%[chat ' . $id . ']%']);
        $bookings = $this->db->prepare('SELECT id,reference,kind,event_date,status FROM bookings WHERE conversation_id=:c ORDER BY created_at DESC LIMIT 20');
        $bookings->execute([':c' => $id]);
        $actions = $this->db->prepare('SELECT tool,status,detail,created_at FROM agent_actions WHERE conversation_id=:c ORDER BY id DESC LIMIT 50');
        $actions->execute([':c' => $id]);
        Response::json(['data' => [
            'id' => (int) $c['id'], 'channel' => $c['channel'], 'name' => $c['contact_name'], 'phone' => $c['phone'], 'mode' => $c['mode'],
            'needs_attention' => (bool) $c['needs_attention'], 'window_open' => $c['channel'] === 'whatsapp' ? KafuiStore::windowOpen($c) : true,
            'is_owner' => $c['channel'] === 'whatsapp' && AgentSettings::isOwnerNumber((string) $c['phone']),
            'messages' => array_reverse($msgs->fetchAll()), 'orders' => $orders->fetchAll(), 'bookings' => $bookings->fetchAll(), 'actions' => $actions->fetchAll(),
        ]]);
    }

    public function reply(int $id): void
    {
        if (!self::guard()) return;
        $c = KafuiStore::get($id);
        if (!$c || $c['channel'] === 'test') { Response::json(['error' => 'Chat not found.'], 404); return; }
        $text = KafuiText::field(self::input()['text'] ?? '', 2000);
        if ($text === '') { Response::json(['error' => 'Type a reply first.'], 422); return; }
        $text = KafuiText::noDashes($text);
        if ($c['channel'] === 'whatsapp') {
            if (!KafuiStore::windowOpen($c)) {
                Response::json(['error' => 'WhatsApp only allows replies within 24 hours of the customer\'s last message. Call the customer on +' . $c['phone'] . ' instead.'], 409);
                return;
            }
            $sent = WhatsAppSender::sendText((string) $c['phone'], $text);
            if (!$sent['ok']) { Response::json(['error' => 'WhatsApp did not accept the message: ' . $sent['error']], 502); return; }
        }
        $admin = Auth::user();
        KafuiStore::add($id, 'staff', $text, null, (string) ($admin['name'] ?? 'Staff'));
        // A staff reply means a person is handling the chat now.
        KafuiStore::setMode($id, 'staff', false);
        Response::json(['data' => ['sent' => true]]);
    }

    public function takeOver(int $id): void
    {
        if (!self::guard()) return;
        if (!KafuiStore::get($id)) { Response::json(['error' => 'Chat not found.'], 404); return; }
        KafuiStore::setMode($id, 'staff', false);
        KafuiStore::add($id, 'system', 'Staff took over from Kafui.');
        Response::json(['data' => ['mode' => 'staff']]);
    }

    public function handBack(int $id): void
    {
        if (!self::guard()) return;
        if (!KafuiStore::get($id)) { Response::json(['error' => 'Chat not found.'], 404); return; }
        KafuiStore::setMode($id, 'kafui', false);
        KafuiStore::add($id, 'system', 'Handed back to Kafui.');
        Response::json(['data' => ['mode' => 'kafui']]);
    }

    public function actions(): void
    {
        $tool = (string) ($_GET['tool'] ?? '');
        $sql = 'SELECT a.*, c.channel, c.contact_name FROM agent_actions a LEFT JOIN conversations c ON c.id=a.conversation_id';
        $params = [];
        if ($tool !== '') { $sql .= ' WHERE a.tool=:t'; $params[':t'] = $tool; }
        $s = $this->db->prepare($sql . ' ORDER BY a.id DESC LIMIT 300');
        $s->execute($params);
        Response::json(['data' => $s->fetchAll()]);
    }

    // ------------------------------------------------------------------ test chat

    /** Try Kafui as a customer or as the owner. Orders and bookings are not saved, nothing is sent on WhatsApp. */
    public function test(): void
    {
        if (!self::guard()) return;
        $in = self::input();
        $role = ($in['role'] ?? '') === 'owner' ? 'owner' : 'customer';
        $admin = Auth::user();
        $key = 'admin-' . (int) ($admin['id'] ?? 0) . '-' . $role;
        if (!empty($in['reset'])) {
            $old = KafuiStore::byKey('test', $key);
            if ($old) $this->db->prepare('DELETE FROM conversations WHERE id=:id')->execute([':id' => $old['id']]);
            Response::json(['data' => ['messages' => []]]);
            return;
        }
        if (!AgentSettings::providers()) { Response::json(['error' => 'Turn on at least one AI provider with a key and a model first.'], 422); return; }
        $conv = KafuiStore::findOrCreate('test', $key, (string) ($admin['name'] ?? 'Admin'));
        $this->db->prepare('UPDATE conversations SET test_role=:r WHERE id=:id')->execute([':r' => $role, ':id' => $conv['id']]);
        if ($conv['mode'] === 'staff') KafuiStore::setMode((int) $conv['id'], 'kafui', false);
        $text = KafuiText::field($in['text'] ?? '', 1000);
        if ($text === '') { Response::json(['error' => 'Type a message first.'], 422); return; }
        $before = (int) $this->db->query('SELECT COALESCE(MAX(id),0) FROM agent_actions')->fetchColumn();
        $mid = (int) KafuiStore::add((int) $conv['id'], 'customer', $text);
        $result = Kafui::respond(KafuiStore::get((int) $conv['id']), $mid, $text);
        if ($result['reply'] !== null) KafuiStore::add((int) $conv['id'], $result['sender'] ?? 'kafui', $result['reply']);
        $actions = $this->db->prepare('SELECT tool,status,detail FROM agent_actions WHERE conversation_id=:c AND id>:b ORDER BY id');
        $actions->execute([':c' => $conv['id'], ':b' => $before]);
        Response::json(['data' => ['reply' => $result['reply'], 'handed_off' => $result['handed_off'], 'tools' => $actions->fetchAll()]]);
    }

    // ------------------------------------------------------------------ bookings

    public function bookings(): void
    {
        Response::json(['data' => $this->db->query('SELECT * FROM bookings ORDER BY created_at DESC LIMIT 300')->fetchAll()]);
    }

    public function updateBooking(int $id): void
    {
        if (!self::guard()) return;
        $in = self::input();
        $status = (string) ($in['status'] ?? '');
        if (!in_array($status, ['pending', 'confirmed', 'declined', 'done'], true)) { Response::json(['error' => 'Choose a valid status.'], 422); return; }
        $notes = KafuiText::field($in['staff_notes'] ?? '', 2000);
        $s = $this->db->prepare('UPDATE bookings SET status=:s, staff_notes=:n WHERE id=:id');
        $s->execute([':s' => $status, ':n' => $notes, ':id' => $id]);
        if ($s->rowCount() === 0) { Response::json(['error' => 'Booking not found.'], 404); return; }
        Response::json(['data' => ['ok' => true]]);
    }

    // ------------------------------------------------------------------ helpers

    /** JSON only and same-origin, so another site cannot post with the admin's cookie. */
    private static function guard(): bool
    {
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $originHost = $origin !== '' ? (string) parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') : '';
        if (!str_starts_with($type, 'application/json') || ($origin !== '' && strcasecmp($originHost, $host) !== 0)) {
            Response::json(['error' => 'This request was refused.'], 403);
            return false;
        }
        return true;
    }

    private static function input(): array
    {
        $in = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
        return is_array($in) ? $in : [];
    }
}
