#!/usr/bin/env python3
"""Read-only: can Bitrix deal fields be made non-editable / non-clickable."""
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

cmds = [
    # entity editor field configs / readonly
    r"grep -rn 'READONLY\|readOnly\|isReadOnly\|editable' /home/bitrix/www/bitrix/modules/crm/lib/Service/EditorAdapter.php 2>/dev/null | head -30",
    r"grep -rn 'READONLY\|readOnly' /home/bitrix/www/bitrix/modules/crm/lib/component/entitydetails 2>/dev/null | head -25",
    r"ls /home/bitrix/www/bitrix/modules/crm/lib/component/entitydetails/ 2>/dev/null | head",
    # user field settings
    r"grep -rn 'EDIT_IN_LIST\|SHOW_IN_LIST\|SETTINGS' /home/bitrix/www/bitrix/modules/crm/lib/userfield 2>/dev/null | head -20",
    # existing local customizations for deal fields
    r"grep -rn 'EntityEditor\|crm.deal.details\|OnEntityDetails\|READONLY\|запрет\|readOnly' /home/bitrix/www/local --include='*.php' --include='*.js' 2>/dev/null | head -40",
    r"grep -rn 'EntityEditor\|crm.deal.details' /home/bitrix/www/bitrix/php_interface --include='*.php' 2>/dev/null | head -20",
    # Bitrix field attribute in editor scheme
    r"grep -rn \"'editable'\|\\\"editable\\\"\|readOnlyOnly\|enableToChange\" /home/bitrix/www/bitrix/js/crm/entity-editor 2>/dev/null | head -25",
    r"find /home/bitrix/www/bitrix/js/crm -maxdepth 2 -type d -iname '*editor*' 2>/dev/null | head",
]
for cmd in cmds:
    print("====", cmd[:90])
    _, o, e = c.exec_command(cmd, timeout=60)
    out = o.read().decode("utf-8", "replace")[:2500]
    err = e.read().decode("utf-8", "replace")[:400]
    print(out if out.strip() else "(empty)")
    if err.strip():
        print("ERR", err)
c.close()
