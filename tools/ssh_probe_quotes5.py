#!/usr/bin/env python3
import sys
import paramiko

PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
c.get_transport().set_keepalive(15)

def run(cmd, timeout=25):
    print("===", cmd[:220], "===")
    _, o, e = c.exec_command(cmd, timeout=timeout)
    o.channel.settimeout(timeout)
    try:
        out = o.read().decode("utf-8", "replace")
    except Exception as ex:
        out = f"<timeout {ex}>\n"
    print(out[:16000])
    try:
        err = e.read().decode("utf-8", "replace")
    except Exception:
        err = ""
    if err.strip():
        print("ERR", err[:400])

run("grep -n -i 'CONNECTOR_MID\\|externalId\\|idMessage' /home/bitrix/www/bitrix/modules/imconnector/lib/input.php | head -30")
run("grep -n -i 'CONNECTOR_MID\\|idMessage\\|quoted' /home/bitrix/www/bitrix/modules/imconnector/lib/output.php | head -40")
run("ls /home/bitrix/www/bitrix/modules/imconnector/lib/rest | head")
run("grep -n -i 'reply\\|quote\\|CONNECTOR_MID' /home/bitrix/www/bitrix/modules/imconnector/lib/rest/*.php | head -40")

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
global $DB;
$ids="13727016,13726759,13726941,13726758,13725966,13725998";
echo "=== params for ids ===\n";
$res=$DB->Query("SELECT MESSAGE_ID, PARAM_NAME, PARAM_VALUE FROM b_im_message_param WHERE MESSAGE_ID IN ($ids) ORDER BY MESSAGE_ID, PARAM_NAME");
while($r=$res->Fetch()) echo $r["MESSAGE_ID"]." ".$r["PARAM_NAME"]."=".$r["PARAM_VALUE"]."\n";

echo "\n=== CONNECTOR_MID around orig 13726759 chat ===\n";
$res=$DB->Query("SELECT CHAT_ID FROM b_im_message WHERE ID=13726759");
$chat=$res->Fetch();
$cid=(int)$chat["CHAT_ID"];
echo "chat=$cid\n";
$res=$DB->Query("SELECT m.ID, m.AUTHOR_ID, LEFT(m.MESSAGE,80) MSG, p.PARAM_VALUE MID FROM b_im_message m LEFT JOIN b_im_message_param p ON p.MESSAGE_ID=m.ID AND p.PARAM_NAME='CONNECTOR_MID' WHERE m.CHAT_ID=$cid AND m.ID BETWEEN 13725900 AND 13727100 ORDER BY m.ID");
while($r=$res->Fetch()) echo $r["ID"]." author=".$r["AUTHOR_ID"]." mid=".$r["MID"]." ".$r["MSG"]."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_quote_probe5.php", "w") as f:
    f.write(php)
sftp.close()
run("php /tmp/wa_quote_probe5.php", timeout=30)
run("rm -f /tmp/wa_quote_probe5.php")
c.close()
