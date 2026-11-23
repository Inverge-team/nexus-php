<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\ClientIp;

use Inverge\Nexus\Support\ClientIp;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * The end user's IP across Messenger: a message sent to a transport while a
 * request is handled carries it in a {@see ClientIpStamp}, and the worker that
 * handles the message restores it — so a handler that starts a verification
 * forwards the address of the user who asked for the code. Messages sent with
 * no request behind them carry none. Sync transports run in the request and
 * need nothing.
 */
final class ClientIpMessengerSubscriber implements EventSubscriberInterface
{
    /** @var array{bool, ?string}|null */
    private ?array $previous = null;

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => 'onSend',
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerMessageHandledEvent::class => 'onDone',
            WorkerMessageFailedEvent::class => 'onDone',
        ];
    }

    public function onSend(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $ip = ClientIp::forwarded();
        if ($ip === null || $envelope->last(ClientIpStamp::class) !== null || !method_exists($event, 'setEnvelope')) {
            return;
        }
        $event->setEnvelope($envelope->with(new ClientIpStamp($ip)));
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->previous = ClientIp::snapshot();
        ClientIp::set($event->getEnvelope()->last(ClientIpStamp::class)?->ip);
    }

    public function onDone(): void
    {
        $this->previous === null ? ClientIp::clear() : ClientIp::restore($this->previous);
        $this->previous = null;
    }
}
