#!/usr/bin/env python3
"""Deploy kanban paint + patch init.php + verify with sample deals."""
import os
import sys
import paramiko

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))

FILES = [
    ("local/crm/kanban_deal_paint.js", "/home/bitrix/www/local/crm/kanban_deal_paint.js"),
    ("local/crm/kanban_deal_paint.css", "/home/bitrix/www/local/crm/kanban_deal_paint.css"),
    ("local/crm/kanban_deal_paint_ajax.php", "/home/bitrix/www/local/crm/kanban_deal_paint_ajax.php"),
    ("local/crm/include_kanban_deal_paint.php", "/home/bitrix/www/local/crm/include_kanban_deal_paint.php"),
]

INIT_SNIPPET = """
$waKanbanPaint = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_kanban_deal_paint.php';
if (is_file($waKanbanPaint)) {
    require_once $waKanbanPaint;
}
"""

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=20)
sftp = c.open_sftp()

# ensure remote dir
try:
    sftp.stat("/home/bitrix/www/local/crm")
except IOError:
    sftp.mkdir("/home/bitrix/www/local/crm")

for local_rel, remote in FILES:
    local = os.path.join(ROOT, local_rel.replace("/", os.sep))
    sftp.put(local, remote)
    print("uploaded", remote)

# patch init.php
init_path = "/home/bitrix/www/bitrix/php_interface/init.php"
with sftp.file(init_path, "r") as f:
    init = f.read().decode("utf-8", "replace")

if "include_kanban_deal_paint.php" not in init:
    if not init.endswith("\n"):
        init += "\n"
    init += "\n" + INIT_SNIPPET.strip() + "\n"
    with sftp.file(init_path, "w") as f:
        f.write(init)
    print("init.php patched")
else:
    print("init.php already has kanban paint include")

sftp.close()

# verify files + php syntax + sample resolve colors via CLI
verify = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");

$files = [
  "/home/bitrix/www/local/crm/kanban_deal_paint.js",
  "/home/bitrix/www/local/crm/kanban_deal_paint.css",
  "/home/bitrix/www/local/crm/kanban_deal_paint_ajax.php",
  "/home/bitrix/www/local/crm/include_kanban_deal_paint.php",
];
foreach ($files as $f) {
  echo (is_file($f) ? "OK " : "MISS ") . $f . "\n";
}
$init = file_get_contents("/home/bitrix/www/bitrix/php_interface/init.php");
echo (strpos($init, "include_kanban_deal_paint.php") !== false ? "INIT_OK\n" : "INIT_MISS\n");

$ufPrepay = "UF_CRM_1764332847245";
$ufBought = "UF_CRM_1783486791226";
$ufPaid = "UF_CRM_1764577842986";
$ufIssued = "UF_CRM_1784524115744";
$boughtOk = [910=>1,911=>1];
$issuedOk = [912=>1,913=>1];

function stageSuffix($s){ $i=strrpos($s,":"); return $i===false?$s:substr($s,$i+1); }
function catFrom($s){ return preg_match('/^C(\d+):/i',$s,$m)?(int)$m[1]:0; }
function truthy($v){ return $v===1||$v==="1"||$v===true||$v==="Y"; }
function enumId($v){ if(is_array($v)&&isset($v["ID"])) return (int)$v["ID"]; return (int)$v; }
function color($stage,$row,$boughtOk,$issuedOk,$ufPrepay,$ufBought,$ufPaid,$ufIssued){
  $cat=catFrom($stage); if($cat<15||$cat>20) return "skip";
  $suf=stageSuffix($stage);
  if($suf==="PREPARATION") return truthy($row[$ufPrepay]??null)?"green":"none";
  if($suf==="PREPAYMENT_INVOIC"){ $e=enumId($row[$ufBought]??null); return isset($boughtOk[$e])?"green":"none"; }
  if($suf==="EXECUTING"){
    $e=enumId($row[$ufIssued]??null);
    if(isset($issuedOk[$e])) return "blue";
    if(truthy($row[$ufPaid]??null)) return "green";
    return "none";
  }
  return "other";
}

$stages = [
  "PREPARATION" => "C15:PREPARATION",
  "PREPAYMENT_INVOIC" => "C15:PREPAYMENT_INVOIC",
  "EXECUTING" => "C15:EXECUTING",
];
foreach ($stages as $label => $stageId) {
  $res = CCrmDeal::GetListEx(["ID"=>"DESC"], ["STAGE_ID"=>$stageId,"CATEGORY_ID"=>15,"CHECK_PERMISSIONS"=>"N"], false, ["nTopCount"=>8],
    ["ID","STAGE_ID","CATEGORY_ID",$ufPrepay,$ufBought,$ufPaid,$ufIssued]);
  $n=0;
  while($row=$res->Fetch()){
    $n++;
    $c=color($row["STAGE_ID"],$row,$boughtOk,$issuedOk,$ufPrepay,$ufBought,$ufPaid,$ufIssued);
    echo $label." #".$row["ID"]." color=".$c
      ." prepay=".var_export($row[$ufPrepay]??null,true)
      ." bought=".var_export($row[$ufBought]??null,true)
      ." paid=".var_export($row[$ufPaid]??null,true)
      ." issued=".var_export($row[$ufIssued]??null,true)
      ."\n";
  }
  if(!$n) echo $label." NONE_FOUND\n";
}

// lint ajax
$lint = shell_exec("php -l /home/bitrix/www/local/crm/kanban_deal_paint_ajax.php 2>&1");
echo "LINT_AJAX ".$lint;
$lint2 = shell_exec("php -l /home/bitrix/www/local/crm/include_kanban_deal_paint.php 2>&1");
echo "LINT_INC ".$lint2;
'''

sftp = c.open_sftp()
with sftp.file("/tmp/wa_kanban_paint_verify.php", "w") as f:
    f.write(verify)
sftp.close()

_, stdout, stderr = c.exec_command("php /tmp/wa_kanban_paint_verify.php 2>&1", timeout=60)
print(stdout.read().decode("utf-8", "replace"))
print(stderr.read().decode("utf-8", "replace"))

# clear bitrix js/css cache lightly
c.exec_command("rm -rf /home/bitrix/www/bitrix/cache/js 2>/dev/null; rm -rf /home/bitrix/www/bitrix/cache/css 2>/dev/null; echo cleared")
_, o, _ = c.exec_command("tail -n 12 /home/bitrix/www/bitrix/php_interface/init.php")
print("INIT_TAIL:\n", o.read().decode("utf-8", "replace"))

c.exec_command("rm -f /tmp/wa_kanban_paint_verify.php")
c.close()
print("DONE")
