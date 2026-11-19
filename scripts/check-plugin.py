#!/usr/bin/env python3
"""Keep full diagnostics; fail CI on actual Plugin Check errors, never hide warnings."""
import argparse,collections,json,re,subprocess
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('compose');p.add_argument('--install',action='store_true');args=p.parse_args()
root=Path(__file__).resolve().parents[1]
wp=['docker','compose','-f',str(Path(args.compose).resolve()),'exec','-T','--user','www-data','wp','php','/downloads/wp-cli.phar']
if args.install:subprocess.run([*wp,'plugin','install','plugin-check','--version=2.2.0','--activate'],check=True)
r=subprocess.run([*wp,'plugin','check','quoteflow-free','--format=json'],capture_output=True,text=True,check=True)
folder=root/'work/reports';folder.mkdir(parents=True,exist_ok=True)
(folder/'plugin-check.txt').write_text(r.stdout+r.stderr)
issues=[]
for name,body in re.findall(r'FILE: ([^\n]+)\n(\[.*?\])(?=\n\n|$)',r.stdout,re.S):
    issues += [dict(file=name,**row) for row in json.loads(body)]
# An empty result is valid only when the CLI explicitly reports success.
assert issues or 'Success:' in r.stdout,'Unrecognized Plugin Check output'
summary={'counts':dict(collections.Counter(row['type'] for row in issues)),
         'warning_codes':dict(collections.Counter(row['code'] for row in issues if row['type']=='WARNING'))}
(folder/'plugin-check-summary.json').write_text(json.dumps(summary,indent=2)+'\n')
print(json.dumps(summary,indent=2))
assert not any(row['type']=='ERROR' for row in issues),'Plugin Check errors require correction'
