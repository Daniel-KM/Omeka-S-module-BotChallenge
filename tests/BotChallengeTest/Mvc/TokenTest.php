<?php declare(strict_types=1);

namespace BotChallengeTest\Mvc;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the HMAC token logic shared between MvcListeners and IndexController.
 *
 * Token formula: microtime() . '_' . hash_hmac('sha256', $salt . $microtime, $salt)
 *
 * @covers \BotChallenge\Mvc\MvcListeners::handleChallenge
 * @covers \BotChallenge\Controller\IndexController::indexAction
 */
class TokenTest extends TestCase
{
    /**
     * Generate a token using the same formula as the module.
     */
    protected function generateToken(string $salt, string $timestamp): string
    {
        $hmac = hash_hmac('sha256', $salt . $timestamp, $salt);
        return $timestamp . '_' . $hmac;
    }

    /**
     * Extract the seconds from a microtime() string ("0.12345678 1234567890").
     */
    protected function extractSeconds(string $timestamp): int
    {
        return (int) substr($timestamp, strpos($timestamp, ' ') + 1);
    }

    public function testSameInputsProduceSameToken(): void
    {
        $salt = 'test-salt-abc123';
        $ts = '0.12345678 1700000000';
        $token1 = $this->generateToken($salt, $ts);
        $token2 = $this->generateToken($salt, $ts);
        $this->assertSame($token1, $token2);
    }

    public function testDifferentSaltProducesDifferentToken(): void
    {
        $ts = '0.12345678 1700000000';
        $token1 = $this->generateToken('salt-one', $ts);
        $token2 = $this->generateToken('salt-two', $ts);
        $this->assertNotSame($token1, $token2);
    }

    public function testDifferentTimestampProducesDifferentToken(): void
    {
        $salt = 'same-salt';
        $token1 = $this->generateToken($salt, '0.12345678 1700000000');
        $token2 = $this->generateToken($salt, '0.98765432 1700000001');
        $this->assertNotSame($token1, $token2);
    }

    public function testTokenFormat(): void
    {
        $token = $this->generateToken('any-salt', '0.12345678 1700000000');
        // Format: "microtime_hmac" where hmac is 64 hex chars.
        $this->assertMatchesRegularExpression('/^[\d.]+ \d+_[0-9a-f]{64}$/', $token);
    }

    public function testValidation(): void
    {
        $salt = 'secret-salt';
        $ts = (string) microtime();
        $token = $this->generateToken($salt, $ts);

        // Parse like MvcListeners does.
        $pos = strrpos($token, '_');
        $this->assertNotFalse($pos);
        $parsedTs = substr($token, 0, $pos);
        $parsedHmac = substr($token, $pos + 1);

        $expectedHmac = hash_hmac('sha256', $salt . $parsedTs, $salt);
        $this->assertTrue(hash_equals($expectedHmac, $parsedHmac));
    }

    public function testRejectsWrongSalt(): void
    {
        $ts = (string) microtime();
        $token = $this->generateToken('correct-salt', $ts);

        $pos = strrpos($token, '_');
        $parsedTs = substr($token, 0, $pos);
        $parsedHmac = substr($token, $pos + 1);

        $wrongHmac = hash_hmac('sha256', 'wrong-salt' . $parsedTs, 'wrong-salt');
        $this->assertFalse(hash_equals($wrongHmac, $parsedHmac));
    }

    public function testExtractSeconds(): void
    {
        $seconds = $this->extractSeconds('0.12345678 1700000000');
        $this->assertSame(1700000000, $seconds);
    }

    public function testExpirationCheck(): void
    {
        $ts = '0.12345678 ' . (time() - 100);
        $cookieLifetime = 86400;
        $seconds = $this->extractSeconds($ts);
        $this->assertTrue(time() - $seconds <= $cookieLifetime);

        // Expired token.
        $tsOld = '0.12345678 ' . (time() - 200000);
        $secondsOld = $this->extractSeconds($tsOld);
        $this->assertFalse(time() - $secondsOld <= $cookieLifetime);
    }

    public function testEmptySaltStillProducesValidToken(): void
    {
        $token = $this->generateToken('', '0.12345678 1700000000');
        $this->assertMatchesRegularExpression('/^[\d.]+ \d+_[0-9a-f]{64}$/', $token);
    }
}
