#!/usr/bin/env python3
"""Real HTTP headers/body through the production cache/drop-in; isolated PHP router."""
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time

SOURCE = Path(__file__).resolve().parent


def main():
    with tempfile.TemporaryDirectory(prefix="tk-cache-http-") as directory:
        root = Path(directory)
        (root / "wp-config.php").write_text("<?php\n// isolated fixture\n")
        fixture = root / "fixture.json"
        fixture.write_text("{}")
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        env = dict(os.environ, TK_CACHE_TEST_ROOT=directory)
        with (root / "server.log").open("w+") as log:
            server = subprocess.Popen(["php", "-d", "opcache.enable=0", "-d", "opcache.enable_cli=0", "-S", f"127.0.0.1:{port}", str(SOURCE / "cache-http-fixture.php")], env=env, stdout=log, stderr=log)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                            break
                    except OSError:
                        if server.poll() is not None:
                            raise RuntimeError("PHP test server could not start")
                        time.sleep(0.02)
                checks = 0

                def request(path, method="GET", headers=None):
                    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
                    start = time.perf_counter()
                    connection.request(method, path, headers=headers or {})
                    response = connection.getresponse()
                    elapsed = time.perf_counter() - start
                    body = response.read().decode()
                    result = dict((key.lower(), value) for key, value in response.getheaders())
                    assert response.status == 200, (response.status, body)
                    connection.close()
                    assert "Fatal error" not in body and "Warning:" not in body, body
                    return result, body, elapsed

                def nonce_matches(headers, body):
                    nonlocal checks
                    policy = headers.get("content-security-policy", "")
                    policy_nonces = re.findall(r"'nonce-([^']+)'", policy)
                    body_nonces = re.findall(r' nonce="([^"]+)"', body)
                    assert len(body_nonces) == 3 and policy_nonces, (policy, body)
                    assert set(body_nonces) == set(policy_nonces) and len(set(body_nonces)) == 1
                    assert "max-age=0" in headers["cache-control"] and "2592000" not in headers["cache-control"]
                    checks += 1
                    return body_nonces[0]

                timings = []
                for path in ["/outer", "/inner", "/theme"]:
                    cold_headers, cold_body, cold_time = request(path)
                    assert cold_headers["x-tool-kits-cache"] == "MISS", cold_headers
                    cold_nonce = nonce_matches(cold_headers, cold_body)
                    boots_before = (root / "boots.log").read_text()
                    hit_headers, hit_body, hit_time = request(path)
                    assert hit_headers["x-tool-kits-cache"] == "HIT-EARLY", hit_headers
                    assert "x-test-bootstrap" not in hit_headers, "Early hit bootstrapped WordPress"
                    assert (root / "boots.log").read_text() == boots_before, "Early hit ran application code"
                    assert nonce_matches(hit_headers, hit_body) != cold_nonce, "Nonce reused across requests"
                    assert hit_time < 0.15, hit_time
                    for name in ["x-frame-options", "x-content-type-options", "referrer-policy", "permissions-policy", "strict-transport-security", "cross-origin-resource-policy", "content-type"]:
                        assert hit_headers[name] == cold_headers[name], (name, hit_headers)
                    assert "set-cookie" not in hit_headers
                    timings.append((path, round(cold_time * 1000, 2), round(hit_time * 1000, 2)))
                    checks += 1

                cache = root / "wp-content/cache/tool-kits/page"
                for html_file in cache.glob("*.html"):
                    assert " nonce=" not in html_file.read_text(), "Nonce persisted on disk"
                    meta = json.loads(html_file.with_suffix(".html.meta.json").read_text())
                    assert "nonce-__TOOL_KITS_CSP_NONCE__" in str(meta["headers"])
                    assert meta["sha256"] == hashlib.sha256(html_file.read_bytes()).hexdigest()
                    checks += 1

                chrome = Path(os.environ.get("TK_CHROME", "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"))
                if chrome.is_file():
                    browser = subprocess.run([str(chrome), "--headless=new", "--no-first-run", "--no-default-browser-check", "--enable-logging=stderr", "--dump-dom", f"--user-data-dir={root / 'chrome-profile'}", f"http://127.0.0.1:{port}/theme"], capture_output=True, text=True, timeout=30)
                    assert browser.returncode == 0, browser.stderr[-2000:]
                    assert 'data-script-one="ok"' in browser.stdout and 'data-script-two="ok"' in browser.stdout, browser.stdout
                    assert 'data-css-applied="rgb(12, 34, 56)"' in browser.stdout, browser.stdout
                    assert "violates the following Content Security Policy" not in browser.stderr, browser.stderr[-2000:]
                    print("PASS: headless Chrome executed both inline scripts and applied inline CSS on HIT-EARLY; no CSP violation logged")
                    checks += 1
                else:
                    print("SKIP: Chrome unavailable (set TK_CHROME to enable the browser CSP check)")

                # Seed a cached public URL, then ensure dynamic requests to that
                # SAME URL bypass it, rather than merely testing nonexistent keys.
                for method, headers in [
                    ("POST", {}), ("HEAD", {}),
                    ("GET", {"Cookie": "wordpress_logged_in_test=1"}),
                    ("GET", {"Cookie": "wp-postpass_test=1"}),
                    ("GET", {"Cookie": "comment_author_test=1"}),
                    ("GET", {"Cookie": "woocommerce_items_in_cart=1"}),
                    ("GET", {"Cookie": "wp_woocommerce_session_test=1"}),
                    ("GET", {"Authorization": "Bearer private"}),
                ]:
                    actual, _, _ = request("/outer", method, headers)
                    assert "x-tool-kits-cache" not in actual, (method, headers, actual)
                    assert actual["x-test-bootstrap"] == "loaded"
                    checks += 1
                for path in ["/?s=test", "/wp-admin", "/wp-admin/edit.php", "/cart", "/excluded", "/sets-cookie", "/json", "/private", "/varies-cookie"]:
                    for _ in range(2):
                        headers, body, _ = request(path)
                        assert "x-tool-kits-cache" not in headers, (path, headers, body)
                    checks += 1

                # The normal template_redirect HIT path still renders fresh
                # matching nonces when an early snapshot has expired.
                config_path = root / "wp-content/cache/tool-kits/page-cache-config.php"
                config_text = config_path.read_text()
                changed_config = re.sub(r"'valid_until' => \d+", "'valid_until' => 1", config_text)
                assert changed_config != config_text, config_text
                config_path.write_text(changed_config)
                normal_headers, normal_body, _ = request("/theme")
                assert normal_headers["x-tool-kits-cache"] == "HIT", normal_headers
                nonce_matches(normal_headers, normal_body)
                checks += 1

                # Expired disk entry and missing/mismatched metadata fail closed.
                key = hashlib.md5(f"http|127.0.0.1:{port}|/outer".encode()).hexdigest()
                html_path = cache / f"{key}.html"
                old = time.time() - 4000
                os.utime(html_path, (old, old))
                headers, body, _ = request("/outer")
                assert headers["x-tool-kits-cache"] == "MISS"
                nonce_matches(headers, body)
                html_path.with_suffix(".html.meta.json").unlink()
                headers, _, _ = request("/outer")
                assert headers["x-tool-kits-cache"] == "MISS"
                html_path.write_text("corrupt")
                headers, _, _ = request("/outer")
                assert headers["x-tool-kits-cache"] == "MISS"
                checks += 3

                request("/purge", "POST")
                assert not list(cache.glob("*.html")) and not list(cache.glob("*.meta.json"))
                checks += 1
                request("/outer")
                fixture.write_text(json.dumps({"unlicensed": True}))
                request("/license-refresh", "POST")
                assert not config_path.exists() and not (root / "wp-content/advanced-cache.php").exists()
                headers, _, _ = request("/outer")
                assert "x-tool-kits-cache" not in headers
                checks += 1
                print(f"PASS: {checks} real HTTP cache/CSP/security-header/bypass/purge/license checks")
                print("Isolated localhost fixture TTFB (includes simulated 180 ms application boot on MISS):")
                for path, cold, hit in timings:
                    print(f"  {path}: MISS {cold} ms; HIT-EARLY {hit} ms")
            except Exception:
                log.flush()
                print("\n".join((root / "server.log").read_text().splitlines()[-12:]))
                raise
            finally:
                server.terminate()
                server.wait(timeout=5)


if __name__ == "__main__":
    main()
