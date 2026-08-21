#!/usr/bin/env python3
import sys, paramiko, os
PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
FILES = [
    ("local/crm/kanban_deal_paint.js", "/home/bitrix/www/local/crm/kanban_deal_paint.js"),
    ("local/crm/kanban_deal_paint.css", "/home/bitrix/www/local/crm/kanban_deal_paint.css"),
    ("local/crm/include_kanban_deal_paint.php", "/home/bitrix/www/local/crm/include_kanban_deal_paint.php"),
]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

# check Asset LOCATION constants
_, o, _ = c.exec_command(r'''php -r '
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
$r=new ReflectionClass("Bitrix\\Main\\Page\\Asset");
foreach($r->getConstants() as $k=>$v) if(stripos($k,"LOC")!==false||stripos($k,"AFTER")!==false) echo "$k=$v\n";
if(class_exists("Bitrix\\Main\\Page\\AssetLocation")){
  $r2=new ReflectionClass("Bitrix\\Main\\Page\\AssetLocation");
  foreach($r2->getConstants() as $k=>$v) echo "AssetLocation::$k=$v\n";
}
' ''')
print("CONSTS:\n", o.read().decode("utf-8","replace")[:2000])

sftp = c.open_sftp()
for local_rel, remote in FILES:
    sftp.put(os.path.join(ROOT, local_rel.replace("/", os.sep)), remote)
    print("up", remote)
sftp.close()

# lint + simulate inject detection
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
echo shell_exec("php -l /home/bitrix/www/local/crm/include_kanban_deal_paint.php");
require_once "/home/bitrix/www/local/crm/include_kanban_deal_paint.php";
echo function_exists("waKanbanDealPaint_isDealKanbanPage") ? "fn_ok\n" : "fn_miss\n";

class FakeApp {
  private $p;
  function __construct($p){$this->p=$p;}
  function GetCurPage($f=false){return $this->p;}
}
$tests = [
  "/crm/deal/kanban/category/15/",
  "/crm/deal/kanban/",
  "/crm/deal/details/1/",
  "/crm/lead/kanban/",
];
foreach ($tests as $p) {
  $GLOBALS["APPLICATION"] = new FakeApp($p);
  $_SERVER["REQUEST_URI"] = $p;
  echo $p." => ".(waKanbanDealPaint_isDealKanbanPage()?"YES":"no")."\n";
}

// deals from screenshot that MUST paint
$ids = [215339, 215400, 215402, 215393, 215666];
\Bitrix\Main\Loader::includeModule("crm");
$uf=["UF_CRM_1764332847245","UF_CRM_1783486791226","UF_CRM_1764577842986","UF_CRM_1784524115744"];
$res=CCrmDeal::GetListEx([],["@ID"=>$ids,"CHECK_PERMISSIONS"=>"N"],false,false,array_merge(["ID","TITLE","STAGE_ID","CATEGORY_ID"],$uf));
while($r=$res->Fetch()){
  $suf=substr(strrchr($r["STAGE_ID"],":")?:(":".$r["STAGE_ID"]),1);
  $color="none";
  if($suf==="PREPARATION" && ($r[$uf[0]]==1||$r[$uf[0]]==="1")) $color="green";
  if($suf==="PREPAYMENT_INVOIC" && in_array((int)$r[$uf[1]],[910,911],true)) $color="green";
  if($suf==="EXECUTING"){
    if(in_array((int)$r[$uf[3]],[912,913],true)) $color="blue";
    elseif($r[$uf[2]]==1||$r[$uf[2]]==="1") $color="green";
  }
  echo "#".$r["ID"]." ".$color." stage=".$r["STAGE_ID"]." ".mb_substr($r["TITLE"],0,50)."\n";
}
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_kanban_fix_verify.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_kanban_fix_verify.php 2>&1", timeout=60)
print(o.read().decode("utf-8","replace"))

# fix LOCATION if needed - check php lint of include after potential fix
c.exec_command("rm -rf /home/bitrix/www/bitrix/cache/js /home/bitrix/www/bitrix/cache/css 2>/dev/null")
c.close()
print("DONE")
