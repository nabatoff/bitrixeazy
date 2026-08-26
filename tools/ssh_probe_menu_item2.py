#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_, o, _ = c.exec_command(
    "grep -rn 'ItemRestApplication\\|menu_app_\\|COUNTER_ID' "
    "/home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu "
    "/home/bitrix/www/bitrix/modules/intranet/lib/Internal/Service/LeftMenu "
    "2>/dev/null | head -40; "
    "echo '----'; "
    "grep -n 'getId\\|ID\\|LINK\\|TEXT\\|COUNTER' /home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/MenuItem/Item.php | head -40; "
    "echo '---- Item.php id ----'; "
    "sed -n '1,120p' /home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/MenuItem/Item.php",
    timeout=20)
sys.stdout.buffer.write(o.read()[:10000])
c.close()
