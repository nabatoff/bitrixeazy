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
@ini_set('display_errors', '1');
error_reporting(E_ALL);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');
\Bitrix\Main\Loader::includeModule('im');
\Bitrix\Main\Loader::includeModule('imopenlines');
global $DB;
mb_internal_encoding('UTF-8');

function out($s) { echo $s . "\n"; }
function q($sql) {
    global $DB;
    try { return $DB->Query($sql); }
    catch (\Throwable $e) { out('SQLERR '.$e->getMessage().' :: '.$sql); return false; }
}

$dealId = 215348;
$dealType = 2;
$leadType = 1;

$deal = \CCrmDeal::GetByID($dealId, false);
out('=== DEAL ===');
foreach (['ID','TITLE','CATEGORY_ID','STAGE_ID','ASSIGNED_BY_ID','CONTACT_ID','COMPANY_ID','LEAD_ID','DATE_CREATE','DATE_MODIFY'] as $k) {
    out("$k=" . (string)($deal[$k] ?? ''));
}
$assignee = (int)($deal['ASSIGNED_BY_ID'] ?? 0);
$contactId = (int)($deal['CONTACT_ID'] ?? 0);
$leadId = (int)($deal['LEAD_ID'] ?? 0);

$u = \CUser::GetByID($assignee)->Fetch();
out('ASSIGNEE_NAME=' . trim(($u['NAME']??'').' '.($u['LAST_NAME']??'')) . ' LOGIN=' . ($u['LOGIN']??''));
if ($contactId) {
    $ct = \CCrmContact::GetByID($contactId, false);
    out('CONTACT_NAME=' . trim(($ct['NAME']??'').' '.($ct['LAST_NAME']??'')));
}

$catRow = q("SELECT ID, NAME FROM b_crm_deal_category WHERE ID=".(int)($deal['CATEGORY_ID']??0));
if ($catRow) { $c=$catRow->Fetch(); if ($c) out('CATEGORY_NAME='.$c['NAME']); }

out('=== PHONES ===');
$phones = [];
if ($contactId) {
    $multi = \CCrmFieldMulti::GetList(['ID'=>'ASC'], ['ENTITY_ID'=>'CONTACT','ELEMENT_ID'=>$contactId,'TYPE_ID'=>'PHONE']);
    while ($r = $multi->Fetch()) { out('contact '.$r['VALUE']); $phones[] = $r['VALUE']; }
}

out('=== ASSIGNEE QUEUE LINES SQL ===');
$rs = q("SELECT CONFIG_ID FROM b_imopenlines_queue WHERE USER_ID=".$assignee);
$lineIds = [];
if ($rs) while ($row = $rs->Fetch()) $lineIds[] = (int)$row['CONFIG_ID'];
if (!$lineIds) out('(none)');
foreach ($lineIds as $lid) {
    $cfg = q("SELECT ID, LINE_NAME FROM b_imopenlines_config WHERE ID=".$lid);
    $name = '';
    if ($cfg) { $x=$cfg->Fetch(); $name = $x['LINE_NAME'] ?? ''; }
    out("line=$lid name=$name");
}

out('=== CHATS ENTITY_DATA DEAL ===');
$chatIds = [];
$rs = q("SELECT ID, TITLE, ENTITY_ID, ENTITY_DATA_1, ENTITY_DATA_2, ENTITY_DATA_3 FROM b_im_chat WHERE TYPE='L' AND (ENTITY_DATA_1 LIKE '%DEAL|$dealId%' OR ENTITY_DATA_2 LIKE '%DEAL|$dealId%' OR ENTITY_DATA_3 LIKE '%DEAL|$dealId%') ORDER BY ID DESC");
if ($rs) while ($r = $rs->Fetch()) {
    $chatIds[] = (int)$r['ID'];
    out("chat={$r['ID']} title={$r['TITLE']}");
    out("  ENTITY_ID={$r['ENTITY_ID']}");
    out("  ED1={$r['ENTITY_DATA_1']}");
    out("  ED2={$r['ENTITY_DATA_2']}");
    out("  ED3={$r['ENTITY_DATA_3']}");
}
out('entity_data_count='.count($chatIds));

