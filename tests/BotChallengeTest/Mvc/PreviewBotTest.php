<?php declare(strict_types=1);

namespace BotChallengeTest\Mvc;

use BotChallenge\Stdlib\Cache;
use BotChallenge\Stdlib\ReverseDns;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BotChallenge\Mvc\MvcListeners::isPreviewBot
 * @covers \BotChallenge\Mvc\MvcListeners::isVerifiedByReverseDns
 */
class PreviewBotTest extends TestCase
{
    /**
     * @var array
     */
    private $store = [];

    private function listener(): TestableMvcListeners
    {
        $config = include dirname(__DIR__, 3) . '/config/module.config.php';
        $this->store = $config['botchallenge']['config'];
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(fn ($name, $default = null) => $this->store[$name] ?? $default);
        $settings->method('set')->willReturnCallback(function ($name, $value) {
            $this->store[$name] = $value;
        });
        $listener = new TestableMvcListeners($settings);
        $namespace = 'test-' . bin2hex(random_bytes(4));
        $this->cacheDir = sys_get_temp_dir() . '/botchallenge-' . $namespace;
        $listener->fakeCache = new Cache($namespace, null, false);
        return $listener;
    }

    /**
     * @var string|null
     */
    private $cacheDir;

    protected function tearDown(): void
    {
        if ($this->cacheDir && is_dir($this->cacheDir)) {
            array_map('unlink', glob($this->cacheDir . '/{,.}*[!.]*', GLOB_BRACE) ?: []);
            rmdir($this->cacheDir);
        }
    }

    /**
     * A reverse dns counting its lookups.
     */
    private function countingDns(string $host, array $forward, int &$count): ReverseDns
    {
        return new ReverseDns(function ($ip) use ($host, &$count) {
            ++$count;
            return $host;
        }, fn ($h) => $forward);
    }

    /**
     * @dataProvider previewProvider
     */
    public function testPreviewBots(string $userAgent, bool $expected): void
    {
        $this->assertSame($expected, $this->listener()->publicIsPreviewBot($userAgent), $userAgent);
    }

    public function previewProvider(): array
    {
        return [
            'facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', true],
            'mastodon' => ['Mastodon/4.3.1 (http.rb/5.2.0; +https://mastodon.social/)', true],
            'x' => ['Twitterbot/1.0', true],
            'linkedin' => ['LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)', true],
            'telegram' => ['TelegramBot (like TwitterBot)', true],
            'browser' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', false],
            'curl' => ['curl/8.14.1', false],
            'empty' => ['', false],
        ];
    }

    public function testTelegramVerifiedByItsNetwork(): void
    {
        $listener = $this->listener();
        $listener->fakeReverseDns = new ReverseDns(fn ($ip) => 'a1.ptr.telegram.org', fn ($h) => ['149.154.161.1']);
        $this->assertTrue($listener->publicIsVerifiedByReverseDns('TelegramBot (like TwitterBot)', '149.154.161.1'));
    }

    public function testTelegramUserAgentFromAnotherNetworkIsNotVerified(): void
    {
        $listener = $this->listener();
        $listener->fakeReverseDns = new ReverseDns(fn ($ip) => 'host.scraper.example', fn ($h) => ['203.0.113.9']);
        $this->assertFalse($listener->publicIsVerifiedByReverseDns('TelegramBot (like TwitterBot)', '203.0.113.9'));
        // The spoofed user agent still gets the preview, not the whole page.
        $this->assertTrue($listener->publicIsPreviewBot('TelegramBot (like TwitterBot)'));
    }

    /**
     * A botnet using the user agent of a verified bot must not trigger two dns
     * lookups on each request.
     */
    public function testVerificationIsCachedByIp(): void
    {
        $count = 0;
        $listener = $this->listener();
        $listener->fakeReverseDns = $this->countingDns('host.scraper.example', ['203.0.113.9'], $count);
        $this->assertFalse($listener->publicIsVerifiedByReverseDns('TelegramBot', '203.0.113.9'));
        $this->assertFalse($listener->publicIsVerifiedByReverseDns('TelegramBot', '203.0.113.9'));
        $this->assertSame(1, $count);

        $listener->fakeReverseDns = $this->countingDns('a1.ptr.telegram.org', ['149.154.161.1'], $count);
        $this->assertTrue($listener->publicIsVerifiedByReverseDns('TelegramBot', '149.154.161.1'));
        $this->assertTrue($listener->publicIsVerifiedByReverseDns('TelegramBot', '149.154.161.1'));
        $this->assertSame(2, $count);
    }

    public function testExpiredCacheIsLookedUpAgain(): void
    {
        $count = 0;
        $listener = $this->listener();
        $listener->fakeReverseDns = $this->countingDns('a1.ptr.telegram.org', ['149.154.161.1'], $count);
        // An expired entry is ignored.
        file_put_contents(
            $this->cacheDir . '/' . sha1('rdns:149.154.161.1 ptr.telegram.org') . '.cache',
            serialize([time() - 1, false])
        );
        $this->assertTrue($listener->publicIsVerifiedByReverseDns('TelegramBot', '149.154.161.1'));
        $this->assertSame(1, $count);
    }

    /**
     * When the dns was slow, the lookups are paused and the bot is not
     * verified, so it gets the cheap link preview only.
     */
    public function testLookupsArePausedAfterASlowDns(): void
    {
        $count = 0;
        $listener = $this->listener();
        $listener->fakeReverseDns = $this->countingDns('a1.ptr.telegram.org', ['149.154.161.1'], $count);
        $listener->fakeCache->set('rdns:paused', true, 600);
        $this->assertFalse($listener->publicIsVerifiedByReverseDns('TelegramBot', '149.154.161.1'));
        $this->assertSame(0, $count);
    }

    public function testOtherUserAgentIsNeverLookedUp(): void
    {
        $listener = $this->listener();
        $listener->fakeReverseDns = new ReverseDns(function ($ip) {
            throw new \RuntimeException('No dns lookup expected.');
        });
        $this->assertFalse($listener->publicIsVerifiedByReverseDns('Mozilla/5.0 Chrome/130.0', '203.0.113.9'));
    }
}
