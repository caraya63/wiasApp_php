<?php

declare(strict_types=1);

final class Http
{
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public static function json(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function notFound(): void
    {
        self::json(404, ['error' => 'not_found']);
    }

    public static function badRequest(string $message, array $details = []): void
    {
        self::json(400, ['error' => 'bad_request', 'message' => $message, 'details' => $details]);
    }

    public static function unauthorized(string $message = 'unauthorized'): void
    {
        self::json(401, ['error' => 'unauthorized', 'message' => $message]);
    }

    public static function forbidden(string $message = 'forbidden'): void
    {
        self::json(403, ['error' => 'forbidden', 'message' => $message]);
    }
    public static function dbServerFail(string $message = 'fail'): void
    {
        self::json(417, ['error' => 'dbServerFail', 'message' => $message]);
    }
}