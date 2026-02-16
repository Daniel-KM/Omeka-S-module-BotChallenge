<?php declare(strict_types=1);

namespace BotChallenge;

use BotChallenge\Controller\IndexController;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;

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
        $settings->set('botchallenge_exception_ips', $formData['botchallenge_exception_ips'] ?? []);

        return true;
    }
}
