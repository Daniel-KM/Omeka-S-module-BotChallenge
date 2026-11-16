<?php declare(strict_types=1);

namespace BotChallengeTest\Stdlib;

use BotChallenge\Stdlib\ReverseDns;
use PHPUnit\Framework\TestCase;

class ReverseDnsTest extends TestCase
{
    private function dns(string $host, array $forward): ReverseDns
    {
        return new ReverseDns(fn ($ip) => $host, fn ($h) => $h === rtrim($host, '.') ? $forward : []);
    }

    public function testHostOfTheDomainResolvingBackIsVerified(): void
    {
        $this->assertTrue($this->dns('a1.ptr.telegram.org', ['149.154.161.1'])->verify('149.154.161.1', 'ptr.telegram.org'));
        $this->assertTrue($this->dns('a1.ptr.telegram.org.', ['149.154.161.1'])->verify('149.154.161.1', 'ptr.telegram.org'));
    }

    public function testIpv6IsComparedInBinary(): void
    {
        $this->assertTrue($this->dns('x.ptr.telegram.org', ['2001:db8::1'])->verify('2001:0db8:0:0::1', 'ptr.telegram.org'));
    }

    public function testHostOutsideTheDomainIsRejected(): void
    {
        // A lookalike suffix without the label boundary.
        $this->assertFalse($this->dns('evilptr.telegram.org', ['149.154.161.1'])->verify('149.154.161.1', 'ptr.telegram.org'));
        $this->assertFalse($this->dns('ptr.telegram.org.evil.example', ['149.154.161.1'])->verify('149.154.161.1', 'ptr.telegram.org'));
    }

    /**
     * Anybody owning an ip can set its reverse record, so the forward record of
     * the domain owner must confirm it.
     */
    public function testReverseRecordNotConfirmedIsRejected(): void
    {
        $this->assertFalse($this->dns('a1.ptr.telegram.org', ['149.154.161.2'])->verify('149.154.161.1', 'ptr.telegram.org'));
        $this->assertFalse($this->dns('a1.ptr.telegram.org', [])->verify('149.154.161.1', 'ptr.telegram.org'));
    }

    public function testMissingReverseRecordOrInvalidInputIsRejected(): void
    {
        // Without record, gethostbyaddr() returns the ip itself.
        $this->assertFalse($this->dns('149.154.161.1', ['149.154.161.1'])->verify('149.154.161.1', 'ptr.telegram.org'));
        $this->assertFalse($this->dns('', [])->verify('149.154.161.1', 'ptr.telegram.org'));
        $this->assertFalse($this->dns('a1.ptr.telegram.org', ['149.154.161.1'])->verify('not-an-ip', 'ptr.telegram.org'));
        $this->assertFalse($this->dns('a1.ptr.telegram.org', ['149.154.161.1'])->verify('149.154.161.1', ''));
    }
}
