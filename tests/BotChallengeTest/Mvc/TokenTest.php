<?php declare(strict_types=1);

namespace BotChallengeTest\Mvc;

use BotChallenge\Controller\IndexController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the signature shared between MvcListeners and IndexController.
 *
 * Token format: "{timestamp}_{signature}", where the signature covers the
 * timestamp and the user agent, so a cookie is not usable by another client.
 *
 * The signature of the module is called directly: a test rewriting the formula
 * would keep passing after the formula changed.
 *
 * @covers \BotChallenge\Controller\IndexController::signature
 */
class TokenTest extends TestCase
{
    const SALT = 'test-salt-abc123';
    const AGENT = 'Mozilla/5.0 (X11; Linux x86_64)';

    public function testSameInputsProduceSameSignature(): void
    {
        $this->assertSame(
            IndexController::signature('1700000000', self::AGENT, self::SALT),
            IndexController::signature('1700000000', self::AGENT, self::SALT)
        );
    }

    public function testDifferentSaltProducesDifferentSignature(): void
    {
        $this->assertNotSame(
            IndexController::signature('1700000000', self::AGENT, 'salt-one'),
            IndexController::signature('1700000000', self::AGENT, 'salt-two')
        );
    }

    public function testDifferentTimestampProducesDifferentSignature(): void
    {
        $this->assertNotSame(
            IndexController::signature('1700000000', self::AGENT, self::SALT),
            IndexController::signature('1700000001', self::AGENT, self::SALT)
        );
    }

    /**
     * The core of the fix: a cookie shared with another client is refused.
     */
    public function testDifferentUserAgentProducesDifferentSignature(): void
    {
        $this->assertNotSame(
            IndexController::signature('1700000000', self::AGENT, self::SALT),
            IndexController::signature('1700000000', 'Some-Bot/1.0', self::SALT)
        );
    }

    public function testEmptyUserAgentIsSupported(): void
    {
        $signature = IndexController::signature('1700000000', '', self::SALT);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }

    public function testSignatureFormat(): void
    {
        $signature = IndexController::signature('1700000000', self::AGENT, self::SALT);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }

    /**
     * The timestamp must hold no space: it is encoded as "+" in the cookie and
     * not decoded back, so the signature would never match.
     */
    public function testTokenHasNoSpace(): void
    {
        $timestamp = (string) time();
        $token = $timestamp . '_' . IndexController::signature($timestamp, self::AGENT, self::SALT);
        $this->assertStringNotContainsString(' ', $token);
        $this->assertMatchesRegularExpression('/^\d+_[0-9a-f]{64}$/', $token);
    }

    /**
     * Validation as done by MvcListeners.
     */
    public function testValidation(): void
    {
        $timestamp = (string) time();
        $token = $timestamp . '_' . IndexController::signature($timestamp, self::AGENT, self::SALT);

        $pos = strrpos($token, '_');
        $this->assertNotFalse($pos);
        $parsedTimestamp = substr($token, 0, $pos);
        $parsedSignature = substr($token, $pos + 1);

        $this->assertTrue(hash_equals(
            IndexController::signature($parsedTimestamp, self::AGENT, self::SALT),
            $parsedSignature
        ));
        $this->assertSame(time(), (int) $parsedTimestamp);
    }

    public function testRejectsWrongSalt(): void
    {
        $timestamp = (string) time();
        $signature = IndexController::signature($timestamp, self::AGENT, 'correct-salt');

        $this->assertFalse(hash_equals(
            IndexController::signature($timestamp, self::AGENT, 'wrong-salt'),
            $signature
        ));
    }

    /**
     * The ip is added to the signature only when the option is set.
     */
    public function testIpIsNotInTheSignatureByDefault(): void
    {
        $this->assertSame(
            IndexController::signature('1700000000', self::AGENT, self::SALT),
            IndexController::signature('1700000000', self::AGENT, self::SALT, '')
        );
    }

    public function testDifferentIpProducesDifferentSignature(): void
    {
        $this->assertNotSame(
            IndexController::signature('1700000000', self::AGENT, self::SALT, '10.0.0.1'),
            IndexController::signature('1700000000', self::AGENT, self::SALT, '10.0.0.2')
        );
    }

    public function testEnablingTheIpInvalidatesTheCookies(): void
    {
        $this->assertNotSame(
            IndexController::signature('1700000000', self::AGENT, self::SALT),
            IndexController::signature('1700000000', self::AGENT, self::SALT, '10.0.0.1')
        );
    }

    public function testRejectsSharedCookie(): void
    {
        $timestamp = (string) time();
        $signature = IndexController::signature($timestamp, self::AGENT, self::SALT);

        // Another client replaying the very same cookie.
        $this->assertFalse(hash_equals(
            IndexController::signature($timestamp, 'Another-Client/2.0', self::SALT),
            $signature
        ));
    }
}
