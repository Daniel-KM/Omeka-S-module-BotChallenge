<?php declare(strict_types=1);

namespace BotChallenge\Service\Mvc;

use BotChallenge\Mvc\MvcListeners;
use BotChallenge\Service\ClientIpTrait;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class MvcListenersFactory implements FactoryInterface
{
    use ClientIpTrait;

    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new MvcListeners(
            $services->get('Omeka\Settings'),
            $this->clientIp($services)
        );
    }
}
