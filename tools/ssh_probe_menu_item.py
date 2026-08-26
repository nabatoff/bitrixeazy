#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_, o, _ = c.exec_command(
    "sed -n '1,200p' /home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/MenuItem/ItemRestApplication.php; "
    "echo '---- Item.php data-id ----'; "
    "grep -n 'data-id\\|getId\\|menu_app\\|COUNTER\\|counter\\|icon' "
    "/home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/MenuItem/Item.php "
    "/home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/MenuItem/ItemUser.php "
    "| head -50",
    timeout=20)
sys.stdout.buffer.write(o.read()[:9000])
c.close()
