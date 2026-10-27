<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Shield;

use Closure;
use Illuminate\Http\Request;
use Inverge\Nexus\NexusClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: only let requests from your genuine app on a real device
 * through. Registered as `nexus.shield`; the parameter is the Shield resource
 * whose console policy applies:
 *
 *     Route::post('/checkout', CheckoutController::class)->middleware('nexus.shield:checkout');
 *
 * In `monitor` mode the request passes and Nexus records a "would deny". The
 * verdict is available to the controller as `$request->attributes->get('nexus.shield')`.
 * If Nexus can't be reached, the request passes unless `nexus.shield.fail_closed` is true.
 * Behind a proxy/tunnel set `NEXUS_SHIELD_URL` to the public origin the app calls.
 */
final class VerifyShield
{
    public function __construct(private readonly NexusClient $nexus)
    {
    }

    public function handle(Request $request, Closure $next, ?string $resource = null): Response
    {
        $verdict = $this->nexus->shield()->verifyRequest(
            $request->headers->all(),
            $request->getMethod(),
            self::publicUrl($request),
            $request->getContent(),
            $resource,
        );
        $request->attributes->set('nexus.shield', $verdict);

        $unavailable = ($verdict['reason'] ?? null) === 'unavailable';
        $failClosed = (bool) config('nexus.shield.fail_closed', false);
        if (($verdict['decision'] ?? 'deny') !== 'allow' && (!$unavailable || $failClosed)) {
            return response()->json(['error' => ['code' => 'integrity_check_failed', 'reason' => $verdict['reason'] ?? null]], 401);
        }

        return $next($request);
    }

    /**
     * The URL the app called: the configured public origin (`nexus.shield.url`)
     * + the request path, or what Laravel sees when no origin is configured.
     * The full request path is used (not getPathInfo), so an app mounted under
     * a sub-directory still matches.
     */
    public static function publicUrl(Request $request): string
    {
        $path = parse_url($request->getRequestUri(), PHP_URL_PATH) ?: '/';
        $origin = config('nexus.shield.url');
        if (is_string($origin) && $origin !== '') {
            $parts = parse_url($origin);
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'] ?? $origin;
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';

            return $scheme . '://' . $host . $port . $path;
        }

        return $request->getSchemeAndHttpHost() . $path;
    }
}
