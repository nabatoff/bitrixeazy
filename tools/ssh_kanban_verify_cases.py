#!/usr/bin/env python3
"""Re-upload JS fix + find deals covering all 3 paint cases on cat 15."""
import os, sys, paramiko

PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
sftp = c.open_sftp()
sftp.put(
    os.path.join(ROOT, "local", "crm", "kanban_deal_paint.js"),
    "/home/bitrix/www/local/crm/kanban_deal_paint.js",
)
print("js reuploaded")
sftp.close()

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true);
define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
$ufPrepay="UF_CRM_1764332847245"; $ufBought="UF_CRM_1783486791226";
$ufPaid="UF_CRM_1764577842986"; $ufIssued="UF_CRM_1784524115744";

$cases=[
  ["PREPARATION green", ["STAGE_ID"=>"C15:PREPARATION",$ufPrepay=>1], [$ufPrepay,$ufBought,$ufPaid,$ufIssued]],
  ["PREPAYMENT none", ["STAGE_ID"=>"C15:PREPAYMENT_INVOIC"], [$ufBought]],
  ["PREPAYMENT green", ["STAGE_ID"=>"C15:PREPAYMENT_INVOIC",$ufBought=>910], [$ufBought]],
  ["EXECUTING green", ["STAGE_ID"=>"C15:EXECUTING",$ufPaid=>1], [$ufPaid,$ufIssued]],
  ["EXECUTING blue", ["STAGE_ID"=>"C15:EXECUTING",$ufIssued=>912], [$ufPaid,$ufIssued]],
];
foreach($cases as [$title,$filter,$extra]){
  $filter["CATEGORY_ID"]=15; $filter["CHECK_PERMISSIONS"]="N";
  // for PREPAYMENT none we want bought empty - filter in PHP
  $select=array_merge(["ID","STAGE_ID","CATEGORY_ID"],[$ufPrepay,$ufBought,$ufPaid,$ufIssued]);
  $res=CCrmDeal::GetListEx(["ID"=>"DESC"],$filter,false,["nTopCount"=>30],$select);
  $found=null;
  while($row=$res->Fetch()){
    if($title==="PREPAYMENT none"){
      $b=$row[$ufBought]??null;
      if($b===null||$b===''||$b==='0'||$b===0){ $found=$row; break; }
      continue;
    }
    if($title==="EXECUTING green"){
      $iss=$row[$ufIssued]??null;
      if($iss!==null && $iss!=='' && (int)$iss){ continue; } // prefer paid without issued
      if(($row[$ufPaid]??null)==1||($row[$ufPaid]??null)==='1'){ $found=$row; break; }
      continue;
    }
    $found=$row; break;
  }
  if(!$found){ echo $title." NOT_FOUND\n"; continue; }
  echo $title." #".$found["ID"]
    ." stage=".$found["STAGE_ID"]
    ." prepay=".var_export($found[$ufPrepay]??null,true)
    ." bought=".var_export($found[$ufBought]??null,true)
    ." paid=".var_export($found[$ufPaid]??null,true)
    ." issued=".var_export($found[$ufIssued]??null,true)
    ."\n";
}

// simulate ajax payload build for one green prepayment deal
$id=215666;
$res=CCrmDeal::GetListEx([],["ID"=>$id,"CHECK_PERMISSIONS"=>"N"],false,false,["ID","STAGE_ID","CATEGORY_ID",$ufPrepay,$ufBought,$ufPaid,$ufIssued]);
$row=$res->Fetch();
echo "AJAX_SAMPLE ".json_encode($row, JSON_UNESCAPED_UNICODE)."\n";

// check include path match for common kanban URLs
$urls=[
  "/crm/deal/kanban/category/15/",
  "/crm/deal/kanban/",
  "/crm/deal/category/15/kanban/",
  "/crm/lead/kanban/",
];
foreach($urls as $uri){
  $path=parse_url($uri, PHP_URL_PATH);
  $ok=preg_match('#/crm/deal/kanban/?#i',$path)||preg_match('#/crm/deal/category/\d+/kanban/?#i',$path)||preg_match('#/crm/deal/kanban/category/\d+#i',$path);
  echo "URL ".$uri." => ".($ok?"INJECT":"skip")."\n";
}
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_kanban_cases.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_kanban_cases.php 2>&1", timeout=60)
print(o.read().decode("utf-8","replace"))
c.exec_command("rm -f /tmp/wa_kanban_cases.php")
c.close()
