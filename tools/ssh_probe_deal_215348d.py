#!/usr/bin/env python3
import paramiko
import sys

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=20)

php = r"""<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');
global $DB;
function out($s){ echo $s."\n"; }

foreach ([215405, 214776] as $id) {
    $rs = $DB->Query("SELECT ID, TITLE, CATEGORY_ID, STAGE_ID, CONTACT_ID, COMPANY_ID, LEAD_ID, ASSIGNED_BY_ID, DATE_CREATE, CLOSED FROM b_crm_deal WHERE ID=$id");
    $d = $rs->Fetch();
    $cat='';
    if ($d) {
        $c = $DB->Query("SELECT NAME FROM b_crm_deal_category WHERE ID=".(int)$d['CATEGORY_ID'])->Fetch();
        $cat = $c['NAME']??'';
    }
    out("=== DEAL $id ===");
    out(json_encode($d, JSON_UNESCAPED_UNICODE).' cat='.$cat);
}

out('=== chats on deal 215405 ===');
$rs = $DB->Query("SELECT ID, TITLE, ENTITY_ID, ENTITY_DATA_2 FROM b_im_chat WHERE TYPE='L' AND (ENTITY_DATA_1 LIKE '%DEAL|215405%' OR ENTITY_DATA_2 LIKE '%DEAL|215405%')");
while ($r = $rs->Fetch()) out(json_encode($r, JSON_UNESCAPED_UNICODE));

out('=== activity 602443 bindings ===');
if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
    $rs = \Bitrix\Crm\ActivityBindingTable::getList(['filter'=>['=ACTIVITY_ID'=>602443]]);
    while ($b = $rs->fetch()) out("type={$b['OWNER_TYPE_ID']} id={$b['OWNER_ID']}");
}
$act = \CCrmActivity::GetByID(602443, false);
out('owner='.$act['OWNER_TYPE_ID'].'|'.$act['OWNER_ID'].' subj='.$act['SUBJECT']);

out('=== duplicates phone 77711039323 ===');
if (class_exists('\CCrmDuplicate')) {
    // skip
}
$rs = $DB->Query("SELECT ENTITY_ID, ELEMENT_ID, VALUE FROM b_crm_field_multi WHERE TYPE_ID='PHONE' AND VALUE LIKE '%7711039323%' LIMIT 20");
while ($r = $rs->Fetch()) out("{$r['ENTITY_ID']}|{$r['ELEMENT_ID']} {$r['VALUE']}");

out('DONE');
"""

sftp = c.open_sftp()
with sftp.file("/tmp/probe_deal_215348d.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/probe_deal_215348d.php 2>&1", timeout=60)
print(o.read().decode("utf-8", "replace"))
c.exec_command("rm -f /tmp/probe_deal_215348d.php")
c.close()
