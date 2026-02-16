<?php declare(strict_types=1);

namespace BotChallenge\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    public function init()
    {
        $this
            ->add([
                'name' => 'botchallenge_salt',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'HMAC salt', // @translate
                    'info' => 'Secret salt used to generate challenge tokens. Leave empty to regenerate automatically. Changing this value invalidates all existing cookies.', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_salt',
                ],
            ])
            ->add([
                'name' => 'botchallenge_delay',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Challenge delay (seconds)', // @translate
                    'info' => 'Number of seconds the visitor must wait before the cookie is set.', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_delay',
                    'min' => 1,
                    'max' => 30,
                ],
            ])
            ->add([
                'name' => 'botchallenge_cookie_lifetime',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Cookie lifetime (days)', // @translate
                    'info' => 'Number of days the challenge cookie remains valid.', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_cookie_lifetime',
                    'min' => 1,
                    'max' => 365,
                ],
            ])
            ->add([
                'name' => 'botchallenge_test_headless',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Detect headless browsers', // @translate
                    'info' => 'Run additional tests to detect headless browsers (Selenium, PhantomJS, Puppeteer, etc.).', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_test_headless',
                ],
            ])
            ->add([
                'name' => 'botchallenge_exception_paths',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Exception paths', // @translate
                    'info' => 'Url path prefixes to exclude from the challenge, one per line. Example: /api', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_exception_paths',
                    'rows' => 5,
                ],
            ])
            ->add([
                'name' => 'botchallenge_exception_ips',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Exception IPs', // @translate
                    'info' => 'Ips v4 or v6 or cidr ranges to exclude from the challenge, one per line.', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_exception_ips',
                    'rows' => 5,
                ],
            ]);

            $inputFilter = $this->getInputFilter();
            $inputFilter
                ->add([
                    'name' => 'botchallenge_salt',
                    'required' => false,
                ]);
    }
}
