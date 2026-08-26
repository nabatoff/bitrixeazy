#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_,o,_=c.exec_command("sed -n '38290,38420p' /home/bitrix/www/upload/dealguard_test.log")
print('===== 13:15 block =====')
print(o.read().decode('utf-8','replace'))
_,o,_=c.exec_command("sed -n '38720,38780p' /home/bitrix/www/upload/dealguard_test.log")
print('===== 13:29 PREPAYMENT =====')
print(o.read().decode('utf-8','replace'))
_,o,_=c.exec_command(r"""python3 - <<'PY'
from pathlib import Path
text=Path('/home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php').read_text(encoding='utf-8', errors='replace')
i=text.find('function isFieldFilled')
print('===== isFieldFilled =====')
print(text[i:i+1800])
# uf enrich in evaluate?
idx=text.find('function evaluate(')
chunk=text[idx:idx+8000]
print('\n===== getUf in evaluate? =====', 'getUfFieldValue' in chunk)
# find getUfFieldValue call sites
start=0
while True:
    j=text.find('getUfFieldValue', start)
    if j<0: break
    line=text[:j].count('\n')+1
    print('L',line, text[j:j+120].replace('\n',' '))
    start=j+1
# tpl 309
print('done')
PY""")
print(o.read().decode('utf-8','replace')[:8000])
c.close()
