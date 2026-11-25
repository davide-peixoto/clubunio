"""Root-side transport for the explicitly synthetic IdP acceptance lifecycle."""
import argparse
import json
import os
from pathlib import Path
import pwd
import shutil
import socket
import subprocess
import urllib.error
import urllib.request

BASE = Path('/root/clubunio-releases/security-20261125')
PHP = '/RunCloud/Packages/php83rc/bin/php'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('environment', choices=['stage', 'prod'])
    parser.add_argument('action', choices=['sync', 'create', 'map', 'issue', 'http', 'verify', 'unmap', 'cleanup'])
    parser.add_argument('--index', type=int, default=-1)
    args = parser.parse_args()
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    env = args.environment
    app = Path('/home/runcloud/webapps/ClubUnio' + ('Stage' if env == 'stage' else 'Prod'))
    account = pwd.getpwnam('clubunio-' + env)
    folder = app / 'storage/app/private/security-idp-review-20261125'
    folder.mkdir(mode=0o700, exist_ok=True)
    os.chown(folder, account.pw_uid, account.pw_gid)
    os.chmod(folder, 0o700)
    client = 'clubunio-verein-' + env
    state = folder / 'state.json'
    tokens, flow = folder / 'tokens.json', folder / 'flow.json'
    if args.action == 'sync':
        for name, target in [('tokens-' + client + '.json', tokens), ('flow-' + client + '.json', flow)]:
            shutil.copyfile(BASE / 'idp-review' / name, target)
            os.chown(target, account.pw_uid, account.pw_gid)
            os.chmod(target, 0o600)
        print(json.dumps({'environment': env, 'private_flow_synced': True}))
        return
    if args.action in ('map', 'unmap'):
        program = r'''
$file=$argv[1];$s=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
$c=require $file;$key='clubunio-verein-oidc';$map=$c[$key]['subjects'];
if($argv[3]==='map'){
 if(isset($map[$s['subject']]))throw new RuntimeException('Synthetic subject already mapped');
 $map[$s['subject']]=$s['ids']['accounts'];
}else{
 if(($map[$s['subject']]??null)!==$s['ids']['accounts'])throw new RuntimeException('Synthetic mapping mismatch');
 unset($map[$s['subject']]);
}
$c[$key]['subjects']=$map;
if(file_put_contents($file,'<?php return '.var_export($c,true).';')===false)throw new RuntimeException('Private config write failed');
'''
        # Cached configuration is runtime-owned executable PHP: never load it as root.
        result = subprocess.run(['runuser', '-u', 'clubunio-' + env, '--', PHP, '-r', program, str(app / 'bootstrap/cache/config.php'), str(state), args.action], capture_output=True)
        if result.returncode: raise RuntimeError('Private synthetic map operation failed')
        os.chown(app / 'bootstrap/cache/config.php', account.pw_uid, account.pw_gid)
        os.chmod(app / 'bootstrap/cache/config.php', 0o600)
        print(json.dumps({'environment': env, 'synthetic_mapping': args.action, 'persistent_env_changed': False}))
        return
    if args.action == 'http':
        data = json.loads(state.read_text())
        issue = data['issues'][args.index]
        origin = 'https://stage.clubunio.com' if env == 'stage' else 'https://app.clubunio.com'
        request = urllib.request.Request(origin + '/api/mobile/v1/context', headers={'Authorization': 'Bearer ' + issue['token'], 'Accept': 'application/json'})
        opener = urllib.request.build_opener(NoRedirect())
        try:
            response = opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            status = response.code
            # Do not emit member/profile data or authorization headers.
            response.read(262144)
        print(json.dumps({'environment': env, 'https_mobile_context': status, 'issue_index': args.index}))
        return
    command = ['runuser', '-u', 'clubunio-' + env, '--', PHP,
               '/tmp/clubunio-security-20261125.9RMgXI/idp.php', '--root=' + str(app), '--action=' + args.action,
               '--state=' + str(state)]
    if args.action in ('create', 'issue'): command += ['--tokens=' + str(tokens), '--flow=' + str(flow)]
    if args.action == 'verify': command += ['--index=' + str(max(args.index, 0))]
    result = subprocess.run(command, capture_output=True)
    print(result.stdout.decode(), end='')
    if result.returncode:
        print(result.stderr.decode(), end='') # PHP helper deliberately emits only safe error codes.
        raise SystemExit(result.returncode)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'ok': False, 'error_class': type(error).__name__}))
        raise SystemExit(1)
