<?php

namespace HoldGrip;

use HoldGrip\Controller\TrackController;
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
        $details = null;
        $file = $exception->getFile();

        if (!str_starts_with($file, dirname(__DIR__).'/vendor')) {
            $message = $exception->getMessage();

            if ($exception->getTrace()[0]['class'] === TrackController::class) {
                $details = 'Currently the workshop track IDs are unstable and may change when the stats are updated. Please look for your track through the track list.';
            }
        }

        if ($message === null) {
            $message = 'File not found';
        }

        $out = $this->twig->render('404.twig', [
            'message' => $message,
            'details' => $details,
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
