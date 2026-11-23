<?php declare(strict_types=1);

namespace BotChallenge\Service;

use Psr\Container\ContainerInterface;

trait ClientIpTrait
{
    /**
     * Get the service of Omeka or Common, else the class of Common directly.
     *
     * Module Common is not loaded when it needs an upgrade, so its service is
     * missing, but its class does not depend on it.
     *
     * @return \Omeka\Stdlib\ClientIp|\Common\Stdlib\ClientIp|null
     */
    protected function clientIp(ContainerInterface $services)
    {
        if ($services->has('Omeka\ClientIp')) {
            return $services->get('Omeka\ClientIp');
        }

        if (!class_exists(\Common\Stdlib\ClientIp::class)) {
            $file = OMEKA_PATH . '/modules/Common/src/Stdlib/ClientIp.php';
            if (!is_readable($file)) {
                return null;
            }
            require_once $file;
        }

        $trustedProxies = $services->get('Config')['trusted_proxies']
            ?? $services->get('Omeka\Settings')->get('trusted_proxies', []);
        return new \Common\Stdlib\ClientIp(
            \Common\Stdlib\ClientIp::normalizeTrustedProxies($trustedProxies)
        );
    }
}
