<?php declare(strict_types=1);

namespace BotChallengeTest\Mvc;

use Laminas\Http\PhpEnvironment\Request;
use Laminas\Mvc\MvcEvent;
use Laminas\Stdlib\Parameters;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The ip is resolved by the service Omeka\ClientIp with the trusted proxies of
 * the main settings.
 *
 * @covers \BotChallenge\Mvc\MvcListeners::getClientIp
 */
class GetClientIpTest extends TestCase
{
    protected function listener(array $trustedProxies = []): TestableMvcListeners
    {
        return new TestableMvcListeners($this->createMock(Settings::class), $trustedProxies);
    }

    protected function event(array $server): MvcEvent
    {
        $request = new Request();
        $request->setServer(new Parameters($server));
        $event = new MvcEvent();
        $event->setRequest($request);
        return $event;
    }

    public function testRemoteAddrOnly(): void
    {
        $event = $this->event(['REMOTE_ADDR' => '203.0.113.50']);
        $this->assertSame('203.0.113.50', $this->listener()->publicGetClientIp($event));
    }

    public function testHeadersFromPublicPeerAreIgnored(): void
    {
        // Without proxy, a visitor may write an excepted ip in the headers.
        $event = $this->event([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
            'HTTP_X_REAL_IP' => '198.51.100.20',
        ]);
        $this->assertSame('203.0.113.50', $this->listener()->publicGetClientIp($event));
    }

    public function testLocalProxyAndForgedLeftPart(): void
    {
        $event = $this->event([
            'REMOTE_ADDR' => '172.18.0.2',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 203.0.113.50',
        ]);
        $this->assertSame('203.0.113.50', $this->listener()->publicGetClientIp($event));
    }

    public function testTrustedProxies(): void
    {
        $event = $this->event([
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        ]);
        $this->assertSame('203.0.113.50', $this->listener(['192.0.2.0/24'])->publicGetClientIp($event));
        $this->assertSame('192.0.2.10', $this->listener(['10.0.0.1'])->publicGetClientIp($event));
    }

    public function testUnknownPeer(): void
    {
        $this->assertSame('::', $this->listener()->publicGetClientIp($this->event([])));
    }
}
