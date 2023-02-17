<?php

require dirname(__DIR__).'/vendor/autoload.php';

$app = new HoldGrip\App();

$req = Symfony\Component\HttpFoundation\Request::createFromGlobals();

$res = $app->handle($req);
