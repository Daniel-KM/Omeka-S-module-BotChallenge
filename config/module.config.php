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
            'botchallenge_cookie_lifetime' => 90,
            'botchallenge_test_headless' => true,
            'botchallenge_exception_paths' => [
                '/api',
                '/api-local',
            ],
            'botchallenge_exception_ips' => [],
        ],
    ],
];
