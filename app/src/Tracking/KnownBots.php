<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

/**
 * Names the robots that announce themselves in their user agent: who runs
 * them and what they come for. Used both to reject them at ingestion and to
 * group the rejected requests on the dashboard.
 */
final class KnownBots
{
    /** Search engines rendering the page to index it, AI search included. */
    public const SEARCH = 'search';
    /** Browsers driven by a script (headless, Selenium, Playwright...). */
    public const AUTOMATED = 'automated';
    /** SEO and monitoring tools, AI assistants and crawlers, link previews, HTTP libraries and unknown robots. */
    public const OTHER = 'other';

    /**
     * Only robots that run the tracking script reach the report, so most of
     * them are search engines rendering the page or automated browsers. The
     * others are rare enough to share one category.
     */
    public const CATEGORIES = [self::SEARCH, self::AUTOMATED, self::OTHER];

    /**
     * Checked in order, so specific tokens come before generic ones.
     * Each entry: user agent token (regex fragment), product, operator, operator's domain, category.
     */
    private const BOTS = [
        // AI assistants fetching a page for a user
        ['chatgpt-user', 'ChatGPT', 'OpenAI', 'openai.com', self::OTHER],
        ['claude-user', 'Claude', 'Anthropic', 'claude.ai', self::OTHER],
        ['perplexity-user', 'Perplexity', 'Perplexity', 'perplexity.ai', self::OTHER],
        ['mistralai-user', 'Le Chat', 'Mistral AI', 'mistral.ai', self::OTHER],
        ['duckassistbot', 'DuckAssist', 'DuckDuckGo', 'duckduckgo.com', self::OTHER],
        ['meta-externalfetcher', 'Meta AI', 'Meta', 'meta.ai', self::OTHER],
        ['google-notebooklm', 'NotebookLM', 'Google', 'notebooklm.google.com', self::OTHER],
        ['youbot', 'You.com', 'You.com', 'you.com', self::OTHER],
        // AI search indexes
        ['oai-searchbot', 'ChatGPT search', 'OpenAI', 'openai.com', self::SEARCH],
        ['claude-searchbot', 'Claude search', 'Anthropic', 'claude.ai', self::SEARCH],
        ['perplexitybot', 'Perplexity', 'Perplexity', 'perplexity.ai', self::SEARCH],
        // AI training crawlers
        ['gptbot', 'GPTBot', 'OpenAI', 'openai.com', self::OTHER],
        ['claudebot|anthropic-ai|claude-web', 'ClaudeBot', 'Anthropic', 'claude.ai', self::OTHER],
        ['ccbot', 'Common Crawl', 'Common Crawl', 'commoncrawl.org', self::OTHER],
        ['bytespider', 'Bytespider', 'ByteDance', 'bytedance.com', self::OTHER],
        ['meta-externalagent', 'Meta AI', 'Meta', 'meta.ai', self::OTHER],
        ['amazonbot', 'Amazonbot', 'Amazon', 'amazon.com', self::OTHER],
        ['applebot-extended', 'Applebot', 'Apple', 'apple.com', self::OTHER],
        ['cohere-ai|cohere-training', 'Cohere', 'Cohere', 'cohere.com', self::OTHER],
        ['ai2bot', 'AI2Bot', 'Allen Institute for AI', 'allenai.org', self::OTHER],
        ['diffbot', 'Diffbot', 'Diffbot', 'diffbot.com', self::OTHER],
        ['timpibot', 'Timpibot', 'Timpi', 'timpi.io', self::OTHER],
        ['omgili', 'Webz.io', 'Webz.io', 'webz.io', self::OTHER],
        // Search engines
        ['googlebot|google-inspectiontool|adsbot-google|storebot-google|mediapartners-google|googleother', 'Google', 'Google', 'google.com', self::SEARCH],
        ['bingbot|bingpreview|adidxbot', 'Bing', 'Microsoft', 'bing.com', self::SEARCH],
        ['applebot', 'Apple', 'Apple', 'apple.com', self::SEARCH],
        ['duckduckbot', 'DuckDuckGo', 'DuckDuckGo', 'duckduckgo.com', self::SEARCH],
        ['yandexbot|yandex\\.com/bots', 'Yandex', 'Yandex', 'yandex.com', self::SEARCH],
        ['baiduspider', 'Baidu', 'Baidu', 'baidu.com', self::SEARCH],
        ['qwantbot|qwantify', 'Qwant', 'Qwant', 'qwant.com', self::SEARCH],
        ['seznambot', 'Seznam', 'Seznam', 'seznam.cz', self::SEARCH],
        ['petalbot', 'Petal Search', 'Huawei', 'petalsearch.com', self::SEARCH],
        ['slurp', 'Yahoo', 'Yahoo', 'yahoo.com', self::SEARCH],
        ['mojeekbot', 'Mojeek', 'Mojeek', 'mojeek.com', self::SEARCH],
        // Link previews
        ['facebookexternalhit|facebookcatalog', 'Facebook', 'Meta', 'facebook.com', self::OTHER],
        ['twitterbot', 'X (Twitter)', 'X', 'x.com', self::OTHER],
        ['linkedinbot', 'LinkedIn', 'LinkedIn', 'linkedin.com', self::OTHER],
        ['slackbot|slack-imgproxy', 'Slack', 'Slack', 'slack.com', self::OTHER],
        ['discordbot', 'Discord', 'Discord', 'discord.com', self::OTHER],
        ['telegrambot', 'Telegram', 'Telegram', 'telegram.org', self::OTHER],
        ['whatsapp', 'WhatsApp', 'Meta', 'whatsapp.com', self::OTHER],
        ['pinterestbot', 'Pinterest', 'Pinterest', 'pinterest.com', self::OTHER],
        ['redditbot', 'Reddit', 'Reddit', 'reddit.com', self::OTHER],
        ['skypeuripreview', 'Skype', 'Microsoft', 'skype.com', self::OTHER],
        ['embedly', 'Embedly', 'Embedly', 'embed.ly', self::OTHER],
        ['iframely', 'Iframely', 'Iframely', 'iframely.com', self::OTHER],
        // SEO and monitoring
        ['ahrefsbot|ahrefssiteaudit', 'Ahrefs', 'Ahrefs', 'ahrefs.com', self::OTHER],
        ['semrushbot', 'Semrush', 'Semrush', 'semrush.com', self::OTHER],
        ['mj12bot', 'Majestic', 'Majestic', 'majestic.com', self::OTHER],
        ['dotbot', 'Moz', 'Moz', 'moz.com', self::OTHER],
        ['screaming frog', 'Screaming Frog', 'Screaming Frog', 'screamingfrog.co.uk', self::OTHER],
        ['lighthouse|pagespeed', 'Lighthouse', 'Google', 'developer.chrome.com', self::OTHER],
        ['uptimerobot', 'UptimeRobot', 'UptimeRobot', 'uptimerobot.com', self::OTHER],
        ['pingdom', 'Pingdom', 'SolarWinds', 'pingdom.com', self::OTHER],
        ['statuscake', 'StatusCake', 'StatusCake', 'statuscake.com', self::OTHER],
        ['betteruptime|better stack', 'Better Stack', 'Better Stack', 'betterstack.com', self::OTHER],
        ['site24x7', 'Site24x7', 'Zoho', 'site24x7.com', self::OTHER],
        // Automated browsers
        ['headlesschrome', 'Headless Chrome', '', '', self::AUTOMATED],
        ['phantomjs', 'PhantomJS', '', '', self::AUTOMATED],
        ['puppeteer', 'Puppeteer', '', '', self::AUTOMATED],
        ['playwright', 'Playwright', '', '', self::AUTOMATED],
        ['selenium', 'Selenium', '', '', self::AUTOMATED],
    ];

