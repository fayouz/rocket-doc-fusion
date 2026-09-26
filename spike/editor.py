# Writes editor.html (signed editor config) and drives it with Playwright: type, force save, check the callback got the edit.
import json, sys, time, glob, os
sys.argv = ['x']; exec(open('call.py').read().split('args = ')[0])
key = 'edit-%d' % time.time()
config = {'document': {'fileType': 'docx', 'key': key, 'title': 'fusion.docx', 'url': 'http://files/fusion-copy.docx',
                       'permissions': {'edit': True}},
          'documentType': 'word',
          'editorConfig': {'mode': 'edit', 'lang': 'fr', 'callbackUrl': 'http://cb:3000/callback',
                           'user': {'id': 'marie', 'name': 'Marie Martin'}, 'customization': {'forcesave': True}}}
config['token'] = jwt(config)
open('www/editor.html', 'w').write(f'''<!doctype html><html><body style="margin:0">
<div id="ed" style="height:100vh"></div>
<script src="http://localhost:8480/web-apps/apps/api/documents/api.js"></script>
<script>const c = {json.dumps(config)}; c.events = {{ onDocumentReady: () => window.ready = true, onError: e => window.err = e }};
new DocsAPI.DocEditor("ed", c);</script></body></html>''')
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b = p.chromium.launch(args=['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader']); pg = b.new_page(viewport={'width': 1400, 'height': 900})
    pg.goto('http://localhost:8081/editor.html')
    pg.wait_for_function('window.ready === true || window.err', timeout=120000)
    print('editor ready, error =', pg.evaluate('window.err || null'))
    frame = pg.frame_locator('iframe[name="frameEditor"]')
    try: frame.get_by_role('button', name='OK').first.click(timeout=5000)
    except Exception as e: print('no tip', e.__class__.__name__)
    time.sleep(8); pg.screenshot(path='out-editor-before.png')
    ed = next(f for f in pg.frames if f.name == 'frameEditor')
    print('sdk:', ed.evaluate("typeof Asc !== 'undefined' && !!Asc.editor"), 'area:', ed.locator('#area_id').count())
    ed.locator('#area_id').focus()
    pg.keyboard.press('Control+End'); pg.keyboard.type(' MODIFIE-DANS-ONLYOFFICE'); time.sleep(2)
    print('modified:', ed.evaluate("Asc.editor.isDocumentModified ? Asc.editor.isDocumentModified() : 'n/a'"))
    time.sleep(3); pg.screenshot(path='out-editor.png')
    print('forcesave:', post('/command', {'c': 'forcesave', 'key': key}))
    for _ in range(30):
        if glob.glob('cb-out/saved-*.docx'): break
        time.sleep(2)
    b.close()
saved = sorted(glob.glob('cb-out/saved-*.docx'))
print('saved files:', saved)
if saved:
    from docx import Document
    print('last paragraph:', [p.text for p in Document(saved[-1]).paragraphs][-1])
