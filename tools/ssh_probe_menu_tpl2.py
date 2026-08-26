#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_, o, _ = c.exec_command(
    "sed -n '220,340p' /home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/template.php",
    timeout=15)
sys.stdout.buffer.write(o.read()[:8000])
c.close()
