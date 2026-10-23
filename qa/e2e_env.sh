#!/bin/bash
# Runs a command in web/ with the lab's browser-test logins (never production).
QA="$(cd "$(dirname "$0")" && pwd)"; LAB="${QA_LAB:-$QA/.lab}"
eval "$(LAB="$LAB" python3 -c "
import json,os,shlex
L=os.environ['LAB']; s=json.load(open(L+'/state.json')); r=s['shops']['rest']
env=dict(l.strip().split('=',1) for l in open(L+'/lab.env') if '=' in l)
for k,v in [('E2E_OWNER_EMAIL',env['OWNER_EMAIL']),('E2E_OWNER_PASSWORD',env['OWNER_PASSWORD']),('E2E_SHOP_EMAIL',r['email']),('E2E_SHOP_PASSWORD',r['pw']),('E2E_SHOP_SLUG',r['raw']['slug'])]:
    print('export '+k+'='+shlex.quote(v))
")"
export E2E_BASE_URL=http://localhost:3011
cd "$QA/../web" && exec "$@"
