<?php

declare(strict_types=1);

/** Conversations and their messages, shared by WhatsApp, the website chat and the admin test chat. */
final class KafuiStore
{
    public static function findOrCreate(string $channel, string $key, string $name = '', string $phone = ''): array
    {
        $db = Database::connection();
        $db->prepare('INSERT OR IGNORE INTO conversations(channel,contact_key,contact_name,phone) VALUES(:c,:k,:n,:p)')
           ->execute([':c' => $channel, ':k' => $key, ':n' => mb_substr($name, 0, 80), ':p' => $phone]);
        if ($name !== '') {
            $db->prepare("UPDATE conversations SET contact_name=:n WHERE channel=:c AND contact_key=:k AND contact_name=''")
               ->execute([':n' => mb_substr($name, 0, 80), ':c' => $channel, ':k' => $key]);
        }
        return self::byKey($channel, $key);
    }

    public static function byKey(string $channel, string $key): array
    {
        $s = Database::connection()->prepare('SELECT * FROM conversations WHERE channel=:c AND contact_key=:k');
        $s->execute([':c' => $channel, ':k' => $key]);
        return $s->fetch() ?: [];
    }

    /** The WhatsApp chat for a number (latest first), so messages go to the exact address WhatsApp gave us. */
    public static function byPhone(string $phone): ?array
    {
        $digits = KafuiText::digits($phone);
        if ($digits === '') return null;
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) $digits = '233' . substr($digits, 1);
        $s = Database::connection()->prepare("SELECT * FROM conversations WHERE channel='whatsapp' AND (phone=:p OR contact_key=:p) ORDER BY last_message_at DESC LIMIT 1");
        $s->execute([':p' => $digits]);
        return $s->fetch() ?: null;
    }

    public static function get(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM conversations WHERE id=:id');
        $s->execute([':id' => $id]);
        return $s->fetch() ?: null;
    }

    /** Stores a message. Returns its id, or null when this WhatsApp message id was already stored. */
    public static function add(int $conversationId, string $sender, string $body, ?string $waMessageId = null, string $staffName = ''): ?int
    {
        $db = Database::connection();
        $s = $db->prepare('INSERT OR IGNORE INTO conversation_messages(conversation_id,sender,body,wa_message_id,staff_name) VALUES(:c,:s,:b,:w,:n)');
        $s->execute([':c' => $conversationId, ':s' => $sender, ':b' => $body, ':w' => $waMessageId, ':n' => $staffName]);
        if ($s->rowCount() === 0) return null;
        $id = (int) $db->lastInsertId();
        $sql = $sender === 'customer'
            ? "UPDATE conversations SET last_message_at=datetime('now'),last_inbound_at=datetime('now'),unread=unread+1 WHERE id=:id"
            : "UPDATE conversations SET last_message_at=datetime('now') WHERE id=:id";
        $db->prepare($sql)->execute([':id' => $conversationId]);
        return $id;
    }

    /** Last $limit messages, oldest first. */
    public static function history(int $conversationId, int $limit = 20): array
    {
        $s = Database::connection()->prepare('SELECT id,sender,body,staff_name,created_at FROM conversation_messages WHERE conversation_id=:c ORDER BY id DESC LIMIT ' . max(1, $limit));
        $s->execute([':c' => $conversationId]);
        return array_reverse($s->fetchAll());
    }

    public static function setMode(int $conversationId, string $mode, bool $attention): void
    {
        $sql = $mode === 'kafui'
            ? "UPDATE conversations SET mode='kafui',needs_attention=0,failed_answers=0 WHERE id=:id"
            : "UPDATE conversations SET mode='staff',needs_attention=:a WHERE id=:id";
        $params = [':id' => $conversationId];
        if ($mode !== 'kafui') $params[':a'] = $attention ? 1 : 0;
        Database::connection()->prepare($sql)->execute($params);
    }

    public static function flag(int $conversationId): void
    {
        Database::connection()->prepare('UPDATE conversations SET needs_attention=1 WHERE id=:id')->execute([':id' => $conversationId]);
    }

    /** Customer messages from this conversation in the last $minutes. */
    public static function inboundCount(int $conversationId, int $minutes): int
    {
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM conversation_messages WHERE conversation_id=:c AND sender='customer' AND created_at>=datetime('now',:m)");
        $s->execute([':c' => $conversationId, ':m' => '-' . $minutes . ' minutes']);
        return (int) $s->fetchColumn();
    }

    /**
     * Where to send WhatsApp replies: the exact address the provider gave
     * (WhatsApp can send a user ID instead of a phone number), else the number.
     */
    public static function replyAddress(array $conversation): string
    {
        $to = (string) ($conversation['reply_to'] ?? '');
        return $to !== '' ? $to : (string) $conversation['phone'];
    }

    /** WhatsApp sent an ID rather than a phone number (more than 15 digits). */
    public static function isWhatsAppId(string $phone): bool
    {
        return strlen(KafuiText::digits($phone)) > 15;
    }

    /** Whether WhatsApp's 24-hour customer service window is open. */
    public static function windowOpen(array $conversation): bool
    {
        $last = (string) ($conversation['last_inbound_at'] ?? '');
        return $last !== '' && strtotime($last . ' UTC') > time() - 24 * 3600;
    }
}
