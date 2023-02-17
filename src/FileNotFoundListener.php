<?php

namespace HoldGrip;

use HoldGrip\Controller\TrackController;
use HoldGrip\NotFoundException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

class FileNotFoundListener implements EventSubscriberInterface
{
    private $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function handle404(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof NotFoundHttpException) {
            return;
        }

        $message = null;
        if ($exception instanceof NotFoundException) {
            $message = $exception->getMessage();
        }

        $out = $this->twig->render('404.twig', [
            'message' => $message,
        ]);

        $event->setResponse(new Response($out, 404));
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::EXCEPTION => 'handle404',
        ];
    }
}
