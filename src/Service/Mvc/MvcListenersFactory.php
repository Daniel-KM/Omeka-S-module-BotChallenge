<?php declare(strict_types=1);

namespace BotChallenge\Service\Mvc;

use BotChallenge\Mvc\MvcListeners;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class MvcListenersFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new MvcListeners(
            $services->get('Omeka\Settings'),
            // Missing while the module Common is not upgraded.
            $services->has('Omeka\ClientIp') ? $services->get('Omeka\ClientIp') : null
        );
    }
}
