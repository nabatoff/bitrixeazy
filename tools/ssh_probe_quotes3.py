#!/usr/bin/env python3
import sys
import paramiko

PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
c.get_transport().set_keepalive(15)

def run(cmd, timeout=30):
    print("===", cmd[:180], "===")
    _, o, e = c.exec_command(cmd, timeout=timeout)
    o.channel.settimeout(timeout)
    try:
        out = o.read().decode("utf-8", "replace")
    except Exception as ex:
        out = f"<timeout {ex}>\n"
    print(out[:14000])
    try:
        err = e.read().decode("utf-8", "replace")
    except Exception:
        err = ""
    if err.strip():
        print("ERR", err[:500])

run("grep -n -i 'reply\\|quoted\\|CONNECTOR_MID' /home/bitrix/www/bitrix/modules/imconnector/lib/output.php | head -40")
run("grep -n -i 'reply\\|quoted' /home/bitrix/www/bitrix/modules/imconnector/lib/converter.php | head -30")
run("grep -n -i 'REPLY_ID\\|quotedMessage' /home/bitrix/www/bitrix/modules/im/lib/*.php /home/bitrix/www/bitrix/modules/im/lib/*/*.php 2>/dev/null | head -40")
run("ls /home/bitrix/www/bitrix/modules/imconnector/lib/connectors | head")
run("grep -n quotedMessageId /home/bitrix/www/local/custom_chat/app/green_api_instances.local.php; wc -l /home/bitrix/www/local/custom_chat/app/green_api_instances.local.php")

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
global $DB;
echo "param names sample:\n";
$res=$DB->Query("SELECT PARAM_NAME, COUNT(*) CNT FROM b_im_message_param GROUP BY PARAM_NAME ORDER BY CNT DESC LIMIT 40");
while($r=$res->Fetch()) echo $r["PARAM_NAME"]." ".$r["CNT"]."\n";

echo "\n=== REPLY_ID last 15 ===\n";
$res=$DB->Query("SELECT MESSAGE_ID, PARAM_VALUE FROM b_im_message_param WHERE PARAM_NAME='REPLY_ID' ORDER BY MESSAGE_ID DESC LIMIT 15");
$n=0;
while($r=$res->Fetch()){ $n++; echo $r["MESSAGE_ID"]." -> ".$r["PARAM_VALUE"]."\n"; }
echo "count=$n\n";

echo "\n=== one reply message dump ===\n";
$res=$DB->Query("SELECT MESSAGE_ID FROM b_im_message_param WHERE PARAM_NAME='REPLY_ID' ORDER BY MESSAGE_ID DESC LIMIT 1");
$row=$res->Fetch();
if($row){
  $mid=(int)$row["MESSAGE_ID"];
  $res=$DB->Query("SELECT ID,CHAT_ID,AUTHOR_ID,DATE_CREATE,LEFT(MESSAGE,200) MSG FROM b_im_message WHERE ID=".$mid);
  print_r($res->Fetch());
  $res=$DB->Query("SELECT PARAM_NAME, PARAM_VALUE FROM b_im_message_param WHERE MESSAGE_ID=".$mid);
  while($r=$res->Fetch()) echo $r["PARAM_NAME"]."=".$r["PARAM_VALUE"]."\n";
  $replyId=0;
  $res=$DB->Query("SELECT PARAM_VALUE FROM b_im_message_param WHERE MESSAGE_ID=".$mid." AND PARAM_NAME='REPLY_ID'");
  if($x=$res->Fetch()) $replyId=(int)$x["PARAM_VALUE"];
  if($replyId){
    echo "\n--- original $replyId ---\n";
    $res=$DB->Query("SELECT ID,AUTHOR_ID,LEFT(MESSAGE,200) MSG FROM b_im_message WHERE ID=".$replyId);
    print_r($res->Fetch());
    $res=$DB->Query("SELECT PARAM_NAME, PARAM_VALUE FROM b_im_message_param WHERE MESSAGE_ID=".$replyId);
    while($r=$res->Fetch()) echo $r["PARAM_NAME"]."=".$r["PARAM_VALUE"]."\n";
  }
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_quote_probe3.php", "w") as f:
    f.write(php)
sftp.close()
run("php /tmp/wa_quote_probe3.php", timeout=40)
run("rm -f /tmp/wa_quote_probe3.php")
c.close()
