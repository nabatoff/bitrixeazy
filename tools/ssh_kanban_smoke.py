#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

# ensure latest local js has marker
_, o, _ = c.exec_command("grep -n waKanbanPaintLoaded /home/bitrix/www/local/crm/kanban_deal_paint.js; tail -n 20 /home/bitrix/www/bitrix/php_interface/init.php; curl -sI https://crm.artflowers.kz/local/crm/kanban_deal_paint.js | head -15")
print(o.read().decode("utf-8","replace"))

php = r'''<?php
error_reporting(E_ALL);
ini_set("display_errors","1");
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true);
define("NOT_CHECK_PERMISSIONS",true);
define("BX_BUFFER_USED", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
echo "BOOT\n";
require_once "/home/bitrix/www/local/crm/include_kanban_deal_paint.php";
echo "INC\n";
$_SERVER["REQUEST_URI"]="/crm/deal/kanban/category/15/";
class A { function GetCurPage($x=false){ return "/crm/deal/kanban/category/15/"; } }
$GLOBALS["APPLICATION"]=new A();
echo "PAGE=". (waKanbanDealPaint_isDealKanbanPage()?"Y":"N")."\n";
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wk.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wk.php 2>&1", timeout=30)
print("PHP:\n", o.read().decode("utf-8","replace")[:2000])

# reupload js once more to be sure
import os
ROOT=os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
sftp=c.open_sftp()
sftp.put(os.path.join(ROOT,"local","crm","kanban_deal_paint.js"), "/home/bitrix/www/local/crm/kanban_deal_paint.js")
sftp.put(os.path.join(ROOT,"local","crm","include_kanban_deal_paint.php"), "/home/bitrix/www/local/crm/include_kanban_deal_paint.php")
sftp.put(os.path.join(ROOT,"local","crm","kanban_deal_paint.css"), "/home/bitrix/www/local/crm/kanban_deal_paint.css")
sftp.close()
print("reuploaded")
c.close()
