"""Loopback static server for reviewing these frames.

    python docs/design/setup-1.4.5/serve.py [port]

/assets/* is served from www/phpledger/public/assets (the real compiled app.css, fonts, logos),
everything else from this folder. Read-only, 127.0.0.1 only, nothing is written anywhere.
"""
import http.server
import os
import sys
import urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
ASSETS = os.path.join(ROOT, 'www', 'phpledger', 'public', 'assets')


class Handler(http.server.SimpleHTTPRequestHandler):
    def translate_path(self, path):
        path = urllib.parse.unquote(urllib.parse.urlparse(path).path)
        if path.startswith('/assets/'):
            base, rel = ASSETS, path[len('/assets/'):]
        else:
            base, rel = HERE, path.lstrip('/')
        full = os.path.normpath(os.path.join(base, rel))
        return full if full.startswith(base) else base

    def end_headers(self):
        self.send_header('Cache-Control', 'no-store')
        super().end_headers()

    def log_message(self, *args):
        pass


if __name__ == '__main__':
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 18455
    http.server.ThreadingHTTPServer(('127.0.0.1', port), Handler).serve_forever()
