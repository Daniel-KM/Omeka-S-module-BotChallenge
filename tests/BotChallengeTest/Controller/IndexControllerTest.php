<?php declare(strict_types=1);

namespace BotChallengeTest\Controller;

use BotChallenge\Controller\IndexController;
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
        $this->assertStringContainsString('setInterval', $body);
        // The client posts the nonce back and the server sets the cookie, so
        // the page never writes the cookie by itself.
        $this->assertStringContainsString('fetch(', $body);
        $this->assertStringNotContainsString('document.cookie', $body);
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

    public function testNoncePresentInBody(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->getResponse()->getBody();
        $this->assertMatchesRegularExpression('/var nonce = "[0-9a-f]{32}"/', $body);
    }

    /**
     * The page used to print a valid token, so the cookie could be harvested
     * with a single request and shared with any other client.
     */
    public function testGetDeliversNoUsableToken(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->getResponse()->getBody();

        // No signed token: a token is "{timestamp}_{64 hex}".
        $this->assertDoesNotMatchRegularExpression('/\d{10}_[0-9a-f]{64}/', $body);
        $this->assertStringNotContainsString('omeka_bot_challenge', $body);

        // And no cookie is set by the response either.
        $setCookie = $this->getResponse()->getHeaders()->get('Set-Cookie');
        $cookies = $setCookie === false ? [] : (is_array($setCookie) ? $setCookie : [$setCookie]);
        foreach ($cookies as $cookie) {
            $this->assertNotSame('omeka_bot_challenge', $cookie->getName());
        }
    }

    public function testPostWithoutNonceIsRefused(): void
    {
        $this->dispatch('/bot-challenge', 'POST', ['nonce' => 'not-a-valid-nonce']);
        $this->assertResponseStatusCode(403);
    }

    /**
     * The countdown is run by the client, so the delay is checked by the
     * server too: else a client posting the nonce immediately gets the cookie.
     *
     * @dataProvider provideDelays
     */
    public function testDelayIsCheckedByTheServer(int $elapsed, int $delay, bool $tooEarly): void
    {
        $this->assertSame($tooEarly, IndexController::isTooEarly($elapsed, $delay));
    }

    public function provideDelays(): array
    {
        return [
            'immediate answer of a bot' => [0, 5, true],
            'answer far too early' => [1, 5, true],
            'answer just too early' => [3, 5, true],
            'one second short, tolerated' => [4, 5, false],
            'answer on time' => [5, 5, false],
            'answer after the delay' => [30, 5, false],
            'no delay configured' => [0, 0, false],
            'delay of one second' => [0, 1, false],
        ];
    }
}
