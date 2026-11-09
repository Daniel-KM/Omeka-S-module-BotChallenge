<?php declare(strict_types=1);

namespace BotChallengeTest;

use BotChallenge\Module;
use BotChallenge\Stdlib\BotIps;
use CommonTest\AbstractHttpControllerTestCase;

/**
 * Tests for the upgrade script, that merges the exception ips on each upgrade.
 */
class UpgradeTest extends AbstractHttpControllerTestCase
{
    protected array $managedSettings = [
        'botchallenge_exception_bots',
        'botchallenge_exception_ips',
        'botchallenge_exception_ips_all',
        'botchallenge_bot_ips',
    ];

    protected array $savedSettings = [];

    public function setUp(): void
    {
        parent::setUp();
        foreach ($this->managedSettings as $key) {
            $this->savedSettings[$key] = $this->settings()->get($key);
        }
    }

    public function tearDown(): void
    {
        foreach ($this->savedSettings as $key => $value) {
            $this->settings()->set($key, $value);
        }
        parent::tearDown();
    }

    protected function settings()
    {
        return $this->getApplication()->getServiceManager()->get('Omeka\Settings');
    }

    protected function upgrade(string $oldVersion = '3.4.5', string $newVersion = '3.4.8'): void
    {
        $services = $this->getApplication()->getServiceManager();
        (new Module())->upgrade($oldVersion, $newVersion, $services);
    }

    /**
     * A site whose config was never saved had no bot ranges in the merged list,
     * so the search engines were challenged.
     */
    public function testUpgradeMergesBotRangesWhenListIsEmpty(): void
    {
        $settings = $this->settings();
        $settings->set('botchallenge_exception_bots', ['googlebot', 'bingbot']);
        $settings->set('botchallenge_exception_ips', ['192.0.2.1']);
        $settings->set('botchallenge_exception_ips_all', []);
        $settings->set('botchallenge_bot_ips', []);

        $this->upgrade();

        $all = $settings->get('botchallenge_exception_ips_all');
        $this->assertContains('192.0.2.1', $all);
        foreach (['googlebot', 'bingbot'] as $bot) {
            $ips = BotIps::ips($bot);
            $this->assertNotEmpty($ips);
            $this->assertEmpty(array_diff($ips, $all), "Missing ranges of $bot.");
        }
    }

    public function testUpgradeUsesRefreshedRanges(): void
    {
        $settings = $this->settings();
        $settings->set('botchallenge_exception_bots', ['googlebot']);
        $settings->set('botchallenge_exception_ips', []);
        $settings->set('botchallenge_exception_ips_all', []);
        $settings->set('botchallenge_bot_ips', ['googlebot' => ['198.51.100.0/24']]);

        $this->upgrade();

        $this->assertSame(['198.51.100.0/24'], $settings->get('botchallenge_exception_ips_all'));
    }

    public function testUpgradeKeepsUncheckedBotsOut(): void
    {
        $settings = $this->settings();
        $settings->set('botchallenge_exception_bots', []);
        $settings->set('botchallenge_exception_ips', ['192.0.2.1', '2001:db8::/32']);
        $settings->set('botchallenge_exception_ips_all', []);
        $settings->set('botchallenge_bot_ips', []);

        $this->upgrade();

        $this->assertSame(['192.0.2.1', '2001:db8::/32'], $settings->get('botchallenge_exception_ips_all'));
    }

    public function testUpgradeIsReplayable(): void
    {
        $settings = $this->settings();
        $settings->set('botchallenge_exception_bots', ['googlebot']);
        $settings->set('botchallenge_exception_ips', ['192.0.2.1']);
        $settings->set('botchallenge_exception_ips_all', []);
        $settings->set('botchallenge_bot_ips', []);

        $this->upgrade();
        $first = $settings->get('botchallenge_exception_ips_all');
        $this->upgrade();

        $this->assertSame($first, $settings->get('botchallenge_exception_ips_all'));
    }
}
