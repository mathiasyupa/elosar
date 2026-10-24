<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Signed, expiring URLs for the images that used to sit on the public disk:
 * doctor signatures and profile photos, patient photos and letterhead art.
 *
 * These are rendered straight into <img src>, which cannot carry an
 * Authorization header, so access is granted by a short-lived signature rather
 * than a bearer token. Laravel's `local` disk is configured with `serve => true`
 * (config/filesystems.php), which registers the signed serving route and makes
 * Illuminate\Filesystem\ServeFile reject any request without a valid signature.
 *
 * Trade-off worth remembering: a signed URL is a bearer capability. Whoever
 * copies it can fetch that one file until it expires, with no workspace check.
 * That is acceptable for images with a minutes-long TTL, and a large improvement
 * over the permanent unauthenticated URLs this replaced — but it is why clinical
 * attachments deliberately do NOT use this helper. They keep the stronger
 * authenticated, workspace-scoped route in Api\AttachmentController.
 */
class PrivateImageUrl
{
    /**
     * How long a generated URL stays valid.
     *
     * Must comfortably exceed the staleTime of the medico-web queries that hold
     * these URLs, or a cached response will still be rendering a link that the
     * server has already stopped honouring.
     */
    public const TTL_MINUTES = 10;

    /**
     * Signed URL for a path on the private disk, or null when there is no file.
     */
    public static function for(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk('local')->temporaryUrl(
            $path,
            now()->addMinutes(self::TTL_MINUTES),
        );
    }
}
