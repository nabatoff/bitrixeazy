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
\Bitrix\Main\Loader::includeModule('imopenlines');
global $DB;

function out($s){ echo $s."\n"; }

out('=== COMPANY 1477 ===');
$co = \CCrmCompany::GetByID(1477, false);
if ($co) {
    foreach (['ID','TITLE','ASSIGNED_BY_ID','DATE_CREATE'] as $k) out("$k=".$co[$k]);
}
$multi = \CCrmFieldMulti::GetList(['ID'=>'ASC'], ['ENTITY_ID'=>'COMPANY','ELEMENT_ID'=>1477,'TYPE_ID'=>'PHONE']);
while ($r = $multi->Fetch()) out('company_phone '.$r['VALUE']);

out('=== CONTACT 224693 <-> COMPANY ===');
if (class_exists('\Bitrix\Crm\Binding\ContactCompanyTable')) {
    $rs = \Bitrix\Crm\Binding\ContactCompanyTable::getList(['filter'=>['=CONTACT_ID'=>224693]]);
    while ($r = $rs->fetch()) out('contact_company '.json_encode($r, JSON_UNESCAPED_UNICODE));
    $rs = \Bitrix\Crm\Binding\ContactCompanyTable::getList(['filter'=>['=COMPANY_ID'=>1477]]);
    $n=0;
    while ($r = $rs->fetch()) { $n++; if ($n<=8) out('co1477_contact='.$r['CONTACT_ID']); }
    out("co1477_contacts_n=$n");
}

out('=== DEALS of company 1477 (open-ish) ===');
$res = \CCrmDeal::GetListEx(['ID'=>'DESC'], ['COMPANY_ID'=>1477, 'CHECK_PERMISSIONS'=>'N'], false, ['nTopCount'=>15], ['ID','TITLE','CATEGORY_ID','STAGE_ID','ASSIGNED_BY_ID','CONTACT_ID','LEAD_ID','DATE_CREATE','DATE_MODIFY','CLOSED']);
while ($d = $res->Fetch()) {
    out("deal={$d['ID']} cat={$d['CATEGORY_ID']} stage={$d['STAGE_ID']} contact={$d['CONTACT_ID']} lead={$d['LEAD_ID']} assigned={$d['ASSIGNED_BY_ID']} closed={$d['CLOSED']} date={$d['DATE_CREATE']} title={$d['TITLE']}");
}

out('=== DEALS of contact 224693 ===');
$res = \CCrmDeal::GetListEx(['ID'=>'DESC'], ['CONTACT_ID'=>224693, 'CHECK_PERMISSIONS'=>'N'], false, ['nTopCount'=>15], ['ID','TITLE','CATEGORY_ID','STAGE_ID','COMPANY_ID','LEAD_ID','DATE_CREATE','CLOSED']);
while ($d = $res->Fetch()) {
    out("deal={$d['ID']} cat={$d['CATEGORY_ID']} stage={$d['STAGE_ID']} company={$d['COMPANY_ID']} lead={$d['LEAD_ID']} closed={$d['CLOSED']} date={$d['DATE_CREATE']} title={$d['TITLE']}");
}

out('=== SESSIONS 569826/569732/569605 full CRM flags ===');
$rs = $DB->Query("SELECT ID,CHAT_ID,CONFIG_ID,OPERATOR_ID,CRM,CRM_CREATE,CRM_CREATE_LEAD,CRM_CREATE_COMPANY,CRM_CREATE_CONTACT,CRM_CREATE_DEAL,CRM_ACTIVITY_ID,DATE_CREATE,USER_CODE FROM b_imopenlines_session WHERE ID IN (569826,569732,569605)");
while ($r = $rs->Fetch()) out(json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

out('=== LINE CRM CONFIG 39/40/41/49 ===');
$rs = $DB->Query("SELECT ID, LINE_NAME, CRM, CRM_CREATE, CRM_FORWARD, CRM_SOURCE, CRM_TRANSFER_CHANGE, CRM_CREATE_SECOND FROM b_imopenlines_config WHERE ID IN (39,40,41,49,42)");
while ($r = $rs->Fetch()) out(json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

out('=== ACTIVITIES SETTINGS ===');
foreach ([603712,603603,603450] as $aid) {
    $act = \CCrmActivity::GetByID($aid, false);
    $set = $act['SETTINGS'] ?? '';
    if (is_array($set)) $set = json_encode($set, JSON_UNESCAPED_UNICODE);
    out("act=$aid owner={$act['OWNER_TYPE_ID']}|{$act['OWNER_ID']} assoc={$act['ASSOCIATED_ENTITY_ID']} created={$act['CREATED']}");
    out("  settings=".substr((string)$set,0,500));
}

out('=== LEAD 332043 (Uralsk chat still on it) ===');
$lead = \CCrmLead::GetByID(332043, false);
if ($lead) {
    foreach (['ID','TITLE','STATUS_ID','ASSIGNED_BY_ID','CONTACT_ID','COMPANY_ID','DATE_CREATE','DATE_MODIFY'] as $k) out("$k=".$lead[$k]);
}

out('=== DEAL 215348 COMPANY_ID vs CONTACT ===');
$d = \CCrmDeal::GetListEx([], ['ID'=>215348,'CHECK_PERMISSIONS'=>'N'], false, false, ['ID','COMPANY_ID','CONTACT_ID','UF_*']);
$row = $d->Fetch();
out('company_from_list='.($row['COMPANY_ID']??'').' contact='.($row['CONTACT_ID']??''));

out('=== b_crm_deal COMPANY_ID raw ===');
$rs = $DB->Query("SELECT ID, COMPANY_ID, CONTACT_ID, LEAD_ID, CATEGORY_ID, STAGE_ID, ASSIGNED_BY_ID, DATE_CREATE FROM b_crm_deal WHERE ID=215348");
out(json_encode($rs->Fetch(), JSON_UNESCAPED_UNICODE));

out('DONE');
"""

sftp = c.open_sftp()
with sftp.file("/tmp/probe_deal_215348b.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/probe_deal_215348b.php 2>&1", timeout=90)
print(o.read().decode("utf-8", "replace"))
c.exec_command("rm -f /tmp/probe_deal_215348b.php")
c.close()
