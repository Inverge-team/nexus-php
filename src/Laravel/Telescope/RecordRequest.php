<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Telescope;

use Closure;
use Illuminate\Http\Request;
use Inverge\Nexus\Telescope\Telescope;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Global middleware (prepended, so it wraps everything) that opens the request
 * journey and closes it in `terminate()` — after the response has been sent
 * under PHP-FPM, so delivery never adds latency. Works per request under
 * Octane too.
 */
final class RecordRequest
{
    public const ATTRIBUTE = 'nexus.telescope';

    public function __construct(private readonly Telescope $telescope, private readonly TelescopeRegistrar $registrar)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $path = '/' . ltrim($request->path(), '/');
        if (!$this->telescope->enabled() || $this->telescope->ignoresPath($path)) {
            return $next($request);
        }

        $start = \defined('LARAVEL_START') && !$this->registrar->octane() ? (float) LARAVEL_START : null;
        $id = $this->telescope->start('request', [
            'method' => $request->getMethod(),
            'uri' => $request->fullUrl(),
            'path' => $path,
            'ip' => $request->ip(),
            'headers' => self::headers($request->headers->all()),
            'payload' => self::payload($request),
            'query' => Telescope::scalarize($request->query->all()),
        ], array_filter([
            'traceparent' => $request->header('traceparent'),
            'startedAt' => $start,
        ], static fn ($v) => $v !== null));

        if ($id === null) {
            return $next($request);
        }
        $request->attributes->set(self::ATTRIBUTE, $id);
        if ($start !== null) {
            $this->telescope->record('middleware', ['name' => 'Framework boot'], [
                'name' => 'Framework boot',
                'startedAt' => $start,
                'durationMs' => (microtime(true) - $start) * 1000,
            ]);
        }
        $this->registrar->requestStarted(microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->attributes->get(self::ATTRIBUTE) === null || !$this->telescope->active()) {
            return;
        }
        try {
            $route = $request->route();
            $content = [
                'responseStatus' => $response->getStatusCode(),
                'responseHeaders' => self::headers($response->headers->all()),
                'response' => $this->response($response),
                'memoryMb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ];
            if (\is_object($route)) {
                $content['route'] = $route->uri();
                $content['controller'] = $route->getActionName();
                $content['middleware'] = array_values(array_map('strval', $route->gatherMiddleware()));
                if ($route->getName()) {
                    $content['routeName'] = $route->getName();
                }
            }
            if ($request->hasSession()) {
                $content['session'] = Telescope::scalarize(array_diff_key($request->session()->all(), ['_token' => 1, '_flash' => 1]));
            }
            $this->user($request);
            $this->telescope->finish($content, (string) $response->getStatusCode());
        } catch (\Throwable) {
            $this->telescope->finishAll();
        }
    }

    private function user(Request $request): void
    {
        try {
            $user = $request->user();
            if ($user === null) {
                return;
            }
            $info = ['id' => (string) $user->getAuthIdentifier()];
            foreach (['email', 'name'] as $k) {
                if (isset($user->{$k}) && \is_scalar($user->{$k})) {
                    $info[$k] = (string) $user->{$k};
                }
            }
            $this->telescope->user($info);
        } catch (\Throwable) {
        }
    }

    /** @return mixed */
    private function response(Response $response)
    {
        if (!$this->telescope->option('capture_response_bodies')) {
            return null;
        }
        if ($response instanceof RedirectResponse) {
            return 'Redirected to ' . $response->getTargetUrl();
        }
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return 'Streamed or file response';
        }
        $body = (string) $response->getContent();
        $limit = (int) $this->telescope->option('body_size_limit') * 1024;
        if (\strlen($body) > $limit) {
            return 'Response over ' . (int) ($limit / 1024) . ' KB — not recorded';
        }
        $type = (string) $response->headers->get('Content-Type', '');
        if (str_contains($type, 'json')) {
            $decoded = json_decode($body, true);

            return \is_array($decoded) ? $decoded : $body;
        }
        if (str_contains($type, 'html')) {
            return 'HTML response (' . \strlen($body) . ' bytes)';
        }

        return mb_check_encoding($body, 'UTF-8') ? $body : 'Binary response';
    }

    /** @return array<mixed> */
    private static function payload(Request $request): array
    {
        $files = [];
        foreach ($request->allFiles() as $key => $file) {
            $files[$key] = \is_array($file) ? \count($file) . ' files' : [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
            ];
        }

        return array_replace((array) Telescope::scalarize($request->input()), $files);
    }

    /**
     * @param array<string, list<string|null>> $headers
     * @return array<string, string>
     */
    public static function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[$k] = implode(', ', array_map('strval', (array) $v));
        }

        return $out;
    }
}
