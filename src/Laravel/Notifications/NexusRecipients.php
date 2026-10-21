<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

/**
 * Who a Nexus notification goes to, from the notifiable's route.
 *
 * Tried in order: the channel's own route (`routeNotificationForNexusPush()`,
 * `routeNotificationForNexusLiveActivity()`), then the shared
 * `routeNotificationForNexus()`. A route may be a distinctId string, a list of
 * distinctIds, or `['distinctId' => …]`. On-demand notifications work the same:
 * `Notification::route('nexus', 'user_7')->notify(...)`.
 *
 * @internal
 */
final class NexusRecipients
{
    /** @return list<string> */
    public static function for(object $notifiable, object $notification, string $channel): array
    {
        if (!method_exists($notifiable, 'routeNotificationFor')) {
            return [];
        }

        foreach ([$channel, 'nexus'] as $name) {
            $ids = self::normalize($notifiable->routeNotificationFor($name, $notification));
            if ($ids !== []) {
                return $ids;
            }
        }

        return [];
    }

    /** @return list<string> */
    private static function normalize(mixed $route): array
    {
        if (is_string($route) || is_int($route)) {
            return (string) $route === '' ? [] : [(string) $route];
        }
        if (is_array($route)) {
            if (isset($route['distinctId'])) {
                return [(string) $route['distinctId']];
            }
            if (array_is_list($route)) {
                return array_values(array_unique(array_map('strval', array_filter($route, static fn ($v) => is_string($v) || is_int($v)))));
            }
        }

        return [];
    }

    public static function missing(object $notifiable, string $channel): \LogicException
    {
        return new \LogicException(sprintf(
            'No Nexus recipient for %s. Add routeNotificationForNexus() to it (returning the user\'s distinctId), '
            .'target the message explicitly (->toUser(...)), or use Notification::route(\'%s\', $distinctId).',
            $notifiable::class,
            $channel,
        ));
    }
}
