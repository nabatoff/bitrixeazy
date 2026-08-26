#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_, o, e = c.exec_command(
    "grep -n 'function Get\\|SKIP\\|REPLY_ID\\|hiddenParams\\|notReturn' "
    "/home/bitrix/www/bitrix/modules/im/classes/general/im_message_param.php "
    "/home/bitrix/www/bitrix/modules/im/lib/messageparam.php 2>/dev/null | head -40",
    timeout=20)
print(o.read().decode("utf-8","replace")[:4000])

_, o, e = c.exec_command(
    "php -r 'echo class_exists(\"CIMMessageParam\")?\"y\":\"n\";'",
    timeout=10)

# dump Get() source around filter
_, o, e = c.exec_command(
    "grep -l 'class CIMMessageParam' /home/bitrix/www/bitrix/modules/im -r 2>/dev/null | head",
    timeout=20)
print("files", o.read().decode("utf-8","replace"))
c.close()
