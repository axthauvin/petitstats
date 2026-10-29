<?php

declare(strict_types=1);

namespace Petitstats\Tests\Tracking;

use Petitstats\Tracking\KnownBots;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KnownBotsTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function robots(): array
    {
        return [
            'chatgpt user' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ChatGPT-User/1.0; +https://openai.com/bot)', 'ChatGPT', KnownBots::OTHER],
            'claude user' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 'Claude', KnownBots::OTHER],
            'perplexity user' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)', 'Perplexity', KnownBots::OTHER],
            'gptbot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'GPTBot', KnownBots::OTHER],
            'claudebot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'ClaudeBot', KnownBots::OTHER],
            'oai searchbot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot', 'ChatGPT search', KnownBots::SEARCH],
            'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Google', KnownBots::SEARCH],
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'Bing', KnownBots::SEARCH],
            'slack preview' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', 'Slack', KnownBots::OTHER],
            'headless chrome' => ['Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0.0.0 Safari/537.36', 'Headless Chrome', KnownBots::AUTOMATED],
        ];
    }

    #[DataProvider('robots')]
    public function testNamesKnownRobots(string $userAgent, string $name, string $category): void
    {
        $bot = KnownBots::match($userAgent);
        $this->assertNotNull($bot);
        $this->assertSame([$name, $category], [$bot['name'], $bot['category']]);
    }

    public function testLeavesRegularBrowsersAlone(): void
    {
        $this->assertNull(KnownBots::match('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36'));
        $this->assertNull(KnownBots::match('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36 DuckDuckGo/5'));
    }

    public function testNamesUnknownRobotsAfterTheirUserAgent(): void
    {
        $this->assertSame('StackScopeBot', KnownBots::identify('Mozilla/5.0 (compatible; StackScopeBot/1.0; +https://stackscope.example)', 'known_bot_user_agent')['name']);
        $this->assertSame('python-requests', KnownBots::identify('python-requests/2.31.0', 'known_bot_user_agent')['name']);
        $this->assertSame('No user agent', KnownBots::identify('', 'missing_user_agent')['name']);
    }

    public function testBrowsersCaughtByTheScriptAreAutomated(): void
    {
        $bot = KnownBots::identify('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'client:webdriver');
        $this->assertSame(['Chrome · Linux', KnownBots::AUTOMATED], [$bot['name'], $bot['category']]);
    }
}
