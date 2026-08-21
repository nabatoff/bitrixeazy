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

out('=== deal categories ===');
$rs = $DB->Query("SELECT ID, NAME FROM b_crm_deal_category ORDER BY SORT, ID");
while ($r = $rs->Fetch()) out($r['ID'].' '.$r['NAME']);

out('=== sample UF deal fields (checkbox-like) ===');
$rs = $DB->Query("SELECT FIELD_NAME, USER_TYPE_ID, EDIT_FORM_LABEL, LIST_COLUMN_LABEL, SETTINGS FROM b_user_field WHERE ENTITY_ID='CRM_DEAL' AND (USER_TYPE_ID IN ('boolean','enumeration','string','integer') OR FIELD_NAME LIKE '%COLOR%' OR FIELD_NAME LIKE '%FLAG%' OR FIELD_NAME LIKE '%YES%') ORDER BY FIELD_NAME LIMIT 40");
while ($r = $rs->Fetch()) {
  $lab = $r['EDIT_FORM_LABEL'] ?: $r['LIST_COLUMN_LABEL'];
  if (is_string($lab) && $lab !== '' && $lab[0]==='a') {
    $u = @unserialize($lab);
    if (is_array($u)) $lab = $u['ru'] ?? $u['en'] ?? reset($u);
  }
  out($r['FIELD_NAME'].' type='.$r['USER_TYPE_ID'].' label='.$lab);
}

out('=== option/module crm kanban color ===');
$rs = $DB->Query("SELECT MODULE_ID, NAME, VALUE FROM b_option WHERE (NAME LIKE '%KANBAN%' OR NAME LIKE '%COLOR%' OR NAME LIKE '%card%') AND MODULE_ID IN ('crm','main') LIMIT 30");
while ($r = $rs->Fetch()) out($r['MODULE_ID'].'.'.$r['NAME'].'='.substr((string)$r['VALUE'],0,120));

out('=== local customizations mentioning kanban ===');
passthru("grep -rni 'kanban\\|crm-kanban\\|Kanban' /home/bitrix/www/local --include='*.php' --include='*.js' --include='*.css' 2>/dev/null | head -25");

out('DONE');
"""

sftp = c.open_sftp()
with sftp.file("/tmp/probe_kanban_color.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/probe_kanban_color.php 2>&1", timeout=60)
print(o.read().decode("utf-8", "replace"))
c.exec_command("rm -f /tmp/probe_kanban_color.php")
c.close()
