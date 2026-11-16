<?php declare(strict_types=1);

namespace BotChallenge\Mvc;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Settings\Settings;

class MvcListeners extends AbstractListenerAggregate
{
    /**
     * Duration of the cache of the reverse dns verifications, by ip.
     */
    const RDNS_CACHE_SECONDS = 21600;

    /**
     * Maximum number of ips in the cache of the reverse dns verifications.
     */
    const RDNS_CACHE_SIZE = 1000;

    /**
     * A reverse dns verification longer than that pauses the next ones.
     */
    const RDNS_SLOW_SECONDS = 2;

    const RDNS_PAUSE_SECONDS = 600;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Omeka\Stdlib\ClientIp|\Common\Stdlib\ClientIp
     */
    protected $clientIp;

    public function __construct(Settings $settings, $clientIp)
    {
        $this->settings = $settings;
        $this->clientIp = $clientIp;
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

        $headers = $event->getRequest()->getHeaders();
        $userAgent = $headers->has('User-Agent')
            ? (string) $headers->get('User-Agent')->getFieldValue()
            : '';

        // Skip the bots verified by their network: the user agent announces a
        // bot and the ip belongs to its domain (forward-confirmed reverse dns).
        if ($this->isVerifiedByReverseDns($userAgent, $clientIp)) {
            return;
        }

        // A link preview bot (social networks, messaging apps) cannot solve the
        // challenge, and the ip of many of them is unknown (each instance of
        // Mastodon fetches its previews). So it gets the head of the page only,
        // with its metadata: a scraper using this user agent gets nothing else.
        if ($this->isPreviewBot($userAgent)) {
            $this->servePreviewOnly($event);
            return;
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
            $ip = $this->settings->get('botchallenge_bind_ip', false)
                ? $clientIp
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
     * Is the user agent a bot verified by its network?
     *
     * Each entry maps a part of the user agent to the domain of the reverse dns
     * of its ips, for example "TelegramBot" to "ptr.telegram.org".
     */
    protected function isVerifiedByReverseDns(string $userAgent, string $ip): bool
    {
        if ($userAgent === '' || $ip === '') {
            return false;
        }
        foreach ((array) $this->settings->get('botchallenge_exception_rdns', []) as $agent => $domain) {
            if ((string) $agent !== '' && stripos($userAgent, (string) $agent) !== false) {
                return $this->verifyReverseDnsCached($ip, (string) $domain);
            }
        }
        return false;
    }

    /**
     * Verify the reverse dns of an ip, with a cache and a pause when it is slow.
     *
     * The user agent is declarative, so a botnet may use the one of a verified
     * bot to trigger two dns lookups on each request. The result is cached by
     * ip, positive or not, and the lookups are paused when the dns is slow: the
     * bot then gets the link preview only, that is cheap.
     */
    protected function verifyReverseDnsCached(string $ip, string $domain): bool
    {
        $now = time();
        $key = $ip . ' ' . $domain;
        $cache = (array) $this->settings->get('botchallenge_rdns_cache', []);
        if (isset($cache[$key]) && (int) ($cache[$key][1] ?? 0) > $now) {
            return (bool) $cache[$key][0];
        }
        if ((int) $this->settings->get('botchallenge_rdns_paused_until', 0) > $now) {
            return false;
        }

        $start = microtime(true);
        $isVerified = $this->reverseDns()->verify($ip, $domain);
        if (microtime(true) - $start > self::RDNS_SLOW_SECONDS) {
            $this->settings->set('botchallenge_rdns_paused_until', $now + self::RDNS_PAUSE_SECONDS);
        }

        $cache = array_filter($cache, fn ($entry) => (int) ($entry[1] ?? 0) > $now);
        $cache = array_slice($cache, -(self::RDNS_CACHE_SIZE - 1), null, true);
        $cache[$key] = [$isVerified, $now + self::RDNS_CACHE_SECONDS];
        $this->settings->set('botchallenge_rdns_cache', $cache);
        return $isVerified;
    }

    protected function isPreviewBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }
        foreach ((array) $this->settings->get('botchallenge_preview_user_agents', []) as $agent) {
            $agent = trim((string) $agent);
            if ($agent !== '' && stripos($userAgent, $agent) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render the page in a layout that outputs its head only.
     *
     * The page is processed normally, so the metadata added by the theme or
     * the modules (Sharing, MetaTags…) are kept, but its body is not output.
     */
    protected function servePreviewOnly(MvcEvent $event): void
    {
        $event->getApplication()->getEventManager()->attach(
            MvcEvent::EVENT_RENDER,
            function (MvcEvent $event): void {
                $viewModel = $event->getViewModel();
                if ($viewModel->terminate()) {
                    return;
                }
                $vars = [];
                foreach ($viewModel->getChildren() as $child) {
                    $vars += array_filter([
                        'previewResource' => $child->getVariable('resource') ?? $child->getVariable('media') ?? $child->getVariable('itemSet'),
                        'previewPage' => $child->getVariable('page'),
                    ]);
                }
                $viewModel
                    ->setTemplate('bot-challenge/preview')
                    ->setVariables($vars);
                $event->getResponse()->getHeaders()->addHeaderLine('Cache-Control', 'no-store');
            },
            // Before the rendering of the view (-10000).
            100
        );
    }

    protected function reverseDns(): \BotChallenge\Stdlib\ReverseDns
    {
        return new \BotChallenge\Stdlib\ReverseDns();
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

    /**
     * Get the ip of the client, even behind the trusted reverse proxies.
     */
    protected function getClientIp(MvcEvent $event): string
    {
        return $this->clientIp->getIp($event->getRequest()->getServer()->toArray());
    }

    protected function ipInCidr(string $ip, string $cidr): bool
    {
        return $this->clientIp::ipInCidr($ip, $cidr);
    }

}
