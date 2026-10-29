<?php

declare(strict_types=1);

namespace Petitstats\OAuth;

use RuntimeException;

/** An OAuth error, with its RFC 6749 code (`invalid_grant`, `access_denied`…). */
final class OAuthException extends RuntimeException
{
    /**
     * @param ?string $redirectUri where an authorization error can be sent back to
     *                             the client; null when the redirect URI itself cannot be trusted
     */
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
        public readonly ?string $redirectUri = null,
        public readonly ?string $state = null,
    ) {
        parent::__construct($description);
    }

    /** @return array{error: string, error_description: string} */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
