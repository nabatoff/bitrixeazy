#!/usr/bin/env python3
import sys
import paramiko

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=25)
_, o, e = c.exec_command(
    "grep -n 'function Update' /home/bitrix/www/bitrix/modules/voximplant/classes/general/sip.php "
    "/home/bitrix/www/bitrix/modules/voximplant/lib/*.php 2>/dev/null | head -20; "
    "echo '---'; "
    "rg -n 'function Update' /home/bitrix/www/bitrix/modules/voximplant --glob '*.php' | head -30",
    timeout=30,
)
print(o.read().decode("utf-8", "replace"))
print(e.read().decode("utf-8", "replace"))
c.close()
