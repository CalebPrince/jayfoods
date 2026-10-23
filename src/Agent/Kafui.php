<?php

declare(strict_types=1);

/**
 * Kafui: answers one customer message, the same way on WhatsApp, the
 * website chat and the admin test chat (one brain, as Lisa).
 *
 * Order of checks for each message (see security-baseline.yaml):
 *   kill switch (CTL-AI-007) -> staff mode (CTL-AI-005) -> per-sender limits
 *   (CTL-AI-004) -> handoff words -> model with role-scoped tools (CTL-AI-001)
 *   -> money check on the reply (CTL-AI-003) -> provider failure fallback (CTL-ERR-001).
 */
final class Kafui
{
    public const MAX_ROUNDS = 2;
    /** Whole chain cap; each attempt also has its own limit (AgentSettings::TIMEOUTS). */
    public const BUDGET_SECONDS = 90;
    public const MAX_TOKENS = 600;
    public const HISTORY = 20;
    public const LIMIT_10_MIN = 20;
    public const LIMIT_DAY = 200;

    /** Tests replace the model call: fn(array $provider, string $system, array $messages, array $tools, callable $runTool): string */
    public static $modelOverride = null;
    /** Tests replace the provider list. @var list<array>|null */
    public static ?array $providersOverride = null;

    public const AWAY_TEXT = 'Hi, thanks for your message. Our assistant is resting right now, and a member of the Jay foods team will reply as soon as they can.';
    public const LIMIT_TEXT = 'Thanks for all your messages! Let me pause here for a little while. A member of our team will look at this chat.';
    /** Who takes over, by name when the admin sets one. No promise of how soon (the business asked for that). */
    public static function handoffText(): string
    {
        return 'No problem. I have asked ' . self::handoffWho() . ' to take over this chat, and they will reply to you here.';
    }

    public static function ownerFailText(): string
    {
        return 'Sorry, I could not get an answer just now. Please try again in a minute.';
    }

    public static function failText(): string
    {
        return 'Sorry, I could not answer that just now. I have asked ' . self::handoffWho() . ' to reply to you here.';
    }

    private static function handoffWho(): string
    {
        $name = trim(AgentSettings::get('handoff_name'));
        return $name !== '' ? $name : 'a member of our team';
    }

