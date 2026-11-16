<?php declare(strict_types=1);

namespace BotChallenge;

return [
    'service_manager' => [
        'factories' => [
            Mvc\MvcListeners::class => Service\Mvc\MvcListenersFactory::class,
        ],
    ],
    'listeners' => [
        Mvc\MvcListeners::class,
    ],
    'controllers' => [
        'factories' => [
            Controller\IndexController::class => Service\Controller\IndexControllerFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],
    'router' => [
        'routes' => [
            'bot-challenge' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route' => '/bot-challenge',
                    'defaults' => [
                        '__NAMESPACE__' => 'BotChallenge\Controller',
                        'controller' => Controller\IndexController::class,
                        'action' => 'index',
                    ],
                ],
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'botchallenge' => [
        'config' => [
            'botchallenge_salt' => '',
            'botchallenge_delay' => 5,
            'botchallenge_cookie_lifetime' => 7,
            'botchallenge_test_headless' => true,
            'botchallenge_interaction' => 'none',
            'botchallenge_bind_ip' => false,
            // Machine endpoints and token authorized links, where a browser
            // challenge is meaningless or harmful or breaks the service (api,
            // images, feeds, harvesting, callbacks, downloads).
            // A path starting with a star matches on the last segment
            // (search autocompletion, rss…).
            // Exempting relaxes nothing: Omeka and Access rights still apply.
            'botchallenge_exception_paths' => [
                '*/atom',
                '*/rss',
                '*/suggest',
                '/.well-known',
                '/access/authorize',
                '/access/files',
                '/api',
                '/api-local',
                '/atom',
                '/cas',
                '/contact-us/zip',
                '/derivative',
                '/download',
                '/favicon.ico',
                '/feed',
                '/filegate',
                '/files',
                '/iiif',
                '/iiif-presentation',
                '/iiif-search',
                '/oai',
                '/oembed',
                '/pdf',
                '/robots.txt',
                '/rss',
                '/saml',
                '/security.txt',
                '/session-token',
                '/sitemap.xml',
                '/sparql',
                '/sso',
                '/unapi',
                '/uri-dereferencer',
                '/validate',
                '/zip-download',
            ],
            // Link preview bots (social networks, messaging apps): they get
            // the head of the page only, with its metadata, never its body.
            'botchallenge_preview_user_agents' => [
                'Akkoma',
                'Bluesky',
                'Discordbot',
                'Embedly',
                'facebookexternalhit',
                'Friendica',
                'Iframely',
                'Lemmy',
                'LinkedInBot',
                'Mastodon/',
                'Misskey',
                'Pinterestbot',
                'Pleroma',
                'redditbot',
                'SkypeUriPreview',
                'Slackbot',
                'TelegramBot',
                'Twitterbot',
                'vkShare',
                'WhatsApp',
            ],
            // Bots verified by their network (forward-confirmed reverse dns):
            // part of the user agent => domain of the reverse dns of its ips.
            'botchallenge_exception_rdns' => [
                'TelegramBot' => 'ptr.telegram.org',
            ],
            // Cache of the reverse dns verifications by ip, and pause of them
            // when the dns is slow.
            'botchallenge_rdns_cache' => [],
            'botchallenge_rdns_paused_until' => 0,
            'botchallenge_exception_bots' => \BotChallenge\Stdlib\BotIps::DEFAULT_BOTS,
            'botchallenge_exception_ips' => [],
            // Ranges refreshed from the sources, by bot.
            'botchallenge_bot_ips' => [],
            // Ranges of the exempted bots and manual ranges, merged when the
            // config is saved and read by the listener of each request.
            'botchallenge_exception_ips_all' => [],
        ],
    ],
];
