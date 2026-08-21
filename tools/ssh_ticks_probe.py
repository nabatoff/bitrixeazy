#!/usr/bin/env python3
import paramiko, sys
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('crm.artflowers.kz', username='bitrix', password=sys.argv[1], timeout=20)
cmds = [
    "grep -rl 'gaApplyOutgoingMessageStatus\\|waCcTicksApplyWebhook' /home/bitrix/www/local 2>/dev/null",
    "head -20 /home/bitrix/www/local/custom_chat/green_api_group_sender_callback_patch.php",
    "stat -c '%a %U:%G' /home/bitrix/www/local/custom_chat/var",
    "php -r 'require \"/home/bitrix/www/local/custom_chat/app/wa_ticks.php\"; waCcTicksApplyWebhook([\"typeWebhook\"=>\"outgoingMessageStatus\",\"status\"=>\"read\",\"chatId\"=>\"77710888089@c.us\",\"timestamp\"=>time(),\"idMessage\"=>\"test\"]); echo file_exists(\"/home/bitrix/www/local/custom_chat/var/wa_ticks.json\")?\"ok\":\"fail\";' ",
    "cat /home/bitrix/www/local/custom_chat/var/wa_ticks.json 2>/dev/null | head -c 400",
]
for cmd in cmds:
    print('===', cmd[:90])
    _, o, e = c.exec_command(cmd, timeout=30)
    print(o.read().decode()[:2000])
    er = e.read().decode()
    if er.strip(): print('ERR', er[:200])
    print()
c.exec_command('rm -f /home/bitrix/www/local/custom_chat/var/wa_ticks.json')
c.close()
