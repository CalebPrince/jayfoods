<?php

declare(strict_types=1);

/** Everything a tool needs to know about the turn it runs in. Set by code, never by the model. */
final class KafuiContext
{
    /** @var list<int> money amounts (pesewas) a reply may mention this turn */
    public array $allowedAmounts = [];
    /** Server-written text to fall back on when the model's reply cannot be used. */
    public ?string $customerText = null;
    /** @var array{link:string,filename:string,caption:string}|null a document to send after the reply (WhatsApp) */
    public ?array $document = null;
    public int $toolCalls = 0;
    public bool $handedOff = false;

    /**
     * @param array<string,mixed> $conversation
     * @param string $role customer | owner (decided from the channel identity in Kafui::roleFor)
     * @param int $turnId id of the customer message being answered
     */
    public function __construct(
        public array $conversation,
        public string $role,
        public int $turnId,
    ) {}

    public function channel(): string { return (string) $this->conversation['channel']; }
    public function isTest(): bool { return $this->channel() === 'test'; }
    public function conversationId(): int { return (int) $this->conversation['id']; }
    /** The Meta-verified sender number (WhatsApp only). */
    public function verifiedPhone(): string { return $this->channel() === 'whatsapp' ? (string) $this->conversation['phone'] : ''; }
}
