<?php

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

/**
 * Last gate before anything leaves for Sentry.
 *
 * Installing an error tracker in a clinical product is not a neutral act: by
 * default it ships request bodies, query strings and user identities to a third
 * party, which for Elosar means patient names, cédulas and diagnoses crossing a
 * border under LOPDP. A crash report is a data export.
 *
 * So the rule here is inverted from the usual one — nothing is sent unless it is
 * needed to locate the bug. A stack trace, the route, and which account and
 * workspace hit it are enough. What the doctor was typing is not.
 *
 * Referenced from config/sentry.php as an array callable on purpose: the Docker
 * entrypoint runs `config:cache`, and a closure in a config file makes that fail
 * ("Your configuration files are not serializable").
 */
class SentryScrubber
{
    public static function handle(Event $event, ?EventHint $hint = null): ?Event
    {
        self::scrubRequest($event);
        self::scrubUser($event);

        // Extra context is free-form and nobody can audit every future call
        // site, so it does not travel at all.
        $event->setExtra([]);

        return $event;
    }

    /**
     * Keeps the path, drops everything the caller sent.
     *
     * The URL alone can carry data — /patients/1044 is a patient id, and a
     * search endpoint puts the query in the string — so the query is cut and
     * only the path survives.
     */
    private static function scrubRequest(Event $event): void
    {
        $request = $event->getRequest();

        if ($request === []) {
            return;
        }

        $url = $request['url'] ?? null;

        $event->setRequest(array_filter([
            'url' => is_string($url) ? strtok($url, '?') : null,
            'method' => $request['method'] ?? null,
        ], static fn ($value): bool => $value !== null));
    }

    /**
     * An id is enough to find the account in the panel; a name or an e-mail
     * would put a real person's identity in a third-party system for nothing.
     */
    private static function scrubUser(Event $event): void
    {
        $user = $event->getUser();

        if (! $user instanceof UserDataBag) {
            return;
        }

        $safe = UserDataBag::createFromUserIdentifier($user->getId());

        // Which clinic was involved is operationally useful and identifies no
        // person: it is an internal number, not a name.
        if ($workspace = $user->getMetadata()['workspace_id'] ?? null) {
            $safe->setMetadata('workspace_id', $workspace);
        }

        $event->setUser($safe);
    }
}
