<?php

namespace DHB;

use DHB\Controller\PlayerController;
use DHB\Controller\TrackController;
use DHB\DataUpdater;
use Doctrine\DBAL\Configuration as DBALConfig;
use Doctrine\DBAL\Logging\DebugStack;
use Doctrine\DBAL\DriverManager;
use Kint;
use Pimple\Container;
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
use Twig\Loader\FilesystemLoader;

class App
{
    private readonly Container $container;
    private bool $booted = false;

    public function __construct()
    {
        $this->container = new Container();

        $this->container['db'] = fn($c) => DriverManager::getConnection(
            $c['config']['db'],
            $c['dbal_config']
        );
        $this->container['flip_db'] = fn($c) => DriverManager::getConnection(
            $c['config']['flip_db'],
            $c['dbal_config']
        );
        $this->container['external_db'] = fn($c) => DriverManager::getConnection(
            $c['config']['external_db'],
            $c['dbal_config']
        );
        $this->container['sql_logger'] = function ($c) {
            $logger = new DebugStack();
            $logger->enabled = $c['config']['debug'];
            return $logger;
        };
        $this->container['dbal_config'] = function ($c) {
            $conf = new DBALConfig();
            $conf->setSqlLogger($c['sql_logger']);
            return $conf;
        };

        $this->container['twig'] = fn($c) => new Environment(
            new FilesystemLoader($c['config']['twig']['templates']),
            [
                'cache' => $c['config']['twig']['cache'],
                'debug' => $c['config']['debug'],
            ]
        );

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

        $this->container['route_collection'] = function ($c) {
            $routes = new RouteCollection();
            foreach ($c['config']['routes'] as $name => $routeinfo) {
                $routes->add($name, new Route($routeinfo['url'], $routeinfo['defaults']));
            }
            return $routes;
        };

        $this->container['request_context'] = fn($c) => new RequestContext();
        $this->container['url_matcher'] = fn($c) => new UrlMatcher($c['route_collection'], $c['request_context']);

        $this->container['router_listener'] = fn($c) => new RouterListener(
            $c['url_matcher'],
            $c['request_stack'],
            $c['request_context'],
            null,
            null,
            $c['config']['debug']
        );

        $this->container->extend('dispatcher', function ($dispatcher, $c) {
            $dispatcher->addSubscriber($c['router_listener']);
            return $dispatcher;
        });

        $this->container['controller.player'] = fn($c) => new PlayerController($c['db'], $c['twig']);
        $this->container['controller.track'] = fn($c) => new TrackController($c['db'], $c['twig']);

        $this->container['updater'] = fn($c) => new DataUpdater($c['external_db']);

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
                'path' => dirname(__DIR__).'/var/db/dhb.sqlite',
                'driver' => 'sqlite3',
            ],
            'flip_db' => [
                'path' => dirname(__DIR__).'/var/db/dhb.flip.sqlite',
                'driver' => 'sqlite3',
            ],
            'external_db' => [
                'dbname' => getenv('DB_NAME'),
                'user' => getenv('DB_USER'),
                'password' => getenv('DB_PASSWORD'),
                'host' => getenv('DB_HOST'),
                'driver' => 'pdo_pgsql',
            ],
            'routes' => [
                'index' => [
                    'url' => '/',
                    'defaults' => ['_controller' => 'controller.player::list']
                ],
                'player' => [
                    'url' => '/player/{id}',
                    'defaults' => ['_controller' => 'controller.player::show']
                ],
                'tracks' => [
                    'url' => '/tracks',
                    'defaults' => ['_controller' => 'controller.track::list']
                ],
                'popular_tracks' => [
                    'url' => '/tracks/popular',
                    'defaults' => ['_controller' => 'controller.track::popular']
                ],
                'track' => [
                    'url' => '/tracks/{id}',
                    'defaults' => ['_controller' => 'controller.track::show']
                ],
            ],
            'debug' => (bool) getenv('DEBUG'),
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
    }

    protected function boot()
    {
        if ($this->booted) {
            return;
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
