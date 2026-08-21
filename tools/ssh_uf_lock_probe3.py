#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
cmds = [
    "grep -rn 'setEditable\\|isEditable\\|getControlById' /home/bitrix/www/bitrix/js/ui/entity-editor --include='*.js' 2>/dev/null | grep -v '.min.js' | grep -v '.map.js' | head -40",
    "grep -rn 'getDefault\\|EntityEditor.get' /home/bitrix/www/bitrix/js/crm/entity-editor --include='*.js' 2>/dev/null | grep -v min | head -25",
    "grep -n 'function getUserFieldsInfo' -A40 /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory.php | head -50",
]
for cmd in cmds:
    print("====")
    _, o, _ = c.exec_command(cmd, timeout=50)
    print(o.read().decode("utf-8", "replace")[:4000])
c.close()
