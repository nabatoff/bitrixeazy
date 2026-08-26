#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

_,o,_=c.exec_command("cat /home/bitrix/www/local/crm/deal_uf_lock.js")
print("===== deal_uf_lock.js =====")
print(o.read().decode("utf-8","replace"))

_,o,_=c.exec_command(r"""python3 - <<'PY'
from pathlib import Path
# find admin UI for dsg
import os
hits=[]
for root, dirs, files in os.walk('/home/bitrix/www'):
    dirs[:] = [d for d in dirs if d not in ('bitrix/cache','bitrix/backup','upload','bitrix/modules','bitrix/components','bitrix/js','bitrix/css','bitrix/themes')]
    for fn in files:
        if 'dealstage' in fn.lower() or 'dsg_field' in fn.lower():
            hits.append(os.path.join(root,fn))
print('FILES', hits[:40])
PY""")
print("===== dsg files walk =====")
print(o.read().decode("utf-8","replace")[:4000])

_,o,_=c.exec_command("grep -rl 'dsg_field_permissions' /home/bitrix/www --include='*.php' 2>/dev/null | grep -v '/bitrix/modules/' | grep -v '/bitrix/cache/' | head")
print("===== grep option =====")
print(o.read().decode("utf-8","replace"))

_,o,_=c.exec_command("ls /home/bitrix/www/local/; find /home/bitrix/www/local -iname '*deal*' -o -iname '*dsg*' -o -iname '*guard*' 2>/dev/null | head -40")
print("===== local deal files =====")
print(o.read().decode("utf-8","replace"))
c.close()
