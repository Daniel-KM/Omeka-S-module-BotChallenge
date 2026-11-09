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
                'name' => 'botchallenge_exception_paths',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Exception paths', // @translate
                    'info' => 'Url path prefixes to exclude from the challenge, one per line. Example: /api', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_exception_paths',
                    'rows' => 10,
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
            ])
            ->add([
                'name' => 'botchallenge_trusted_proxies',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Trusted reverse proxies', // @translate
                    'info' => 'Ips or cidr ranges of the reverse proxies in front of the server (Traefik, nginx, load balancer, cdn), one per line, to use instead of the trusted reverse proxies of the main settings. The ip of the visitor is read in the headers X-Forwarded-For and X-Real-IP only when the request comes from one of them, else anybody could write an excepted ip in them. When both lists are empty, the proxies with a private or loopback address are trusted, that is the common case of a proxy in the same host or network.', // @translate
                ],
                'attributes' => [
                    'id' => 'botchallenge_trusted_proxies',
                    'rows' => 4,
                    'placeholder' => "127.0.0.1\n172.16.0.0/12",
                ],
            ]);

            $inputFilter = $this->getInputFilter();
            $inputFilter
                ->add([
                    'name' => 'botchallenge_salt',
                    'required' => false,
                ])
                ->add([
                    'name' => 'botchallenge_exception_bots',
                    'required' => false,
                ]);
    }
}
