<?php declare(strict_types=1);

namespace BotChallenge;

// Common may be installed but not registered in autoloader, in particular
// during upgrade. So dynamically register all classes of the module.
if (!defined('COMMON_PSR4_FALLBACK')) {
    foreach ([
        OMEKA_PATH . '/modules/Common/src',
        OMEKA_PATH . '/composer-addons/modules/Common/src',
        dirname(__DIR__) . '/Common/src',
    ] as $commonSrc) {
        if (file_exists($commonSrc . '/TraitModule.php')) {
            define('COMMON_PSR4_FALLBACK', $commonSrc);
            spl_autoload_register(static function ($class): void {
                if (str_starts_with($class, 'Common\\')) {
                    $file = COMMON_PSR4_FALLBACK . '/' . strtr(substr($class, 7), '\\', '/') . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
            });
            break;
        }
    }
}

// The module namespace is not registered by Omeka while the module is inactive,
// but the config file and the upgrade script reference BotIps.
require_once __DIR__ . '/src/Stdlib/BotIps.php';

use BotChallenge\Controller\IndexController;
use Common\TraitModule;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl
            ->allow(
                null,
                IndexController::class
            );
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.96')) {
            $message = new Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.96'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    protected function postInstall(): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->set('botchallenge_salt', bin2hex(random_bytes(32)));
        $this->updateExceptionIps($settings);
        $this->postInstallAuto();
    }

    /**
     * Merge the ip ranges of the exempted bots and the manual ones.
     *
     * The ranges of the bots are the bundled files or the base override
     * refreshed by the job. They are merged once, so the check of each request
     * reads a single list.
     */
    protected function updateExceptionIps(\Omeka\Settings\Settings $settings): void
    {
        $bots = $settings->get('botchallenge_exception_bots') ?: [];
        $override = $settings->get('botchallenge_bot_ips') ?: [];
        $exceptionIps = $settings->get('botchallenge_exception_ips') ?: [];
        $settings->set('botchallenge_exception_ips_all', array_values(array_unique(array_merge(
            Stdlib\BotIps::ipsForBots($bots, $override),
            $exceptionIps
        ))));
    }

    /**
     * Save the options, then regenerate an empty salt, merge the exception ips
     * and refresh the ranges of the search engines when requested.
     */
    public function handleConfigForm(AbstractController $controller)
    {
        if (!$this->handleConfigFormAuto($controller)) {
            return false;
        }

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        if ((string) $settings->get('botchallenge_salt') === '') {
            $settings->set('botchallenge_salt', bin2hex(random_bytes(32)));
        }

        $this->updateExceptionIps($settings);

        if (!empty($controller->getRequest()->getPost('botchallenge_refresh_ips'))) {
            $urlPlugin = $controller->plugin('url');
            $job = $services->get('Omeka\Job\Dispatcher')
                ->dispatch(Job\RefreshBotIps::class);
            $message = new Message(
                'Updating the ip ranges of the search engines in a background job (%1$sjob #%2$s%3$s, %4$slogs%3$s).', // @translate
                sprintf('<a href="%s">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                $job->getId(),
                '</a>',
                class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', htmlspecialchars($urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])))
                    : sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])))
            );
            $message->setEscapeHtml(false);
            $controller->messenger()->addSuccess($message);
        }

        $this->redirectToConfigFormOnApply($controller);
        return true;
    }
}
