#!/usr/bin/env python3
"""Exercise real PHP cURL timings/body limits against an isolated local server."""
import json
import os
from pathlib import Path
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class Handler(BaseHTTPRequestHandler):
    target_requests = 0

    def log_message(self, *_):
        pass

    def do_GET(self):
        if self.path == '/target':
            Handler.target_requests += 1
        body = b'<html><head><title>Measured</title><meta name="description" content="Hello"><link rel="canonical" href="https://site.test/"></head><body>Real request</body></html>'
        if self.path == '/large':
            body += b'x' * 600000
        if self.path == '/slow':
            time.sleep(0.09)
        self.send_response(302 if self.path == '/redirect' else (500 if self.path == '/error' else 200))
        self.send_header('Content-Type', 'text/html; charset=UTF-8')
        self.send_header('Content-Length', str(len(body)))
        self.send_header('Cache-Control', 'public, max-age=2592000' if self.path == '/old-cache' else 'public, max-age=0')
        self.send_header('X-Tool-Kits-Cache', 'HIT-EARLY')
        if self.path == '/redirect':
            self.send_header('Location', '/target')
        self.end_headers()
        try:
            self.wfile.write(body[:50]); self.wfile.flush()
            if self.path == '/slow':
                time.sleep(0.3)
            self.wfile.write(body[50:]); self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError):
            pass


def main():
    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
    env = dict(os.environ, TK_HEALTH_HTTP_ORIGIN=f'http://127.0.0.1:{server.server_port}')
    client = Path(__file__).with_name('health-center-http-client.php')

    def measure(path):
        completed = subprocess.run([os.environ.get('TK_PHP_BINARY', 'php'), str(client), path], env=env, capture_output=True, text=True, check=True, timeout=25)
        return json.loads(completed.stdout)

    def check(result, label):
        return next(row for row in result['report']['checks'] if row['label'] == label)

    try:
        slow = measure('/slow')
        assert .08 <= slow['response']['ttfb'] < .3, slow
        assert slow['elapsed'] > slow['response']['ttfb'] + .25, slow
        assert slow['report']['performance']['cache'] == 'HIT-EARLY'
        assert check(slow, 'Homepage title')['status'] == 'passed'
        redirect = measure('/redirect')
        assert redirect['response']['status'] == 302 and Handler.target_requests == 0
        assert redirect['report']['performance']['ttfb'] is None
        large = measure('/large')
        assert large['response']['truncated'] and large['response']['bytes'] == 524288
        assert large['response']['error'] == '' and check(large, 'Homepage title')['status'] == 'unknown'
        old = measure('/old-cache')
        assert check(old, 'HTML browser cache lifetime')['status'] == 'critical'
        error = measure('/error')
        assert error['report']['performance']['ttfb'] is None and check(error, 'Public homepage response')['status'] == 'critical'
        print('PASS: real cURL first-byte versus total time, cache header, HTML scan, redirect refusal, bounded body and error handling')
    finally:
        server.shutdown(); server.server_close(); thread.join(timeout=2)


if __name__ == '__main__':
    main()
