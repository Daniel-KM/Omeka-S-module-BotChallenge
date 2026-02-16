<?php declare(strict_types=1);

namespace BotChallengeTest\Mvc;

use BotChallenge\Mvc\MvcListeners;
use Laminas\Mvc\MvcEvent;
use Omeka\Settings\Settings;

/**
 * Testable subclass that exposes protected methods of MvcListeners.
 *
 * MvcListeners::handleChallenge() checks PHP_SAPI === 'cli' and returns
 * immediately in test environment. This subclass allows testing the
 * individual protected methods directly.
 */
class TestableMvcListeners extends MvcListeners
{
    public function __construct(Settings $settings)
    {
        parent::__construct($settings);
    }

    public function publicIpInCidr(string $ip, string $cidr): bool
    {
        return $this->ipInCidr($ip, $cidr);
    }

    public function publicGetClientIp(MvcEvent $event): string
    {
        return $this->getClientIp($event);
    }
}
