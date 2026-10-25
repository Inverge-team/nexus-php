<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\Telescope;

use Inverge\Nexus\Telescope\Telescope;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Nexus Telescope for Symfony: the request journey (with its phases —
 * request listeners incl. routing & security, the controller, response
 * listeners), exceptions, and console commands. Messenger messages and
 * Doctrine queries are covered by {@see MessengerSubscriber} and
 * {@see DoctrineMiddleware}.
 *
 * The journey is delivered on `kernel.terminate`, i.e. after the response was
 * sent under PHP-FPM.
 */
final class TelescopeSubscriber implements EventSubscriberInterface
{
    private float $requestAt = 0.0;
    private float $controllerAt = 0.0;
    private float $responseAt = 0.0;
    private ?string $controllerSpan = null;

    /** @param list<string> $ignoreCommands */
    public function __construct(private readonly Telescope $telescope, private readonly array $ignoreCommands = [])
    {
    }

    /** @return array<string, array{0:string,1:int}> */
    public static function getSubscribedEvents(): array
    {
        $events = [
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::CONTROLLER_ARGUMENTS => ['onControllerArguments', -4096],
            KernelEvents::RESPONSE => ['onResponse', 4096],
            KernelEvents::EXCEPTION => ['onException', 4096],
            KernelEvents::TERMINATE => ['onTerminate', -4096],
        ];
        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::COMMAND] = ['onCommand', 4096];
            $events[ConsoleEvents::ERROR] = ['onConsoleError', 0];
            $events[ConsoleEvents::TERMINATE] = ['onConsoleTerminate', -4096];
        }

        return $events;
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $r = $event->getRequest();
        $path = $r->getPathInfo();
        if (!$this->telescope->enabled() || $this->telescope->ignoresPath($path)) {
            return;
        }
        $start = $r->server->get('REQUEST_TIME_FLOAT');
        $this->telescope->start('request', [
            'method' => $r->getMethod(),
            'uri' => $r->getUri(),
            'path' => $path,
            'ip' => $r->getClientIp(),
            'headers' => self::headers($r->headers->all()),
            'payload' => Telescope::scalarize($r->request->all() ?: (json_decode((string) $r->getContent(), true) ?: [])),
            'query' => Telescope::scalarize($r->query->all()),
        ], array_filter(['traceparent' => $r->headers->get('traceparent'), 'startedAt' => \is_float($start) ? $start : null]));
        $this->requestAt = microtime(true);
        $this->controllerAt = $this->responseAt = 0.0;
        $this->controllerSpan = null;
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest() || $this->telescope->current()?->type !== 'request') {
            return;
        }
        $now = microtime(true);
        $this->telescope->record('middleware', ['name' => 'Request listeners (routing, firewall, …)'], [
            'name' => 'Request listeners (routing, firewall, …)',
            'startedAt' => $this->requestAt,
            'durationMs' => ($now - $this->requestAt) * 1000,
        ]);
        $controller = $event->getRequest()->attributes->get('_controller');
        $name = \is_string($controller) ? $controller : 'controller';
        $this->controllerSpan = $this->telescope->openSpan('span', $name, ['controller' => $name, 'route' => $event->getRequest()->attributes->get('_route')]);
        $this->controllerAt = $now;
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if ($this->controllerSpan !== null) {
            $this->telescope->closeSpan($this->controllerSpan);
            $this->controllerSpan = null;
        }
        $this->responseAt = microtime(true);
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($this->telescope->active()) {
            $this->telescope->exception($event->getThrowable());
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->telescope->current()?->type !== 'request') {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        if ($this->responseAt > 0) {
            $this->telescope->record('middleware', ['name' => 'Response listeners'], [
                'name' => 'Response listeners',
                'startedAt' => $this->responseAt,
                'durationMs' => (microtime(true) - $this->responseAt) * 1000,
            ]);
        }
        $this->telescope->finish([
            'responseStatus' => $response->getStatusCode(),
            'responseHeaders' => self::headers($response->headers->all()),
            'response' => $this->body($response),
            'route' => $request->attributes->get('_route'),
            'controller' => \is_string($request->attributes->get('_controller')) ? $request->attributes->get('_controller') : null,
            'memoryMb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ], (string) $response->getStatusCode(), $this->routeName($request));
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName() ?? '';
        if ($name === '' || $this->ignored($name)) {
            return;
        }
        $input = $event->getInput();
        $this->telescope->start('command', [
            'command' => $name,
            'arguments' => Telescope::scalarize($input->getArguments()),
            'options' => Telescope::scalarize(array_filter($input->getOptions(), static fn ($v) => $v !== null && $v !== false && $v !== [])),
        ], array_filter(['traceparent' => getenv('TRACEPARENT') ?: null]));
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        if ($this->telescope->current()?->type === 'command') {
            $this->telescope->exception($event->getError());
        }
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->telescope->current()?->type === 'command') {
            $this->telescope->finish(['exitCode' => $event->getExitCode()], (string) $event->getExitCode());
        }
    }

    private function routeName(Request $request): ?string
    {
        $route = $request->attributes->get('_route_params') !== null ? $this->routePattern($request) : null;

        return $route !== null ? $request->getMethod() . ' ' . $route : null;
    }

    /** The route's path pattern (`/orders/{id}`), so requests group by endpoint. */
    private function routePattern(Request $request): ?string
    {
        $path = $request->getPathInfo();
        foreach ((array) $request->attributes->get('_route_params', []) as $k => $v) {
            if (\is_scalar($v) && (string) $v !== '') {
                $path = preg_replace('#/' . preg_quote((string) $v, '#') . '(?=/|$)#', '/{' . $k . '}', $path, 1) ?? $path;
            }
        }

        return $path;
    }

    private function ignored(string $command): bool
    {
        foreach ($this->ignoreCommands as $p) {
            if (\Inverge\Nexus\Telescope\Redactor::globMatch($p, $command)) {
                return true;
            }
        }

        return false;
    }

    /** @return mixed */
    private function body(Response $response)
    {
        if (!$this->telescope->option('capture_response_bodies')) {
            return null;
        }
        $body = (string) $response->getContent();
        if ($body === '' || \strlen($body) > (int) $this->telescope->option('body_size_limit') * 1024) {
            return $body === '' ? null : 'Response too large — not recorded';
        }
        if ($response instanceof JsonResponse || str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            $d = json_decode($body, true);

            return \is_array($d) ? $d : $body;
        }

        return str_contains((string) $response->headers->get('Content-Type'), 'html') ? 'HTML response (' . \strlen($body) . ' bytes)' : $body;
    }

    /**
     * @param array<string, list<string|null>> $headers
     * @return array<string, string>
     */
    private static function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[$k] = implode(', ', array_map('strval', (array) $v));
        }

        return $out;
    }
}
