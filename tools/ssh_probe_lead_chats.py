#!/usr/bin/env python3
import paramiko
import sys

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=20)

php = """<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
global $DB;
$leadId = 332986;
$needle = 'LEAD|' . $leadId . '|';
echo "needle=$needle\\n";
$res = $DB->Query("SELECT ID,TITLE,ENTITY_ID,ENTITY_DATA_1,ENTITY_DATA_2 FROM b_im_chat WHERE TYPE='L' AND (ENTITY_DATA_2 LIKE '%LEAD|332986|%' OR ENTITY_DATA_1 LIKE '%|LEAD|332986|%') ORDER BY ID DESC");
while ($r = $res->Fetch()) {
    echo $r['ID'].' entity='.$r['ENTITY_ID'].' ed2='.$r['ENTITY_DATA_2']."\\n";
}
\\Bitrix\\Main\\Loader::includeModule('im');
$rs = \\Bitrix\\Im\\Model\\ChatTable::getList([
    'filter' => [
        '=TYPE' => 'L',
        [
            'LOGIC' => 'OR',
            ['=%ENTITY_DATA_2' => $needle],
            ['=%ENTITY_DATA_1' => '%|' . $needle],
        ],
    ],
    'select' => ['ID', 'ENTITY_ID', 'ENTITY_DATA_2'],
    'order' => ['ID' => 'DESC'],
    'limit' => 50,
]);
echo "ORM count:\\n";
while ($row = $rs->fetch()) {
    echo $row['ID'].' '.$row['ENTITY_ID'].' '.$row['ENTITY_DATA_2']."\\n";
}
"""

sftp = c.open_sftp()
with sftp.file("/tmp/ol_db_probe.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/ol_db_probe.php 2>&1", timeout=60)
print(o.read().decode("utf-8", "replace"))
c.exec_command("rm -f /tmp/ol_db_probe.php")
c.close()
