#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
cmds = [
    "grep -n 'function isFieldReadOnly\\|ReadOnly' /home/bitrix/www/bitrix/modules/crm/classes/general/crm_field_info_attr.php | head -30",
    "sed -n '1,100p' /home/bitrix/www/bitrix/modules/crm/classes/general/crm_field_info_attr.php",
    "grep -rn 'GetFieldsInfo\\|onGetFieldsInfo\\|ATTRIBUTES' /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory/Deal.php 2>/dev/null | head -30",
    "grep -n 'getFieldsInfo\\|getUserFieldsInfo\\|ReadOnly' /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory.php 2>/dev/null | head -40",
    "grep -rn \"'crm', 'on\\|Event('crm'\" /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory/Deal.php 2>/dev/null | head -20",
    # JS events for entity editor field
    "grep -rn 'enableEditInView\\|setEditable\\|editable:' /home/bitrix/www/bitrix/js/ui/entity-editor/src 2>/dev/null | head -30",
    "ls /home/bitrix/www/bitrix/js/ui/entity-editor/src 2>/dev/null | head",
    "head -100 /home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php",
]
for cmd in cmds:
    print("====", cmd[:70])
    _, o, _ = c.exec_command(cmd, timeout=50)
    print(o.read().decode("utf-8", "replace")[:3000])
c.close()
