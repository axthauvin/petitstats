<?php

declare(strict_types=1);

namespace Petitstats\Analytics;

/** Human-friendly names for the referrers people actually recognise. */
final class TrafficSource
{
    /** @var array<string, array{name: string, domain: string, url: string, category: string, hosts: string[]}> */
    private const KNOWN = [
        'google' => ['name' => 'Google', 'domain' => 'google.com', 'url' => 'https://www.google.com', 'category' => 'search', 'hosts' => ['google.com']],
        'bing' => ['name' => 'Bing', 'domain' => 'bing.com', 'url' => 'https://www.bing.com', 'category' => 'search', 'hosts' => ['bing.com']],
        'duckduckgo' => ['name' => 'DuckDuckGo', 'domain' => 'duckduckgo.com', 'url' => 'https://duckduckgo.com', 'category' => 'search', 'hosts' => ['duckduckgo.com']],
        'brave-search' => ['name' => 'Brave Search', 'domain' => 'search.brave.com', 'url' => 'https://search.brave.com', 'category' => 'search', 'hosts' => ['search.brave.com']],
        'kagi' => ['name' => 'Kagi', 'domain' => 'kagi.com', 'url' => 'https://kagi.com', 'category' => 'search', 'hosts' => ['kagi.com']],
        'chatgpt' => ['name' => 'ChatGPT', 'domain' => 'chatgpt.com', 'url' => 'https://chatgpt.com', 'category' => 'ai', 'hosts' => ['chatgpt.com', 'chat.openai.com']],
        'claude' => ['name' => 'Claude', 'domain' => 'claude.ai', 'url' => 'https://claude.ai', 'category' => 'ai', 'hosts' => ['claude.ai']],
        'perplexity' => ['name' => 'Perplexity', 'domain' => 'perplexity.ai', 'url' => 'https://www.perplexity.ai', 'category' => 'ai', 'hosts' => ['perplexity.ai']],
        'gemini' => ['name' => 'Gemini', 'domain' => 'gemini.google.com', 'url' => 'https://gemini.google.com', 'category' => 'ai', 'hosts' => ['gemini.google.com']],
        'copilot' => ['name' => 'Copilot', 'domain' => 'copilot.microsoft.com', 'url' => 'https://copilot.microsoft.com', 'category' => 'ai', 'hosts' => ['copilot.microsoft.com']],
        'grok' => ['name' => 'Grok', 'domain' => 'grok.com', 'url' => 'https://grok.com', 'category' => 'ai', 'hosts' => ['grok.com']],
        'meta-ai' => ['name' => 'Meta AI', 'domain' => 'meta.ai', 'url' => 'https://www.meta.ai', 'category' => 'ai', 'hosts' => ['meta.ai']],
        'mistral' => ['name' => 'Mistral Le Chat', 'domain' => 'chat.mistral.ai', 'url' => 'https://chat.mistral.ai', 'category' => 'ai', 'hosts' => ['mistral.ai']],
        'deepseek' => ['name' => 'DeepSeek', 'domain' => 'deepseek.com', 'url' => 'https://chat.deepseek.com', 'category' => 'ai', 'hosts' => ['deepseek.com']],
        'poe' => ['name' => 'Poe', 'domain' => 'poe.com', 'url' => 'https://poe.com', 'category' => 'ai', 'hosts' => ['poe.com']],
        'huggingchat' => ['name' => 'HuggingChat', 'domain' => 'huggingface.co', 'url' => 'https://huggingface.co/chat', 'category' => 'ai', 'hosts' => ['huggingface.co']],
        'facebook' => ['name' => 'Facebook', 'domain' => 'facebook.com', 'url' => 'https://www.facebook.com', 'category' => 'social', 'hosts' => ['facebook.com']],
        'instagram' => ['name' => 'Instagram', 'domain' => 'instagram.com', 'url' => 'https://www.instagram.com', 'category' => 'social', 'hosts' => ['instagram.com']],
        'linkedin' => ['name' => 'LinkedIn', 'domain' => 'linkedin.com', 'url' => 'https://www.linkedin.com', 'category' => 'social', 'hosts' => ['linkedin.com']],
        'x' => ['name' => 'X', 'domain' => 'x.com', 'url' => 'https://x.com', 'category' => 'social', 'hosts' => ['x.com', 'twitter.com']],
        'reddit' => ['name' => 'Reddit', 'domain' => 'reddit.com', 'url' => 'https://www.reddit.com', 'category' => 'social', 'hosts' => ['reddit.com']],
        'tiktok' => ['name' => 'TikTok', 'domain' => 'tiktok.com', 'url' => 'https://www.tiktok.com', 'category' => 'social', 'hosts' => ['tiktok.com']],
        'youtube' => ['name' => 'YouTube', 'domain' => 'youtube.com', 'url' => 'https://www.youtube.com', 'category' => 'social', 'hosts' => ['youtube.com', 'youtu.be']],
        'gmail' => ['name' => 'Gmail', 'domain' => 'mail.google.com', 'url' => 'https://mail.google.com', 'category' => 'email', 'hosts' => ['mail.google.com', 'gmail.com']],
        'outlook' => ['name' => 'Outlook', 'domain' => 'outlook.com', 'url' => 'https://outlook.com', 'category' => 'email', 'hosts' => ['outlook.com', 'outlook.office.com']],
    ];

    /** @param array<string, mixed> $data @return array{id: string, name: string, domain: ?string, url: ?string, category: string} */
    public static function fromData(array $data): array
    {
        $referrer = trim((string) ($data['referrer'] ?? ''));
        if ($referrer !== '' && strtolower($referrer) !== 'null') {
            return self::fromValue($referrer, 'referral');
        }

        $utm = is_array($data['utm'] ?? null) ? $data['utm'] : [];
        $source = trim((string) ($data['utm_source'] ?? $utm['utm_source'] ?? ''));
        return $source !== '' ? self::fromValue($source, 'campaign') : self::direct();
    }

    /** @return array{id: string, name: string, domain: ?string, url: ?string, category: string} */
    private static function fromValue(string $value, string $fallbackCategory): array
    {
        $host = self::host($value);
        if (preg_match('/(^|\.)google\.[a-z.]+$/', $host)) {
            $source = self::KNOWN['google'];
            return ['id' => 'google'] + array_diff_key($source, ['hosts' => true]);
        }
        foreach (self::KNOWN as $id => $source) {
            foreach ($source['hosts'] as $knownHost) {
                if ($host === $knownHost || str_ends_with($host, '.' . $knownHost)) {
                    return ['id' => $id] + array_diff_key($source, ['hosts' => true]);
                }
            }
        }
        if ($host !== '') {
            return ['id' => $host, 'name' => $host, 'domain' => $host, 'url' => 'https://' . $host, 'category' => $fallbackCategory];
        }
        return ['id' => 'utm:' . strtolower($value), 'name' => $value, 'domain' => null, 'url' => null, 'category' => $fallbackCategory];
    }

    /** @return array{id: string, name: string, domain: ?string, url: ?string, category: string} */
    private static function direct(): array
    {
        return ['id' => 'direct', 'name' => 'Direct / None', 'domain' => null, 'url' => null, 'category' => 'direct'];
    }

    private static function host(string $value): string
    {
        $value = strtolower(trim($value));
        $url = str_contains($value, '://') ? $value : 'https://' . $value;
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        return preg_replace('/^www\./', '', $host) ?? '';
    }
}