    /**
     * The robot this user agent belongs to, when it is a known one.
     *
     * @return array{name: string, operator: string, domain: string, category: string}|null
     */
    public static function match(string $userAgent): ?array
    {
        foreach (self::BOTS as [$token, $name, $operator, $domain, $category]) {
            if (preg_match('~' . $token . '~i', $userAgent)) {
                return ['name' => $name, 'operator' => $operator, 'domain' => $domain, 'category' => $category];
            }
        }
        return null;
    }

    /**
     * Who sent a rejected request, as shown on the dashboard. Unknown robots
     * are named after the product token of their user agent, and browsers
     * caught by the tracking script after their browser and system.
     *
     * @param string $reason the reason the request was rejected (see bot_activity.reason)
     * @return array{name: string, operator: string, domain: string, category: string, browser?: string}
     */
    public static function identify(string $userAgent, string $reason): array
    {
        if ($userAgent === '') {
            return ['name' => 'No user agent', 'operator' => '', 'domain' => '', 'category' => self::OTHER];
        }
        if (($bot = self::match($userAgent)) !== null) {
            return $bot;
        }
        if ($reason !== 'known_bot_user_agent') {
            // A regular browser user agent, caught by the tracking script.
            $agent = new UserAgent($userAgent);
            return ['name' => $agent->browser() . ' · ' . $agent->os(), 'operator' => '', 'domain' => '', 'category' => self::AUTOMATED, 'browser' => $agent->browser()];
        }
        return ['name' => self::productName($userAgent), 'operator' => '', 'domain' => '', 'category' => self::OTHER];
    }

    /** "StackScopeBot" in "Mozilla/5.0 (compatible; StackScopeBot/1.0; ...)", "curl" in "curl/8.5.0". */
    private static function productName(string $userAgent): string
    {
        if (preg_match('/([a-z][\w.-]*(?:bot|crawler|spider|fetcher|scraper))\b/i', $userAgent, $m)) {
            return $m[1];
        }
        if (preg_match('/^([a-z][\w.-]*)\//i', $userAgent, $m) && strcasecmp($m[1], 'Mozilla') !== 0) {
            return $m[1];
        }
        return 'Unknown robot';
    }
}
