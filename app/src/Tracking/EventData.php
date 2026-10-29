<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

/** Turns the `data` object sent by the tracker into what is stored with the event. */
final class EventData
{
    /** Keys that may hold personal data: they are dropped at any depth. */
    private const SENSITIVE_KEYS = '/password|token|secret|email|phone|address|card|authorization/i';

    /**
     * Bounds sizes and depth, drops sensitive keys and full URLs, keeps only the
     * referrer's host, and adds the browser, OS and device.
     *
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data, UserAgent $userAgent): array
    {
        $data = self::clean($data);
        unset($data['url'], $data['search'], $data['hash']);
        $data['_ml_tracking_mode'] = ($data['tracking_mode'] ?? 'strict') === 'enriched' ? 'enriched' : 'strict';
        unset($data['tracking_mode']);
        if (isset($data['path'])) {
            $data['path'] = '/' . ltrim((string) $data['path'], '/');
        }
        if (isset($data['referrer'])) {
            $data['referrer'] = TrackingAccess::host((string) $data['referrer']);
        }
        $data['browser'] = $userAgent->browser();
        $data['os'] = $userAgent->os();
        $data['device'] = $userAgent->device();
        return $data;
    }

    private static function clean(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 3) {
            return null;
        }
        if (is_string($value)) {
            return substr($value, 0, 500);
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        $safe = [];
        foreach ($value as $key => $item) {
            $key = substr((string) $key, 0, 80);
            if (!preg_match(self::SENSITIVE_KEYS, $key)) {
                $safe[$key] = self::clean($item, $depth + 1);
            }
        }
        return $safe;
    }
}
