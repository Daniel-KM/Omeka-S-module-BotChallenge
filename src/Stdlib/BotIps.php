<?php declare(strict_types=1);

namespace BotChallenge\Stdlib;

/**
 * Registry of the search engine bots whose ip ranges are bundled, and of the
 * sources used to refresh them.
 *
 * The module exempts by ip/cidr only: a user agent is declarative, so it cannot
 * be opposed to a scraper that presents itself as a browser. The only usable
 * criterion is the membership of a range published by the operator of the bot.
 *
 * Sources are first-hand when the operator publishes one (Google, Bing and
 * Apple publish a json), else the GoodBots aggregate, that republishes the
 * official lists daily.
 *
 * Deliberately not included: Common Crawl (training corpus, not a search
 * engine), the commercial seo crawlers (Ahrefs, Semrush), the cdn (exempting a
 * cdn range exempts anyone routing requests through it), the monitoring
 * services and the webhooks.
 */
class BotIps
{
    const GOODBOTS_URL = 'https://raw.githubusercontent.com/AnTheMaker/GoodBots/main/iplists/{bot}.ips';

    /**
     * Label and source of each bot, by name.
     *
     * The source is "json" for a Google-style json with a key "prefixes",
     * "goodbots" for a plain list of ranges from the aggregate, and "static"
     * when the operator publishes nothing exploitable.
     */
    const BOTS = [
        'googlebot' => [
            'label' => 'Googlebot (Google)', // @translate
            'source' => 'json',
            'url' => 'https://developers.google.com/search/apis/ipranges/googlebot.json',
        ],
        'bingbot' => [
            'label' => 'Bingbot (Microsoft)', // @translate
            'source' => 'json',
            'url' => 'https://www.bing.com/toolbox/bingbot.json',
        ],
        'applebot' => [
            'label' => 'Applebot (Apple)', // @translate
            'source' => 'json',
            'url' => 'https://search.developer.apple.com/applebot.json',
        ],
        'duckduckbot' => [
            'label' => 'DuckDuckBot (DuckDuckGo)', // @translate
            'source' => 'goodbots',
        ],
        'qwant' => [
            // Qwant publishes no list: the prefix announced by its autonomous
            // system is used (AS199064, holder "QWANT-AS QWANT SAS"), so the
            // whole network of Qwant is exempted, not only its bot.
            'label' => 'Qwant', // @translate
            'source' => 'static',
        ],
        'mojeekbot' => [
            'label' => 'MojeekBot (Mojeek)', // @translate
            'source' => 'goodbots',
        ],
        'marginalia' => [
            'label' => 'Marginalia', // @translate
            'source' => 'goodbots',
        ],
        'yandex' => [
            'label' => 'Yandex', // @translate
            'source' => 'goodbots',
        ],
    ];

    /**
     * Bots exempted by default: the ones that are identifiable and that bring
     * visitors back to the site.
     */
    const DEFAULT_BOTS = [
        'googlebot',
        'bingbot',
        'applebot',
        'duckduckbot',
        'qwant',
        'mojeekbot',
        'marginalia',
    ];

    /**
     * Value options for a select or a multicheckbox.
     */
    public static function valueOptions(): array
    {
        return array_map(fn ($bot) => $bot['label'], self::BOTS);
    }

    /**
     * Source url to refresh the ranges of a bot, or null when it is static.
     */
    public static function sourceUrl(string $bot): ?string
    {
        $source = self::BOTS[$bot]['source'] ?? null;
        if ($source === 'goodbots') {
            return strtr(self::GOODBOTS_URL, ['{bot}' => $bot]);
        }
        return $source === 'json'
            ? self::BOTS[$bot]['url']
            : null;
    }

    public static function isJsonSource(string $bot): bool
    {
        return (self::BOTS[$bot]['source'] ?? null) === 'json';
    }

    /**
     * Ip ranges bundled for a bot.
     *
     * @return string[]
     */
    public static function ips(string $bot): array
    {
        if (!isset(self::BOTS[$bot])) {
            return [];
        }
        $filepath = self::path($bot);
        if (!is_readable($filepath)) {
            return [];
        }
        $ips = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $ips === false
            ? []
            : array_values(array_filter(array_map('trim', $ips), fn ($ip) => $ip !== '' && $ip[0] !== '#'));
    }

    /**
     * Ip ranges of the specified bots, deduplicated.
     *
     * The modules are read-only in production, so the refreshed ranges are
     * stored in the settings, not on disk: the "override" map (bot => ranges),
     * when it holds an entry for a bot, takes precedence over the bundled file.
     *
     * @param string[] $bots
     * @param array<string, string[]> $override
     * @return string[]
     */
    public static function ipsForBots(array $bots, array $override = []): array
    {
        $ips = [];
        foreach ($bots as $bot) {
            $ips = array_merge($ips, $override[$bot] ?? self::ips($bot));
        }
        return array_values(array_unique($ips));
    }

    public static function path(string $bot): string
    {
        return dirname(__DIR__, 2) . '/data/bot-ips/' . $bot . '.txt';
    }
}
