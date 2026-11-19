#!/usr/bin/env python3
"""Sequential integration checks mutate a disposable store only."""
import argparse,subprocess,sys
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('compose');p.add_argument('--disposable',action='store_true',required=True);args=p.parse_args()
root=Path(__file__).resolve().parents[1]
for name in ('smoke','requests','multiline','mail_flow','decisions','delivery','variations','billing','inbox','manual_offers','privacy','release','privacy_retry','smtp'):
    print('Running '+name,flush=True)
    subprocess.run([sys.executable,str(root/'tests'/f'{name}.py'),args.compose],check=True,cwd=root)
