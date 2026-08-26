#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_, o, _ = c.exec_command(
    "grep -n -A 15 'menu-item-no-icon-state' "
    "/home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/style.css | head -40; "
    "echo '----'; "
    "grep -n 'menu-item-icon' "
    "/home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/style.css | head -25",
    timeout=15)
sys.stdout.buffer.write(o.read()[:5000])
c.close()
