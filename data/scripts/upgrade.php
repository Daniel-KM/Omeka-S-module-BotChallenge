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
$translator = $services->get('MvcTranslator');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.96')) {
    $message = new \Omeka\Stdlib\Message(
        $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.96'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translator->translate('Missing requirement. Unable to upgrade.')); // @translate
}

$configLocal = require dirname(__DIR__, 2) . '/config/module.config.php';

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

// Merge the default exception paths on each upgrade: they are machine endpoints
// (api, iiif, oai, auth callbacks, protected files…) that break the service
// when challenged, so a path added in a later version must reach existing
// instances, which a version-gated merge would miss. Exempting them relaxes
// nothing: the Omeka and Access rights still apply. Custom paths are kept.
$exceptionPaths = $settings->get('botchallenge_exception_paths') ?: [];
$exceptionPaths = array_values(array_unique(array_merge(
    $exceptionPaths,
    $configLocal['botchallenge']['config']['botchallenge_exception_paths']
)));
sort($exceptionPaths);
$settings->set('botchallenge_exception_paths', $exceptionPaths);

// The ranges of the exempted bots are merged on each upgrade too: a fresh
// install did not merge them, so the search engines were challenged until the
// config was saved, and the bundled ranges may have been updated.
$this->updateExceptionIps($settings);

// An empty salt would sign every cookie the same way.
if (!$settings->get('botchallenge_salt')) {
    $settings->set('botchallenge_salt', bin2hex(random_bytes(32)));
}

// The trusted proxies are managed in the main settings (module Common): merge
// the list of the module into it.
$moduleTrustedProxies = $settings->get('botchallenge_trusted_proxies');
if ($moduleTrustedProxies !== null) {
    $trustedProxies = array_values(array_unique(array_filter(array_merge(
        (array) ($settings->get('trusted_proxies') ?: []),
        is_array($moduleTrustedProxies) ? $moduleTrustedProxies : preg_split('/[\s,;]+/', (string) $moduleTrustedProxies)
    ))));
    $settings->set('trusted_proxies', $trustedProxies);
    $settings->delete('botchallenge_trusted_proxies');
    if ($trustedProxies) {
        $messenger->addWarning(new \Omeka\Stdlib\Message(
            'The trusted reverse proxies are now managed in the security section of the main settings and apply to all modules, including the ones that grant rights or lock out by ip. Check that each one is a reverse proxy of the server: %s.', // @translate
            implode(', ', $trustedProxies)
        ));
    }
}
