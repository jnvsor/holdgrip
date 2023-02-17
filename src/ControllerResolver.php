<?php

namespace HoldGrip;

use Pimple\Container;
use Symfony\Component\HttpKernel\Controller\ControllerResolver as BaseResolver;

class ControllerResolver extends BaseResolver
{
    private readonly Container $container;

    public function __construct(Container $c, LoggerInterface $logger = null)
    {
        parent::__construct($logger);
        $this->container = $c;
    }

    protected function instantiateController(string $class): object
    {
        if (class_exists($class)) {
            return new $class();
        } else {
            return $this->container[$class];
        }
    }
}
