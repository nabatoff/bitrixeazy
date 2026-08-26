#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD = sys.argv[1]
local = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "local", "custom_chat", "include_portal_widget.php"))
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
sftp = c.open_sftp()
sftp.put(local, "/home/bitrix/www/local/custom_chat/include_portal_widget.php")
print("ok", sftp.stat("/home/bitrix/www/local/custom_chat/include_portal_widget.php").st_size)
sftp.close()
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
require_once "/home/bitrix/www/local/custom_chat/include_portal_widget.php";
waCcEnsureMenuCounterId();
$raw = COption::GetOptionString("intranet", "left_menu_items_marketplace_s1");
$arr = unserialize($raw, ["allowed_classes"=>false]);
foreach ($arr as $it) {
  if (strpos((string)($it["LINK"]??""), "/marketplace/app/64")!==false || ($it["TEXT"]??"")==="Ватсап чат") {
    echo json_encode($it, JSON_UNESCAPED_UNICODE)."\n";
  }
}
echo "SITE_ID=".(defined("SITE_ID")?SITE_ID:"no")."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_cnt.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php -l /home/bitrix/www/local/custom_chat/include_portal_widget.php; php /tmp/wa_cnt.php 2>&1; rm -f /tmp/wa_cnt.php", timeout=40)
print(o.read().decode("utf-8","replace"))
print(e.read().decode("utf-8","replace")[:1000])
c.close()
