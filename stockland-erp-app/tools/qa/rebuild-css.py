#!/usr/bin/env python3
"""Compile six ordered Stockland ERP CSS source modules to local assets."""
from pathlib import Path
import json, re
ROOT=Path(__file__).resolve().parents[2]
ASSET=ROOT/'assets'
MODULE_DIR=ASSET/'css'
manifest=json.loads((MODULE_DIR/'modules.json').read_text())
modules=manifest.get('modules',[])
if manifest.get('release')!='16.1.65' or len(modules)!=6 or len(set(modules))!=6:
    raise SystemExit('Expected release 16.1.65 with exactly six unique CSS modules')
chunks=[]
for name in modules:
    path=MODULE_DIR/name
    if not path.is_file(): raise SystemExit(f'Missing CSS module: {name}')
    chunk=path.read_text().strip()
    if chunk.count('{')!=chunk.count('}'): raise SystemExit(f'Unbalanced braces in {name}')
    chunks.append(chunk)
source='\n\n'.join(chunks)+'\n'
if source.count('{')!=source.count('}'): raise SystemExit('Combined CSS braces mismatch')
(ASSET/'app.css').write_text(source)
mini=re.sub(r'/\*.*?\*/',' ',source,flags=re.S)
mini=re.sub(r'\s+',' ',mini).strip()
mini=re.sub(r'\s*([{}:;,])\s*',r'\1',mini)
mini=re.sub(r';}','}',mini)
(ASSET/'app.min.css').write_text(mini+'\n')
print(f'Built {len(modules)} modules: app.css={len(source)} bytes; app.min.css={len(mini)} bytes')
