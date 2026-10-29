<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

/** The JSON body sent by petitstats.js. */
final class TrackingPayload
{
    /** @param array<mixed> $data */
    private function __construct(
        public readonly string $siteId,
        public readonly string $siteKey,
        private readonly string $rawName,
        public readonly array $data,
        public readonly string $sessionId,
    ) {}

    public static function fromJson(string $json): self
    {
        $payload = json_decode($json, true);
        if (!is_array($payload) || !is_string($payload['name'] ?? null) || !is_array($payload['data'] ?? null)) {
            throw new TrackingException('Expected name and data object.');
        }
        return new self(
            (string) ($payload['site_id'] ?? ''),
            (string) ($payload['site_key'] ?? ''),
            $payload['name'],
            $payload['data'],
            self::identifier($payload['session_id'] ?? ''),
        );
    }

    /** The event name, at most 100 characters and without control characters. */
    public function name(): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', substr($this->rawName, 0, 100)) ?? '');
        if ($name === '') {
            throw new TrackingException('Invalid event name.');
        }
        return $name;
    }

    private static function identifier(mixed $value): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_\-]/', '', is_scalar($value) ? (string) $value : '');
    }
}
