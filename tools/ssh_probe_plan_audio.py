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

$uid = 139808;
$u = \CUser::GetByID($uid)->Fetch();
out('=== USER 139808 ===');
out('NAME='.trim(($u['NAME']??'').' '.($u['LAST_NAME']??'')).' LOGIN='.($u['LOGIN']??'').' EMAIL='.($u['EMAIL']??'').' PERSONAL_MOBILE='.($u['PERSONAL_MOBILE']??'').' WORK_PHONE='.($u['WORK_PHONE']??'').' PERSONAL_PHONE='.($u['PERSONAL_PHONE']??''));

out('=== USER PHONES multi ===');
// bitrix user phones often in PERSONAL_*
out('=== CONTACT 224693 phones vs user ===');
$multi = \CCrmFieldMulti::GetList(['ID'=>'ASC'], ['ENTITY_ID'=>'CONTACT','ELEMENT_ID'=>224693,'TYPE_ID'=>'PHONE']);
while ($r = $multi->Fetch()) out('contact '.$r['VALUE']);

out('=== category 20 line map? UF or options ===');
$rs = $DB->Query("SELECT NAME, VALUE FROM b_option WHERE MODULE_ID LIKE '%custom%' OR NAME LIKE '%ol_line%' OR NAME LIKE '%wa_cc%' LIMIT 30");
if ($rs) while ($r = $rs->Fetch()) out($r['NAME'].'='.$r['VALUE']);

out('=== audio CSS on server index ===');
$idx = file_get_contents('/home/bitrix/www/local/custom_chat/index.php');
foreach (['has-audio','wa-voice','pointer-events','wa-msg-actions','mediaElementHtml','wa-media-audio'] as $needle) {
    out($needle.': '.substr_count($idx, $needle));
}
// extract mobile has-audio block
if (preg_match('/body\.wa-cc-mobile \.wa-msg\.has-audio \.wa-msg-actions\s*\{[^}]+\}/s', $idx, $m)) out('CSS1='.$m[0]);
if (preg_match('/function mediaElementHtml\([^)]*\)\s*\{[^}]{0,800}/s', $idx, $m)) out('FN='.substr($m[0],0,500));

out('=== recent audio files sample ===');
$rs = $DB->Query("SELECT ID, FILE_NAME, CONTENT_TYPE, FILE_SIZE FROM b_file WHERE (CONTENT_TYPE LIKE 'audio%' OR FILE_NAME LIKE '%.ogg' OR FILE_NAME LIKE '%.opus' OR FILE_NAME LIKE '%ptt%') ORDER BY ID DESC LIMIT 8");
while ($r = $rs->Fetch()) out(json_encode($r, JSON_UNESCAPED_UNICODE));

out('DONE');
"""

sftp = c.open_sftp()
with sftp.file("/tmp/probe_plan_audio.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/probe_plan_audio.php 2>&1", timeout=60)
print(o.read().decode("utf-8", "replace"))

# also grep mobile overlays around audio in deployed file
_, o, _ = c.exec_command("grep -n 'has-audio\\|pointer-events\\|wa-msg-actions\\|wa-voice\\|controls' /home/bitrix/www/local/custom_chat/index.php | head -60")
print(o.read().decode("utf-8", "replace"))
c.exec_command("rm -f /tmp/probe_plan_audio.php")
c.close()
