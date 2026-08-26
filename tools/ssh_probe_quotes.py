#!/usr/bin/env python3
import sys
import paramiko

PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
c.get_transport().set_keepalive(15)

def run(cmd, timeout=25):
    print("===", cmd[:160], "===")
    _, o, e = c.exec_command(cmd, timeout=timeout)
    o.channel.settimeout(timeout)
    try:
        out = o.read().decode("utf-8", "replace")
    except Exception as ex:
        out = f"<timeout {ex}>\n"
    print(out[:8000])
    try:
        err = e.read().decode("utf-8", "replace")
    except Exception:
        err = ""
    if err.strip():
        print("ERR", err[:400])

run("ls /home/bitrix/www/bitrix/modules | grep -Ei 'green|connector|imopenlines|rest' | head")
run("ls /home/bitrix/www/bitrix/modules | head -80")
run("find /home/bitrix/www/bitrix/modules -maxdepth 2 -iname '*green*' -o -iname '*whatsapp*' -o -iname '*imconnector*' 2>/dev/null | head")
run("ls /home/bitrix/www/bitrix/modules/imconnector/lib/inc 2>/dev/null | head")
run("ls /home/bitrix/www/bitrix/modules/imconnector 2>/dev/null | head")
run("grep -l quotedMessageId /home/bitrix/www/bitrix/modules/imconnector/lib/*.php 2>/dev/null | head")
run("grep -n REPLY_ID /home/bitrix/www/bitrix/modules/imopenlines/lib/*.php 2>/dev/null | head")
run("ls /home/bitrix/ext_www 2>/dev/null; ls /home/bitrix/www/rest 2>/dev/null | head")

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
global $DB;
echo "=== rest apps ===\n";
$res=$DB->Query("SELECT ID,CODE,APP_NAME,URL FROM b_rest_app WHERE ACTIVE='Y' LIMIT 40");
while($r=$res->Fetch()){
  $blob=strtolower(($r["CODE"]??"")." ".($r["APP_NAME"]??"")." ".($r["URL"]??""));
  if(strpos($blob,"green")!==false||strpos($blob,"whats")!==false||strpos($blob,"1os")!==false||strpos($blob,"fos")!==false||strpos($blob,"wa")!==false)
    echo $r["ID"]." | ".$r["CODE"]." | ".$r["APP_NAME"]." | ".$r["URL"]."\n";
}
echo "\n=== connectors ===\n";
if($DB->TableExists("b_imconnectors_status")){
  $res=$DB->Query("SELECT * FROM b_imconnectors_status LIMIT 20");
  while($r=$res->Fetch()) print_r($r);
}
echo "\n=== last REPLY_ID msgs ===\n";
$res=$DB->Query("SELECT ID,CHAT_ID,AUTHOR_ID,DATE_CREATE,PARAMS,LEFT(MESSAGE,160) MSG FROM b_im_message WHERE PARAMS LIKE '%REPLY_ID%' ORDER BY ID DESC LIMIT 5");
while($r=$res->Fetch()){ echo $r["ID"]." chat=".$r["CHAT_ID"]." auth=".$r["AUTHOR_ID"]." ".$r["DATE_CREATE"]."\nPARAMS=".$r["PARAMS"]."\nMSG=".$r["MSG"]."\n---\n"; }
echo "\n=== last connector-ish params ===\n";
$res=$DB->Query("SELECT ID,CHAT_ID,AUTHOR_ID,LEFT(PARAMS,500) P, LEFT(MESSAGE,80) MSG FROM b_im_message WHERE PARAMS LIKE '%CLASS%' ORDER BY ID DESC LIMIT 3");
while($r=$res->Fetch()) echo $r["ID"]." ".$r["P"]."\nMSG=".$r["MSG"]."\n---\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_quote_probe.php", "w") as f:
    f.write(php)
sftp.close()
run("php /tmp/wa_quote_probe.php", timeout=35)
run("rm -f /tmp/wa_quote_probe.php")
c.close()
