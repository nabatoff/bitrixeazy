#!/usr/bin/env python3
import sys
import paramiko

PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
c.get_transport().set_keepalive(15)

def run(cmd, timeout=25):
    print("===", cmd[:200], "===")
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

run("sed -n '1020,1060p' /home/bitrix/www/bitrix/modules/im/lib/V2/Message.php")
run("grep -n -i 'reply\\|quote\\|CONNECTOR_MID\\|message_id' /home/bitrix/www/bitrix/modules/imconnector/lib/customconnectors.php | head -40")
run("grep -n -i 'reply\\|quote' /home/bitrix/www/bitrix/modules/imconnector/lib/output.php | head -40")
run("grep -n -i 'function send\\|REPLY\\|quote' /home/bitrix/www/bitrix/modules/imopenlines/lib/im.php | head -40")

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
global $DB;
echo "=== last REPLY_ID ===\n";
$res=$DB->Query("SELECT MESSAGE_ID, PARAM_VALUE FROM b_im_message_param WHERE PARAM_NAME='REPLY_ID' ORDER BY ID DESC LIMIT 8");
$rows=[];
while($r=$res->Fetch()){ $rows[]=$r; echo $r["MESSAGE_ID"]." reply=".$r["PARAM_VALUE"]."\n"; }
if($rows){
  $mid=(int)$rows[0]["MESSAGE_ID"];
  $rid=(int)$rows[0]["PARAM_VALUE"];
  echo "\n--- reply msg $mid ---\n";
  $res=$DB->Query("SELECT ID,CHAT_ID,AUTHOR_ID,DATE_CREATE,LEFT(MESSAGE,220) MSG FROM b_im_message WHERE ID=".$mid);
  print_r($res->Fetch());
  $res=$DB->Query("SELECT PARAM_NAME, PARAM_VALUE FROM b_im_message_param WHERE MESSAGE_ID=".$mid);
  while($r=$res->Fetch()) echo $r["PARAM_NAME"]."=".$r["PARAM_VALUE"]."\n";
  echo "\n--- orig $rid ---\n";
  $res=$DB->Query("SELECT ID,AUTHOR_ID,LEFT(MESSAGE,220) MSG FROM b_im_message WHERE ID=".$rid);
  print_r($res->Fetch());
  $res=$DB->Query("SELECT PARAM_NAME, PARAM_VALUE FROM b_im_message_param WHERE MESSAGE_ID=".$rid);
  while($r=$res->Fetch()) echo $r["PARAM_NAME"]."=".$r["PARAM_VALUE"]."\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_quote_probe4.php", "w") as f:
    f.write(php)
sftp.close()
run("php /tmp/wa_quote_probe4.php", timeout=30)
run("rm -f /tmp/wa_quote_probe4.php")
c.close()
