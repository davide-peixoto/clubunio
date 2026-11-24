from pathlib import Path
import subprocess,tarfile,os,json,hashlib,io,re,argparse
parser=argparse.ArgumentParser();parser.add_argument('--snapshots',type=Path,required=True);parser.add_argument('--output',type=Path,required=True);parser.add_argument('--revision',required=True);args=parser.parse_args();out=args.output;out.mkdir(parents=True,exist_ok=True)
paths=subprocess.check_output(['git','diff','--name-only','b4048fb6',args.revision,'--','app','config','routes','resources','public','lang','database/clubunio','bootstrap/app.php']).decode().splitlines()
paths=[p for p in paths if not p.startswith('resources/js/')]
def git(rev,p):
 r=subprocess.run(['git','show',rev+':'+p],capture_output=True);return r.stdout if r.returncode==0 else None
def norm(b):return b.replace(b'\r\n',b'\n').replace(b'clubora.',b'clubunio.') if b is not None else None
for env in ['Stage','Prod']:
 with tarfile.open(args.snapshots/('clubunio-'+env+'-code-before-20261124.tar.gz')) as t:old={m.name:t.extractfile(m).read() for m in t if m.isfile()}
 merged={};conflicts=[]
 for p in paths:
  base=norm(git('b4048fb6',p));new=norm(git(args.revision,p));target=norm(old.get(p))
  if new is None:raise Exception('Deletion needs explicit review '+p)
  if target==base or target is None:result=new
  elif target==new or new==base:continue
  else:
   for n,v in [('target',target),('base',base or b''),('new',new)]: (out/n).write_bytes(v)
   r=subprocess.run(['git','merge-file','-p',str(out/'target'),str(out/'base'),str(out/'new')],capture_output=True)
   result=r.stdout
   if r.returncode!=0:
    def resolve(m):
     ours,theirs=m.group(1),m.group(2)
     if p=='app/ClubUnio/Pages/Dashboard.php':return theirs
     if p=='resources/views/clubunio/pages/social-studio.blade.php':return theirs+'    <link rel="stylesheet" href="{{ asset(\'css/clubunio-social-list.css\') }}?v=2">\n'
     if p=='app/ClubUnio/Resources/MemberResource/Pages/ViewMember.php':
      action=theirs[theirs.index('\\Filament\\Actions\\Action::make(\'timeline\')'):theirs.index(', BexioBillingRecipientAction::make()')]
      return ours.replace('return [...$actions, BexioBillingRecipientAction::make()', 'return [...$actions, '+action+', BexioBillingRecipientAction::make()')
     raise Exception('Unreviewed conflict '+p)
    result=re.sub(r'^<<<<<<<[^\n]*\n(.*?)^=======\n(.*?)^>>>>>>>[^\n]*\n',resolve,result.decode(),flags=re.M|re.S).encode()
    if b'<<<<<<<' in result:raise Exception('Unresolved '+p)
  if result!=target:merged[p]=result
 print(env,'files',len(merged),'conflicts',conflicts)
 manifest=[]
 with tarfile.open(out/(env+'.tar.gz'),'w:gz') as tar:
  for p,b in merged.items():
   info=tarfile.TarInfo(p);info.size=len(b);info.mode=0o644;tar.addfile(info,io.BytesIO(b));manifest.append(hashlib.sha256(b).hexdigest()+'  '+p)
  for p,b in [('deploy/promotion.manifest',('\n'.join(manifest)+'\n').encode()),('deploy/promotion-files.json',json.dumps(list(merged)).encode())]:
   info=tarfile.TarInfo(p);info.size=len(b);info.mode=0o644;tar.addfile(info,io.BytesIO(b))
 (out/(env+'-files.json')).write_text(json.dumps(list(merged)))
 if conflicts:raise Exception('Resolve conflicts before packaging')