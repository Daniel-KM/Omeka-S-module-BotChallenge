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
                'name' => 'botchallenge_bind_ip',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Bind the cookie to the ip', // @translate
                    'info' => 'Add the ip to the signature, so a cookie shared with another machine is refused. Only for a controlled network: an ip changes without the visitor moving (ipv6 rotation, dual stack, private relay, vpn) and is shared by many visitors behind a nat. Toggling it invalidates all cookies.', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_bind_ip',
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
                'name' => 'botchallenge_interaction',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Action of the visitor', // @translate
                    'info' => 'Require a real action of the visitor once the delay is over. The bots that run the javascript but do not interact with the page are stopped.', // @translate
                    'value_options' => [
                        'none' => 'None (automatic redirection)', // @translate
                        'checkbox' => 'Checkbox to check', // @translate
                        'button' => 'Button to click', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'botchallenge_interaction',
                    'value' => 'none',
                ],
            ])
            ->add([
                'name' => 'botchallenge_exception_paths',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Exception paths', // @translate
                    'info' => 'Url path prefixes to exclude from the challenge, one per line. A leading star matches the end of any path, and a star inside matches any characters. Examples: /api, */rss, */sitemap-*.xml', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_exception_paths',
                    'rows' => 10,
                ],
            ])
            ->add([
                'name' => 'botchallenge_preview_user_agents',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Link preview bots', // @translate
                    'info' => 'Parts of the user agent of the bots that build the previews of the shared links (social networks, messaging apps), one per line. They cannot solve the challenge, so they get a card with the metadata of the page (title, description, thumbnail), built without dispatching the page: a scraper using such a user agent gets nothing else and does not load the server.', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_preview_user_agents',
                    'rows' => 8,
                ],
            ])
            ->add([
                'name' => 'botchallenge_preview_modules',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Modules adding the metadata of the link previews', // @translate
                    'info' => 'Namespaces of the modules whose listeners add the metadata of the pages (MetaTags, ResourceMeta, Sharing…), one per line. Only their listeners are called to build the preview, so their settings apply, without dispatching the page.', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_preview_modules',
                    'rows' => 3,
                ],
            ])
            ->add([
                'name' => 'botchallenge_exception_rdns',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Bots verified by reverse dns', // @translate
                    'info' => 'One bot by line, as "part of the user agent = domain", for example "TelegramBot = ptr.telegram.org". The bot is excluded from the challenge when its ip resolves to a host of the domain and this host resolves back to its ip.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'botchallenge_exception_rdns',
                    'rows' => 4,
                ],
            ])
            ->add([
                'name' => 'botchallenge_exception_bots',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Exempted search engines', // @translate
                    'info' => 'Search engines whose published ip ranges are excluded from the challenge. Without exemption, they receive the javascript challenge, do not solve it, and stop indexing the site. The ranges are bundled with the module and may be updated with the job below.', // @translate
                    'value_options' => \BotChallenge\Stdlib\BotIps::valueOptions(),
                ],
                'attributes' => [
                    'id' => 'botchallenge_exception_bots',
                ],
            ])
            ->add([
                'name' => 'botchallenge_refresh_ips',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Update the ip ranges now', // @translate
                    'info' => 'Run a job to fetch the ip ranges from the sources of the search engines. A source that is unreachable or truncated is skipped and the bundled ranges are kept.', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_refresh_ips',
                ],
            ])
            ->add([
                'name' => 'botchallenge_exception_ips',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Exception IPs', // @translate
                    'info' => 'Ips v4 or v6 or cidr ranges to exclude from the challenge, one per line: the networks of the institution, a partner harvester, a specific bot. They are added to the ranges of the search engines above.', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_exception_ips',
                    'rows' => 10,
                ],
            ]);

            $inputFilter = $this->getInputFilter();
            $inputFilter
                ->add([
                    'name' => 'botchallenge_salt',
                    'required' => false,
                    'filters' => [
                        ['name' => \Laminas\Filter\StringTrim::class],
                    ],
                ])
                ->add([
                    'name' => 'botchallenge_delay',
                    'filters' => [
                        ['name' => \Laminas\Filter\ToInt::class],
                    ],
                    'validators' => [
                        ['name' => \Laminas\Validator\Between::class, 'options' => ['min' => 1, 'max' => 30]],
                    ],
                ])
                ->add([
                    'name' => 'botchallenge_cookie_lifetime',
                    'filters' => [
                        ['name' => \Laminas\Filter\ToInt::class],
                    ],
                    'validators' => [
                        ['name' => \Laminas\Validator\Between::class, 'options' => ['min' => 1, 'max' => 365]],
                    ],
                ])
                ->add([
                    'name' => 'botchallenge_refresh_ips',
                    'required' => false,
                ])
                ->add([
                    'name' => 'botchallenge_interaction',
                    'required' => false,
                ])
                ->add([
                    'name' => 'botchallenge_exception_bots',
                    'required' => false,
                    'filters' => [
                        [
                            'name' => \Laminas\Filter\Callback::class,
                            'options' => [
                                'callback' => fn ($bots): array => array_values(array_intersect((array) $bots, array_keys(\BotChallenge\Stdlib\BotIps::BOTS))),
                            ],
                        ],
                    ],
                ]);
    }
}
