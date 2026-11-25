#!/usr/bin/env python3
"""Root-reviewed helper, NEVER auto-run. Secrets stay in root-only JSON files.
Admin input: {"client_id":"clubunio-security-review-...","client_secret":"..."}.
Run each subcommand separately; no Keycloak bootstrap/stop/start is performed here.
"""
import argparse, base64, hashlib, hmac, html.parser, http.cookiejar, json, os, secrets, stat, struct, sys, time
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode, urljoin, urlparse, parse_qs
from urllib.request import Request, build_opener, HTTPRedirectHandler, HTTPCookieProcessor
IDP='https://auth.clubunio.com'; REALM='clubunio'
CLIENTS={'clubunio-verein-stage':'https://stage.clubunio.com','clubunio-platform-stage':'https://stage.clubunio.com','clubunio-verein-prod':'https://app.clubunio.com','clubunio-platform-prod':'https://app.clubunio.com'}
ATTRIBUTE_KEYS=('backchannel.logout.url','backchannel.logout.session.required','backchannel.logout.revoke.offline.tokens')
class SafeFailure(Exception): pass

def private_json(path, value=None):
    path=Path(path)
    if value is None:
        if stat.S_IMODE(path.stat().st_mode)&0o077: raise SafeFailure('Input JSON is not root-only')
        return json.loads(path.read_text())
    if stat.S_IMODE(path.parent.stat().st_mode)&0o077: raise SafeFailure('State directory is not root-only')
    fd=os.open(str(path),os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600)
    with os.fdopen(fd,'w') as stream: json.dump(value,stream)
    os.chmod(path,0o600)

class RestrictedRedirect(HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):
        if urlparse(newurl).scheme!='https' or urlparse(newurl).netloc!='auth.clubunio.com': return None
        return super().redirect_request(req,fp,code,msg,headers,newurl)

