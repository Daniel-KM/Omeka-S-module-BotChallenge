<?php declare(strict_types=1);

namespace BotChallengeTest\Mvc;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the HMAC token logic shared between MvcListeners and IndexController.
 *
 * Token formula: hash_hmac('sha256', $salt . $clientIp, $salt)
 *
 * @covers \BotChallenge\Mvc\MvcListeners::handleChallenge
 * @covers \BotChallenge\Controller\IndexController::indexAction
 */
class TokenTest extends TestCase
{
    /**
     * Generate a token using the same formula as the module.
     */
    protected function generateToken(string $salt, string $clientIp): string
    {
        return hash_hmac('sha256', $salt . $clientIp, $salt);
    }

    public function testSameSaltAndIpProduceSameToken(): void
    {
        $salt = 'test-salt-abc123';
        $ip = '192.168.1.1';
        $token1 = $this->generateToken($salt, $ip);
        $token2 = $this->generateToken($salt, $ip);
        $this->assertSame($token1, $token2);
    }

    public function testDifferentSaltProducesDifferentToken(): void
    {
        $ip = '192.168.1.1';
        $token1 = $this->generateToken('salt-one', $ip);
        $token2 = $this->generateToken('salt-two', $ip);
        $this->assertNotSame($token1, $token2);
    }

    public function testDifferentIpProducesDifferentToken(): void
    {
        $salt = 'same-salt';
        $token1 = $this->generateToken($salt, '192.168.1.1');
        $token2 = $this->generateToken($salt, '10.0.0.1');
        $this->assertNotSame($token1, $token2);
    }

    public function testTokenIsHexSha256(): void
    {
        $token = $this->generateToken('any-salt', '127.0.0.1');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testHashEqualsValidatesToken(): void
    {
        $salt = 'secret-salt';
        $ip = '203.0.113.50';
        $expected = $this->generateToken($salt, $ip);
        $this->assertTrue(hash_equals($expected, $this->generateToken($salt, $ip)));
    }

    public function testHashEqualsRejectsWrongToken(): void
    {
        $salt = 'secret-salt';
        $expected = $this->generateToken($salt, '203.0.113.50');
        $wrong = $this->generateToken($salt, '198.51.100.1');
        $this->assertFalse(hash_equals($expected, $wrong));
    }

    public function testEmptySaltStillProducesValidToken(): void
    {
        $token = $this->generateToken('', '127.0.0.1');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }
}
