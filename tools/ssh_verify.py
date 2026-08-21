#!/usr/bin/env python3
import paramiko, sys
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('crm.artflowers.kz', username='bitrix', password=sys.argv[1], timeout=20)
cmds = [
    'ls -la /home/bitrix/www/local/custom_chat/app/wa_group_titles.php',
    'grep -c getWhatsAppGroupKey /home/bitrix/www/local/custom_chat/index.php',
    'php -r \'define("NO_KEEP_STATISTIC",true);define("NOT_CHECK_PERMISSIONS",true);$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php"; global $DB; $r=$DB->Query("SELECT ID,TITLE,ENTITY_ID FROM b_im_chat WHERE TYPE=\\\"L\\\" AND ENTITY_ID LIKE \\\"%@g.us%\\\" AND TITLE LIKE \\\"%\\u041c\\u0438%\\\" ORDER BY ID DESC LIMIT 8"); while($x=$r->Fetch()) echo $x["ID"]." | ".$x["TITLE"]." | ".$x["ENTITY_ID"]."\\n\";\'',
]
for cmd in cmds:
    print('===', cmd[:90])
    _, stdout, stderr = c.exec_command(cmd, timeout=45)
    print(stdout.read().decode('utf-8', errors='replace')[:3000])
    e = stderr.read().decode('utf-8', errors='replace')
    if e.strip(): print('ERR', e[:200])
c.close()
