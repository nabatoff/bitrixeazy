#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("im");
\Bitrix\Main\Loader::includeModule("imopenlines");

$uid = 1;
$conn = \Bitrix\Main\Application::getConnection();
$r = $conn->query("SELECT USER_ID, COUNT(*) C FROM b_im_recent WHERE ITEM_TYPE='L' GROUP BY USER_ID ORDER BY C DESC LIMIT 5");
echo "=== users with L recent ===\n";
while ($x=$r->fetch()) echo json_encode($x)."\n";

$r = $conn->query("SELECT USER_ID FROM b_user WHERE ACTIVE='Y' AND ID>1 ORDER BY LAST_LOGIN DESC LIMIT 1");
$u = $r->fetch();
if ($u) $uid = (int)$u["USER_ID"];
echo "try uid=$uid\n";

foreach ([1, $uid] as $id) {
  echo "\n=== Recent::getList uid=$id UNREAD_ONLY OL ===\n";
  try {
    $list = \Bitrix\Im\Recent::getList($id, [
      "ONLY_OPENLINES" => "Y",
      "UNREAD_ONLY" => "Y",
      "SHORT_INFO" => "Y",
      "OFFSET" => 0,
      "LIMIT" => 50,
    ]);
    echo "type=".gettype($list)."\n";
    if (is_array($list)) {
      echo "keys=".implode(",", array_keys($list))."\n";
      $items = $list["items"] ?? $list["ITEMS"] ?? (isset($list[0]) ? $list : []);
      if (isset($list["items"]) || isset($list["ITEMS"])) {
        $items = $list["items"] ?? $list["ITEMS"];
      }
      echo "count_items=".(is_array($items)?count($items):0)."\n";
      $i=0;
      if (is_array($items)) {
        foreach ($items as $it) {
          if ($i++>=3) break;
          $blob = is_array($it) ? $it : (method_exists($it,"toArray") ? $it->toArray() : (array)$it);
          $small = [];
          foreach (["id","ID","chat_id","dialog_id","counter","unread","title","type"] as $k) {
            if (is_array($blob) && array_key_exists($k, $blob)) $small[$k]=$blob[$k];
          }
          if (!$small && is_array($blob)) {
            $small = array_slice($blob, 0, 8, true);
          }
          echo json_encode($small, JSON_UNESCAPED_UNICODE)."\n";
          echo "all_keys=".implode(",", array_keys((array)$blob))."\n";
        }
      }
    } else {
      echo var_export($list, true)."\n";
    }
  } catch (\Throwable $e) {
    echo "ERR ".$e->getMessage()."\n".$e->getFile().":".$e->getLine()."\n";
  }
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_recent_try.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_recent_try.php 2>&1; rm -f /tmp/wa_recent_try.php", timeout=60)
sys.stdout.buffer.write(o.read()[:8000])
sys.stdout.buffer.write(e.read()[:1500])

print("\n=== menu rest app html ===")
_, o, _ = c.exec_command(
    "grep -rn 'marketplace/app\\|menu_app_\\|local.6a7b' "
    "/home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu "
    "/home/bitrix/www/bitrix/modules/rest/lib "
    "2>/dev/null | head -35; "
    "ls /home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/; "
    "grep -n 'menu-item-index\\|updateCounters\\|counters' "
    "/home/bitrix/www/bitrix/js/intranet/*.js "
    "/home/bitrix/www/bitrix/components/bitrix/intranet.menu/templates/.default/*.js "
    "2>/dev/null | head -30",
    timeout=25)
sys.stdout.buffer.write(o.read()[:6000])
c.close()
