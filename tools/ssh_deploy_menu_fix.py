#!/usr/bin/env python3
import os, sys, time, paramiko
PASSWORD = sys.argv[1]
BASE_LOCAL = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "local", "custom_chat"))
BASE_REMOTE = "/home/bitrix/www/local/custom_chat"
FILES = [
    "include_portal_widget.php",
    "include_crm_button.php",
    "portal_widget.js",
    "portal_widget.css",
    "portal_unread.php",
    "img/wa-menu.svg",
]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
sftp = c.open_sftp()
for rel in FILES:
    local = os.path.join(BASE_LOCAL, rel.replace("/", os.sep))
    remote = BASE_REMOTE + "/" + rel
    sftp.put(local, remote)
    print("ok", rel, sftp.stat(remote).st_size)
sftp.close()

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
require_once "/home/bitrix/www/local/custom_chat/include_portal_widget.php";
echo "fn_prolog=". (function_exists("waCcOnPrologPortalWidget")?"1":"0") ."\n";
echo "fn_epilog=". (function_exists("waCcOnEpilogPortalWidget")?"1":"0") ."\n";
echo "fn_buf=". (function_exists("waCcOnEndBufferPortalWidget")?"1":"0") ."\n";
echo "reg=". (defined("WA_CC_PORTAL_WIDGET_REG")?"1":"0") ."\n";
waCcEnsureMenuCounterId();
$raw = COption::GetOptionString("intranet", "left_menu_items_marketplace_s1");
$arr = unserialize($raw, ["allowed_classes"=>false]);
foreach ($arr as $it) {
  if (($it["LINK"]??"")==="/marketplace/app/64/" || ($it["TEXT"]??"")==="Ватсап чат") {
    echo json_encode($it, JSON_UNESCAPED_UNICODE)."\n";
  }
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_fix_menu.php", "w") as f:
    f.write(php)
sftp.close()
cmd = (
    "php -l /home/bitrix/www/local/custom_chat/include_portal_widget.php; "
    "php -l /home/bitrix/www/local/custom_chat/portal_unread.php; "
    "php /tmp/wa_fix_menu.php 2>&1; rm -f /tmp/wa_fix_menu.php"
)
_, o, e = c.exec_command(cmd, timeout=40)
print(o.read().decode("utf-8", "replace"))
err = e.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR", err[:1500])
c.close()
