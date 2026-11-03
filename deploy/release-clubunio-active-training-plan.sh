#!/usr/bin/env bash
set -euo pipefail
base=/var/www/svs-laravel
previous="$base/releases/20261103-clubunio-setting-lookups"
release="$base/releases/20261103-clubunio-active-training-plan"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-active-training-plan.tar.gz" -C "$release" --no-same-owner
cd "$release"
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
for file in app/ClubUnio/Services/ActiveTrainingPlan.php app/ClubUnio/Services/SettingOptions.php app/ClubUnio/Services/SignageBrandService.php app/ClubUnio/Services/SignageService.php app/ClubUnio/Pages/SignagePreview.php; do
 php -l "$file"
done
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
php artisan migrate --database=clubunio --path=database/clubunio/2026_11_03_000090_active_training_plan.php --force
for check in check-clubunio check-access-profiles check-impersonation check-mobile-season-scope; do
 sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e)."\n"); exit(1); }' "deploy/$check.php"
done
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
echo "Activated $release"
