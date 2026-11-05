#!/usr/bin/env bash
set -euo pipefail
umask 077
base=/var/www/svs-laravel
release=$base/releases/20261105-clubunio-chat-image-compression
package=$base/shared/mobile-push-activation-20261105
backup=$base/shared/mobile-push-activation-backup-20261105
test "$(readlink -f "$base/current")" = "$release"
test ! -e "$backup"
test ! -e /etc/systemd/system/clubunio-dev-mobile-push.service
test ! -e /etc/systemd/system/clubunio-dev-mobile-push.timer
cd "$release"
mkdir -m 700 "$backup"
cp .env "$backup/env.before"
cp bootstrap/cache/config.php "$backup/config.before.php"
chmod 600 "$backup"/*
php <<'PHP'
<?php
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.url') !== 'https://dev.clubunio.ch' || config('clubunio-mobile-push.enabled') || config('clubunio-mobile-push.delivery_enabled')) throw new LogicException('Unexpected initial push state');
$apps=config('clubunio-mobile-push.apps', []);
if (array_keys($apps) !== ['com.my.clubunio.core']) throw new LogicException('Unexpected registry');
$db=Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->table('clubora.mobile_push_devices')->count() || $db->table('clubora.mobile_push_bindings')->count() || $db->table('clubora.mobile_push_outbox')->count()) throw new LogicException('Existing push registrations/work need separate review');
echo "DEV initial state verified; no devices, bindings or backlog.\n";
PHP
sudo python3 - "$release/.env" <<'PY'
import os, pathlib, re, sys
path=pathlib.Path(sys.argv[1])
assert path.is_file() and not path.is_symlink()
stat=path.stat(); raw=path.read_bytes(); data=raw.decode('utf-8')
for key in ['CLUBUNIO_MOBILE_PUSH_ENABLED', 'CLUBUNIO_MOBILE_PUSH_DELIVERY_ENABLED']:
    pattern=re.compile(r'^'+key+r'=(.*)$', re.MULTILINE)
    matches=pattern.findall(data)
    assert len(matches)<=1
    if matches:
        assert matches[0].strip().strip('"\'').lower() in ['false','0','']
        data=pattern.sub(key+'=true',data)
    else:
        data=data.rstrip('\r\n')+'\n'+key+'=true\n'
staged=path.with_name('.env.push-activation.tmp')
assert not staged.exists()
with staged.open('xb') as handle: handle.write(data.encode('utf-8'))
os.chmod(staged, stat.st_mode & 0o777); os.chown(staged,stat.st_uid,stat.st_gid)
os.replace(staged,path)
PY
compiled=$(php -r '$c=require "bootstrap/cache/config.php"; echo $c["view"]["compiled"];')
VIEW_COMPILED_PATH="$compiled" php artisan config:cache >/dev/null
sudo chgrp www-data bootstrap/cache/config.php
sudo chmod 660 bootstrap/cache/config.php
php <<'PHP'
<?php
$before=require '/var/www/svs-laravel/shared/mobile-push-activation-backup-20261105/config.before.php';
$after=require 'bootstrap/cache/config.php';
if ($after['clubunio-mobile-push']['enabled'] !== true || $after['clubunio-mobile-push']['delivery_enabled'] !== true) throw new LogicException('Activation missing');
foreach (['enabled','delivery_enabled'] as $flag) { unset($before['clubunio-mobile-push'][$flag],$after['clubunio-mobile-push'][$flag]); }
if ($before !== $after) throw new LogicException('Unrelated config change');
echo "Only the two push flags changed; private registry and all other settings preserved.\n";
PHP
sudo -u www-data php <<'PHP'
<?php
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$request=Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/context');
$request->headers->set('X-ClubUnio-Mobile-Features','personal-push-v1');
if (!app(App\ClubUnio\Mobile\MobilePushReady::class)->available($request)) throw new LogicException('iOS push capability not ready for PHP worker');
$request->headers->remove('X-ClubUnio-Mobile-Features');
if (app(App\ClubUnio\Mobile\MobilePushReady::class)->available($request)) throw new LogicException('Unsupported client became push-ready');
echo "Registered iOS capability verified as www-data; unsupported clients remain closed.\n";
PHP
sudo install -o root -g root -m 644 "$package/clubunio-dev-mobile-push.service" /etc/systemd/system/clubunio-dev-mobile-push.service
sudo install -o root -g root -m 644 "$package/clubunio-dev-mobile-push.timer" /etc/systemd/system/clubunio-dev-mobile-push.timer
sudo systemd-analyze verify /etc/systemd/system/clubunio-dev-mobile-push.service /etc/systemd/system/clubunio-dev-mobile-push.timer
sudo systemctl daemon-reload
sudo systemctl reload php8.3-fpm
sudo systemctl enable --now clubunio-dev-mobile-push.timer
sudo systemctl start clubunio-dev-mobile-push.service
sudo systemctl is-active clubunio-dev-mobile-push.timer
sudo systemctl show clubunio-dev-mobile-push.service -p Result -p ExecMainStatus
