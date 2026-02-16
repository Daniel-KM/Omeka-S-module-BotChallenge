<?php declare(strict_types=1);

namespace BotChallenge\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Settings\Settings;

class IndexController extends AbstractActionController
{
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
        $salt = $this->settings->get('botchallenge_salt', '');
        $timestamp = (string) microtime();
        $hmac = hash_hmac('sha256', $salt . $timestamp, $salt);
        $token = $timestamp . '_' . $hmac;

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
        $cookieLifetimeDays = (int) $this->settings->get('botchallenge_cookie_lifetime', 90);
        $cookieLifetimeSeconds = $cookieLifetimeDays * 86400;
        $testHeadless = (bool) $this->settings->get('botchallenge_test_headless', true);

        // Prevent caching.
        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->addHeaderLine('Pragma', 'no-cache');

        $view = new ViewModel([
            'token' => $token,
            'delay' => $delay,
            'cookieLifetime' => $cookieLifetimeSeconds,
            'redirectUrl' => $redirectUrl,
            'isHttps' => $isHttps,
            'testHeadless' => $testHeadless,
        ]);
        return $view
            ->setTerminal(true);
    }

}
