#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
error_reporting(E_ALL);
ini_set("display_errors","1");
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("im");
\Bitrix\Main\Loader::includeModule("imopenlines");
$conn = \Bitrix\Main\Application::getConnection();

echo "=== MessageUnreadTable ===\n";
try {
  $r=$conn->query("SHOW TABLES LIKE 'b_im_message_unread%'");
  while($x=$r->fetch()) echo json_encode($x)."\n";
  $r=$conn->query("SHOW TABLES LIKE '%unread%'");
  while($x=$r->fetch()) echo implode("",$x)."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== GetRecentList params ===\n";
$ref = new ReflectionMethod("CIMContactList", "GetRecentList");
foreach ($ref->getParameters() as $p) echo $p->getName()."\n";
$src = file($ref->getFileName());
echo implode("", array_slice($src, $ref->getStartLine()-1, 35));

echo "\n=== unread table sample ===\n";
foreach (["b_im_message_unread","b_im_unread","b_im_chat_unread"] as $tb) {
  try {
    $r=$conn->query("SHOW COLUMNS FROM $tb");
    echo "$tb cols: ";
    $c=[]; while($x=$r->fetch()) $c[]=$x["Field"];
    echo implode(",",$c)."\n";
  } catch (\Throwable $e) {}
}

echo "\n=== GetRecentList uid 72 OL ===\n";
global $USER;
if (is_object($USER) && method_exists($USER,"Authorize")) {
  $USER->Authorize(72);
}
try {
  $list = CIMContactList::GetRecentList([
    "USER_ID" => 72,
    "JSON" => "Y",
    "SKIP_OPENLINES" => "N",
  ]);
  echo "type=".gettype($list)."\n";
  if (is_array($list)) {
    echo "n=".count($list)." first_keys=".implode(",", array_keys($list))."\n";
    $i=0; $unread=0; $wa=0;
    foreach ($list as $k=>$it) {
      $row = is_array($it) ? $it : [];
      $type = strtolower((string)($row["type"] ?? ($row["chat"]["type"] ?? "")));
      $counter = (int)($row["counter"] ?? 0);
      $eid = strtolower((string)($row["chat"]["entity_id"] ?? $row["entity_id"] ?? ""));
      $isOl = ($type==="lines" || strpos($eid,"fos_green")!==false);
      if ($isOl && $counter>0) { $unread++; }
      if ($isOl && $counter>0 && (strpos($eid,"fos_green")!==false || strpos($eid,"@c.us")!==false || strpos($eid,"@g.us")!==false)) $wa++;
      if ($isOl && $i++<2) {
        echo json_encode([
          "k"=>$k,
          "type"=>$type,
          "counter"=>$counter,
          "unread"=>$row["unread"]??null,
          "id"=>$row["id"]??null,
          "chat_id"=>$row["chat_id"]??($row["chat"]["id"]??null),
          "eid"=>substr($eid,0,70),
          "title"=>$row["title"]??($row["chat"]["name"]??null),
        ], JSON_UNESCAPED_UNICODE)."\n";
      }
    }
    echo "ol_unread=$unread wa_unread=$wa\n";
  }
} catch (\Throwable $e) {
  echo "ERR ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine()."\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_recent6.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_recent6.php 2>&1; rm -f /tmp/wa_recent6.php", timeout=90)
sys.stdout.buffer.write(o.read()[:12000])
sys.stdout.buffer.write(e.read()[:1500])

print("\n=== LeftMenu MenuItem rest ===")
_, o, _ = c.exec_command(
    "ls /home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/; "
    "grep -n 'AppTable\\|marketplace\\|COUNTER\\|getCounter\\|menu-item-index' "
    "/home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/MenuItem/*.php "
    "2>/dev/null | head -40",
    timeout=20)
sys.stdout.buffer.write(o.read()[:5000])
c.close()
