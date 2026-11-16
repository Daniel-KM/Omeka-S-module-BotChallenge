<?php declare(strict_types=1);

namespace BotChallenge\Mvc;

use Laminas\EventManager\Event;
use Laminas\Http\Response;
use Laminas\Mvc\MvcEvent;
use Omeka\Api\Exception\NotFoundException;

/**
 * Build the link preview of a page for a bot, without dispatching the page.
 *
 * The user agent of a preview bot is declarative, so the preview must cost as
 * little as the challenge itself: it reads the resource or the site page only,
 * never runs a search, a controller or the templates of the theme.
 *
 * The metadata are added by the listeners of the modules that manage them
 * (MetaTags, ResourceMeta, Sharing), called on a minimal view, so their
 * settings apply. Without them, the main metadata are added from the resource,
 * the page or the site.
 */
class LinkPreview
{
    /**
     * Resource controllers mapped to their api name and full controller name.
     */
    const RESOURCES = [
        'item' => ['items', 'Omeka\Controller\Site\Item', 'item'],
        'itemset' => ['item_sets', 'Omeka\Controller\Site\ItemSet', 'itemSet'],
        'media' => ['media', 'Omeka\Controller\Site\Media', 'media'],
    ];

    /**
     * @param string[] $modules Namespaces of the modules whose listeners add
     *   the metadata.
     */
    public function __invoke(MvcEvent $event, array $modules): Response
    {
        $services = $event->getApplication()->getServiceManager();
        $routeMatch = $event->getRouteMatch();
        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $services->get('ViewRenderer');
        $site = $view->currentSite();

        // The controller may be short ("item", "item-set") or full, with the
        // module CleanUrl too.
        $controller = strtolower(strtr((string) ($routeMatch->getParam('__CONTROLLER__') ?: $routeMatch->getParam('controller')), ['-' => '']));
        $parts = explode('\\', $controller);
        $controller = (string) end($parts);
        $action = (string) $routeMatch->getParam('action');
        $api = $services->get('Omeka\ApiManager');

        $vars = ['site' => $site];
        $identifier = null;
        $title = '';
        $status = Response::STATUS_CODE_200;
        try {
            if (isset(self::RESOURCES[$controller]) && $action === 'show' && $routeMatch->getParam('id')) {
                [$resourceName, $identifier, $varName] = self::RESOURCES[$controller];
                $resource = $api->read($resourceName, ['id' => $routeMatch->getParam('id')])->getContent();
                $vars += ['resource' => $resource, $varName => $resource, 'previewResource' => $resource];
                $title = (string) $resource->displayTitle();
            } elseif ($controller === 'page' && $action === 'show' && $site && $routeMatch->getParam('page-slug')) {
                $page = $api->read('site_pages', ['site' => $site->id(), 'slug' => $routeMatch->getParam('page-slug')])->getContent();
                $identifier = 'Omeka\Controller\Site\Page';
                $vars += ['page' => $page, 'previewPage' => $page];
                $title = (string) $page->title();
            }
        } catch (NotFoundException $e) {
            $status = Response::STATUS_CODE_404;
        }

        $view->vars()->assign($vars);
        $view->headTitle($title ?: ($site ? (string) $site->title() : (string) $view->setting('installation_title', 'Omeka S')));

        if ($identifier && $status === Response::STATUS_CODE_200) {
            $this->triggerModuleListeners($services, $view, $identifier, $modules);
        }

        $response = $event->getResponse();
        $response->setStatusCode($status);
        $response->getHeaders()
            ->addHeaderLine('Content-Type', 'text/html; charset=utf-8')
            ->addHeaderLine('Cache-Control', 'no-store');
        $response->setContent($view->render('bot-challenge/preview'));
        return $response;
    }

    /**
     * Call the listeners of the allowed modules for the view events of a page.
     *
     * Only these listeners are called, so another module listening the same
     * events (comments, related resources…) does not run. Their output, like
     * the sharing buttons, is discarded: only the head metadata are kept.
     */
    protected function triggerModuleListeners($services, $view, string $identifier, array $modules): void
    {
        $prefixes = array_map(fn ($module) => trim((string) $module, '\\') . '\\', array_filter($modules));
        if (!$prefixes) {
            return;
        }
        $sharedEvents = $services->get('SharedEventManager');
        $logger = $services->get('Omeka\Logger');
        foreach (['view.show.before', 'view.show.after'] as $eventName) {
            $listenersByPriority = $sharedEvents->getListeners([$identifier], $eventName);
            krsort($listenersByPriority);
            $event = new Event($eventName, $view);
            foreach ($listenersByPriority as $listeners) {
                foreach ($listeners as $listener) {
                    $object = is_array($listener) ? ($listener[0] ?? null) : null;
                    $class = is_object($object) ? get_class($object) : '';
                    if ($class === '' || !array_filter($prefixes, fn ($prefix) => strpos($class, $prefix) === 0)) {
                        continue;
                    }
                    ob_start();
                    try {
                        $listener($event);
                    } catch (\Throwable $e) {
                        $logger->warn(
                            'BotChallenge: the link preview listener {listener} failed: {message}', // @translate
                            ['listener' => $class . '::' . $listener[1], 'message' => $e->getMessage()]
                        );
                    } finally {
                        ob_end_clean();
                    }
                }
            }
        }
    }
}
