<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

use RuntimeException;

/** A rejected tracking request, with the HTTP status to answer. */
final class TrackingException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
