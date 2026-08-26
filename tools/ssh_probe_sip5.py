#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

print("=== getAllowedLines source ===")
_, o, _ = c.exec_command(
    "grep -n 'function getAllowedLines\\|function getUserOutgoingLine\\|function canUseLine\\|function GetConfigByUserId' "
    "/home/bitrix/www/bitrix/modules/voximplant/classes/general/vi_user.php "
    "/home/bitrix/www/bitrix/modules/voximplant/classes/general/vi_outgoing.php "
    "2>/dev/null; ls /home/bitrix/www/bitrix/modules/voximplant/classes/general/ | head",
    timeout=15)
sys.stdout.buffer.write(o.read()[:2500])

print("\n=== find getAllowedLines file ===")
_, o, _ = c.exec_command(
    "grep -rn 'function getAllowedLines' /home/bitrix/www/bitrix/modules/voximplant --include='*.php' | head",
    timeout=20)
sys.stdout.buffer.write(o.read()[:1500])

print("\n=== controller startCall / public API ===")
_, o, _ = c.exec_command(
    "sed -n '1,120p' /home/bitrix/www/bitrix/modules/voximplant/install/js/voximplant/phone-calls/src/index.js; "
    "echo '==== controller 740-920 ===='; "
    "sed -n '740,920p' /home/bitrix/www/bitrix/modules/voximplant/install/js/voximplant/phone-calls/src/controller.js",
    timeout=15)
sys.stdout.buffer.write(o.read()[:8000])

print("\n=== CRM phone click ===")
_, o, _ = c.exec_command(
    "grep -rn 'phoneTo\\|startCallViaRestApp\\|BX.Voximplant' "
    "/home/bitrix/www/bitrix/js/crm /home/bitrix/www/bitrix/modules/crm/install/js/crm 2>/dev/null | "
    "grep -iE 'phoneTo|startCall|makeCall' | head -25",
    timeout=25)
sys.stdout.buffer.write(o.read()[:3000])
c.close()
