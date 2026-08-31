<?php declare(strict_types=1);

namespace BotChallenge;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Settings\Settings $settings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$settings = $services->get('Omeka\Settings');
$messenger = $services->get('ControllerPluginManager')->get('messenger');

$configLocal = require dirname(__DIR__, 2) . '/config/module.config.php';

if (version_compare($oldVersion, '3.4.2', '<')) {
    $exceptionPaths = $settings->get('botchallenge_exception_paths')
        ?? $configLocal['botchallenge']['config']['botchallenge_exception_paths'];
    $exceptionPaths = array_unique(array_merge($exceptionPaths, $configLocal['botchallenge']['config']['botchallenge_exception_paths']));
    sort($exceptionPaths);
    $settings->set('botchallenge_exception_paths', $exceptionPaths);
}

if (version_compare($oldVersion, '3.4.3', '<')) {
    // The ip ranges of the search engines are now bundled.
    $bots = $configLocal['botchallenge']['config']['botchallenge_exception_bots'];
    $exceptionIps = $settings->get('botchallenge_exception_ips') ?: [];

    $settings->set('botchallenge_exception_bots', $bots);
    $settings->set('botchallenge_bot_ips', []);
    $settings->set('botchallenge_exception_ips_all', array_values(array_unique(array_merge(
        Stdlib\BotIps::ipsForBots($bots),
        $exceptionIps
    ))));

    // New default exception paths.
    $exceptionPaths = $settings->get('botchallenge_exception_paths')
        ?? $configLocal['botchallenge']['config']['botchallenge_exception_paths'];
    $exceptionPaths = array_unique(array_merge($exceptionPaths, $configLocal['botchallenge']['config']['botchallenge_exception_paths']));
    sort($exceptionPaths);
    $settings->set('botchallenge_exception_paths', $exceptionPaths);
}

if (version_compare($oldVersion, '3.4.4', '<')) {
    // The cookie is now signed with the user agent and renewed once half of its
    // lifetime is spent, so a short lifetime no longer challenges an active
    // visitor again. Ninety days turned a single leaked cookie into a nearly
    // permanent access for a whole botnet.
    $oldLifetime = (int) $settings->get('botchallenge_cookie_lifetime', 90);
    $newLifetime = (int) $configLocal['botchallenge']['config']['botchallenge_cookie_lifetime'];
    if ($oldLifetime !== $newLifetime) {
        $settings->set('botchallenge_cookie_lifetime', $newLifetime);
        $message = new \Omeka\Stdlib\Message(
            'The lifetime of the challenge cookie was changed from %1$s to %2$s days, because the cookie is now renewed automatically as long as the visitor is active. The previous value (%1$s days) can be set again in the config of the module.', // @translate
            $oldLifetime, $newLifetime
        );
        $messenger->addWarning($message);
    }

    $message = new \Omeka\Stdlib\Message(
        'The existing challenge cookies are invalid: each visitor will pass the challenge once again. This is wanted, since a cookie obtained before this version was usable by any client.' // @translate
    );
    $messenger->addWarning($message);
}

// The settings added by a new version are not written by install(), that runs
// only once, so add the ones missing from the config. Done on each upgrade, so
// a setting added later is never forgotten.
foreach ($configLocal['botchallenge']['config'] as $key => $value) {
    if ($key !== 'botchallenge_salt' && $settings->get($key) === null) {
        $settings->set($key, $value);
    }
}

// An empty salt would sign every cookie the same way.
if (!$settings->get('botchallenge_salt')) {
    $settings->set('botchallenge_salt', bin2hex(random_bytes(32)));
}
