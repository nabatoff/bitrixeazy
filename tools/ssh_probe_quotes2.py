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
    print(out[:12000])
    try:
        err = e.read().decode("utf-8", "replace")
    except Exception:
        err = ""
    if err.strip():
        print("ERR", err[:500])

run("ls /home/bitrix/www/bitrix/modules | grep -i fos")
run("ls /home/bitrix/www/local | grep -i fos; find /home/bitrix/www -maxdepth 4 -iname '*fos*' -o -iname '*green_api*' 2>/dev/null | head -40")
run("ls /home/bitrix/www/bitrix/modules/imconnector/lib | head -50")
run("ls /home/bitrix/www/bitrix/modules/imconnector/handlers 2>/dev/null | head")

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
global $DB;
echo "cols b_im_message:\n";
$res=$DB->Query("SHOW COLUMNS FROM b_im_message");
while($r=$res->Fetch()) echo $r["Field"]."\n";
echo "\nexists b_im_message_param=".( $DB->TableExists("b_im_message_param") ? "Y":"N")."\n";
if($DB->TableExists("b_im_message_param")){
  echo "=== REPLY_ID params ===\n";
  $res=$DB->Query("SELECT MESSAGE_ID, PARAM_NAME, PARAM_VALUE FROM b_im_message_param WHERE PARAM_NAME IN ('REPLY_ID','CONNECTOR_MID','CLASS','CONNECTOR') ORDER BY MESSAGE_ID DESC LIMIT 30");
  while($r=$res->Fetch()) echo $r["MESSAGE_ID"]." ".$r["PARAM_NAME"]."=".$r["PARAM_VALUE"]."\n";
}
echo "\n=== module paths ===\n";
foreach(["fos.green.api.kz","fosgreenapikz","greenapi"] as $m){
  echo $m." loaded=".(\Bitrix\Main\Loader::includeModule($m)?"Y":"N")."\n";
}
$res=$DB->Query("SELECT ID, MODULE_ID FROM b_module WHERE MODULE_ID LIKE '%green%' OR MODULE_ID LIKE '%fos%'");
while($r=$res->Fetch()) print_r($r);
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_quote_probe2.php", "w") as f:
    f.write(php)
sftp.close()
run("php /tmp/wa_quote_probe2.php", timeout=35)
run("rm -f /tmp/wa_quote_probe2.php")
c.close()
