<?php

declare(strict_types=1);

namespace Petitstats\Tests\Tracking;

use Petitstats\Tracking\UserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserAgentTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function bots(): array
    {
        return [
            'crawler' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'curl' => ['curl/8.5.0'],
            'python-requests' => ['python-requests/2.31.0'],
            'aiohttp' => ['Python/3.12 aiohttp/3.9.1'],
            'axios' => ['axios/1.6.0'],
            'node-fetch' => ['node-fetch/1.0 (+https://github.com/bitinn/node-fetch)'],
            'go' => ['Go-http-client/1.1'],
            'okhttp' => ['okhttp/4.12.0'],
            'java' => ['Java/17.0.2'],
            'scrapy' => ['Scrapy/2.11.0 (+https://scrapy.org)'],
            'headless chrome' => ['Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0.0.0 Safari/537.36'],
            'claude user' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +Claude-User@anthropic.com)'],
            'perplexity user' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function browsers(): array
    {
        return [
            'chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36'],
            'firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0'],
            'safari ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'],
            'android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36'],
        ];
    }

    #[DataProvider('bots')]
    public function testRecognisesBots(string $value): void
    {
        $this->assertSame('known_bot_user_agent', (new UserAgent($value))->botReason());
    }

    #[DataProvider('browsers')]
    public function testLetsRegularBrowsersThrough(string $value): void
    {
        $this->assertNull((new UserAgent($value))->botReason());
    }

    public function testAMissingUserAgentIsSuspicious(): void
    {
        $this->assertSame('missing_user_agent', (new UserAgent(''))->botReason());
    }
}
