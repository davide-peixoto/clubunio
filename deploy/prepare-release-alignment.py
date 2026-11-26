"""Build an explicit, code-only common release against captured target hashes."""
import hashlib
import io
import json
from pathlib import Path
import tarfile

ROOT = Path(__file__).resolve().parent.parent
FILES = [
    'app/ClubUnio/Services/ReactAssets.php',
    'app/ClubUnio/Services/DashboardService.php',
    'app/ClubUnio/Services/WorkNotificationHub.php',
    'app/ClubUnio/Services/DashboardReservationRequests.php',
    'app/ClubUnio/Widgets/ClubOverview.php',
    'app/ClubUnio/Assistant/BackofficeAssistantService.php',
    'app/ClubUnio/Pages/BackofficeAssistant.php',
    'public/css/clubunio-assistant.css',
    'public/css/clubunio-react-navigation.css',
    'public/js/clubunio-react-navigation.js',
    'public/js/clubunio-ui-performance.js',
    'resources/views/clubunio/react-script.blade.php',
    'resources/views/clubunio/react-navigation.blade.php',
    'resources/views/clubunio/react-navigation-head.blade.php',
    'resources/views/clubunio/pages/dashboard.blade.php',
    'resources/views/clubunio/pages/backoffice-assistant.blade.php',
    'resources/views/clubunio/pitch-planning/planner.blade.php',
    'resources/views/clubunio/veo-planning.blade.php',
    'resources/views/clubunio/widgets/pending-reservation-requests.blade.php',
    'lang/de/assistant_chat.php', 'lang/en/assistant_chat.php',
    'deploy/check-release-alignment.php',
]
FILES += [p.relative_to(ROOT).as_posix() for p in (ROOT / 'resources/views/clubunio/pages/partials').glob('assistant*.blade.php')]
FILES += [p.relative_to(ROOT).as_posix() for p in (ROOT / 'public/js/react-ui').rglob('*') if p.is_file()]


def main():
    target = ROOT / 'storage/app/release-alignment-20261126.tar.gz'
    inventory = {}
    with tarfile.open(target, 'w:gz') as bundle:
        for name in sorted(set(FILES)):
            data = (ROOT / name).read_bytes().replace(b'\r\n', b'\n')
            if name.endswith('.php'):
                data = data.replace(b'clubora.', b'clubunio.')
            member = tarfile.TarInfo(name)
            member.size, member.mode = len(data), 0o640
            bundle.addfile(member, io.BytesIO(data))
            inventory[name] = hashlib.sha256(data).hexdigest()
    (ROOT / 'storage/app/release-alignment-20261126.json').write_text(json.dumps(inventory, indent=2))
    print(json.dumps({'files': len(inventory), 'archive': str(target)}))


if __name__ == '__main__':
    main()
