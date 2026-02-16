<?php declare(strict_types=1);

namespace BotChallengeTest\Controller;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * @covers \BotChallenge\Controller\IndexController
 */
class IndexControllerTest extends AbstractHttpControllerTestCase
{
    protected bool $requiresLogin = false;

    public function testRouteIsAccessible(): void
    {
        $this->dispatch('/bot-challenge');
        $this->assertResponseStatusCode(200);
    }

    public function testTerminalViewHasNoOmekaLayout(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->getResponse()->getBody();
        // Terminal view: standalone HTML, no Omeka admin/site layout.
        $this->assertStringContainsString('<!DOCTYPE html>', $body);
        // Should not contain the Omeka admin header.
        $this->assertStringNotContainsString('id="admin-bar"', $body);
    }

    public function testContainsJsChallenge(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->getResponse()->getBody();
        $this->assertStringContainsString('omeka_bot_challenge', $body);
        $this->assertStringContainsString('setInterval', $body);
        $this->assertStringContainsString('document.cookie', $body);
    }

    public function testDefaultRedirectUrl(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->getResponse()->getBody();
        // json_encode('/') outputs "\/" (JSON-escaped slash).
        $this->assertMatchesRegularExpression(
            '/var redirectUrl = "\\\\?\/"/',
            $body,
            'Default redirect URL should be "/"'
        );
    }

    public function testValidRedirectUrl(): void
    {
        $this->dispatch('/bot-challenge?redirect_url=/items/1');
        $body = $this->getResponse()->getBody();
        $this->assertStringContainsString('items', $body);
        // json_encode('/items/1') outputs "\/items\/1".
        $this->assertMatchesRegularExpression(
            '/var redirectUrl = ".*items.*1"/',
            $body
        );
    }

    public function testOpenRedirectBlockedDoubleSlash(): void
    {
        $this->dispatch('/bot-challenge?redirect_url=//evil.com');
        $body = $this->getResponse()->getBody();
        // Should fallback to "/" for open redirect attempts.
        // Must NOT contain "evil.com" in redirectUrl.
        $this->assertDoesNotMatchRegularExpression(
            '/var redirectUrl = ".*evil\.com.*"/',
            $body,
            'Open redirect with // should be blocked'
        );
    }

    public function testOpenRedirectBlockedProtocol(): void
    {
        $this->dispatch('/bot-challenge?redirect_url=http://evil.com');
        $body = $this->getResponse()->getBody();
        // Should fallback to "/" for external protocol URLs.
        $this->assertDoesNotMatchRegularExpression(
            '/var redirectUrl = ".*evil\.com.*"/',
            $body,
            'Open redirect with http:// should be blocked'
        );
    }

    public function testCacheControlHeaders(): void
    {
        $this->dispatch('/bot-challenge');
        $headers = $this->getResponse()->getHeaders();
        $cacheControl = $headers->get('Cache-Control');
        $this->assertNotFalse($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl->getFieldValue());
        $this->assertStringContainsString('no-cache', $cacheControl->getFieldValue());
    }

    public function testTokenPresentInBody(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->getResponse()->getBody();
        // Token is "microtime_hmac": e.g. "0.12345678 1234567890_abc...def".
        $this->assertMatchesRegularExpression('/var token = "[\d.]+ \d+_[0-9a-f]{64}"/', $body);
    }
}
