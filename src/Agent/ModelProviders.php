<?php

declare(strict_types=1);

/**
 * One conversation turn on one AI provider, with tool calls. Ported from
 * Lisa's AiAgentEngine: Anthropic, and the OpenAI-style chat API that
 * OpenAI, OpenRouter and Groq share.
 *
 * converse() runs at most $maxRounds model calls. Tool calls the model asks
 * for in the last round are still run (so a reply can fall back on their
 * server-written text) but the model is not called again.
 */
final class ModelProviders
{
    private const OPENAI_STYLE = [
        'openai'     => 'https://api.openai.com/v1/chat/completions',
        'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
        'groq'       => 'https://api.groq.com/openai/v1/chat/completions',
    ];

    /**
     * @param array{name:string,key:string,model:string} $provider
     * @param list<array{role:string,content:string}> $messages user/assistant turns, oldest first, last one from the user
     * @param list<array{name:string,description:string,parameters:array}> $tools
     * @param callable(string,array):array $runTool
     */
    public static function converse(array $provider, string $system, array $messages, array $tools, callable $runTool, int $maxRounds, float $deadline, int $maxTokens): string
    {
        $messages = self::alternate($messages);
        return match ($provider['name']) {
            'anthropic' => self::anthropic($provider, $system, $messages, $tools, $runTool, $maxRounds, $deadline, $maxTokens),
            default => self::openAiStyle($provider, $system, $messages, $tools, $runTool, $maxRounds, $deadline, $maxTokens),
        };
    }

    private static function timeout(float $deadline): int
    {
        $left = (int) floor($deadline - microtime(true));
        if ($left < 3) throw new RuntimeException('out of time');
        return $left;
    }

    /** Providers need user/assistant turns to alternate and start with the user. */
    private static function alternate(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
            if ($out && end($out)['role'] === $role) { $out[count($out) - 1]['content'] .= "\n" . $m['content']; continue; }
            $out[] = ['role' => $role, 'content' => $m['content']];
        }
        while ($out && $out[0]['role'] !== 'user') array_shift($out);
        return $out;
    }

    private static function args(mixed $raw): array
    {
        if (is_array($raw)) return $raw;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Thinking models (gpt-oss, Gemini 2.5, OpenAI o-series and gpt-5) count their
     * thinking against the reply limit; a long prompt then cuts the reply off.
     * Ask them to think briefly. Models that do not think are left alone.
     */
    private static function reasoning(array $p): array
    {
        $model = strtolower($p['model']);
        if ($p['name'] === 'openrouter') return ['reasoning' => ['effort' => 'low']];
        if ($p['name'] === 'groq' && str_contains($model, 'gpt-oss')) return ['reasoning_effort' => 'low'];
        if ($p['name'] === 'openai' && (preg_match('/^o\d/', $model) || str_starts_with($model, 'gpt-5'))) return ['reasoning_effort' => 'low'];
        return [];
    }

    // ------------------------------------------------------------------ Anthropic

    private static function anthropic(array $p, string $system, array $messages, array $tools, callable $runTool, int $maxRounds, float $deadline, int $maxTokens): string
    {
        $msgs = array_map(static fn($m) => ['role' => $m['role'], 'content' => $m['content']], $messages);
        $defs = array_map(static fn($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters']], $tools);
        for ($round = 1; $round <= $maxRounds; $round++) {
            $body = ['model' => $p['model'], 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => $msgs, 'temperature' => 0.3];
            if ($defs) $body['tools'] = $defs;
            $data = AgentHttp::json('POST', 'https://api.anthropic.com/v1/messages', ['x-api-key: ' . $p['key'], 'anthropic-version: 2023-06-01'], $body, self::timeout($deadline));
            $blocks = $data['content'] ?? [];
            $uses = array_values(array_filter($blocks, static fn($b) => ($b['type'] ?? '') === 'tool_use'));
            $text = trim(implode("\n\n", array_filter(array_map(static fn($b) => ($b['type'] ?? '') === 'text' ? trim((string) $b['text']) : '', $blocks), static fn($t) => $t !== '')));
            if (!$uses) {
                if (($data['stop_reason'] ?? '') === 'max_tokens') throw new RuntimeException('truncated');
                return $text;
            }
            $msgs[] = ['role' => 'assistant', 'content' => $blocks];
            $results = [];
            foreach ($uses as $u) {
                $result = $runTool((string) $u['name'], self::args($u['input'] ?? []));
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $u['id'], 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
            }
            $msgs[] = ['role' => 'user', 'content' => $results];
        }
        return '';
    }

    // ------------------------------------------------------------------ OpenAI-style (OpenAI, OpenRouter, Groq)

    private static function openAiStyle(array $p, string $system, array $messages, array $tools, callable $runTool, int $maxRounds, float $deadline, int $maxTokens): string
    {
        $url = self::OPENAI_STYLE[$p['name']] ?? null;
        if ($url === null) throw new RuntimeException('unknown provider');
        $msgs = array_merge([['role' => 'system', 'content' => $system]], $messages);
        $defs = array_map(static fn($t) => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['parameters']]], $tools);
        for ($round = 1; $round <= $maxRounds; $round++) {
            $body = ['model' => $p['model'], 'messages' => $msgs];
            $body[$p['name'] === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $maxTokens;
            $body += self::reasoning($p);
            if ($defs) $body['tools'] = $defs;
            $headers = ['Authorization: Bearer ' . $p['key']];
            if ($p['name'] === 'openrouter') { $headers[] = 'HTTP-Referer: ' . (AgentSettings::siteUrl() ?: 'https://jayfoods.local'); $headers[] = 'X-Title: Jay foods Kafui'; }
            $data = AgentHttp::json('POST', $url, $headers, $body, self::timeout($deadline));
            $choice = $data['choices'][0] ?? [];
            $msg = $choice['message'] ?? [];
            $calls = $msg['tool_calls'] ?? [];
            if (!$calls) {
                if (($choice['finish_reason'] ?? '') === 'length') throw new RuntimeException('truncated (reply limit reached' . (isset($data['usage']['completion_tokens_details']['reasoning_tokens']) ? ', ' . (int) $data['usage']['completion_tokens_details']['reasoning_tokens'] . ' spent thinking' : '') . ')');
                return trim((string) ($msg['content'] ?? ''));
            }
            $msgs[] = ['role' => 'assistant', 'content' => $msg['content'] ?? null, 'tool_calls' => $calls];
            foreach ($calls as $c) {
                $result = $runTool((string) ($c['function']['name'] ?? ''), self::args($c['function']['arguments'] ?? '{}'));
                $msgs[] = ['role' => 'tool', 'tool_call_id' => $c['id'], 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
            }
        }
        return '';
    }
}
