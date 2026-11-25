<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

/**
 * Adds up signs that a page view comes from a headless browser. None of them
 * is enough on its own, since real visitors show each one now and then (a VPN
 * or a virtual desktop in the cloud, a computer without a graphics driver,
 * someone reading a French page with an English browser), so a visit is only
 * rejected when they add up.
 *
 * The signals are read from the event the tracker sends (see petitstats.js)
 * and never stored, except _ml_confirm, kept with the event for the burst
 * detection.
 */
final class BotScore
{
    /** A visit scoring this much or more is rejected. */
    public const THRESHOLD = 3;

    /**
     * What each signal weighs. Language and time on screen weigh 1 so that,
     * together, they never reach the threshold: a real visitor can show both.
     * A cloud network weighs most, but a visitor on a VPN who interacts with
     * the page in their own language is kept.
     */
    public const WEIGHTS = [
        // Sent from a cloud or hosting provider's network (see HostingNetworks).
        'datacenter' => 2,
        // Desktop browser without the built-in PDF viewer plugins every desktop browser lists.
        'no_plugins' => 2,
        // WebGL drawn in software (SwiftShader, llvmpipe), as in cloud machines.
        'software_gl' => 1,
        // Counted after 3 seconds on screen, without a single interaction.
        'passive' => 1,
        // The browser's language differs from the page's.
        'language_mismatch' => 1,
    ];

    /**
     * The signals this event shows, in the order of WEIGHTS. The cloud
     * network comes with its provider: "datacenter:google_cloud".
     *
     * @param array<mixed> $data the event's data as sent by the tracker
     * @param ?string $hostingProvider the provider whose network sent it, if any (see HostingNetworks)
     * @return list<string>
     */
    public static function signals(array $data, ?string $hostingProvider = null): array
    {
        $sent = array_map('trim', explode(',', is_string($data['_ml_signals'] ?? null) ? $data['_ml_signals'] : ''));
        // Only the server knows where the request comes from.
        $found = array_diff($sent, ['datacenter']);
        if ($hostingProvider !== null) {
            $found[] = 'datacenter';
        }
        if (($data['_ml_confirm'] ?? null) === 'visible') {
            $found[] = 'passive';
        }
        $browser = self::primaryLanguage($data['language'] ?? null);
        $page = self::primaryLanguage($data['page_lang'] ?? null);
        if ($browser !== null && $page !== null && $browser !== $page) {
            $found[] = 'language_mismatch';
        }
        $signals = array_values(array_filter(array_keys(self::WEIGHTS), static fn(string $signal): bool => in_array($signal, $found, true)));
        return array_map(static fn(string $signal): string => $signal === 'datacenter' ? 'datacenter:' . $hostingProvider : $signal, $signals);
    }

    /** @param array<mixed> $data */
    public static function score(array $data, ?string $hostingProvider = null): int
    {
        return array_sum(array_map(static fn(string $signal): int => self::WEIGHTS[strstr($signal, ':', true) ?: $signal], self::signals($data, $hostingProvider)));
    }

    /**
     * Why the event is rejected, as stored in bot_activity.reason
     * ("suspicious:datacenter:google_cloud+passive"), or null when it is kept.
     *
     * @param array<mixed> $data
     */
    public static function reason(array $data, ?string $hostingProvider = null): ?string
    {
        return self::score($data, $hostingProvider) >= self::THRESHOLD ? 'suspicious:' . implode('+', self::signals($data, $hostingProvider)) : null;
    }

    /** "fr" for "fr-FR" or "FR"; null when it is not a language tag. */
    private static function primaryLanguage(mixed $tag): ?string
    {
        return is_string($tag) && preg_match('/^([a-z]{2,3})(?:[-_]|$)/i', trim($tag), $m) ? strtolower($m[1]) : null;
    }
}
