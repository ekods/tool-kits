#!/usr/bin/env python3
"""Run the actual PHP report and scanner JS in an isolated headless Chrome profile."""
import os
from pathlib import Path
import subprocess
import tempfile


def main():
    source = Path(__file__).resolve().parent
    chrome = os.environ.get('TK_CHROME_BINARY', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
    with tempfile.TemporaryDirectory(prefix='tk-html-browser-') as directory:
        root = Path(directory)
        html = subprocess.run([os.environ.get('TK_PHP_BINARY', 'php'), str(source / 'html-injection-browser-fixture.php')], capture_output=True, text=True, check=True).stdout
        page = root / 'report.html'; page.write_text(html)
        result = subprocess.run([chrome, '--headless', '--disable-gpu', '--no-sandbox', '--no-first-run', '--no-default-browser-check', f'--user-data-dir={root / "profile"}', '--virtual-time-budget=2000', '--window-size=1280,1400', '--dump-dom', f'--screenshot={root / "report.png"}', page.as_uri()], capture_output=True, text=True, timeout=30, check=True)
        assert 'data-fixture-result="passed"' in result.stdout, result.stdout[-5000:]
        preview = os.environ.get('TK_HTML_SCAN_PREVIEW')
        if preview:
            Path(preview).write_bytes((root / 'report.png').read_bytes())
        print('PASS: real browser report sections, action filtering, grouped evidence, restore flow, no automatic requests and inert evidence')


if __name__ == '__main__':
    main()
