# ConvertService (DOCX -> PDF, what Print needs) and the CommandService "license" command, both JWT-signed.
import json, sys, time, urllib.request
sys.argv = ['x']; exec(open('call.py').read().split('args = ')[0])  # reuse jwt() and post()
res = post('/converter', {'async': False, 'filetype': 'docx', 'outputtype': 'pdf', 'key': 'conv-%d' % time.time(),
                          'url': 'http://files/fusion-copy.docx', 'title': 'fusion.docx'})
print('convert:', res)
if res.get('fileUrl'): urllib.request.urlretrieve(res['fileUrl'].replace('://localhost/', '://localhost:8480/'), 'out-convert.pdf')
print('license:', json.dumps(post('/command', {'c': 'license'}), indent=1)[:900])
print('version:', post('/command', {'c': 'version'}))
