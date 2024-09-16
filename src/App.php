<?php

namespace HoldGrip;

use Doctrine\DBAL\DriverManager;
use HoldGrip\Controller\PlayerController;
use HoldGrip\Controller\TrackController;
use Kint;
use Pimple\Container;
use SensitiveParameter;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\TwigFilter;
use Twig\Loader\FilesystemLoader;

class App
{
    private readonly Container $container;
    private bool $booted = false;

    public function __construct()
    {
        $this->container = new Container();

        // Databases
        $this->container['db'] = fn($c) => DriverManager::getConnection($c['config']['db']);
        $this->container['flip_db'] = fn($c) => DriverManager::getConnection($c['config']['flip_db']);
        $this->container['external_db'] = fn($c) => DriverManager::getConnection($c['config']['external_db']);

        // Twig
        $this->container['twig'] = function ($c) {
            $twig = new Environment(
                new FilesystemLoader($c['config']['twig']['templates']),
                [
                    'cache' => $c['config']['twig']['cache'],
                    'debug' => $c['config']['debug'],
                ]
            );

            $twig->addGlobal('stylehash', hash_file('md5', dirname(__DIR__).'/html/style.css'));
            $twig->addFilter(new TwigFilter('int_time', TwigFilters::time(...), ['is_safe' => ['html']]));
            $twig->addFilter(new TwigFilter('int_place', TwigFilters::place(...), ['is_safe' => ['html']]));

            return $twig;
        };

        // HTTPKernel
        $this->container['dispatcher'] = fn($c) => new EventDispatcher();
        $this->container['controller_resolver'] = fn($c) => new ControllerResolver($c, null);
        $this->container['request_stack'] = fn($c) => new RequestStack();
        $this->container['argument_resolver'] = fn($c) => new ArgumentResolver();
        $this->container['kernel'] = fn($c) => new HttpKernel(
            $c['dispatcher'],
            $c['controller_resolver'],
            $c['request_stack'],
            $c['argument_resolver'],
        );

        // Router
        $this->container['request_context'] = fn($c) => new RequestContext();
        $this->container['url_matcher'] = fn($c) => new UrlMatcher($c['route_collection'], $c['request_context']);
        $this->container['route_collection'] = function ($c) {
            $routes = new RouteCollection();
            foreach ($c['routes'] as $name => $routeinfo) {
                $routes->add($name, new Route(
                    $routeinfo['url'],
                    $routeinfo['defaults'] ?? [],
                    $routeinfo['requirements'] ?? []
                ));
            }
            return $routes;
        };
        $this->container['routes'] = fn($c) => $this->getRoutes($c['config']);
        $this->container['router_listener'] = fn($c) => new RouterListener(
            $c['url_matcher'],
            $c['request_stack'],
            $c['request_context'],
            null,
            null,
            $c['config']['debug']
        );
        $this->container['404_listener'] = fn($c) => new FileNotFoundListener($c['twig']);
        $this->container['cache_listener'] = fn($c) => new CacheListener(
            $c['config']['db_lifetime'],
            filemtime($c['config']['db']['path'])
        );
        $this->container->extend('dispatcher', function ($dispatcher, $c) {
            $dispatcher->addSubscriber($c['router_listener']);
            $dispatcher->addSubscriber($c['404_listener']);

            if (!$c['config']['debug']) {
                $dispatcher->addSubscriber($c['cache_listener']);
            }

            return $dispatcher;
        });

        // Controllers
        $this->container['controller.player'] = fn($c) => new PlayerController(
            $c['db'],
            $c['twig'],
            $c['config']['lb_types']
        );
        $this->container['controller.track'] = fn($c) => new TrackController(
            $c['db'],
            $c['twig'],
            $c['config']['lb_types']
        );

        // Updater
        $this->container['updater'] = fn($c) => new DataUpdater($c['external_db'], $c['config']['lb_types']);

        // Config
        $this->container['config'] = $this->getConfig();
    }

