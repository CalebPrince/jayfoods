<?php

declare(strict_types=1);

/**
 * Tiny JSON response helper shared by every controller.
 */
final class Response
{
    /**
     * @param mixed $data
     */
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
