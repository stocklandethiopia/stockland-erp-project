#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
python3 - "$ROOT" <<'PY'
from pathlib import Path
import hashlib,re,sys
root=Path(sys.argv[1])
src=(root/'assets/app.css').read_text()
mini=(root/'assets/app.min.css').read_text()
assert src.count('{')==src.count('}'), 'app.css braces mismatch'
assert mini.count('{')==mini.count('}'), 'app.min.css braces mismatch'
assert '16.1.65 MOBILE-FIRST PREMIUM PRESENTATION LAYER' in src
manifest=__import__('json').loads((root/'assets/css/modules.json').read_text())
assert manifest.get('release')=='16.1.65' and len(manifest.get('modules',[]))==6, 'six CSS module manifest mismatch'
chunks=[]
for name in manifest['modules']:
    p=root/'assets/css'/name
    assert p.is_file(), f'missing CSS module {name}'
    chunk=p.read_text().strip()
    assert chunk.count('{')==chunk.count('}'), f'brace mismatch in {name}'
    chunks.append(chunk)
assert src=='\n\n'.join(chunks)+'\n', 'app.css is stale; rebuild from the six source modules'
assert '.sl-content-inner>.sl-page-title-static{position:relative' in src, 'static health heading guard missing'
assert '@media (max-width:760px)' in src and 'content:attr(data-label)' in src, 'mobile labelled-table contract missing'
assert 'assets/app.min.css' in (root/'includes/class-stockland-frontend.php').read_text(), 'single CSS delivery bundle not enqueued'
# Explicitly check the operational label selectors rather than globally banning display:none.
for token in ['sl-top-action-text','sl-nav-label','sl-alert-label']:
    if re.search(re.escape(token)+r'[^{}]*\{[^}]*display\s*:\s*none\s*!important',src,re.I|re.S):
        raise SystemExit(f'operational label hidden: {token}')
if '.sl-mobile-menu-open .sl-mobile-menu{display:grid}' not in src:
    raise SystemExit('mobile drawer open-state rule missing')
if '.sl-content-inner>.sl-page-title{position:sticky' not in src:
    raise SystemExit('sticky page action bar rule missing')
if hashlib.sha256(src.encode()).hexdigest()==hashlib.sha256(mini.encode()).hexdigest():
    raise SystemExit('app.min.css is byte-identical to source')
print('CSS QA PASS')
PY
