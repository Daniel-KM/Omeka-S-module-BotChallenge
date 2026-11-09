<?php declare(strict_types=1);

namespace BotChallenge\Service\Controller;

use BotChallenge\Controller\IndexController;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IndexController(
            $services->get('Omeka\Settings'),
            // Missing while the module Common is not upgraded.
            $services->has('Omeka\ClientIp') ? $services->get('Omeka\ClientIp') : null
        );
    }
}
