#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import paramiko, sys
PASSWORD = sys.argv[1]
OUT = r"d:\project\bitrixeazy\tools\_audit_out2.txt"
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true);
define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
global $DB;

echo "=== recent fos chats: lead + line ===\n";
$q = "SELECT ID, ENTITY_ID, ENTITY_DATA_2 FROM b_im_chat
 WHERE TYPE='L' AND ENTITY_ID LIKE 'fos_green_api_kz|%'
 ORDER BY ID DESC LIMIT 40";
$res=$DB->Query($q);
$byLead = [];
while($r=$res->Fetch()){
  $eid=(string)$r["ENTITY_ID"];
  $ed2=(string)$r["ENTITY_DATA_2"];
  $ep=explode("|",$eid);
  $line=$ep[1]??"0";
  $lead=0;
  if(preg_match('/LEAD\\|(\\d+)/',$ed2,$m)) $lead=(int)$m[1];
  if($lead<=0) continue;
  if(!isset($byLead[$lead])) $byLead[$lead]=[];
  $byLead[$lead][$line]=($byLead[$lead][$line]??0)+1;
}
$shared=0; $ok=0;
foreach($byLead as $lead=>$lines){
  if(count($lines)<2){ $ok++; continue; }
  $shared++;
  if($shared<=12){
    $parts=[];
    foreach($lines as $l=>$c) $parts[]="$l:$c";
    echo "SHARED LEAD=$lead lines=".implode(",", $parts)."\n";
  }
}
echo "leads_in_sample_with_1_line=$ok shared_multi_line=$shared\n";

echo "\n=== phone across lines (last 200 fos personal chats) ===\n";
$q = "SELECT ID, ENTITY_ID, ENTITY_DATA_2 FROM b_im_chat
 WHERE TYPE='L' AND ENTITY_ID LIKE 'fos_green_api_kz|%@c.us|%'
 ORDER BY ID DESC LIMIT 200";
$res=$DB->Query($q);
$byPhone=[];
while($r=$res->Fetch()){
  $eid=(string)$r["ENTITY_ID"];
  $ed2=(string)$r["ENTITY_DATA_2"];
  $ep=explode("|",$eid);
  $line=$ep[1]??"0";
  $phone=$ep[2]??"";
  $lead=0;
  if(preg_match('/LEAD\\|(\\d+)/',$ed2,$m)) $lead=(int)$m[1];
  if($phone===""||$lead<=0) continue;
  if(!isset($byPhone[$phone])) $byPhone[$phone]=[];
  $byPhone[$phone][]=["line"=>$line,"lead"=>$lead,"chat"=>(int)$r["ID"]];
}
$bad=0;$good=0;$checked=0;
foreach($byPhone as $phone=>$rows){
  $lines=[]; $leads=[];
  foreach($rows as $row){ $lines[$row["line"]]=1; $leads[$row["lead"]]=1; }
  if(count($lines)<2) continue;
  $checked++;
  if(count($leads)>=count($lines)){ $good++; }
  else {
    $bad++;
    if($bad<=8){
      echo "BAD phone=".substr($phone,0,22)." lines=".implode(",",array_keys($lines))." leads=".implode(",",array_keys($leads))."\n";
    }
  }
}
echo "phones_on_multi_lines=$checked good_split=$good bad_merge=$bad\n";

echo "\n=== agent last run ok ===\n";
$res=$DB->Query("SELECT ID,ACTIVE,LAST_EXEC,NEXT_EXEC FROM b_agent WHERE NAME='olLineLeadsAgent();' LIMIT 1");
print_r($res->Fetch());

echo "\n=== mobile path files ===\n";
foreach(["/mobile/crm/lead/index.php","/mobile/crm/deal/index.php","/bitrix/components/bitrix/mobile.crm.lead.edit"] as $rel){
  $p="/home/bitrix/www".$rel;
  echo $rel." ".(file_exists($p)?"Y":"N")."\n";
}
'''

sftp=c.open_sftp()
with sftp.file("/tmp/wa_audit2.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_audit2.php 2>&1", timeout=90)
raw=o.read()
open(OUT,"wb").write(raw)
sys.stdout.buffer.write(raw)
c.exec_command("rm -f /tmp/wa_audit2.php")
c.close()
