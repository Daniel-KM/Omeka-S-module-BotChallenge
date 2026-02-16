<?php declare(strict_types=1);

namespace BotChallengeTest\Mvc;

use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BotChallenge\Mvc\MvcListeners::ipInCidr
 */
class IpInCidrTest extends TestCase
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

    /**
     * @dataProvider ipCidrProvider
     */
    public function testIpInCidr(string $ip, string $cidr, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->listener->publicIpInCidr($ip, $cidr),
            sprintf('ipInCidr("%s", "%s") should return %s', $ip, $cidr, $expected ? 'true' : 'false')
        );
    }

    public function ipCidrProvider(): array
    {
        return [
            'IPv4 exact match' => [
                '192.168.1.1', '192.168.1.1', true,
            ],
            'IPv4 exact no match' => [
                '192.168.1.1', '192.168.1.2', false,
            ],
            'IPv4 /24 match' => [
                '192.168.1.100', '192.168.1.0/24', true,
            ],
            'IPv4 /24 no match' => [
                '192.168.2.1', '192.168.1.0/24', false,
            ],
            'IPv4 /32 single host' => [
                '10.0.0.1', '10.0.0.1/32', true,
            ],
            'IPv4 /32 no match' => [
                '10.0.0.2', '10.0.0.1/32', false,
            ],
            'IPv4 /16' => [
                '172.16.5.1', '172.16.0.0/16', true,
            ],
            'IPv4 /0 match all' => [
                '8.8.8.8', '0.0.0.0/0', true,
            ],
            'IPv6 exact match' => [
                '::1', '::1', true,
            ],
            'IPv6 exact no match' => [
                '::1', '::2', false,
            ],
            'IPv6 /64 match' => [
                '2001:db8::1', '2001:db8::/64', true,
            ],
            'IPv6 /64 no match' => [
                '2001:db9::1', '2001:db8::/64', false,
            ],
            'IPv6 /128 single host' => [
                '2001:db8::1', '2001:db8::1/128', true,
            ],
            'Mixed IPv4/IPv6' => [
                '192.168.1.1', '2001:db8::/64', false,
            ],
            'Invalid IP' => [
                'not-an-ip', '192.168.1.0/24', false,
            ],
            'Invalid CIDR range' => [
                '192.168.1.1', 'invalid/24', false,
            ],
            'CIDR bits too large (IPv4)' => [
                '192.168.1.1', '192.168.1.0/33', false,
            ],
            'CIDR bits negative' => [
                '192.168.1.1', '192.168.1.0/-1', false,
            ],
            'Localhost IPv4' => [
                '127.0.0.1', '127.0.0.0/8', true,
            ],
            'Localhost IPv6' => [
                '::1', '::1/128', true,
            ],
        ];
    }
}
