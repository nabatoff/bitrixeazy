#!/usr/bin/env python3
import sys, paramiko, os
PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
sftp = c.open_sftp()
for rel, rem in [
    ("local/crm/kanban_deal_paint.js", "/home/bitrix/www/local/crm/kanban_deal_paint.js"),
    ("local/crm/kanban_deal_paint.css", "/home/bitrix/www/local/crm/kanban_deal_paint.css"),
    ("local/crm/include_kanban_deal_paint.php", "/home/bitrix/www/local/crm/include_kanban_deal_paint.php"),
]:
    sftp.put(os.path.join(ROOT, rel.replace("/", os.sep)), rem)
    print("up", rem, "size", sftp.stat(rem).st_size)
sftp.close()

# php -l without bitrix bootstrap
_, o, _ = c.exec_command("php -l /home/bitrix/www/local/crm/include_kanban_deal_paint.php; php -l /home/bitrix/www/local/crm/kanban_deal_paint_ajax.php; grep -n crm-kanban-item /home/bitrix/www/local/crm/kanban_deal_paint.js | head; grep -n OnProlog /home/bitrix/www/local/crm/include_kanban_deal_paint.php; grep -n addString /home/bitrix/www/local/crm/include_kanban_deal_paint.php; wc -c /home/bitrix/www/local/crm/kanban_deal_paint.js")
print(o.read().decode())

# bitrix CLI with NOT_CHECK and without auth redirect tricks
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
$_SERVER["HTTP_HOST"]="crm.artflowers.kz";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define("BX_NO_ACCELERATOR_RESET", true);
define("StopBuffering", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
require_once "/home/bitrix/www/local/crm/include_kanban_deal_paint.php";
class FakeApp {
  private $p; function __construct($p){$this->p=$p;}
  function GetCurPage($f=false){return $this->p;}
}
foreach (["/crm/deal/kanban/category/15/","/crm/deal/details/1/"] as $p) {
  $GLOBALS["APPLICATION"]=new FakeApp($p);
  $_SERVER["REQUEST_URI"]=$p;
  echo $p." => ".(waKanbanDealPaint_isDealKanbanPage()?"YES":"no")."\n";
}
\Bitrix\Main\Loader::includeModule("crm");
foreach ([215339,215400,215402,215393] as $id) {
  $r=CCrmDeal::GetListEx([],["ID"=>$id,"CHECK_PERMISSIONS"=>"N"],false,false,
    ["ID","STAGE_ID","UF_CRM_1764332847245","UF_CRM_1783486791226","UF_CRM_1764577842986","UF_CRM_1784524115744"])->Fetch();
  echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_kfix2.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_kfix2.php 2>&1 | head -40", timeout=40)
print("VERIFY:\n", o.read().decode("utf-8","replace")[:3000])
c.exec_command("rm -f /tmp/wa_kfix2.php")
c.close()
