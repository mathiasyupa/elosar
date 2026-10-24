#!/usr/bin/env bash
# Container entrypoint: prepare runtime state, then hand off to supervisor.
set -e

cd /var/www/html

# 1. Render the nginx vhost with the port Railway injects at runtime.
PORT="${PORT:-8080}"
sed "s/__PORT__/${PORT}/g" \
    /etc/nginx/sites-available/default.template \
    > /etc/nginx/sites-available/default

# 2. Ensure writable storage exists (a mounted volume starts empty).
mkdir -p \
    storage/app/public \
    storage/app/private \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs
chown -R www-data:www-data storage bootstrap/cache

# 3. No public storage symlink, on purpose.
#
#    Doctor signatures, photos, patient photos and letterhead art now live on
#    the private disk and are served only through signed, expiring URLs (see
#    App\Support\PrivateImageUrl). While public/storage existed, nginx served
#    anything under storage/app/public directly — BEFORE Laravel could check a
#    signature — so a single stray `->store(..., 'public')` would silently make
#    a file world-readable again. Removing the link makes that failure loud
#    instead of silent: the file simply is not reachable.
#
#    The `public` disk itself is kept in config/filesystems.php because
#    elosar:migrate-public-images reads from it to move legacy files.

# 3b. Publish Filament core assets (CSS/JS). Must run on every deploy so the
#     published assets match the installed Filament version; without this the
#     admin/superadmin panels render unstyled (raw HTML).
php artisan filament:assets || true

# 4. Discover packages + cache config, routes & views. routes/web.php used to
#    have two closure routes, which route:cache cannot compile — replaced with
#    Route::view() so every request stops re-parsing the route files from
#    scratch. Verified locally (`php artisan route:cache` succeeds) before
#    trusting it here, since `set -e` means a route:cache failure would now
#    crash the boot instead of silently skipping it.
php artisan package:discover --ansi || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Apply database migrations (idempotent). Disable with RUN_MIGRATIONS=false.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

# 5b. Backfill the demo clinic's re-engage / follow-up call lists. Idempotent
#     and demo-clinic-only: a no-op on a real production database (no demo
#     clinic) and after the first run. Creates no accounts. Never fails boot.
php artisan db:seed --class=DemoOutreachSeeder --force || true

# 5c. Fictional "Clínica Demo Elosar" for promo videos, only when the operator
#     sets DEMO_CLINIC_PASSWORD in Railway. First deploy creates it; later ones
#     only move its agenda to today. Accounts live under @demo.elosar.site.
if [ -n "${DEMO_CLINIC_PASSWORD:-}" ]; then
    php artisan elosar:demo-clinic --password="$DEMO_CLINIC_PASSWORD" || true
fi

# 6. Run nginx + php-fpm + queue worker + scheduler under supervisor.
exec supervisord -c /etc/supervisor/conf.d/app.conf
