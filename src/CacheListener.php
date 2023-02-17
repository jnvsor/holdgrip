<?php

namespace HoldGrip;

use DateInterval;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class CacheListener implements EventSubscriberInterface
{
    private $interval;
    private $mtime;

    public function __construct(string $interval, int $mtime)
    {
        $this->interval = new DateInterval($interval);
        $time = new DateTimeImmutable();
        $this->mtime = $time->setTimestamp($mtime);
    }

    public function add_cache(ResponseEvent $event)
    {
        $res = $event->getResponse();

        $end_timestamp = $this->mtime->add($this->interval)->getTimestamp();
        $res->setMaxAge(max(60, $end_timestamp - time()))
            ->setLastModified($this->mtime)
            ->setPublic();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'add_cache',
        ];
    }
}
