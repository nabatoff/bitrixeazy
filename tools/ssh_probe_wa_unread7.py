#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
error_reporting(E_ALL); ini_set("display_errors","1");
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("im");
\Bitrix\Main\Loader::includeModule("imopenlines");
global $USER;
$USER->Authorize(72);
echo "uid=".$USER->GetID()."\n";

ob_start();
$_GET = [];
require "/home/bitrix/www/local/custom_chat/portal_unread.php";
$out = ob_get_clean();
echo "portal_unread=".$out."\n";

echo "\n=== GetRecentList sample OL ===\n";
try {
  $list = CIMContactList::GetRecentList(["USER_ID"=>72,"JSON"=>"Y","SKIP_OPENLINES"=>"N"]);
  echo "type=".gettype($list)." count=".(is_array($list)?count($list):0)."\n";
  $n=0; $unread=0;
  if (is_array($list)) {
    $items = isset($list["items"]) ? $list["items"] : $list;
    echo "items=".count($items)." keys0=".implode(",", array_slice(array_keys($list),0,12))."\n";
    foreach ($items as $row) {
      if (!is_array($row)) continue;
      $chat = $row["chat"] ?? [];
      $type = strtolower((string)($row["type"] ?? $chat["type"] ?? ""));
      $eid = (string)($chat["entity_id"] ?? "");
      $counter = (int)($row["counter"] ?? 0);
      $isOl = ($type==="lines" || strpos(strtolower($eid),"fos_green")!==false);
      if (!$isOl) continue;
      if ($counter>0) $unread++;
      if ($n++<3) {
        echo json_encode([
          "id"=>$row["id"]??null,
          "type"=>$type,
          "counter"=>$counter,
          "unread"=>$row["unread"]??null,
          "eid"=>substr($eid,0,60),
          "title"=>$chat["name"]??$row["title"]??null,
        ], JSON_UNESCAPED_UNICODE)."\n";
      }
    }
    echo "ol_seen=$n ol_unread=$unread\n";
  }
} catch (Throwable $e) {
  echo "ERR ".$e->getMessage()." ".$e->getFile().":".$e->getLine()."\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_unread_run.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_unread_run.php 2>&1; rm -f /tmp/wa_unread_run.php", timeout=90)
sys.stdout.buffer.write(o.read()[:10000])
sys.stdout.buffer.write(e.read()[:1500])

print("\n=== rest app menu item renderer ===")
_, o, _ = c.exec_command(
    "grep -n 'marketplace/app\\|RestApp\\|APPLICATION' "
    "/home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/MenuItem/*.php "
    "2>/dev/null | head -40; "
    "ls /home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/MenuItem/",
    timeout=20)
sys.stdout.buffer.write(o.read()[:5000])
c.close()
