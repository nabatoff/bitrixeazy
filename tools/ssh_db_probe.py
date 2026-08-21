#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import paramiko
import sys

PASSWORD = sys.argv[1]
OUT = r"d:\project\bitrixeazy\tools\_audit_out.txt"
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
global $DB;
header("Content-Type: text/plain; charset=utf-8");

echo "=== init.php includes ===\n";
$initPaths = [
  "/home/bitrix/www/bitrix/php_interface/init.php",
  "/home/bitrix/www/local/php_interface/init.php",
];
foreach ($initPaths as $p) {
  echo $p." exists=". (is_file($p)?"Y":"N")."\n";
  if (!is_file($p)) continue;
  $raw = file_get_contents($p);
  echo "  ol_line_leads=".(strpos($raw,"include_ol_line_leads")!==false?"Y":"N")."\n";
  echo "  crm_button=".(strpos($raw,"include_crm_button")!==false?"Y":"N")."\n";
}

echo "\n=== ol files ===\n";
foreach ([
  "/home/bitrix/www/local/custom_chat/include_ol_line_leads.php",
  "/home/bitrix/www/local/custom_chat/ol_line_leads_run.php",
  "/home/bitrix/www/local/custom_chat/include_crm_button.php",
] as $p) {
  echo basename($p)." ".(is_file($p)?"Y ".filesize($p)." ".date("Y-m-d H:i", filemtime($p)):"N")."\n";
}

echo "\n=== agent ===\n";
$res = $DB->Query("SELECT ID, NAME, ACTIVE, NEXT_EXEC, LAST_EXEC, AGENT_INTERVAL FROM b_agent WHERE NAME LIKE '%olLineLeads%' LIMIT 10");
$n=0; while($r=$res->Fetch()){ $n++; echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";} if(!$n) echo "NO AGENT\n";

echo "\n=== multi-chat leads sample ===\n";
$q = "SELECT OWNER_ID, COUNT(DISTINCT ASSOCIATED_ENTITY_ID) CNT
FROM b_crm_act
WHERE OWNER_TYPE_ID=1 AND PROVIDER_ID='IMOPENLINES_SESSION' AND ASSOCIATED_ENTITY_ID>0
GROUP BY OWNER_ID HAVING CNT>=2
ORDER BY CNT DESC LIMIT 6";
$res = $DB->Query($q);
while ($r = $res->Fetch()) {
  $leadId = (int)$r["OWNER_ID"];
  echo "LEAD {$leadId} chats={$r["CNT"]}\n";
  $q2 = "SELECT a.ID AID, a.ASSOCIATED_ENTITY_ID CID, LEFT(a.SUBJECT,50) SUBJ, c.ENTITY_ID
    FROM b_crm_act a
    LEFT JOIN b_im_chat c ON c.ID=a.ASSOCIATED_ENTITY_ID
    WHERE a.OWNER_TYPE_ID=1 AND a.OWNER_ID={$leadId} AND a.PROVIDER_ID='IMOPENLINES_SESSION'
    ORDER BY a.ID DESC LIMIT 5";
  $res2 = $DB->Query($q2);
  $lines = [];
  while ($x = $res2->Fetch()) {
    $eid = (string)$x["ENTITY_ID"];
    $parts = explode("|", $eid);
    $line = isset($parts[1]) ? $parts[1] : "?";
    $lines[$line] = true;
    echo "  chat={$x["CID"]} line={$line} eid=".substr($eid,0,70)."\n";
  }
  echo "  distinct_lines=".count($lines)." keys=".implode(",", array_keys($lines))."\n";
}

echo "\n=== recent green-api chats entity_data_2 vs line ===\n";
$q = "SELECT ID, LEFT(TITLE,40) TITLE, ENTITY_ID, ENTITY_DATA_2 FROM b_im_chat
 WHERE TYPE='L' AND ENTITY_ID LIKE 'fos_green_api_kz|%'
 ORDER BY ID DESC LIMIT 8";
$res=$DB->Query($q);
while($r=$res->Fetch()){
  $parts=explode("|",(string)$r["ENTITY_ID"]);
  $line=$parts[1]??"?";
  echo "chat={$r["ID"]} line={$line} ed2={$r["ENTITY_DATA_2"]}\n";
}

echo "\n=== openCrm flags in index ===\n";
$idx = file_get_contents("/home/bitrix/www/local/custom_chat/index.php");
foreach (["openCrmEntity","openCrmViaMobileBridge","Application.openCrmEntity","/mobile/crm/","btnLead","resolveCrmLeadForChat","BX24.openPath"] as $n) {
  echo $n."=".(strpos($idx,$n)!==false?"Y":"N")."\n";
}

echo "\n=== bitrix mobile crm paths exist? ===\n";
foreach ([
  "/home/bitrix/www/mobile/crm/lead/index.php",
  "/home/bitrix/www/mobile/crm/deal/index.php",
  "/home/bitrix/www/mobile/crm/type",
] as $p) {
  echo $p." ".(file_exists($p)?"Y":"N")."\n";
}
'''

sftp = c.open_sftp()
with sftp.file("/tmp/wa_audit.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_audit.php 2>&1", timeout=60)
raw = o.read()
with open(OUT, "wb") as f:
    f.write(raw)
print("wrote", OUT, "bytes", len(raw))
print(e.read().decode("utf-8", errors="replace")[:300])
c.exec_command("rm -f /tmp/wa_audit.php")
c.close()
