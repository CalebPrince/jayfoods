<?php

declare(strict_types=1);

/**
 * Kafui's settings, typed into the admin. Secrets are encrypted with
 * SecretBox before they are stored and are never sent back to the browser.
 */
final class AgentSettings
{
    /**
     * Provider order is the failover order, as in Lisa's AiAgentEngine::run():
     * Anthropic, then OpenAI, then OpenRouter, then Groq. Each gets its own
     * time limit (seconds) so a slow provider never uses up the next one's turn.
     */
    public const PROVIDERS = [
        'anthropic'  => 'Anthropic',
        'openai'     => 'OpenAI',
        'openrouter' => 'OpenRouter',
        'groq'       => 'Groq',
    ];
    public const TIMEOUTS = ['anthropic' => 20, 'openai' => 20, 'openrouter' => 30, 'groq' => 20];

    public const SECRETS = [
        'twilio_auth_token',
        'wa_access_token', 'wa_app_secret', 'wa_verify_token',
        'anthropic_key', 'openai_key', 'openrouter_key', 'groq_key',
    ];

    /** Plain settings and their defaults. */
    public const PLAIN = [
        'agent_enabled'      => '0',
        'site_url'           => '',
        'whatsapp_provider'  => 'twilio',   // twilio (as Lisa) or meta (Cloud API directly)
        'twilio_account_sid' => '',
        'twilio_whatsapp_number' => '',
        'wa_phone_number_id' => '',
        'wa_graph_version'   => '',
        'owner_email'        => '',
        'alert_email'        => '',
        'pickup_enabled'     => '0',
        'pickup_details'     => '',
        'payment_note'       => 'Our team will confirm your order and tell you how to pay (Mobile Money or cash on delivery).',
        'anthropic_enabled' => '0', 'anthropic_model' => '', 'anthropic_fallback_model' => '',
        'openai_enabled' => '0', 'openai_model' => '', 'openai_fallback_model' => '',
        'openrouter_enabled' => '0', 'openrouter_model' => '', 'openrouter_fallback_model' => '',
        'groq_enabled' => '0', 'groq_model' => '', 'groq_fallback_model' => '',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function reset(): void { self::$cache = null; }

    public static function get(string $key): string
    {
        $all = self::load();
        return $all[$key] ?? (self::PLAIN[$key] ?? '');
    }

    public static function secret(string $key): string
    {
        $stored = self::load()[$key] ?? '';
        if ($stored === '') return '';
        try { return SecretBox::decrypt($stored); } catch (Throwable $e) { error_log('[kafui] could not decrypt ' . $key); return ''; }
    }

    public static function hasSecret(string $key): bool { return (self::load()[$key] ?? '') !== ''; }

    public static function set(string $key, string $value): void
    {
        if (!array_key_exists($key, self::PLAIN) && !in_array($key, self::SECRETS, true)) {
            throw new InvalidArgumentException('Unknown setting ' . $key);
        }
        $stored = in_array($key, self::SECRETS, true) && $value !== '' ? SecretBox::encrypt($value) : $value;
        $s = Database::connection()->prepare("INSERT INTO agent_settings(setting_key,value,updated_at) VALUES(:k,:v,datetime('now')) ON CONFLICT(setting_key) DO UPDATE SET value=excluded.value,updated_at=datetime('now')");
        $s->execute([':k' => $key, ':v' => $stored]);
        self::$cache = null;
    }

    public static function enabled(): bool { return self::get('agent_enabled') === '1'; }

    /**
     * The failover chain: for each enabled provider with a key, its main model,
     * then its backup model if one is set. Each attempt is a fresh turn.
     * @return list<array{name:string,key:string,model:string,timeout:int}>
     */
    public static function providers(): array
    {
        $out = [];
        foreach (array_keys(self::PROVIDERS) as $p) {
            if (self::get($p . '_enabled') !== '1') continue;
            $key = self::secret($p . '_key');
            if ($key === '') continue;
            $models = array_unique(array_filter([trim(self::get($p . '_model')), trim(self::get($p . '_fallback_model'))]));
            foreach ($models as $model) $out[] = ['name' => $p, 'key' => $key, 'model' => $model, 'timeout' => self::TIMEOUTS[$p]];
        }
        return $out;
    }

    /** @return list<array{number:string,label:string}> */
    public static function ownerNumbers(): array
    {
        return Database::connection()->query('SELECT number,label FROM agent_owner_numbers ORDER BY created_at,number')->fetchAll();
    }

    /** Exact match on digits with country code; never a partial match. */
    public static function isOwnerNumber(string $digits): bool
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if ($digits === '') return false;
        $s = Database::connection()->prepare('SELECT 1 FROM agent_owner_numbers WHERE number=:n');
        $s->execute([':n' => $digits]);
        return (bool) $s->fetchColumn();
    }

    /** Where owner notices and invoices go by email. */
    public static function ownerEmail(): string
    {
        $email = trim(self::get('owner_email'));
        if ($email === '') $email = (new SmtpMailer())->notificationEmail();
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    public static function alertEmail(): string
    {
        $email = trim(self::get('alert_email'));
        if ($email === '') $email = (new SmtpMailer())->notificationEmail();
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    public static function siteUrl(): string { return rtrim(trim(self::get('site_url')), '/'); }

    /** @return array<string,string> */
    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::connection()->query('SELECT setting_key,value FROM agent_settings')->fetchAll() as $r) {
                self::$cache[$r['setting_key']] = (string) $r['value'];
            }
        }
        return self::$cache;
    }
}
