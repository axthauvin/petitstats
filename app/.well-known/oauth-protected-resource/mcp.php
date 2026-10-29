<?php

declare(strict_types=1);

use Petitstats\Http\Http;
use Petitstats\OAuth\OAuthServer;

// RFC 9728 metadata of the MCP endpoint, at the path clients derive from /mcp.php.
require_once __DIR__ . '/../../vendor/autoload.php';
Http::allowCors('GET');
Http::json(OAuthServer::protectedResourceMetadata());
