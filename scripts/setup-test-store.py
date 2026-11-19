#!/usr/bin/env python3
"""Provision only the named, local, fictitious M0 store. Never imports production data."""
import argparse,hashlib,re,subprocess,sys,urllib.request
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--disposable',action='store_true',required=True);args=p.parse_args()
root=Path(__file__).resolve().parents[1]
download=root/'work/downloads/wp-cli.phar';download.parent.mkdir(parents=True,exist_ok=True)
if not download.exists():
    urllib.request.urlretrieve('https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar',download)
assert hashlib.sha256(download.read_bytes()).hexdigest()=='ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c','Unexpected WP-CLI checksum'
compose=['docker','compose','-f',str(root/'compose.test.yaml')]
subprocess.run([*compose,'up','-d','--wait'],check=True)
def wp(*a,check=True):
    r=subprocess.run([*compose,'exec','-T','--user','www-data','wp','php','/downloads/wp-cli.phar',*a],text=True,capture_output=True)
    if check and r.returncode: raise RuntimeError(r.stdout+r.stderr)
    return r
if wp('core','is-installed',check=False).returncode:
    result=wp('core','install','--url=http://127.0.0.1:18972','--title=QuoteFlow M0','--admin_user=quote-m0','--admin_password=quoteflow-m0-local-only','--admin_email=local@example.test','--skip-email');print(result.stdout)
else:
    assert wp('option','get','blogname').stdout.strip()=='QuoteFlow M0','Refusing an unknown WordPress installation'
    assert wp('option','get','home').stdout.strip()=='http://127.0.0.1:18972','Refusing a non-local store'
if wp('plugin','is-installed','woocommerce',check=False).returncode:
    print(wp('plugin','install','woocommerce','--version=11.3.0','--activate').stdout)
else:wp('plugin','activate','woocommerce')
wp('eval', "WC_Install::create_pages();update_option('woocommerce_bacs_settings',['enabled'=>'yes','title'=>'Local test transfer']);update_option('woocommerce_coming_soon','no');update_option('woocommerce_calc_taxes','no');")
if wp('theme','is-installed','twentytwentyfive',check=False).returncode:
    wp('theme','install','twentytwentyfive','--activate')
else: wp('theme','activate','twentytwentyfive')
wp('language','core','install','es_AR')
subprocess.run([sys.executable,str(root/'scripts/build.py')],check=True)
version=re.search(r' \* Version: (\S+)',(root/'quoteflow-free.php').read_text())[1]
archive=root/'dist'/f'quoteflow-free-{version}.zip'
subprocess.run([*compose,'cp',str(archive),'wp:/tmp/quoteflow-free.zip'],check=True)
print(wp('plugin','install','/tmp/quoteflow-free.zip','--force','--activate').stdout)
print('Disposable store ready: http://127.0.0.1:18972')
