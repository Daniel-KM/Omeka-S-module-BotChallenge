<?php declare(strict_types=1);

namespace BotChallenge\Controller;

use Laminas\Http\Header\SetCookie;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Session\Container as SessionContainer;
use Laminas\View\Model\ViewModel;
use Omeka\Settings\Settings;

class IndexController extends AbstractActionController
{
    /**
     * Time to solve the challenge, so a harvested nonce is quickly useless.
     */
    const NONCE_LIFETIME = 600;

    /**
     * Margin allowed on the challenge delay.
     *
     * The nonce time and the check are in seconds, so an answer sent right on
     * time may be counted one second short.
     */
    const DELAY_TOLERANCE = 1;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function indexAction()
    {
        // The cookie is delivered by verifyAction() only, after the client
        // posted back the nonce: a plain GET on this page must not return
        // anything usable, else the challenge is only a decoration and the
        // token can be harvested with a single request.
        if ($this->getRequest()->isPost()) {
            return $this->verify();
        }

        $session = new SessionContainer('BotChallenge');
        $nonce = bin2hex(random_bytes(16));
        $session->nonce = $nonce;
        $session->nonceTime = time();

        // Validate redirect url: must start with "/" and not "//".
        $basePath = $this->getRequest()->getBasePath();
        $defaultRedirect = rtrim($basePath, '/') . '/';
        $redirectUrl = $this->params()->fromQuery('redirect_url', $defaultRedirect);
        if (!is_string($redirectUrl)
            || $redirectUrl === ''
            || $redirectUrl[0] !== '/'
            || (strlen($redirectUrl) > 1 && $redirectUrl[1] === '/')
        ) {
            $redirectUrl = $defaultRedirect;
        }

        // Detect https.
        $server = $this->getRequest()->getServer();
        $isHttps = false;
        if (!empty($server['HTTPS']) && strtolower($server['HTTPS']) !== 'off') {
            $isHttps = true;
        } elseif ($this->getRequest()->getHeaders()->has('X-Forwarded-Proto')
            && strtolower($this->getRequest()->getHeaders()->get('X-Forwarded-Proto')->getFieldValue()) === 'https'
        ) {
            $isHttps = true;
        } elseif (($server['SERVER_PORT'] ?? null) === '443') {
            $isHttps = true;
        }

        $delay = (int) $this->settings->get('botchallenge_delay', 5);
        $testHeadless = (bool) $this->settings->get('botchallenge_test_headless', true);

        // Prevent caching.
        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->addHeaderLine('Pragma', 'no-cache');

        $view = new ViewModel([
            'nonce' => $nonce,
            'delay' => $delay,
            'redirectUrl' => $redirectUrl,
            'isHttps' => $isHttps,
            'testHeadless' => $testHeadless,
        ]);
        return $view
            ->setTerminal(true);
    }

    /**
     * Check the nonce posted by the client and deliver the cookie.
     *
     * The nonce is single use and tied to the session, so the cookie cannot be
     * obtained with a single request, and a harvested page is useless without
     * its session.
     */
    protected function verify()
    {
        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->addHeaderLine('Content-Type', 'application/json');

        $session = new SessionContainer('BotChallenge');
        $expected = (string) ($session->nonce ?? '');
        $nonceTime = (int) ($session->nonceTime ?? 0);

        // Single use: consume the nonce whatever the result, so a failed or
        // replayed check requires a new challenge page.
        unset($session->nonce, $session->nonceTime);

        $posted = (string) $this->params()->fromPost('nonce', '');
        $elapsed = time() - $nonceTime;
        if ($expected === ''
            || !hash_equals($expected, $posted)
            || $elapsed > self::NONCE_LIFETIME
        ) {
            $response->setStatusCode(403);
            $response->setContent(json_encode(['status' => 'error']));
            return $response;
        }

        // The delay is checked here too: the countdown of the page is run by
        // the client, so without this check it is only a decoration and a
        // client posting the nonce immediately gets the cookie. The nonce is
        // consumed above, so a new challenge page is required to retry.
        $delay = (int) $this->settings->get('botchallenge_delay', 5);
        if (self::isTooEarly($elapsed, $delay)) {
            $response->setStatusCode(403);
            $response->setContent(json_encode(['status' => 'too_early']));
            return $response;
        }

        $salt = (string) $this->settings->get('botchallenge_salt', '');
        $cookieLifetime = (int) $this->settings->get('botchallenge_cookie_lifetime', 7) * 86400;
        $userAgent = $this->userAgent();
        $ip = $this->settings->get('botchallenge_bind_ip', false)
            ? \BotChallenge\Stdlib\ClientIp::fromRequest(
                $this->getRequest(),
                $this->settings->get('botchallenge_trusted_proxies') ?: []
            )
            : '';
        // Seconds only: microtime() holds a space, that is encoded as "+" in
        // the cookie and not decoded back, so the signature never matched.
        $timestamp = (string) time();
        $token = $timestamp . '_' . self::signature($timestamp, $userAgent, $salt, $ip);

        // The cookie is set by the server, so it can be http only: the client
        // never needs to read it.
        $setCookie = new SetCookie(
            'omeka_bot_challenge',
            $token,
            time() + $cookieLifetime,
            rtrim($this->getRequest()->getBasePath(), '/') . '/',
            null,
            $this->isHttps(),
            true,
            null,
            null,
            'Lax'
        );
        $response->getHeaders()->addHeader($setCookie);
        $response->setContent(json_encode(['status' => 'success']));
        return $response;
    }

    /**
     * Check that the challenge was not answered before its delay.
     *
     * The tolerance covers the second used for the times: an answer sent right
     * on time may be counted one second short.
     */
    public static function isTooEarly(int $elapsed, int $delay): bool
    {
        return $elapsed < $delay - self::DELAY_TOLERANCE;
    }

    /**
     * Sign a token for a client.
     *
     * The user agent is included, so a cookie shared with another client is
     * refused. The ip is included only when the option is set: it changes too
     * often (mobile network, cgnat, rotating ipv6) to be used by default with
     * a cookie living several days.
     */
    public static function signature(string $timestamp, string $userAgent, string $salt, string $ip = ''): string
    {
        return hash_hmac('sha256', $timestamp . "\n" . $userAgent . "\n" . $ip, $salt);
    }

    protected function userAgent(): string
    {
        $headers = $this->getRequest()->getHeaders();
        return $headers->has('User-Agent')
            ? (string) $headers->get('User-Agent')->getFieldValue()
            : '';
    }

    protected function isHttps(): bool
    {
        $request = $this->getRequest();
        $server = $request->getServer();
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }
        $headers = $request->getHeaders();
        if ($headers->has('X-Forwarded-Proto')
            && strtolower($headers->get('X-Forwarded-Proto')->getFieldValue()) === 'https'
        ) {
            return true;
        }
        return ($server['SERVER_PORT'] ?? null) === '443';
    }
}
