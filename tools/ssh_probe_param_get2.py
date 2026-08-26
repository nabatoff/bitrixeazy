#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_, o, _ = c.exec_command("sed -n '370,455p' /home/bitrix/www/bitrix/modules/im/classes/general/im_message_param.php", timeout=20)
sys.stdout.buffer.write(o.read())
_, o, _ = c.exec_command("sed -n '680,780p' /home/bitrix/www/bitrix/modules/im/classes/general/im_message_param.php", timeout=20)
print("\n=== defaults ===")
sys.stdout.buffer.write(o.read())
c.close()