out('=== SESSIONS CRM DEAL ===');
$cols = [];
$cr = q("SHOW COLUMNS FROM b_imopenlines_session");
if ($cr) while ($x=$cr->Fetch()) $cols[] = $x['Field'];
out('session_cols='.implode(',', $cols));
$sel = 'ID, CHAT_ID, CONFIG_ID, USER_CODE, OPERATOR_ID, DATE_CREATE';
foreach (['CRM_ENTITY_TYPE','CRM_ENTITY_ID','CRM_CREATE_ENTITY','CRM_CREATE_ID','CRM','CRM_CREATE'] as $c) {
    if (in_array($c, $cols, true)) $sel .= ', '.$c;
}
$whereParts = [];
if (in_array('CRM_ENTITY_ID', $cols, true)) $whereParts[] = "(CRM_ENTITY_ID=$dealId)";
if (in_array('CRM_CREATE_ID', $cols, true)) $whereParts[] = "(CRM_CREATE_ID=$dealId)";
$where = $whereParts ? implode(' OR ', $whereParts) : '1=0';
$rs = q("SELECT $sel FROM b_imopenlines_session WHERE $where ORDER BY ID DESC LIMIT 40");
if ($rs) while ($r = $rs->Fetch()) {
    $chatIds[] = (int)$r['CHAT_ID'];
    out('sess='.json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

out('=== OL ACTIVITIES OWNER DEAL ===');
$res = \CCrmActivity::GetList(['ID'=>'DESC'], ['OWNER_TYPE_ID'=>$dealType,'OWNER_ID'=>$dealId,'CHECK_PERMISSIONS'=>'N'], false, ['nTopCount'=>80], ['ID','SUBJECT','PROVIDER_ID','PROVIDER_TYPE_ID','ASSOCIATED_ENTITY_ID','OWNER_TYPE_ID','OWNER_ID','CREATED']);
while ($act = $res->Fetch()) {
    $p = strtoupper((string)$act['PROVIDER_ID']);
    $looks = (strpos($p,'IMOPEN')!==false || strpos($p,'OPENLINE')!==false || stripos((string)$act['SUBJECT'],'чат')!==false || stripos((string)$act['SUBJECT'],'Whats')!==false);
    out("act={$act['ID']} prov={$act['PROVIDER_ID']}/{$act['PROVIDER_TYPE_ID']} assoc={$act['ASSOCIATED_ENTITY_ID']} created={$act['CREATED']} subj={$act['SUBJECT']} ol=".($looks?'Y':'N'));
}

out('=== ACTIVITY BINDINGS DEAL (OL only) ===');
if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
    $rs = \Bitrix\Crm\ActivityBindingTable::getList(['filter'=>['=OWNER_TYPE_ID'=>$dealType,'=OWNER_ID'=>$dealId],'select'=>['ACTIVITY_ID'],'limit'=>120]);
    $n=0;
    while ($b = $rs->fetch()) {
        $aid = (int)$b['ACTIVITY_ID'];
        $act = \CCrmActivity::GetByID($aid, false);
        if (!is_array($act)) continue;
        $p = strtoupper((string)($act['PROVIDER_ID']??''));
        $looks = (strpos($p,'IMOPEN')!==false || strpos($p,'OPENLINE')!==false || stripos((string)($act['SUBJECT']??''),'чат')!==false);
        if (!$looks) continue;
        $n++;
        out("bind act=$aid owner={$act['OWNER_TYPE_ID']}|{$act['OWNER_ID']} assoc={$act['ASSOCIATED_ENTITY_ID']} subj={$act['SUBJECT']}");
    }
    out("ol_bind_count=$n");
}

out('=== GetChatsForDeal ===');
try {
    if (function_exists('olLineLeadsGetChatsForDeal')) {
        $list = olLineLeadsGetChatsForDeal($dealId);
        out('count='.count($list));
        foreach ($list as $item) {
            out("  chat={$item['CHAT_ID']} line={$item['LINE_ID']} key={$item['KEY']}");
            $chatIds[] = (int)$item['CHAT_ID'];
        }
    } else out('fn missing');
} catch (\Throwable $e) { out('ERR '.$e->getMessage()); }

out('=== SAME PHONE CHATS ===');
$tails = [];
foreach ($phones as $ph) {
    $d = preg_replace('/\D+/','', (string)$ph);
    if (strlen($d) >= 10) $tails[substr($d,-10)] = substr($d,-10);
}
foreach ($tails as $tail) {
    out("tail=$tail");
    $rs = q("SELECT ID, TITLE, ENTITY_ID, ENTITY_DATA_2 FROM b_im_chat WHERE TYPE='L' AND ENTITY_ID LIKE '%".$DB->ForSql($tail)."%' ORDER BY ID DESC LIMIT 25");
    if ($rs) while ($r = $rs->Fetch()) {
        $chatIds[] = (int)$r['ID'];
        out("  chat={$r['ID']} entity={$r['ENTITY_ID']} ed2={$r['ENTITY_DATA_2']}");
    }
}

$chatIds = array_values(array_unique(array_filter($chatIds)));
out('=== CHAT DETAILS ===');
foreach ($chatIds as $cid) {
    $chat = q("SELECT ID, TITLE, ENTITY_ID, ENTITY_DATA_1, ENTITY_DATA_2 FROM b_im_chat WHERE ID=".(int)$cid);
    $r = $chat ? $chat->Fetch() : false;
    if (!$r) continue;
    $parts = explode('|', (string)$r['ENTITY_ID']);
    $lineId = (int)($parts[1] ?? 0);
    $lineName = '';
    if ($lineId) {
        $cfg = q("SELECT LINE_NAME FROM b_imopenlines_config WHERE ID=$lineId");
        if ($cfg) { $x=$cfg->Fetch(); $lineName = $x['LINE_NAME'] ?? ''; }
    }
    $sess = q("SELECT ID, CONFIG_ID, USER_CODE, OPERATOR_ID, DATE_CREATE".(in_array('CRM_ENTITY_TYPE',$cols,true)?", CRM_ENTITY_TYPE, CRM_ENTITY_ID":"")." FROM b_imopenlines_session WHERE CHAT_ID=$cid ORDER BY ID DESC LIMIT 1");
    $s = $sess ? $sess->Fetch() : false;
    out("chat=$cid line=$lineId [$lineName] entity={$r['ENTITY_ID']}");
    out("  ed2={$r['ENTITY_DATA_2']}");
    if ($s) out("  sess=".json_encode($s, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

out('=== LOG 215348 ===');
$cands = [
    $_SERVER['DOCUMENT_ROOT'].'/local/custom_chat/ol_line_leads.log',
    $_SERVER['DOCUMENT_ROOT'].'/upload/ol_line_leads.log',
    '/home/bitrix/www/local/logs/ol_line_leads.log',
];
$foundLog = false;
foreach ($cands as $log) {
    if (is_file($log)) { $foundLog = $log; break; }
}
if (!$foundLog) {
    out('no known log');
    $rs = q("SELECT VALUE FROM b_option WHERE MODULE_ID='main' AND NAME LIKE '%ol_line%' LIMIT 5");
} else {
    out('log='.$foundLog);
    $hit=0;
    foreach (file($foundLog) as $ln) {
        if (strpos($ln, '215348') !== false || strpos($ln, (string)$contactId) !== false) {
            out(rtrim($ln));
            $hit++;
            if ($hit>=50) break;
        }
    }
    out("shown=$hit");
}

out('=== CODE TARGET/BEST ===');
try {
    if (function_exists('olLineLeadsResolveTargetLineIdsForDeal')) {
        $t = olLineLeadsResolveTargetLineIdsForDeal($dealId);
        out('targetLines='.implode(',', $t));
    }
    if (function_exists('olLineLeadsPickBestChatForDeal') && $chatIds) {
        out('best='.olLineLeadsPickBestChatForDeal($chatIds, $dealId));
    }
} catch (\Throwable $e) { out('ERR '.$e->getMessage()); }

out('DONE');
"""

sftp = c.open_sftp()
with sftp.file("/tmp/probe_deal_215348.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/probe_deal_215348.php 2>&1", timeout=90)
out = o.read().decode("utf-8", "replace")
sys.stdout.buffer.write(out.encode("utf-8", "replace") if False else out.encode("utf-8", "replace"))
print(out)
c.exec_command("rm -f /tmp/probe_deal_215348.php")
c.close()
