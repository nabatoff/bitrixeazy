#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_, o, _ = c.exec_command(
    "sed -n '650,720p' /home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/script.js",
    timeout=15)
sys.stdout.buffer.write(o.read()[:4000])
print("\n=== updateCounters ===")
_, o, _ = c.exec_command(
    "grep -n 'updateCounters\\|user_counter\\|menu-counter' "
    "/home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/script.js "
    "| head -30",
    timeout=15)
sys.stdout.buffer.write(o.read()[:2500])
print("\n=== onPullEvent-main ===")
_, o, _ = c.exec_command(
    "sed -n '3430,3520p' /home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/script.js",
    timeout=15)
sys.stdout.buffer.write(o.read()[:3500])
c.close()
