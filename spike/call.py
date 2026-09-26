# Calls ONLYOFFICE Docs /docbuilder with a JWT-signed body (HS256), polls until done, downloads the results.
import base64, hashlib, hmac, json, sys, time, urllib.request
SECRET = b'spike-secret-at-least-32-characters-long'
def b64(b): return base64.urlsafe_b64encode(b).rstrip(b'=').decode()
def jwt(payload):
    h = b64(json.dumps({'alg': 'HS256', 'typ': 'JWT'}).encode()); p = b64(json.dumps(payload).encode())
    return f'{h}.{p}.' + b64(hmac.new(SECRET, f'{h}.{p}'.encode(), hashlib.sha256).digest())
def post(path, body):
    body = dict(body, token=jwt(body))
    req = urllib.request.Request('http://localhost:8480' + path, json.dumps(body).encode(), {'Content-Type': 'application/json', 'Accept': 'application/json'})
    return json.load(urllib.request.urlopen(req, timeout=120))
args = {'template': 'http://files/template.docx', 'values': {
    'numero': 'D-2026-042', 'client_nom': 'Société Martin', 'date': '26/09/2026', 'total': '1 250,00', 'expediteur': 'Marie Martin',
    'lignes': [{'designation': 'Audit', 'quantite': 1, 'prix': '750,00'}, {'designation': 'Formation', 'quantite': 2, 'prix': '250,00'}]}}
key = 'spike-%d' % time.time()
script = sys.argv[1] if len(sys.argv) > 1 else 'merge.js'
res = post('/docbuilder', {'async': True, 'url': 'http://files/' + script, 'key': key, 'argument': args})
for _ in range(60):
    print(script, res)
    if res.get('end') or res.get('error'): break
    time.sleep(2); res = post('/docbuilder', {'async': True, 'key': key})
for name, url in (res.get('urls') or {}).items():
    urllib.request.urlretrieve(url.replace('://localhost/', '://localhost:8480/'), 'out-' + name); print('saved out-' + name)
