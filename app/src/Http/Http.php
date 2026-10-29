<?php

declare(strict_types=1);

namespace Petitstats\Http;

/** Response helpers for the endpoints called by AI assistants (MCP and OAuth). */
final class Http
{
    /**
     * Sends permissive CORS headers and answers preflight requests. These
     * endpoints are authenticated by bearer tokens, never by cookies.
     */
    public static function allowCors(string $methods): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: ' . $methods . ', OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version, Mcp-Session-Id, Last-Event-ID');
        header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
