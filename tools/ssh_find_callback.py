#!/usr/bin/env python3
import paramiko, sys
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('crm.artflowers.kz', username='bitrix', password=sys.argv[1], timeout=20)
for cmd in [
    'find /home/bitrix/www -maxdepth 5 -name callback.php 2>/dev/null | head -10',
    'grep -r "waCcTicksApplyWebhook\\|gaApplyOutgoingMessageStatus" /home/bitrix/www 2>/dev/null | head -10',
]:
    print('===', cmd)
    _, stdout, _ = c.exec_command(cmd, timeout=40)
    print(stdout.read().decode('utf-8', errors='replace')[:2000])
c.close()