    /**
     * @param array<string,mixed> $conversation row from conversations
     * @param int $turnId id of the stored customer message being answered
     * @return array{reply:?string,document:?array,handed_off:bool}
     */
    public static function respond(array $conversation, int $turnId, string $text): array
    {
        $conv = KafuiStore::get((int) $conversation['id']) ?? $conversation;
        $none = ['reply' => null, 'document' => null, 'handed_off' => false];

        if (!AgentSettings::enabled() && $conv['channel'] !== 'test') {
            KafuiStore::flag((int) $conv['id']);
            // One away message per chat every 6 hours, not one per message.
            $s = Database::connection()->prepare("SELECT COUNT(*) FROM conversation_messages WHERE conversation_id=:c AND sender='system' AND body=:b AND created_at>=datetime('now','-6 hours')");
            $s->execute([':c' => $conv['id'], ':b' => self::AWAY_TEXT]);
            return (int) $s->fetchColumn() > 0 ? $none : ['reply' => self::AWAY_TEXT, 'document' => null, 'handed_off' => false, 'sender' => 'system'];
        }
        if ($conv['mode'] === 'staff') return $none;

        $inTen = KafuiStore::inboundCount((int) $conv['id'], 10);
        $inDay = KafuiStore::inboundCount((int) $conv['id'], 1440);
        if ($inTen > self::LIMIT_10_MIN || $inDay > self::LIMIT_DAY) {
            AgentMonitor::event('rate_limited', $conv['channel'] . ' ' . KafuiText::maskPhone((string) $conv['phone']));
            KafuiStore::flag((int) $conv['id']);
            $justOver = $inTen === self::LIMIT_10_MIN + 1 || $inDay === self::LIMIT_DAY + 1;
            return $justOver ? ['reply' => self::LIMIT_TEXT, 'document' => null, 'handed_off' => false, 'sender' => 'system'] : $none;
        }

        $ctx = new KafuiContext($conv, self::roleFor($conv), $turnId);

        if (self::wantsPerson($text)) {
            AgentMonitor::action($ctx->conversationId(), $ctx->role, 'handoff', ['reason' => 'asked for a person'], 'ok', 'keyword');
            self::handOff($ctx, 'Customer asked for a person');
            return ['reply' => $ctx->customerText, 'document' => null, 'handed_off' => true];
        }

        $history = KafuiStore::history((int) $conv['id'], self::HISTORY);
        $firstReply = !array_filter($history, static fn($m) => $m['sender'] === 'kafui');
        $messages = [];
        foreach ($history as $m) {
            if ($m['sender'] === 'system') continue;
            $messages[] = ['role' => $m['sender'] === 'customer' ? 'user' : 'assistant', 'content' => ($m['sender'] === 'staff' ? '[Jay foods staff] ' : '') . $m['body']];
        }
        $system = KafuiPrompt::build($ctx->role, $ctx->channel() === 'whatsapp' ? 'whatsapp' : 'web', $firstReply, (string) $conv['phone'])
            . self::quoteContext((int) $conv['id'], $turnId);
        $tools = KafuiTools::definitions($ctx->role, $ctx->channel());
        $runTool = static fn(string $name, array $args): array => KafuiTools::run($name, $args, $ctx);

        // Lisa's chain: each provider (and its backup model) is a fresh, independent
        // attempt with its own time limit; the next one only runs if this one fails.
        @set_time_limit(self::BUDGET_SECONDS + 30);
        $reply = null;
        $errors = [];
        $chainEnd = microtime(true) + self::BUDGET_SECONDS;
        foreach (self::$providersOverride ?? AgentSettings::providers() as $provider) {
            if ($ctx->handedOff) break;
            $attemptEnd = min($chainEnd, microtime(true) + (int) ($provider['timeout'] ?? 20));
            try {
                $reply = self::$modelOverride !== null
                    ? (self::$modelOverride)($provider, $system, $messages, $tools, $runTool)
                    : ModelProviders::converse($provider, $system, $messages, $tools, $runTool, self::MAX_ROUNDS, $attemptEnd, self::MAX_TOKENS);
                $reply = trim((string) $reply);
                if ($reply !== '' || $ctx->customerText !== null) {
                    if ($reply !== '') AgentMonitor::event('provider_used', $provider['name']);
                    break;
                }
                AgentMonitor::event('provider_empty', $provider['name'] . ' ' . $provider['model']);
            } catch (Throwable $e) {
                AgentMonitor::event('provider_error', $provider['name'] . ' ' . $provider['model'] . ': ' . mb_substr($e->getMessage(), 0, 120));
                $errors[] = $provider['name'] . ' ' . $provider['model'] . ': ' . mb_substr($e->getMessage(), 0, 160);
                // A tool may already have acted (an order placed); never repeat the turn on another provider then.
                if ($ctx->customerText !== null) break;
            }
            if (microtime(true) > $chainEnd - 3) break;
        }

        if ($ctx->handedOff) return ['reply' => $ctx->customerText, 'document' => null, 'handed_off' => true];

        if (($reply === null || $reply === '') && $ctx->customerText === null) {
            AgentMonitor::event('provider_failed', (string) $conv['channel']);
            // Errors go to the admin only: a note in this chat with the cause, never a WhatsApp alert.
            if (!$ctx->isTest()) KafuiStore::add((int) $conv['id'], 'system', 'Kafui could not get an answer from any AI provider. ' . ($errors ? 'Errors: ' . implode(' | ', $errors) : 'No provider answered in time.'));
            // An owner is not handed to staff (that would silence Kafui in their own chat); they just try again.
            if ($ctx->role === 'owner') return ['reply' => self::ownerFailText(), 'document' => null, 'handed_off' => false];
            self::handOff($ctx, 'Kafui could not get an answer from any AI provider', self::failText(), false);
            return ['reply' => self::failText(), 'document' => null, 'handed_off' => true];
        }
        if ($reply === null || $reply === '') $reply = (string) $ctx->customerText;

        // CTL-AI-003: every amount in the reply must be a real price, fee or total from this chat.
        $allowed = array_merge(KafuiTools::publicAmounts(), $ctx->allowedAmounts, self::quotedAmounts((int) $conv['id']));
        $bad = array_diff(KafuiText::moneyAmounts($reply), $allowed);
        if ($bad) {
            AgentMonitor::event('reply_replaced', 'amounts ' . implode(',', array_map(static fn($p) => KafuiText::money($p), $bad)));
            $reply = $ctx->customerText ?? self::menuText();
            self::countFailure($ctx);
        } else {
            Database::connection()->prepare('UPDATE conversations SET failed_answers=0 WHERE id=:id')->execute([':id' => $conv['id']]);
        }

        $reply = KafuiText::spaceSentences(KafuiText::noDashes($reply));
        if ($firstReply && stripos($reply, 'Kafui') === false) {
            $reply = KafuiPrompt::greeting($ctx->role, (string) $conv['phone']) . "\n\n" . $reply;
        }
        return ['reply' => mb_substr($reply, 0, 3500), 'document' => $ctx->document, 'handed_off' => $ctx->handedOff];
    }

    /** Role comes from the channel identity only: owner tools need a listed number on WhatsApp (CTL-AUTHZ-002). */
    public static function roleFor(array $conversation): string
    {
        if ($conversation['channel'] === 'whatsapp' && AgentSettings::isOwnerNumber((string) $conversation['phone'])) return 'owner';
        if ($conversation['channel'] === 'test' && ($conversation['test_role'] ?? '') === 'owner') return 'owner';
        return 'customer';
    }

    public static function wantsPerson(string $text): bool
    {
        // Phrases only: "I'm the office manager" or "are you human?" must not end the chat.
        return (bool) preg_match('/\b(real person|a human|(talk|speak|chat) (to|with) (a |an |the |your |some )?(person|someone|somebody|staff|manager|human|agent|team|owner)|customer (care|service))\b/i', $text);
    }

