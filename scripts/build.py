#!/usr/bin/env python3
"""Build a deterministic installable ZIP; no test credentials or developer files."""
import argparse, hashlib, re
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_STORED
root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--output', type=Path, default=root / 'dist')
args = parser.parse_args()
version = re.search(r' \* Version: (\S+)', (root / 'quoteflow-free.php').read_text())[1]
assert f'const QUOTEFLOW_FREE_VERSION = \'{version}\';' in (root / 'quoteflow-free.php').read_text()
assert f'Stable tag: {version}' in (root / 'readme.txt').read_text()
files = [root / 'quoteflow-free.php', root / 'readme.txt', root / 'LICENSE']
for folder in ('includes', 'assets', 'languages'):
    files += [p for p in (root / folder).rglob('*') if p.is_file() and not p.name.startswith('.')]
args.output.mkdir(parents=True, exist_ok=True)
path = args.output / f'quoteflow-free-{version}.zip'
with ZipFile(path, 'w', ZIP_STORED) as z:
    for p in sorted(files, key=lambda f: f.relative_to(root).as_posix()):
        info = ZipInfo('quoteflow-free/' + p.relative_to(root).as_posix(), (1980,1,1,0,0,0))
        info.compress_type = ZIP_STORED
        info.create_system = 3
        info.external_attr = 0o100644 << 16
        z.writestr(info, p.read_bytes())
with ZipFile(path) as z:
    assert z.testzip() is None and len(z.namelist()) == len(files)
    assert all(not any(part in n for part in ('tests/', '.git/', 'docs/', 'scripts/')) for n in z.namelist())
checksum = hashlib.sha256(path.read_bytes()).hexdigest()
path.with_suffix('.zip.sha256').write_text(f'{checksum}  {path.name}\n')
print(path)
print('SHA256 ' + checksum)
