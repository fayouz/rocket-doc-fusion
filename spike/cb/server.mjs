// Callback endpoint of the editor: logs every call, downloads the saved file on status 2 (closed) or 6 (force save).
import http from 'node:http'; import fs from 'node:fs';
http.createServer((req, res) => {
  let body = ''; req.on('data', c => body += c);
  req.on('end', async () => {
    console.log(new Date().toISOString(), req.method, req.url, body.slice(0, 400));
    try {
      const data = JSON.parse(body || '{}');
      if ((data.status === 2 || data.status === 6) && data.url) {
        const file = await fetch(data.url.replace('://localhost/', '://ds/').replace('://localhost:8480/', '://ds/'));
        fs.writeFileSync(`/out/saved-${data.status}-${Date.now()}.docx`, Buffer.from(await file.arrayBuffer()));
        console.log('saved from', data.url);
      }
    } catch (e) { console.log('error', e.message); }
    res.setHeader('Content-Type', 'application/json'); res.end('{"error":0}');
  });
}).listen(3000);
