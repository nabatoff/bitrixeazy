#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
cmds = [
    "grep -rn 'isFieldReadOnly\\|FieldReadOnly\\|EDITABLE' /home/bitrix/www/bitrix/modules/crm/classes/general/crm_field_info_attr.php 2>/dev/null | head -40",
    "sed -n '1,120p' /home/bitrix/www/bitrix/modules/crm/classes/general/crm_field_info_attr.php 2>/dev/null",
    "grep -n 'enableEditInView\\|editable' /home/bitrix/www/bitrix/modules/crm/lib/Service/EditorAdapter.php | head -40",
    "sed -n '530,590p' /home/bitrix/www/bitrix/modules/crm/lib/Service/EditorAdapter.php",
    "grep -rn 'onPrepareFields\\|PrepareFieldInfos\\|EditorAdapter' /home/bitrix/www/bitrix/modules/crm/lib/Component/EntityDetails 2>/dev/null | head -25",
    # events for customizing editor fields
    "grep -rn \"addEventHandler.*crm.*[Ee]ditor\\|OnAfterCrmDeal\\|EntityDetails\" /home/bitrix/www/bitrix/modules/crm --include='*.php' 2>/dev/null | grep -i 'event\|EventManager' | head -20",
    "grep -rn 'crm.entity.editor\\|BX.Crm.EntityEditor' /home/bitrix/www/bitrix/js/crm/entity-editor/src 2>/dev/null | grep -i 'editable\\|readonly\\|enableEdit' | head -30",
]
for cmd in cmds:
    print("====")
    _, o, _ = c.exec_command(cmd, timeout=50)
    print(o.read().decode("utf-8", "replace")[:3500])
c.close()
