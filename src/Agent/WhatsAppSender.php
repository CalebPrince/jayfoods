<?php

declare(strict_types=1);

/**
 * Sends WhatsApp messages through whichever provider is chosen in the Kafui
 * settings: Twilio (default, as Lisa) or Meta's Cloud API directly.
 */
final class WhatsAppSender
{
    public static function provider(): string
    {
        return AgentSettings::get('whatsapp_provider') === 'meta' ? 'meta' : 'twilio';
    }

    public static function isConfigured(): bool
    {
        return self::provider() === 'meta' ? WhatsAppCloud::isConfigured() : TwilioWhatsApp::isConfigured();
    }

    /** @return array{ok:bool,id:?string,error:?string} */
    public static function sendText(string $to, string $text): array
    {
        return self::provider() === 'meta' ? WhatsAppCloud::sendText(KafuiText::digits($to), $text) : TwilioWhatsApp::sendText($to, $text);
    }

    /** @return array{ok:bool,id:?string,error:?string} */
    public static function sendDocument(string $to, string $link, string $filename, string $caption): array
    {
        return self::provider() === 'meta' ? WhatsAppCloud::sendDocument(KafuiText::digits($to), $link, $filename, $caption) : TwilioWhatsApp::sendDocument($to, $link, $filename, $caption);
    }
}
