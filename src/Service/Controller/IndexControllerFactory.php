<?php declare(strict_types=1);

namespace BotChallenge\Service\Controller;

use BotChallenge\Controller\IndexController;
use BotChallenge\Service\ClientIpTrait;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IndexControllerFactory implements FactoryInterface
{
    use ClientIpTrait;

    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IndexController(
            $services->get('Omeka\Settings'),
            $this->clientIp($services)
        );
    }
}
