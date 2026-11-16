<?php declare(strict_types=1);

namespace BotChallengeTest\Controller;

use BotChallenge\Controller\IndexController;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BotChallenge\Controller\IndexController::createNonce
 * @covers \BotChallenge\Controller\IndexController::nonceTime
 */
class NonceTest extends TestCase
{
    const RANDOM = '0123456789abcdef0123456789abcdef';

    public function testNonceOfTheClientGivesItsTime(): void
    {
        $nonce = IndexController::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt');
        $this->assertSame(1794942458, IndexController::nonceTime($nonce, 'Mozilla/5.0', 'salt'));
    }

    public function testNonceIsBoundToTheUserAgent(): void
    {
        $nonce = IndexController::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt');
        $this->assertNull(IndexController::nonceTime($nonce, 'curl/8.0', 'salt'));
    }

    public function testNonceIsBoundToTheIpWhenSet(): void
    {
        $nonce = IndexController::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt', '192.0.2.1');
        $this->assertSame(1794942458, IndexController::nonceTime($nonce, 'Mozilla/5.0', 'salt', '192.0.2.1'));
        $this->assertNull(IndexController::nonceTime($nonce, 'Mozilla/5.0', 'salt', '192.0.2.2'));
    }

    public function testNonceNeedsTheSalt(): void
    {
        $nonce = IndexController::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt');
        $this->assertNull(IndexController::nonceTime($nonce, 'Mozilla/5.0', 'other'));
    }

    public function testTamperedNonceIsRefused(): void
    {
        $nonce = IndexController::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt');
        [, $random, $hmac] = explode('.', $nonce);
        // A later time cannot be set to skip the delay.
        $this->assertNull(IndexController::nonceTime('1794942400.' . $random . '.' . $hmac, 'Mozilla/5.0', 'salt'));
    }

    public function testNonceIsNotTheSignatureOfTheCookie(): void
    {
        $cookieSignature = IndexController::signature('1794942458', 'Mozilla/5.0', 'salt');
        $nonce = IndexController::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt');
        $this->assertStringNotContainsString($cookieSignature, $nonce);
    }

    /**
     * @dataProvider provideMalformedNonces
     */
    public function testMalformedNonceIsRefused(string $nonce): void
    {
        $this->assertNull(IndexController::nonceTime($nonce, 'Mozilla/5.0', 'salt'));
    }

    public function provideMalformedNonces(): array
    {
        return [
            'empty' => [''],
            'old format' => [self::RANDOM],
            'two parts' => ['1794942458.' . self::RANDOM],
            'four parts' => ['1794942458.a.b.c'],
            'no time' => ['.' . self::RANDOM . '.abc'],
            'not a time' => ['abc.' . self::RANDOM . '.abc'],
            'no random' => ['1794942458..abc'],
        ];
    }
}
