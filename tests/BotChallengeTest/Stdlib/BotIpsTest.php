<?php declare(strict_types=1);

namespace BotChallengeTest\Stdlib;

use BotChallenge\Stdlib\BotIps;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests of the registry of the search engines and of their bundled ranges.
 */
class BotIpsTest extends TestCase
{
    public function testEachBotHasALabelAndABundledList(): void
    {
        foreach (BotIps::BOTS as $bot => $data) {
            $this->assertNotEmpty($data['label'], "No label for $bot.");
            $this->assertContains($data['source'], ['json', 'goodbots', 'static'], "Unknown source for $bot.");
            $this->assertFileExists(BotIps::path($bot), "No bundled ranges for $bot.");
            $this->assertNotEmpty(BotIps::ips($bot), "Empty bundled ranges for $bot.");
        }
    }

    public function testDefaultBotsAreKnown(): void
    {
        foreach (BotIps::DEFAULT_BOTS as $bot) {
            $this->assertArrayHasKey($bot, BotIps::BOTS);
        }
    }

    public function testBundledRangesAreValidIpsOrCidr(): void
    {
        foreach (array_keys(BotIps::BOTS) as $bot) {
            foreach (BotIps::ips($bot) as $ip) {
                [$address, $prefix] = array_pad(explode('/', $ip, 2), 2, null);
                $this->assertNotFalse(filter_var($address, FILTER_VALIDATE_IP), "Invalid ip in $bot: $ip");
                if ($prefix !== null) {
                    $max = strpos($address, ':') === false ? 32 : 128;
                    $this->assertTrue(ctype_digit($prefix) && (int) $prefix <= $max, "Invalid prefix in $bot: $ip");
                }
            }
        }
    }

    public function testIpsForBotsMergesAndDeduplicates(): void
    {
        $google = BotIps::ips('googlebot');
        $bing = BotIps::ips('bingbot');
        $merged = BotIps::ipsForBots(['googlebot', 'bingbot', 'googlebot']);

        $this->assertSame(count(array_unique(array_merge($google, $bing))), count($merged));
        $this->assertSame($merged, array_values(array_unique($merged)));
    }

    public function testOverrideTakesPrecedenceOverBundledFile(): void
    {
        $override = ['googlebot' => ['1.2.3.0/24', '4.5.6.7']];
        $merged = BotIps::ipsForBots(['googlebot', 'bingbot'], $override);

        // The bundled googlebot ranges are replaced by the override, the
        // bingbot ones still come from its file.
        $this->assertContains('1.2.3.0/24', $merged);
        $this->assertContains('4.5.6.7', $merged);
        foreach (BotIps::ips('googlebot') as $bundled) {
            $this->assertNotContains($bundled, $merged);
        }
        $this->assertSame(
            count($override['googlebot']) + count(BotIps::ips('bingbot')),
            count($merged)
        );
    }

    public function testEmptyOverrideFallsBackToBundledFiles(): void
    {
        $this->assertSame(
            BotIps::ipsForBots(['googlebot']),
            BotIps::ipsForBots(['googlebot'], [])
        );
    }

    public function testUnknownBotHasNoIpAndNoSource(): void
    {
        $this->assertSame([], BotIps::ips('ahrefsbot'));
        $this->assertNull(BotIps::sourceUrl('ahrefsbot'));
    }

    /**
     * The exemption of a cdn range would exempt anyone routing requests through
     * it, and a commercial seo crawler is precisely what the challenge blocks.
     */
    public function testExcludedCrawlersAreNotBundled(): void
    {
        foreach (['ahrefsbot', 'semrushbot', 'commoncrawl', 'cloudflare', 'fastly'] as $excluded) {
            $this->assertArrayNotHasKey($excluded, BotIps::BOTS);
        }
    }

    public function testSourceUrlByKindOfSource(): void
    {
        $this->assertStringContainsString('developers.google.com', (string) BotIps::sourceUrl('googlebot'));
        $this->assertStringContainsString('GoodBots', (string) BotIps::sourceUrl('marginalia'));
        // Qwant publishes no list: the range is static, so it is never fetched.
        $this->assertNull(BotIps::sourceUrl('qwant'));
    }

    public function testValueOptionsAreLabelsByName(): void
    {
        $valueOptions = BotIps::valueOptions();
        $this->assertSame(array_keys(BotIps::BOTS), array_keys($valueOptions));
        $this->assertSame('Googlebot (Google)', $valueOptions['googlebot']);
    }
}
