<?php

declare(strict_types=1);

namespace Inverge\Nexus;

/**
 * Per-service on/off switches — turn a Nexus product off without removing the
 * code that uses it. A disabled service makes no network call and never throws:
 * writes are skipped and reads return their neutral value (flags → defaults,
 * Remote Config → defaults, lists → empty).
 *
 * From the environment (all default to on):
 *
 *     NEXUS_ENABLED=false                  # everything off
 *     NEXUS_PUSH_ENABLED=false             # only push off
 *     NEXUS_LIVE_ACTIVITIES_ENABLED=false  # only Live Activities off
 *
 * One variable per service — see {@see self::ENV}. `false`, `0`, `off` and `no`
 * mean off; anything else (or unset) means on.
 */
final class Services
{
    public const REALTIME = 'realtime';
    public const EVENTS = 'events';
    public const ERRORS = 'errors';
    public const LOGS = 'logs';
    public const SESSIONS = 'sessions';
    public const FLAGS = 'flags';
    public const REMOTE_CONFIG = 'remote_config';
    public const LINKS = 'links';
    public const SURVEYS = 'surveys';
    public const PUSH = 'push';
    public const IN_APP = 'in_app';
    public const LIVE_ACTIVITIES = 'live_activities';
    public const TELESCOPE = 'telescope';
    public const SHIELD = 'shield';
    public const DOCSTORE = 'docstore';

    /** Service → the environment variable that switches it. */
    public const ENV = [
        self::REALTIME => 'NEXUS_REALTIME_ENABLED',
        self::EVENTS => 'NEXUS_EVENTS_ENABLED',
        self::ERRORS => 'NEXUS_ERRORS_ENABLED',
        self::LOGS => 'NEXUS_LOGS_ENABLED',
        self::SESSIONS => 'NEXUS_SESSIONS_ENABLED',
        self::FLAGS => 'NEXUS_FLAGS_ENABLED',
        self::REMOTE_CONFIG => 'NEXUS_REMOTE_CONFIG_ENABLED',
        self::LINKS => 'NEXUS_LINKS_ENABLED',
        self::SURVEYS => 'NEXUS_SURVEYS_ENABLED',
        self::PUSH => 'NEXUS_PUSH_ENABLED',
        self::IN_APP => 'NEXUS_IN_APP_ENABLED',
        self::LIVE_ACTIVITIES => 'NEXUS_LIVE_ACTIVITIES_ENABLED',
        self::TELESCOPE => 'NEXUS_TELESCOPE_ENABLED',
        self::SHIELD => 'NEXUS_SHIELD_ENABLED',
        self::DOCSTORE => 'NEXUS_DOCSTORE_ENABLED',
    ];

    /** The master switch. */
    public const ENV_ALL = 'NEXUS_ENABLED';

    /** `/partner/<segment>` → service. */
    private const PATHS = [
        'rooms' => self::REALTIME,
        'events' => self::EVENTS,
        'errors' => self::ERRORS,
        'logs' => self::LOGS,
        'sessions' => self::SESSIONS,
        'flags' => self::FLAGS,
        'remote-config' => self::REMOTE_CONFIG,
        'links' => self::LINKS,
        'surveys' => self::SURVEYS,
        'push' => self::PUSH,
        'inapp' => self::IN_APP,
        'live-activities' => self::LIVE_ACTIVITIES,
        'telescope' => self::TELESCOPE,
        'shield' => self::SHIELD,
        'docstore' => self::DOCSTORE,
    ];

    /** @param array<string, bool> $disabled service => true for each one that's off */
    private function __construct(private readonly bool $all, private readonly array $disabled)
    {
    }

    /** Everything on. */
    public static function allEnabled(): self
    {
        return new self(true, []);
    }

    /**
     * From switches, e.g. `['push' => false]` (unknown keys are rejected so a
     * typo can't silently leave a service on). `enabled` is the master switch.
     *
     * @param array<string, mixed> $switches
     */
    public static function fromArray(array $switches, bool|string|int|null $enabled = true): self
    {
        $disabled = [];
        foreach ($switches as $service => $value) {
            if (!isset(self::ENV[$service])) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown Nexus service "%s". Known: %s.', $service, implode(', ', array_keys(self::ENV)),
                ));
            }
            if (!self::truthy($value)) {
                $disabled[$service] = true;
            }
        }

        return new self(self::truthy($enabled), $disabled);
    }

    /**
     * From environment variables (`getenv`, then `$_ENV` / `$_SERVER`).
     *
     * @param array<string, string>|null $env an explicit environment (tests)
     */
    public static function fromEnvironment(?array $env = null): self
    {
        $read = static function (string $name) use ($env): ?string {
            if ($env !== null) {
                return isset($env[$name]) ? (string) $env[$name] : null;
            }
            $v = getenv($name);
            if ($v !== false) {
                return $v;
            }

            return isset($_ENV[$name]) ? (string) $_ENV[$name] : (isset($_SERVER[$name]) && is_scalar($_SERVER[$name]) ? (string) $_SERVER[$name] : null);
        };
        $switches = [];
        foreach (self::ENV as $service => $var) {
            $value = $read($var);
            if ($value !== null) {
                $switches[$service] = $value;
            }
        }

        return self::fromArray($switches, $read(self::ENV_ALL));
    }

    public function enabled(string $service): bool
    {
        return $this->all && !isset($this->disabled[$service]);
    }

    /** The service a `/partner/...` path belongs to, or null for unswitched paths. */
    public static function forPath(string $path): ?string
    {
        $segment = explode('/', ltrim(preg_replace('#^/?partner/#', '', $path) ?? '', '/'), 2)[0];

        return self::PATHS[$segment] ?? null;
    }

    /** Why a service is off — for logs. */
    public function reason(string $service): string
    {
        return $this->all ? (self::ENV[$service] ?? $service).'=false' : self::ENV_ALL.'=false';
    }

    private static function truthy(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true; // unset = on
        }
        if (is_bool($value)) {
            return $value;
        }

        return !\in_array(strtolower(trim((string) $value)), ['false', '0', 'off', 'no', '(false)'], true);
    }
}
