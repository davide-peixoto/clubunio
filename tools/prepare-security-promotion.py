"""Read-only runtime snapshot and three-way preparation; never deploys files."""
import hashlib
import io
import json
from pathlib import Path
import shlex
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
BASE_REVISION = 'ac07415ac7121bcc232736172aa842ae2ac786e0'
WORK = ROOT / 'storage/app/private/security-release-20261125'
TRACKED = [
    'app/ClubUnio/Http/KeycloakMobilePairingController.php',
    'app/ClubUnio/Http/PlatformOidcController.php',
    'app/ClubUnio/Http/RequirePlatformAdministrator.php',
    'app/ClubUnio/Http/RequireStrongWebSession.php',
    'app/ClubUnio/Services/AccountSecurity.php',
    'app/ClubUnio/Services/DocumentUploadService.php',
    'app/ClubUnio/Services/KeycloakCodeExchange.php',
    'app/ClubUnio/Services/KeycloakMobileAssurance.php',
    'bootstrap/app.php', 'config/clubunio-verein-oidc.php',
    'config/clubunio-platform-oidc.php',
]
NEW = [
    'app/ClubUnio/Http/KeycloakBackchannelController.php',
    'app/ClubUnio/Services/KeycloakBackchannelLogout.php',
    'app/ClubUnio/Services/KeycloakLogoutState.php',
    'app/ClubUnio/Services/KeycloakWebAssurance.php',
    'app/ClubUnio/Services/DocumentSafety.php',
    'config/clubunio-keycloak-backchannel.php',
    'lang/de/document_safety.php', 'lang/en/document_safety.php',
    'database/clubunio/2026_11_25_010000_keycloak_logout_watermarks.php',
]


def main():
    WORK.mkdir(parents=True, exist_ok=True)
    report = {}
    for environment, app in [('stage', 'ClubUnioStage'), ('prod', 'ClubUnioProd')]:
        folder = WORK / environment
        before, candidate = folder / 'before', folder / 'candidate'
        if before.exists():
            raise SystemExit('Snapshot already exists: ' + str(before))
        before.mkdir(parents=True)
        command = 'tar --ignore-failed-read -cf - -C ' + shlex.quote('/home/runcloud/webapps/' + app) + ' ' + ' '.join(shlex.quote(f) for f in TRACKED + NEW + ['composer.json', 'composer.lock'])
        result = subprocess.run(['ssh', '-o', 'BatchMode=yes', 'root@vps.clubunio.com', command], capture_output=True, check=True)
        with tarfile.open(fileobj=io.BytesIO(result.stdout)) as archive:
            archive.extractall(before, filter='data')
        status = {}
        for filename in TRACKED + NEW:
            target = candidate / filename
            target.parent.mkdir(parents=True, exist_ok=True)
            local = (ROOT / filename).read_bytes()
            oldpath = before / filename
            if filename in TRACKED:
                base = subprocess.check_output(['git', 'show', BASE_REVISION + ':' + filename], cwd=ROOT)
                if not oldpath.is_file():
                    status[filename] = 'MISSING_RUNTIME_REVIEW'
                    continue
                basepath, localpath = folder / 'merge-base', folder / 'merge-local'
                basepath.write_bytes(base)
                localpath.write_bytes(local)
                merged = subprocess.run(['git', 'merge-file', '-p', str(oldpath), str(basepath), str(localpath)], capture_output=True)
                target.write_bytes(merged.stdout)
                status[filename] = 'merged' if merged.returncode == 0 else 'CONFLICT'
            elif oldpath.exists() and oldpath.read_bytes() != local:
                target.write_bytes(local)
                status[filename] = 'EXISTING_NEW_REVIEW'
            else:
                target.write_bytes(local)
                status[filename] = 'new' if not oldpath.exists() else 'identical'
        report[environment] = status
    (WORK / 'preparation.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
