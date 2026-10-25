<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\Telescope;

use Inverge\Nexus\Telescope\Telescope;
use Inverge\Nexus\Telescope\TraceContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Symfony Messenger as Telescope jobs: a message sent to a transport is a
 * `job` entry in the journey that sent it and carries a {@see TraceStamp}; the
 * worker that handles it opens a `job` batch that joins the same trace.
 */
final class MessengerSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Telescope $telescope)
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => 'onSend',
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerMessageHandledEvent::class => 'onHandled',
            WorkerMessageFailedEvent::class => 'onFailed',
        ];
    }

    public function onSend(SendMessageToTransportsEvent $event): void
    {
        if (!$this->telescope->active()) {
            return;
        }
        $envelope = $event->getEnvelope();
        $span = TraceContext::newSpanId();
        $prop = $this->telescope->propagation($span);
        if ($prop !== null && method_exists($event, 'setEnvelope')) {
            $event->setEnvelope($envelope->with(new TraceStamp($prop['traceId'], $prop['parentSpanId'])));
        }
        $message = $envelope->getMessage();
        $this->telescope->record('job', [
            'name' => $message::class,
            'status' => 'dispatched',
            'data' => Telescope::scalarize(get_object_vars($message)),
            'transports' => method_exists($event, 'getSenders') ? array_keys($event->getSenders()) : null,
        ], ['id' => $span, 'status' => 'dispatched']);
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $stamp = $envelope->last(TraceStamp::class);
        $message = $envelope->getMessage();
        $this->telescope->start('job', [
            'name' => $message::class,
            'connection' => $event->getReceiverName(),
            'data' => Telescope::scalarize(get_object_vars($message)),
        ], array_filter([
            'traceId' => $stamp?->traceId,
            'parentSpanId' => $stamp?->parentSpanId,
            'name' => $message::class,
        ]));
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        if ($this->telescope->current()?->type === 'job') {
            $this->telescope->finish(['status' => 'processed'], 'processed');
        }
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        if ($this->telescope->current()?->type !== 'job') {
            return;
        }
        $this->telescope->exception($event->getThrowable());
        $status = $event->willRetry() ? 'released' : 'failed';
        $this->telescope->finish(['status' => $status, 'error' => $event->getThrowable()->getMessage()], $status);
    }
}