    public static function handOff(KafuiContext $ctx, string $reason, ?string $text = null, bool $alertOwners = true): void
    {
        KafuiStore::setMode($ctx->conversationId(), 'staff', true);
        $ctx->handedOff = true;
        $ctx->customerText = $text ?? self::handoffText();
        if (!$ctx->isTest()) {
            KafuiNotify::handoff(KafuiStore::get($ctx->conversationId()) ?? $ctx->conversation, $reason, $alertOwners);
        }
    }

    /** Two failed answers in a row hand the chat to staff. */
    private static function countFailure(KafuiContext $ctx): void
    {
        $db = Database::connection();
        $db->prepare('UPDATE conversations SET failed_answers=failed_answers+1 WHERE id=:id')->execute([':id' => $ctx->conversationId()]);
        $s = $db->prepare('SELECT failed_answers FROM conversations WHERE id=:id');
        $s->execute([':id' => $ctx->conversationId()]);
        if ((int) $s->fetchColumn() >= 2) self::handOff($ctx, 'Kafui failed to answer twice in a row', null, false);
    }

    /**
     * The chat history holds only the messages, not tool results, so without this
     * the model cannot see the quote it made earlier and re-quotes on every "yes".
     * Facts come from the database, not from anything the customer typed.
     */
    private static function quoteContext(int $conversationId, int $turnId): string
    {
        $db = Database::connection();
        $s = $db->prepare("SELECT q.id, q.payload_json, q.total_pesewas, q.order_id, o.reference FROM agent_quotes q LEFT JOIN orders o ON o.id = q.order_id
                            WHERE q.conversation_id = :c AND q.created_at >= datetime('now', :w) ORDER BY q.created_at DESC, q.rowid DESC LIMIT 1");
        $s->execute([':c' => $conversationId, ':w' => '-' . KafuiTools::QUOTE_HOURS . ' hours']);
        $q = $s->fetch();
        if (!$q) return '';
        $p = json_decode((string) $q['payload_json'], true) ?: [];
        if ((int) ($p['turn_id'] ?? PHP_INT_MAX) >= $turnId) return '';
        if ($q['order_id'] !== null) {
            return "

LATEST QUOTE IN THIS CHAT: already placed as order " . $q['reference'] . ". Do not place it again; quote again only if the customer wants a new order.";
        }
        $priced = KafuiTools::price($p['items'] ?? [], (string) ($p['fulfilment'] ?? 'delivery'), (string) ($p['area'] ?? ''));
        $summary = isset($priced['error']) ? 'Total ' . KafuiText::money((int) $q['total_pesewas']) : KafuiTools::summaryText($priced, (string) ($p['fulfilment'] ?? 'delivery'));
        return "

OPEN QUOTE IN THIS CHAT (made in an earlier message and shown to the customer):
quote_id: " . $q['id'] . "
" . $summary
            . "
If the customer now confirms it (yes, ok, go ahead, place it), call place_order with quote_id " . $q['id'] . " and the name, phone, address and date they gave. "
            . "Call quote_order again only if they change the items, quantities, delivery or area.";
    }

    /** Totals of quotes made earlier in this chat may be repeated. @return list<int> */
    private static function quotedAmounts(int $conversationId): array
    {
        $s = Database::connection()->prepare("SELECT payload_json,total_pesewas FROM agent_quotes WHERE conversation_id=:c AND created_at>=datetime('now','-1 day')");
        $s->execute([':c' => $conversationId]);
        $out = [];
        foreach ($s->fetchAll() as $q) {
            $out[] = (int) $q['total_pesewas'];
            $p = json_decode((string) $q['payload_json'], true) ?: [];
            $priced = KafuiTools::price($p['items'] ?? [], (string) ($p['fulfilment'] ?? 'delivery'), (string) ($p['area'] ?? ''));
            if (!isset($priced['error'])) {
                foreach ($priced['lines'] as $l) $out[] = $l['line_total_pesewas'];
                $out[] = $priced['subtotal'];
            }
        }
        return $out;
    }

    /** Server-written price list, used when a reply had a wrong amount and no tool text exists. */
    public static function menuText(): string
    {
        $lines = ['Here are our prices from the Jay foods price list:'];
        foreach (KafuiTools::catalogue() as $c) {
            $bulk = (int) $c['bulk_available'] === 1 && $c['bulk_price_pesewas'] !== null && (int) $c['bulk_min_quantity'] > 0
                ? ' (' . KafuiText::money((int) $c['bulk_price_pesewas']) . ' each from ' . (int) $c['bulk_min_quantity'] . ')' : '';
            $lines[] = '- ' . $c['name'] . ', ' . $c['label'] . ': ' . KafuiText::money((int) $c['unit_price_pesewas']) . $bulk;
        }
        $lines[] = 'Tell me what you would like and I will work out the exact total for you.';
        return implode("\n", $lines);
    }
}
