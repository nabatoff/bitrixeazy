#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import paramiko, sys
PASSWORD = sys.argv[1]
OUT = r"d:\project\bitrixeazy\tools\_audit_out3.txt"
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true);
define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
global $DB;

$leadId = 332986;
echo "=== GetChatsForLead($leadId) ===\n";
if (!function_exists("olLineLeadsGetChatsForLead")) { echo "NO FN\n"; exit; }
$list = olLineLeadsGetChatsForLead($leadId);
echo "count=".count($list)."\n";
foreach ($list as $row) {
  echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
}

echo "\n=== im_chat by ed2 LEAD|$leadId ===\n";
$res=$DB->Query("SELECT ID, ENTITY_ID, ENTITY_DATA_2 FROM b_im_chat WHERE ENTITY_DATA_2 LIKE 'LEAD|{$leadId}|%' LIMIT 10");
while($r=$res->Fetch()) echo "chat={$r["ID"]} eid={$r["ENTITY_ID"]}\n";

echo "\n=== sessions CRM fields for these chats ===\n";
$res=$DB->Query("SELECT ID, CHAT_ID, CONFIG_ID, CRM, CRM_CREATE, CRM_CREATE_ENTITY, CRM_CREATE_ID FROM b_imopenlines_session WHERE CHAT_ID IN (329589,329556) ORDER BY ID DESC");
while($r=$res->Fetch()) echo json_encode($r)."\n";
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_audit3.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_audit3.php 2>&1", timeout=60)
raw=o.read()
open(OUT,"wb").write(raw)
print("bytes", len(raw))
c.exec_command("rm -f /tmp/wa_audit3.php")
c.close()
