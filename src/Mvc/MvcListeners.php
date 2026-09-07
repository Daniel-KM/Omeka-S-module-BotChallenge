<?php declare(strict_types=1);

namespace BotChallenge\Mvc;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Settings\Settings;

class MvcListeners extends AbstractListenerAggregate
{
    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function attach(EventManagerInterface $events, $priority = 1)
    {
        // Priority must be < 1 (RouteListener runs at 1) to ensure
        // the route is resolved before we inspect it.
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'handleChallenge'],
            -100
        );
    }

    public function handleChallenge(MvcEvent $event)
    {
        // Skip CLI (background jobs, scripts).
        if (PHP_SAPI === 'cli') {
            return;
        }

        // Skip excluded routes.
        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch) {
            return;
        }

        $routeName = $routeMatch->getMatchedRouteName();
        $excludedRoutes = [
            'install',
            'migrate',
            'maintenance',
            'login',
            'logout',
            'create-password',
            'forgot-password',
            'bot-challenge',
            // Machine endpoints served by a route, not by a path prefix.
            'site/sitemap',
        ];
        if (in_array($routeName, $excludedRoutes)) {
            return;
        }

        // Skip api routes.
        if ($routeMatch->getParam('__API__')) {
            return;
        }

        // Skip admin routes (authenticated users).
        if ($routeMatch->getParam('__ADMIN__')) {
            return;
        }

        // Skip authenticated users.
        $authenticationService = $event->getApplication()->getServiceManager()
            ->get('Omeka\AuthenticationService');
        if ($authenticationService->hasIdentity()) {
            return;
        }

        // Skip exception paths (relative to the base path).
        $path = $event->getRequest()->getUri()->getPath();
        $basePath = $event->getRequest()->getBasePath();
        if ($basePath !== '' && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }
        if ($this->pathIsExcepted($path, $this->settings->get('botchallenge_exception_paths', []))) {
            return;
        }

        // Skip exception ips.
        $clientIp = $this->getClientIp($event);
        // The merged list is prepared when the config is saved; fall back to
        // the manual ranges when the config was never saved.
        $exceptionIps = $this->settings->get('botchallenge_exception_ips_all')
            ?: $this->settings->get('botchallenge_exception_ips', []);
        foreach ($exceptionIps as $exceptionIp) {
            if ($exceptionIp !== '' && $this->ipInCidr($clientIp, $exceptionIp)) {
                return;
            }
        }

        // Check cookie: format is "{timestamp}_{hmac}".
        $cookie = $event->getRequest()->getCookie();
        $cookieValue = $cookie && $cookie->offsetExists('omeka_bot_challenge')
            ? $cookie->offsetGet('omeka_bot_challenge')
            : '';
        $pos = strrpos($cookieValue, '_');
        if ($pos !== false) {
            $salt = (string) $this->settings->get('botchallenge_salt', '');
            $cookieLifetime = (int) $this->settings->get('botchallenge_cookie_lifetime', 7) * 86400;
            $timestamp = substr($cookieValue, 0, $pos);
            $hmac = substr($cookieValue, $pos + 1);
            $headers = $event->getRequest()->getHeaders();
            $userAgent = $headers->has('User-Agent')
                ? (string) $headers->get('User-Agent')->getFieldValue()
                : '';
            $ip = $this->settings->get('botchallenge_bind_ip', false)
                ? \BotChallenge\Stdlib\ClientIp::fromRequest($event->getRequest())
                : '';
            $expectedHmac = \BotChallenge\Controller\IndexController::signature($timestamp, $userAgent, $salt, $ip);
            $seconds = (int) $timestamp;
            $age = time() - $seconds;
            if (hash_equals($expectedHmac, $hmac)
                && $age >= 0
                && $age <= $cookieLifetime
            ) {
                // Sliding window: the lifetime is sealed in the cookie, so a
                // regular visitor would be challenged again on a fixed date
                // whatever its activity. Renew it once it is half spent, so
                // only an inactive visitor is challenged again.
                if ($age > $cookieLifetime / 2) {
                    $this->renewCookie($event, $salt, $userAgent, $cookieLifetime, $ip);
                }
                return;
            }
        }

        // Redirect to challenge page.
        // Use getRequestUri() (path + query) instead of getUriString()
        // (full URL) so the redirect_url starts with "/" and passes
        // validation in the controller.
        $requestUri = $event->getRequest()->getRequestUri();
        $url = $event->getRouter()->assemble([], ['name' => 'bot-challenge']);
        $url .= '?redirect_url=' . urlencode($requestUri);
        $response = $event->getResponse();
        $response->getHeaders()->addHeaderLine('Location', $url);
        $response->setStatusCode(302);
        return $response;
    }

    /**
     * Renew the cookie of a visitor that is still active.
     */
    protected function renewCookie(MvcEvent $event, string $salt, string $userAgent, int $cookieLifetime, string $ip = ''): void
    {
        $request = $event->getRequest();
        $timestamp = (string) time();
        $token = $timestamp . '_'
            . \BotChallenge\Controller\IndexController::signature($timestamp, $userAgent, $salt, $ip);

        $isHttps = false;
        $server = $request->getServer();
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            $isHttps = true;
        } elseif ($request->getHeaders()->has('X-Forwarded-Proto')) {
            $isHttps = strtolower($request->getHeaders()->get('X-Forwarded-Proto')->getFieldValue()) === 'https';
        } elseif (($server['SERVER_PORT'] ?? null) === '443') {
            $isHttps = true;
        }

        $setCookie = new \Laminas\Http\Header\SetCookie(
            'omeka_bot_challenge',
            $token,
            time() + $cookieLifetime,
            rtrim($request->getBasePath(), '/') . '/',
            null,
            $isHttps,
            true,
            null,
            null,
            'Lax'
        );
        $event->getResponse()->getHeaders()->addHeader($setCookie);
    }

    /**
     * Whether the path matches an exception path, on a segment boundary.
     *
     * A prefix pattern ("/sso") matches itself and any sub-path ("/sso",
     * "/sso/metadata"), but not a path that merely shares its prefix
     * ("/ssoxxx"), so an exempted prefix cannot be widened by accident.
     *
     * A suffix pattern (a star then "/suggest") matches any path whose last
     * segment is the given one ("/s/mysite/search/suggest"): the search routes
     * use a configurable slug, so their machine endpoints (suggest, atom, rss)
     * have no fixed prefix. Autocompletion in particular must never be delayed.
     *
     * @param string[] $exceptionPaths
     */
    protected function pathIsExcepted(string $path, array $exceptionPaths): bool
    {
        foreach ($exceptionPaths as $exceptionPath) {
            $exceptionPath = (string) $exceptionPath;
            if ($exceptionPath !== '' && $exceptionPath[0] === '*') {
                $segment = rtrim(substr($exceptionPath, 1), '/');
                if ($segment === '') {
                    continue;
                }
                if (substr($path, -strlen($segment)) === $segment
                    || strpos($path, $segment . '/') !== false
                ) {
                    return true;
                }
                continue;
            }
            $exceptionPath = rtrim($exceptionPath, '/');
            if ($exceptionPath === '') {
                continue;
            }
            if ($path === $exceptionPath
                || strpos($path, $exceptionPath . '/') === 0
            ) {
                return true;
            }
        }
        return false;
    }

    protected function getClientIp(MvcEvent $event): string
    {
        return \BotChallenge\Stdlib\ClientIp::fromRequest($event->getRequest());
    }

    /**
     * Check if an ip address is within a cidr range.
     */
    protected function ipInCidr(string $ip, string $cidr): bool
    {
        // Handle single ip (no mask).
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        [$range, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin = inet_pton($ip);
        $rangeBin = inet_pton($range);

        if ($ipBin === false || $rangeBin === false) {
            return false;
        }

        // Both must be the same family (ip v4 or v6).
        if (strlen($ipBin) !== strlen($rangeBin)) {
            return false;
        }

        // Build mask.
        $totalBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $totalBits) {
            return false;
        }

        $mask = str_repeat("\xff", (int) ($bits / 8));
        if ($bits % 8) {
            $mask .= chr(0xff << (8 - ($bits % 8)));
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($rangeBin & $mask);
    }

}
