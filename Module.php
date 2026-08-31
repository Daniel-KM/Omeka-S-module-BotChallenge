<?php declare(strict_types=1);

namespace BotChallenge;

use BotChallenge\Controller\IndexController;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Stdlib\Message;

// The module namespace is not registered by Omeka while the module is inactive,
// but the config file and the upgrade script reference BotIps.
require_once __DIR__ . '/src/Stdlib/BotIps.php';

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

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

    public function install($serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka\Settings');

        $configLocal = include __DIR__ . '/config/module.config.php';
        $configLocal = $configLocal['botchallenge']['config'];

        foreach ($configLocal as $key => $value) {
            $settings->set($key, $value);
        }

        $salt = bin2hex(random_bytes(32));
        $settings->set('botchallenge_salt', $salt);
    }

    public function uninstall($serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka\Settings');

        $configLocal = include __DIR__ . '/config/module.config.php';
        $configLocal = $configLocal['botchallenge']['config'];

        foreach (array_keys($configLocal) as $key) {
            $settings->delete($key);
        }
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services): void
    {
        $this->setServiceLocator($services);
        $filepath = __DIR__ . '/data/scripts/upgrade.php';
        require_once $filepath;
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $formElementManager = $this->getServiceLocator()->get('FormElementManager');
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        $configLocal = include __DIR__ . '/config/module.config.php';
        $configLocal = $configLocal['botchallenge']['config'];

        $values = [];
        foreach ($configLocal as $key => $value) {
            $values[$key] = $settings->get($key, $value);
        }


        $form = $formElementManager->get(Form\ConfigForm::class);
        $form->setData($values);

        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $formElementManager = $this->getServiceLocator()->get('FormElementManager');
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        $form = $formElementManager->get(Form\ConfigForm::class);
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $formData = $form->getData();

        // Regenerate salt if empty.
        $salt = trim($formData['botchallenge_salt'] ?? '');
        if ($salt === '') {
            $salt = bin2hex(random_bytes(32));
        }

        $settings->set('botchallenge_salt', $salt);
        $settings->set('botchallenge_delay', (int) $formData['botchallenge_delay']);
        $settings->set('botchallenge_cookie_lifetime', (int) $formData['botchallenge_cookie_lifetime']);
        $settings->set('botchallenge_test_headless', (bool) $formData['botchallenge_test_headless']);
        $settings->set('botchallenge_exception_paths', $formData['botchallenge_exception_paths'] ?? []);

        $bots = array_values(array_intersect(
            $formData['botchallenge_exception_bots'] ?? [],
            array_keys(Stdlib\BotIps::BOTS)
        ));
        $exceptionIps = $formData['botchallenge_exception_ips'] ?? [];
        $settings->set('botchallenge_exception_bots', $bots);
        $settings->set('botchallenge_exception_ips', $exceptionIps);

        // The ranges of the bots (bundled files or the base override refreshed
        // by the job) and the manual ones are merged once here, so the check of
        // each request reads a single list.
        $override = $settings->get('botchallenge_bot_ips') ?: [];
        $settings->set('botchallenge_exception_ips_all', array_values(array_unique(array_merge(
            Stdlib\BotIps::ipsForBots($bots, $override),
            $exceptionIps
        ))));

        if (!empty($formData['botchallenge_refresh_ips'])) {
            $services = $this->getServiceLocator();
            $urlPlugin = $controller->plugin('url');
            $messenger = $controller->plugin('messenger');
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
            $messenger->addSuccess($message);
        }

        return true;
    }
}
