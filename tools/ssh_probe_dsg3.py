#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_,o,_=c.exec_command(r"""python3 - <<'PY'
from pathlib import Path
text=Path('/home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php').read_text(encoding='utf-8', errors='replace')
for name in ['function evaluateFieldEditPermissions','function isFieldFilled','function evaluate(','yn','isAutomation','CBP','Robot']:
    print('HAS', name, name in text)
i=text.find('function evaluateFieldEditPermissions')
print('\n===== evaluateFieldEditPermissions =====')
print(text[i:i+4200])
i=text.find('function evaluate(')
print('\n===== evaluate() start =====')
print(text[i:i+3500])
PY""")
print(o.read().decode('utf-8','replace')[:18000])

_,o,_=c.exec_command("grep -n '215868' /home/bitrix/www/upload/dealguard_test.log | tail -40")
print('\n===== LOG 215868 =====')
print(o.read().decode('utf-8','replace')[-6000:])
c.close()
