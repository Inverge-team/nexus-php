<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Telescope;

use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Routing\Route;
use Inverge\Nexus\Telescope\Telescope;

/**
 * Decorates Laravel's controller dispatcher, which runs exactly when the route
 * middleware has finished and the action begins. That gives the journey its
 * phases: route middleware → controller (queries nest under it) → response.
 */
final class TimedControllerDispatcher implements ControllerDispatcher
{
    public function __construct(
        private readonly ControllerDispatcher $inner,
        private readonly Telescope $telescope,
        private readonly TelescopeRegistrar $registrar,
    ) {
    }

    public function dispatch(Route $route, $controller, $method)
    {
        if (!$this->telescope->active()) {
            return $this->inner->dispatch($route, $controller, $method);
        }
        $this->registrar->controllerStarting();
        $name = (\is_object($controller) ? $controller::class : (string) $controller) . '@' . $method;
        try {
            return $this->telescope->span($name, fn () => $this->inner->dispatch($route, $controller, $method), ['controller' => $name, 'route' => $route->uri()]);
        } finally {
            $this->registrar->controllerFinished();
        }
    }

    public function getMiddleware($controller, $method)
    {
        return $this->inner->getMiddleware($controller, $method);
    }
}