class Client:
    def __init__(self,admin):
        self.opener=build_opener(RestrictedRedirect(),HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.admin=admin;self.token=None
    def request(self,url,method='GET',data=None,form=False,auth=False):
        if urlparse(url).scheme!='https' or urlparse(url).netloc!='auth.clubunio.com': raise SafeFailure('Unapproved network destination')
        headers={};body=None
        if data is not None:
            body=urlencode(data).encode() if form else json.dumps(data).encode()
            headers['Content-Type']='application/x-www-form-urlencoded' if form else 'application/json'
        if auth:
            if self.token is None:
                status,_,raw=self.request(IDP+'/realms/master/protocol/openid-connect/token','POST',{'grant_type':'client_credentials',**self.admin},True)
                if status!=200: raise SafeFailure('Temporary master-client token request failed: HTTP '+str(status))
                self.token=json.loads(raw)['access_token']
            headers['Authorization']='Bearer '+self.token
        try:
            with self.opener.open(Request(url,body,headers,method=method),timeout=20) as response:return response.status,dict(response.headers),response.read()
        except HTTPError as error:return error.code,dict(error.headers),error.read()
    def api(self,path,method='GET',data=None,realm=REALM,expected=(200,201,204)):
        status,headers,raw=self.request(IDP+'/admin/realms/'+realm+'/'+path,method,data,auth=True)
        if status not in expected: raise SafeFailure('Admin operation failed: '+method+' '+path.split('?')[0]+' HTTP '+str(status))
        return json.loads(raw) if raw else None,headers,status
    def find_client(self,client_id,realm=REALM):
        rows,_,_=self.api('clients?'+urlencode({'clientId':client_id}),realm=realm)
        exact=[r for r in rows if r.get('clientId')==client_id]
        if len(exact)!=1:raise SafeFailure('Exact client not uniquely found')
        return exact[0]

class Forms(html.parser.HTMLParser):
    def __init__(self,text):super().__init__();self.forms=[];self.current=None;self.feed(text)
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='form':self.current={'action':a.get('action',''),'inputs':{},'types':{}};self.forms.append(self.current)
        elif tag=='input' and self.current is not None and a.get('name'):
            self.current['inputs'][a['name']]=a.get('value','');self.current['types'][a['name']]=a.get('type','text')
    def handle_endtag(self,tag):
        if tag=='form':self.current=None

def totp(secret):
    raw=base64.b32decode(secret+'='*((8-len(secret)%8)%8));digest=hmac.new(raw,struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest();offset=digest[-1]&15
    return str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
def decode_claims(token):
    # Inspection only; PHP receiver performs actual signature validation.
    part=token.split('.')[1];return json.loads(base64.urlsafe_b64decode(part+'='*((4-len(part)%4)%4)))

def main():
    p=argparse.ArgumentParser();p.add_argument('--admin-json',required=True);p.add_argument('--state-dir',required=True)
    p.add_argument('action',choices=['configure','restore','create-user','login','logout-user','cleanup-user','cleanup-admin','server-logout']);p.add_argument('--client',choices=list(CLIENTS));p.add_argument('--acr',default='clubunio-mobile-mfa')
    p.add_argument('--environment', choices=['stage','prod'], default='stage')
    args=p.parse_args();directory=Path(args.state_dir)
    scoped_clients={k:v for k,v in CLIENTS.items() if k.endswith('-'+args.environment)}
    if not directory.is_dir() or stat.S_IMODE(directory.stat().st_mode)&0o077:raise SafeFailure('Existing root-only state directory required')
    admin=private_json(args.admin_json)
    if set(admin)!={'client_id','client_secret'} or not admin['client_id'].startswith('clubunio-security-review-'):raise SafeFailure('Unexpected temporary admin descriptor')
    client=Client(admin);userfile=directory/'synthetic-user.json';backupfile=directory/('client-attributes-before-'+args.environment+'.json')
    if args.action=='server-logout':
        transport_backup=directory/('frontchannel-before-'+args.environment+'.json')
        if transport_backup.exists():raise SafeFailure('Logout transport backup already exists')
        before={name:{'id':client.find_client(name)['id'],'frontchannelLogout':client.find_client(name).get('frontchannelLogout',False)} for name in scoped_clients}
        private_json(transport_backup,before)
        for name in scoped_clients:
            rep=client.find_client(name)
            client.api('clients/'+rep['id'],'PUT',{'frontchannelLogout':False})
            if client.find_client(name).get('frontchannelLogout') is not False:raise SafeFailure('Server logout transport verification failed')
        print(json.dumps({'action':'server-logout','clients':list(scoped_clients),'frontchannelLogout':False,'verified':True}));return
    if args.action in ('configure','restore'):
        if args.action=='configure':
            if backupfile.exists():raise SafeFailure('Attribute backup already exists; do not overwrite')
            backup={}
            # Read every before-value before changing the first client.
            for name in scoped_clients:
                rep=client.find_client(name);attrs=rep.get('attributes') or {};backup[name]={'id':rep['id'],'values':{k:attrs.get(k) for k in ATTRIBUTE_KEYS}}
            private_json(backupfile,backup)
        else:backup=private_json(backupfile)
        changed=[]
        for name,origin in scoped_clients.items():
            rep=client.find_client(name);attrs=dict(rep.get('attributes') or {})
            if args.action=='configure':attrs.update({'backchannel.logout.url':origin+'/auth/keycloak/backchannel-logout','backchannel.logout.session.required':'false','backchannel.logout.revoke.offline.tokens':'false'})
            else:
                for key,value in backup[name]['values'].items():
                    if value is None:attrs.pop(key,None)
                    else:attrs[key]=value
            # Send only attributes; unrelated client fields/themes/redirects remain untouched.
            patch={'attributes':attrs}
            transport_backup=directory/('frontchannel-before-'+args.environment+'.json')
            if args.action=='restore' and transport_backup.exists():patch['frontchannelLogout']=private_json(transport_backup)[name]['frontchannelLogout']
            client.api('clients/'+rep['id'],'PUT',patch)
            current=client.find_client(name)
            if any((current.get('attributes') or {}).get(k)!=attrs.get(k) for k in ATTRIBUTE_KEYS):raise SafeFailure('Client attribute verification failed')
            if 'frontchannelLogout' in patch and current.get('frontchannelLogout')!=patch['frontchannelLogout']:raise SafeFailure('Client logout transport restore verification failed')
            changed.append(name)
        print(json.dumps({'action':args.action,'clients':changed,'verified':True}));return
    if args.action=='create-user':
        if userfile.exists():raise SafeFailure('Synthetic user state already exists')
        username='security-review-'+secrets.token_hex(8)+'@example.invalid';password=secrets.token_urlsafe(36);otp=base64.b32encode(secrets.token_bytes(20)).decode().rstrip('=')
        state={'username':username,'password':password,'otp_base32':otp,'realm':REALM,'id':None}
        private_json(userfile,state)
        payload={'username':username,'email':username,'emailVerified':True,'firstName':'Security','lastName':'Synthetic','enabled':True,'requiredActions':[],
            'attributes':{'clubunioSecurityReview':['20261125']},'credentials':[{'type':'password','value':password,'temporary':False},
            {'type':'otp','userLabel':'Temporary security review','secretData':json.dumps({'value':otp}),
             'credentialData':json.dumps({'subType':'totp','digits':6,'counter':0,'period':30,'algorithm':'HmacSHA1','secretEncoding':'BASE32'})}]}
        _,headers,_=client.api('users','POST',payload)
        location=headers.get('Location') or headers.get('location')
        if not location:raise SafeFailure('Created user but no Location; inspect exact synthetic username privately')
        state['id']=location.rstrip('/').split('/')[-1];private_json(userfile,state)
        credentials,_,_=client.api('users/'+state['id']+'/credentials')
        if sorted(c.get('type') for c in credentials)!=['otp','password']:raise SafeFailure('Synthetic credentials not installed as expected')
        print(json.dumps({'created':True,'subject':state['id'],'credentials':['password','otp'],'mail_sent':False}));return
    if args.action=='cleanup-admin':
        rep=client.find_client(admin['client_id'],realm='master')
        client.api('clients/'+rep['id'],'DELETE',realm='master')
        print(json.dumps({'temporary_admin_deleted':True}));return
    state=private_json(userfile)
    if not state.get('id') or not state['username'].startswith('security-review-') or not state['username'].endswith('@example.invalid'):raise SafeFailure('Invalid synthetic state')
    current,_,_=client.api('users/'+state['id'])
    if (current.get('username')!=state['username'] or current.get('email')!=state['username']
        or current.get('firstName')!='Security' or current.get('lastName')!='Synthetic'
        or current.get('attributes',{}).get('clubunioSecurityReview') not in (None,['20261125'])):raise SafeFailure('Synthetic ownership marker mismatch')
    if args.action=='cleanup-user':
        client.api('users/'+state['id'],'DELETE');print(json.dumps({'synthetic_user_deleted':True,'subject':state['id']}));return
    if args.action=='logout-user':
        _,_,status=client.api('users/'+state['id']+'/logout','POST')
        print(json.dumps({'real_idp_logout_requested':True,'admin_http':status,'subject':state['id'],'requested_at':int(time.time())}));return
    if not args.client:raise SafeFailure('--client required for login')
    rep=client.find_client(args.client);origin=CLIENTS[args.client]
    redirect=origin+('/platform/oidc/callback' if '-platform-' in args.client else '/vereinslogin/oidc/callback')
    if redirect not in rep.get('redirectUris',[]):raise SafeFailure('Expected exact redirect URI not registered')
    verifier=secrets.token_urlsafe(48);challenge=base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip('=');nonce=secrets.token_urlsafe(32);oauthstate=secrets.token_urlsafe(32)
    query={'client_id':args.client,'redirect_uri':redirect,'response_type':'code','scope':'openid','state':oauthstate,'nonce':nonce,'code_challenge':challenge,'code_challenge_method':'S256','prompt':'login','max_age':0,'claims':json.dumps({'id_token':{'acr':{'essential':True,'values':[args.acr]}}})}
    url=IDP+'/realms/'+REALM+'/protocol/openid-connect/auth?'+urlencode(query);method='GET';data=None;form=False;code=None
    for step in range(8):
        status,headers,raw=client.request(url,method,data,form)
        if status in (301,302,303,307,308):
            target=headers.get('Location') or headers.get('location')
            parsed=urlparse(target or '')
            if (parsed.scheme+'://'+parsed.netloc+parsed.path)!=redirect:raise SafeFailure('Unexpected redirect after authentication')
            callback=parse_qs(parsed.query)
            if callback.get('state')!=[oauthstate] or 'code' not in callback:raise SafeFailure('Code/state callback missing')
            code=callback['code'][0];break
        if status!=200:raise SafeFailure('Authentication HTML returned HTTP '+str(status))
        candidates=Forms(raw.decode()).forms
        chosen=next((f for f in candidates if any(n in f['inputs'] for n in ('username','password','otp','totp'))),None)
        if chosen is None:raise SafeFailure('Unexpected required action or login page; no credentials printed')
        data=dict(chosen['inputs']);url=urljoin(url,chosen['action']);method='POST';form=True
        if 'username' in data:data['username']=state['username']
        if 'password' in data:data['password']=state['password']
        for key in ('otp','totp'):
            if key in data:
                if state.get('last_otp_step')==int(time.time())//30:time.sleep(30-(int(time.time())%30)+1)
                data[key]=totp(state['otp_base32']);state['last_otp_step']=int(time.time())//30;private_json(userfile,state)
    if code is None:raise SafeFailure('Code flow did not complete within bounded steps')
    secret,_,_=client.api('clients/'+rep['id']+'/client-secret')
    status,_,raw=client.request(IDP+'/realms/'+REALM+'/protocol/openid-connect/token','POST',{'grant_type':'authorization_code','client_id':args.client,'client_secret':secret['value'],'code':code,'redirect_uri':redirect,'code_verifier':verifier},True)
    if status!=200:raise SafeFailure('Real code exchange failed HTTP '+str(status))
    tokens=json.loads(raw);claims=decode_claims(tokens['id_token'])
    if claims.get('sub')!=state['id'] or claims.get('nonce')!=nonce or claims.get('iss')!=IDP+'/realms/'+REALM or claims.get('acr')!=args.acr:raise SafeFailure('Unexpected identity claims')
    private_json(directory/('tokens-'+args.client+'.json'),tokens)
    private_json(directory/('flow-'+args.client+'.json'),{'nonce':nonce,'client_id':args.client,'redirect_uri':redirect,'state':oauthstate,'acr':args.acr})
    print(json.dumps({'client':args.client,'subject':state['id'],'acr':claims.get('acr'),'auth_time':claims.get('auth_time'),'exp_present':isinstance(claims.get('exp'),int),'token_saved_privately':True,'callback_followed':False}))

if __name__=='__main__':
    try:main()
    except Exception as error:
        # Never print response body, exception repr, HTML, tokens, passwords or OTPs.
        print(json.dumps({'ok':False,'error':str(error) if isinstance(error,SafeFailure) else type(error).__name__}),file=sys.stderr);sys.exit(1)
