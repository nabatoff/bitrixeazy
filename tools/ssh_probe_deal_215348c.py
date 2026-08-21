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

$ct = \CCrmContact::GetByID(231718, false);
out('=== CONTACT 231718 ===');
if ($ct) foreach (['ID','NAME','LAST_NAME','COMPANY_ID','DATE_CREATE','ASSIGNED_BY_ID','SOURCE_ID'] as $k) out("$k=".($ct[$k]??''));
$multi = \CCrmFieldMulti::GetList(['ID'=>'ASC'], ['ENTITY_ID'=>'CONTACT','ELEMENT_ID'=>231718,'TYPE_ID'=>'PHONE']);
while ($r = $multi->Fetch()) out('phone '.$r['VALUE']);

out('=== COMPANY 1477 extra ===');
$rs = $DB->Query("SELECT ID, TITLE, ASSIGNED_BY_ID, DATE_CREATE, DATE_MODIFY, CREATED_BY_ID FROM b_crm_company WHERE ID=1477");
out(json_encode($rs->Fetch(), JSON_UNESCAPED_UNICODE));

out('=== sessions around company create 18.08 13:35 phone 77711039323 ===');
$rs = $DB->Query("SELECT ID, CHAT_ID, CONFIG_ID, CRM, CRM_CREATE, CRM_CREATE_COMPANY, CRM_CREATE_CONTACT, CRM_CREATE_LEAD, CRM_CREATE_DEAL, CRM_ACTIVITY_ID, DATE_CREATE FROM b_imopenlines_session WHERE USER_CODE LIKE '%77711039323%' AND DATE_CREATE BETWEEN '2026-08-18 13:00:00' AND '2026-08-18 16:00:00' ORDER BY ID");
while ($r = $rs->Fetch()) out(json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

out('=== events/log crm company 1477 ===');
if (class_exists('\CCrmEvent')) {
    $ev = \CCrmEvent::GetList(['DATE_CREATE'=>'ASC'], ['ENTITY_TYPE'=>'COMPANY','ENTITY_ID'=>1477], false, false, ['ID','DATE_CREATE','EVENT_NAME','EVENT_TEXT_1','CREATED_BY_ID']);
    $n=0;
    while ($e = $ev->Fetch()) { out(json_encode($e, JSON_UNESCAPED_UNICODE)); if (++$n>=8) break; }
}

out('=== deal 215348 events first/last ===');
$ev = \CCrmEvent::GetList(['DATE_CREATE'=>'ASC'], ['ENTITY_TYPE'=>'DEAL','ENTITY_ID'=>215348], false, false, ['ID','DATE_CREATE','EVENT_NAME','EVENT_TEXT_1','CREATED_BY_ID']);
$n=0;
while ($e = $ev->Fetch()) { out(json_encode($e, JSON_UNESCAPED_UNICODE)); if (++$n>=12) break; }

out('=== activity 603450 bindings all owners ===');
if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
    foreach ([603450,603603,603712] as $aid) {
        $rs = \Bitrix\Crm\ActivityBindingTable::getList(['filter'=>['=ACTIVITY_ID'=>$aid]]);
        while ($b = $rs->fetch()) out("act=$aid bind type={$b['OWNER_TYPE_ID']} id={$b['OWNER_ID']}");
    }
}

out('=== Uralsk chat 328616 activities ===');
$res = \CCrmActivity::GetList(['ID'=>'DESC'], ['ASSOCIATED_ENTITY_ID'=>568800,'CHECK_PERMISSIONS'=>'N'], false, ['nTopCount'=>5], ['ID','OWNER_TYPE_ID','OWNER_ID','SUBJECT','CREATED','ASSOCIATED_ENTITY_ID']);
while ($a = $res->Fetch()) out(json_encode($a, JSON_UNESCAPED_UNICODE));
$res = \CCrmActivity::GetList(['ID'=>'DESC'], ['ASSOCIATED_ENTITY_ID'=>328616,'CHECK_PERMISSIONS'=>'N'], false, ['nTopCount'=>5], ['ID','OWNER_TYPE_ID','OWNER_ID','SUBJECT','CREATED','ASSOCIATED_ENTITY_ID']);
while ($a = $res->Fetch()) out(json_encode($a, JSON_UNESCAPED_UNICODE));

out('DONE');
"""

sftp = c.open_sftp()
with sftp.file("/tmp/probe_deal_215348c.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/probe_deal_215348c.php 2>&1", timeout=90)
print(o.read().decode("utf-8", "replace"))
c.exec_command("rm -f /tmp/probe_deal_215348c.php")
c.close()
