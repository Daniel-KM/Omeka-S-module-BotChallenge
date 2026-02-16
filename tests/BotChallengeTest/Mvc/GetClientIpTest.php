<?php declare(strict_types=1);

namespace BotChallengeTest\Mvc;

use Laminas\Http\Header\GenericHeader;
use Laminas\Http\Headers;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Mvc\MvcEvent;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BotChallenge\Mvc\MvcListeners::getClientIp
 */
class GetClientIpTest extends TestCase
{
    /**
     * @var TestableMvcListeners
     */
    protected $listener;

    public function setUp(): void
    {
        $settings = $this->createMock(Settings::class);
        $this->listener = new TestableMvcListeners($settings);
    }

    protected function createMvcEventWithHeaders(array $headerMap, ?string $remoteAddr = null): MvcEvent
    {
        $headers = new Headers();
        foreach ($headerMap as $name => $value) {
            $headers->addHeader(GenericHeader::fromString("$name: $value"));
        }

        $request = $this->createMock(Request::class);
        $request->method('getHeaders')->willReturn($headers);
        $request->method('getServer')->willReturnCallback(
            function (string $key, $default = null) use ($remoteAddr) {
                if ($key === 'REMOTE_ADDR') {
                    return $remoteAddr ?? $default;
                }
                return $default;
            }
        );

        $event = new MvcEvent();
        $event->setRequest($request);
        return $event;
    }

    public function testRemoteAddrOnly(): void
    {
        $event = $this->createMvcEventWithHeaders([], '203.0.113.50');
        $this->assertSame('203.0.113.50', $this->listener->publicGetClientIp($event));
    }

    public function testXForwardedForSingle(): void
    {
        $event = $this->createMvcEventWithHeaders(
            ['X-Forwarded-For' => '198.51.100.10'],
            '10.0.0.1'
        );
        $this->assertSame('198.51.100.10', $this->listener->publicGetClientIp($event));
    }

    public function testXForwardedForMultiple(): void
    {
        $event = $this->createMvcEventWithHeaders(
            ['X-Forwarded-For' => '198.51.100.10, 10.0.0.1, 172.16.0.1'],
            '10.0.0.1'
        );
        $this->assertSame('198.51.100.10', $this->listener->publicGetClientIp($event));
    }

    public function testXRealIp(): void
    {
        $event = $this->createMvcEventWithHeaders(
            ['X-Real-IP' => '198.51.100.20'],
            '10.0.0.1'
        );
        $this->assertSame('198.51.100.20', $this->listener->publicGetClientIp($event));
    }

    public function testXForwardedForTakesPriorityOverXRealIp(): void
    {
        $event = $this->createMvcEventWithHeaders(
            [
                'X-Forwarded-For' => '198.51.100.10',
                'X-Real-IP' => '198.51.100.20',
            ],
            '10.0.0.1'
        );
        $this->assertSame('198.51.100.10', $this->listener->publicGetClientIp($event));
    }

    public function testFallbackToLoopbackWhenNoHeaders(): void
    {
        $event = $this->createMvcEventWithHeaders([], null);
        $this->assertSame('127.0.0.1', $this->listener->publicGetClientIp($event));
    }
}
