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

        // Skip exception paths (relative to the base path).
        $path = $event->getRequest()->getUri()->getPath();
        $basePath = $event->getRequest()->getBasePath();
        if ($basePath !== '' && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }
        $exceptionPaths = $this->settings->get('botchallenge_exception_paths', []);
        foreach ($exceptionPaths as $exceptionPath) {
            if ($exceptionPath !== '' && strpos($path, $exceptionPath) === 0) {
                return;
            }
        }

        // Skip exception ips.
        $clientIp = $this->getClientIp($event);
        $exceptionIps = $this->settings->get('botchallenge_exception_ips', []);
        foreach ($exceptionIps as $exceptionIp) {
            if ($exceptionIp !== '' && $this->ipInCidr($clientIp, $exceptionIp)) {
                return;
            }
        }

        // Check cookie.
        $salt = $this->settings->get('botchallenge_salt', '');
        $expectedToken = hash_hmac('sha256', $salt . $clientIp, $salt);
        $cookie = $event->getRequest()->getCookie();
        $cookieValue = $cookie && $cookie->offsetExists('omeka_bot_challenge')
            ? $cookie->offsetGet('omeka_bot_challenge')
            : '';
        if (hash_equals($expectedToken, $cookieValue)) {
            return;
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
     * Get client ip, handling proxies.
     */
    protected function getClientIp(MvcEvent $event): string
    {
        $request = $event->getRequest();
        $headers = $request->getHeaders();

        if ($headers->has('X-Forwarded-For')) {
            $ips = $headers->get('X-Forwarded-For')->getFieldValue();
            $ip = trim(explode(',', $ips)[0]);
            if ($ip !== '') {
                return $ip;
            }
        }

        if ($headers->has('X-Real-IP')) {
            $ip = trim($headers->get('X-Real-IP')->getFieldValue());
            if ($ip !== '') {
                return $ip;
            }
        }

        return $request->getServer('REMOTE_ADDR', '127.0.0.1');
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
