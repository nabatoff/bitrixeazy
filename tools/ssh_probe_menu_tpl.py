#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_, o, _ = c.exec_command(
    "sed -n '80,220p' /home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/template.php",
    timeout=15)
sys.stdout.buffer.write(o.read()[:8000])
print("\n=== icon css ===")
_, o, _ = c.exec_command(
    "grep -n 'menu-item-icon\\|menu-item-index\\|menu-item-block\\|data-status' "
    "/home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/style.css "
    "| head -40",
    timeout=15)
sys.stdout.buffer.write(o.read()[:3000])
print("\n=== Basic getId ===")
_, o, _ = c.exec_command(
    "grep -n 'function getId\\|ID\\|LINK' /home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/MenuItem/Basic.php | head -30; "
    "sed -n '1,180p' /home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/MenuItem/Basic.php",
    timeout=15)
sys.stdout.buffer.write(o.read()[:4000])
c.close()
