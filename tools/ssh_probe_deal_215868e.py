#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
ROOT=os.path.dirname(__file__)
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
php=r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
\Bitrix\Main\Loader::includeModule("bizproc");
$conn=\Bitrix\Main\Application::getConnection();

function dumpTpl($conn, $id){
  $raw=$conn->query("SELECT ID,NAME,TEMPLATE FROM b_bp_workflow_template WHERE ID=".(int)$id)->fetch();
  if(!$raw){ echo "NO TPL $id\n"; return; }
  $t=$raw["TEMPLATE"];
  if(is_string($t)){
    $u=@unserialize($t);
    if($u===false){
      $z=@gzuncompress($t);
      if($z!==false) $u=@unserialize($z);
    }
    $t=$u!==false?$u:$t;
  }
  echo "\n==== TPL {$raw["ID"]} {$raw["NAME"]} ====\n";
  echo substr(print_r($t,true),0,2500)."\n";
}
dumpTpl($conn,294);
dumpTpl($conn,307);
dumpTpl($conn,361);

echo "\n==== USER 69 ====\n";
$u=CUser::GetByID(69)->Fetch();
echo "name=".$u["NAME"]." ".$u["LAST_NAME"]." admin=".$u["ADMIN"]." active=".$u["ACTIVE"]."\n";
$groups=[];
$rs=CUser::GetUserGroupList(69);
while($g=$rs->Fetch()) $groups[]=$g["GROUP_ID"];
echo "groups=".implode(",",$groups)."\n";
if(class_exists("CIntranetUtils") || true){
  $dept=[];
  $rs=\CUser::GetList(($by="id"),($order="asc"),["ID"=>69],["SELECT"=>["UF_DEPARTMENT"]]);
  if($row=$rs->Fetch()) echo "dept=".json_encode($row["UF_DEPARTMENT"]??null)."\n";
}

echo "\n==== DSG FILES ====\n";
foreach(glob("/home/bitrix/www/local/crm/*.php") as $f) echo basename($f)."\n";
foreach(glob("/home/bitrix/www/local/php_interface/*.php") as $f) echo basename($f)."\n";
$init=file_get_contents("/home/bitrix/www/bitrix/php_interface/init.php");
echo "\nINIT_HAS_DSG=".(strpos($init,"DealStageGuard")!==false?"Y":"N")." autotake=".(strpos($init,"include_deal_auto_take")!==false?"Y":"N")." hist=".(strpos($init,"include_deal_uf_history")!==false?"Y":"N")."\n";

echo "\n==== GREP PREPAY SET ON SERVER ====\n";
$cmd='grep -l "1764332847245" /home/bitrix/www/local -r --include="*.php" 2>/dev/null | head';
passthru($cmd);

echo "\n==== TRACKING TYPE1 TITLES ====\n";
$sql="SELECT ACTION_TITLE, ACTION_NAME, COUNT(*) C FROM b_bp_tracking WHERE WORKFLOW_ID IN (SELECT ID FROM b_bp_workflow_state WHERE DOCUMENT_ID='DEAL_215868') AND TYPE=1 GROUP BY ACTION_TITLE, ACTION_NAME ORDER BY C DESC LIMIT 40";
foreach($conn->query($sql) as $r){
  echo $r["C"]." ".$r["ACTION_NAME"]." | ".$r["ACTION_TITLE"]."\n";
}

echo "\n==== WORKFLOW 294 TRACKING ====\n";
$sql="SELECT t.ID, t.TYPE, t.ACTION_NAME, t.ACTION_TITLE, LEFT(t.ACTION_NOTE,200) N FROM b_bp_tracking t INNER JOIN b_bp_workflow_state s ON s.ID=t.WORKFLOW_ID WHERE s.DOCUMENT_ID='DEAL_215868' AND s.WORKFLOW_TEMPLATE_ID=294 ORDER BY t.ID";
try {
  foreach($conn->query($sql) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n==== WORKFLOW 361 TRACKING ====\n";
$sql="SELECT t.ID, t.TYPE, t.ACTION_NAME, t.ACTION_TITLE, LEFT(t.ACTION_NOTE,200) N FROM b_bp_tracking t INNER JOIN b_bp_workflow_state s ON s.ID=t.WORKFLOW_ID WHERE s.DOCUMENT_ID='DEAL_215868' AND s.WORKFLOW_TEMPLATE_ID=361 ORDER BY t.ID LIMIT 30";
try {
  $n=0;
  foreach($conn->query($sql) as $r){ echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n"; $n++; }
  echo "n=$n\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n==== UTS ROW ====\n";
$row=$conn->query("SELECT VALUE_ID, UF_CRM_1764332847245, UF_CRM_1785326361467, UF_CRM_1785324070, UF_CRM_1784636341021, UF_CRM_1786008089, UF_CRM_1783486791226, UF_CRM_1783485774093, UF_CRM_1785325552 FROM b_uts_crm_deal WHERE VALUE_ID=215868")->fetch();
echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";

echo "\n==== DEAL CORE ====\n";
$d=$conn->query("SELECT ID, TITLE, STAGE_ID, CATEGORY_ID, DATE_CREATE, DATE_MODIFY, CREATED_BY, MODIFY_BY_ID FROM b_crm_deal WHERE ID=215868")->fetch();
echo json_encode($d, JSON_UNESCAPED_UNICODE)."\n";
'''
sftp=c.open_sftp();
with sftp.file("/tmp/wa_deal_215868e.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_deal_215868e.php 2>&1")
out=o.read().decode("utf-8","replace")
open(os.path.join(ROOT,"_deal_215868e.txt"),"w",encoding="utf-8").write(out)
print(out[:14000])
c.exec_command("rm -f /tmp/wa_deal_215868e.php")
c.close()