    private function getConfig()
    {
        return [
            'twig' => [
                'templates' => dirname(__DIR__).'/views',
                'cache' => dirname(__DIR__).'/var/twig',
            ],
            'db' => [
                'path' => dirname(__DIR__).'/var/db/holdgrip.sqlite',
                'driver' => 'pdo_sqlite',
            ],
            'flip_db' => [
                'path' => dirname(__DIR__).'/var/db/holdgrip.flip.sqlite',
                'driver' => 'pdo_sqlite',
            ],
            'external_db' => [
                'dbname' => getenv('DB_NAME'),
                'user' => getenv('DB_USER'),
                'password' => getenv('DB_PASSWORD'),
                'host' => getenv('DB_HOST'),
                'port' => getenv('DB_PORT') ?: null,
                'driver' => 'pdo_pgsql',
            ],
            'db_lifetime' => 'PT30M',
            'lb_types' => [
                'sprint' => [
                    'name' => 'sprint',
                    'label' => 'Sprint',
                    'score_field' => 'time',
                    'score_label' => 'Time',
                    'top_size' => 30,
                    'dampen' => 0.25,
                    'top_weight' => 0.8,
                    'unfinished_weight' => 0.2,
                ],
                'challenge' => [
                    'name' => 'challenge',
                    'label' => 'Challenge',
                    'score_field' => 'time',
                    'score_label' => 'Time',
                    'top_size' => 10,
                    'dampen' => 0.25,
                    'top_weight' => 0.8,
                    'unfinished_weight' => 0.2,
                ],
                'stunt' => [
                    'name' => 'stunt',
                    'label' => 'Stunt',
                    'score_field' => 'score',
                    'score_label' => 'eV',
                    'top_size' => 10,
                    'dampen' => 0.5,
                    'top_weight' => 1,
                    'unfinished_weight' => 0,
                ],
            ],
            'default_lb_type' => 'sprint',
            'debug' => (bool) getenv('DEBUG'),
        ];
    }

    public function getRoutes(#[SensitiveParameter] array $config)
    {
        $defaultType = $config['default_lb_type'];
        $typereq = [];
        foreach ($config['lb_types'] as $type) {
            $typereq[] = $type['name'];
        }
        $typereq = implode('|', $typereq);

        return [
            'player' => [
                'url' => '/player/{id}/{type}',
                'defaults' => [
                    '_controller' => 'controller.player::show',
                    'type' => $defaultType,
                ],
                'requirements' => [
                    'type' => $typereq,
                ],
            ],
            'tracks' => [
                'url' => '/tracks/{type}/{ranking}',
                'defaults' => [
                    '_controller' => 'controller.track::list',
                ],
                'requirements' => [
                    'type' => $typereq,
                    'ranking' => implode('|', [
                        TrackController::RANK_WEIGHT,
                        TrackController::RANK_COMPLETED,
                        TrackController::RANK_POPULAR,
                    ]),
                ],
            ],
            'track' => [
                'url' => '/tracks/{type}/{id}',
                'defaults' => [
                    '_controller' => 'controller.track::show',
                ],
                'requirements' => [
                    'type' => $typereq,
                    'id' => '\\d+',
                ],
            ],
            'index' => [
                'url' => '/{type}',
                'defaults' => [
                    '_controller' => 'controller.player::list',
                    'type' => $defaultType,
                ],
                'requirements' => [
                    'type' => $typereq,
                ],
            ],
        ];
    }

    public function updateDatabase()
    {
        $this->container['updater']->buildDb($this->container['flip_db']);
        $this->container['flip_db']->close();

        $path = $this->container['config']['db']['path'];
        $flip_path = $this->container['config']['flip_db']['path'];

        unlink($path);
        rename($flip_path, $path);
        echo "DB flip completed. DB size: ".number_format(filesize($path)).PHP_EOL;
    }

    protected function boot()
    {
        if ($this->booted) {
            return;
        } else {
            $this->booted = true;
        }

        if (class_exists(Kint::class)) {
            Kint::$enabled_mode = $this->container['config']['debug'];
        }

        if ($this->container['config']['debug']) {
            Debug::enable();
        }
    }

    public function handle(Request $req): Response
    {
        $this->boot();

        $res = $this->container['kernel']->handle($req);
        $res->send();
        $this->container['kernel']->terminate($req, $res);
        return $res;
    }
}
