<?php declare(strict_types=1);

namespace BotChallenge;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Settings\Settings $settings
 */
$settings = $services->get('Omeka\Settings');

$configLocal = require dirname(__DIR__, 2) . '/config/module.config.php';

if (version_compare($oldVersion, '3.4.2', '<')) {
    $exceptionPaths = $settings->get('botchallenge_exception_paths')
        ?? $configLocal['botchallenge']['config']['botchallenge_exception_paths'];
    $exceptionPaths = array_unique(array_merge($exceptionPaths, $configLocal['botchallenge']['config']['botchallenge_exception_paths']));
    sort($exceptionPaths);
    $settings->set('botchallenge_exception_paths', $exceptionPaths);
}
