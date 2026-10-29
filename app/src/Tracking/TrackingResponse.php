<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

/** What track.php sends back to the tracker. */
final class TrackingResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {}
}
