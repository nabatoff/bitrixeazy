#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "grep -n 'no-icon\\|menu-item-icon::\\|first-letter\\|data-letter\\|item-text\\|abbreviation\\|getInitial' "
    "/home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/style.css "
    "| head -40",
    "grep -n 'no-icon\\|initials\\|firstLetters\\|menu-item-icon' "
    "/home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/script.js "
    "| head -40",
    "sed -n '750,820p' /home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/style.css",
    "grep -n 'content:\\|::before\\|::after' /home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/style.css | head -30",
]
for cmd in cmds:
    print("===", cmd[:90])
    _, o, _ = c.exec_command(cmd, timeout=20)
    sys.stdout.buffer.write(o.read()[:3500])
    print()

print("=== template icon inner ===")
_, o, _ = c.exec_command(
    "grep -n -A 5 'menu-item-icon' /home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/template.php | head -40",
    timeout=15)
sys.stdout.buffer.write(o.read()[:2500])

print("\n=== pull subscribe examples in intranet ===")
_, o, _ = c.exec_command(
    "grep -n 'onPullEvent\\|PULL.subscribe' "
    "/home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/script.js "
    "| head -20",
    timeout=15)
sys.stdout.buffer.write(o.read()[:2000])
c.close()
