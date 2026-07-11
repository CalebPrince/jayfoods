<?php
declare(strict_types=1);
final class PaystackSettingsController
{
    private PDO $db;
    public function __construct() { $this->db = Database::connection(); }
    public function show(): void
    {
        $r = $this->db->query('SELECT * FROM paystack_settings WHERE id = 1')->fetch() ?: [];
        Response::json(['data' => ['public_key' => $r['public_key'] ?? '', 'webhook_url' => $r['webhook_url'] ?? '', 'has_secret_key' => !empty($r['secret_key_encrypted'])]]);
    }
    public function update(): void
    {
        $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $public = trim((string) ($in['public_key'] ?? '')); $secret = trim((string) ($in['secret_key'] ?? '')); $webhook = trim((string) ($in['webhook_url'] ?? ''));
        $errors = [];
        if (!str_starts_with($public, 'pk_')) $errors['public_key'] = 'Enter a valid Paystack public key.';
        if ($webhook !== '' && !filter_var($webhook, FILTER_VALIDATE_URL)) $errors['webhook_url'] = 'Enter a valid HTTPS webhook URL.';
        $old = $this->db->query('SELECT secret_key_encrypted FROM paystack_settings WHERE id = 1')->fetch();
        $encrypted = (string) ($old['secret_key_encrypted'] ?? '');
        if ($secret !== '' && !str_starts_with($secret, 'sk_')) $errors['secret_key'] = 'Enter a valid Paystack secret key.';
        if ($secret === '' && $encrypted === '') $errors['secret_key'] = 'Secret key is required.';
        if ($errors) { Response::json(['error' => 'Please correct the highlighted fields.', 'fields' => $errors], 422); return; }
        if ($secret !== '') $encrypted = SecretBox::encrypt($secret);
        $s = $this->db->prepare("INSERT INTO paystack_settings(id,public_key,secret_key_encrypted,webhook_url,updated_at) VALUES(1,:p,:s,:w,datetime('now')) ON CONFLICT(id) DO UPDATE SET public_key=excluded.public_key,secret_key_encrypted=excluded.secret_key_encrypted,webhook_url=excluded.webhook_url,updated_at=datetime('now')");
        $s->execute([':p'=>$public, ':s'=>$encrypted, ':w'=>$webhook]);
        Response::json(['data' => ['public_key'=>$public,'webhook_url'=>$webhook,'has_secret_key'=>true]]);
    }
}
