#!/usr/bin/env python3
import sys
import paramiko

PHP = r"""<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('DisableEventsCheck', true);
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('imopenlines');
\Bitrix\Main\Loader::includeModule('imconnector');
\Bitrix\Main\Loader::includeModule('im');

echo "Chat=".(class_exists('\\Bitrix\\ImOpenLines\\Chat')?'Y':'N')."\n";
if (class_exists('\\Bitrix\\ImOpenLines\\Chat')) {
  $m = get_class_methods('\\Bitrix\\ImOpenLines\\Chat');
  echo "Chat methods: ".implode(',', array_slice($m,0,40))."\n";
}
echo "Session=".(class_exists('\\Bitrix\\ImOpenLines\\Session')?'Y':'N')."\n";
echo "ConfigTable=".(class_exists('\\Bitrix\\ImOpenLines\\Model\\ConfigTable')?'Y':'N')."\n";
echo "QueueTable=".(class_exists('\\Bitrix\\ImOpenLines\\Model\\QueueTable')?'Y':'N')."\n";
echo "StatusTable=".(class_exists('\\Bitrix\\ImConnector\\Model\\StatusTable')?'Y':'N')."\n";

if (class_exists('\\Bitrix\\ImConnector\\Model\\StatusTable')) {
  $rs = \Bitrix\ImConnector\Model\StatusTable::getList(['select'=>['CONNECTOR','LINE','STATUS'],'limit'=>40]);
  echo "=== connectors ===\n";
  while ($r = $rs->fetch()) {
    echo $r['LINE']."\t".$r['CONNECTOR']."\t".$r['STATUS']."\n";
  }
}
if (class_exists('\\Bitrix\\ImOpenLines\\Model\\ConfigTable')) {
  $rs = \Bitrix\ImOpenLines\Model\ConfigTable::getList(['select'=>['ID','LINE_NAME','ACTIVE'],'limit'=>30]);
  echo "=== configs ===\n";
  while ($r = $rs->fetch()) {
    echo $r['ID']."\t".$r['ACTIVE']."\t".$r['LINE_NAME']."\n";
  }
}
echo "=== imconnector tables ===\n";
$conn = \Bitrix\Main\Application::getConnection();
$rs = $conn->query("SHOW TABLES LIKE 'b_imconnector%'");
while ($r = $rs->fetch()) echo reset($r)."\n";
foreach (['b_imconnectors_status','b_imconnectors_info_connectors','b_imconnectors_custom_connectors','b_imopenlines_queue'] as $table) {
  try {
    echo "=== {$table} columns ===\n";
    $rs = $conn->query("SHOW COLUMNS FROM {$table}");
    while ($r = $rs->fetch()) echo $r['Field']."\t".$r['Type']."\n";
    echo "=== {$table} sample ===\n";
    $rs = $conn->query("SELECT * FROM {$table} LIMIT 20");
    while ($r = $rs->fetch()) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
  } catch (\Throwable $e) { echo $e->getMessage()."\n"; }
}
echo "=== active green queues ===\n";
$rs = $conn->query("
  SELECT q.USER_ID, q.CONFIG_ID, c.LINE_NAME, s.CONNECTOR
  FROM b_imopenlines_queue q
  INNER JOIN b_imopenlines_config c ON c.ID=q.CONFIG_ID
  INNER JOIN b_imconnectors_status s ON s.LINE=q.CONFIG_ID
  WHERE q.CONFIG_ID >= 31 AND s.ACTIVE='Y' AND s.CONNECTION='Y'
    AND s.REGISTER='Y' AND s.CONNECTOR LIKE 'fos_green%'
  ORDER BY q.CONFIG_ID, q.USER_ID
");
while ($r = $rs->fetch()) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
echo "=== recent green sessions ===\n";
$rs = $conn->query("
  SELECT s.ID,s.CHAT_ID,s.CONFIG_ID,s.USER_ID,s.OPERATOR_ID,s.USER_CODE,s.MODE,s.STATUS
  FROM b_imopenlines_session s
  WHERE s.CONFIG_ID IN (31,33,35,38,39,40,41,42,44,46,49,51,55)
  ORDER BY s.ID DESC LIMIT 15
");
while ($r = $rs->fetch()) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
"""

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=25)
sftp = c.open_sftp()
with sftp.file("/tmp/wa_ol_probe.php", "w") as f:
    f.write(PHP)
sftp.close()
_, stdout, stderr = c.exec_command("php /tmp/wa_ol_probe.php 2>&1", timeout=40)
print(stdout.read().decode("utf-8", errors="replace"))
print(stderr.read().decode("utf-8", errors="replace"))
c.exec_command("rm -f /tmp/wa_ol_probe.php")
c.close()
