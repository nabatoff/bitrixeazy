#!/usr/bin/env python3
"""Read-only: how deal field change history works on this Bitrix."""
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

cmds = [
    # timeline / history for field updates
    "grep -rn 'FieldModifier\\|UPDATE_FIELD\\|onAfterUpdate\\|Timeline.*Field' /home/bitrix/www/bitrix/modules/crm/lib/timeline --include='*.php' 2>/dev/null | head -35",
    "ls /home/bitrix/www/bitrix/modules/crm/lib/timeline 2>/dev/null | head -40",
    "grep -rn 'REGISTER_SONET\\|RegisterEvent\\|History\\|CHANGES' /home/bitrix/www/bitrix/modules/crm/classes/general/crm_deal.php 2>/dev/null | head -30",
    # tracked fields / settings
    "grep -rn 'TrackedField\\|trackField\\|getTracked\\|COMPARE_FIELDS\\|UF_CRM' /home/bitrix/www/bitrix/modules/crm/lib --include='*History*' 2>/dev/null | head -30",
    "find /home/bitrix/www/bitrix/modules/crm -iname '*tracked*' 2>/dev/null | head -20",
    "find /home/bitrix/www/bitrix/modules/crm -iname '*comparer*' 2>/dev/null | head -20",
]
for cmd in cmds:
    print("====", cmd[:75])
    _, o, _ = c.exec_command(cmd, timeout=50)
    print(o.read().decode("utf-8", "replace")[:2500])
c.close()
