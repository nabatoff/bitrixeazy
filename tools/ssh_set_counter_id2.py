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
$conn = \Bitrix\Main\Application::getConnection();
$r = $conn->query("SELECT VALUE FROM b_option WHERE MODULE_ID='intranet' AND NAME='left_menu_items_marketplace_s1'");
$row = $r->fetch();
$raw = $row["VALUE"] ?? "";
echo "db_len=".strlen($raw)." has_counter=".(strpos($raw,"wa_cc_unread")!==false?"1":"0")."\n";
$arr = unserialize($raw, ["allowed_classes"=>false]);
foreach ($arr as $it) {
  if (is_array($it) && (($it["LINK"]??"")==="/marketplace/app/64/")) {
    echo "db_item=".json_encode($it, JSON_UNESCAPED_UNICODE)." keys=".implode(",", array_keys($it))."\n";
  }
}

// force write via SQL if needed
$found=false;
foreach ($arr as &$it) {
  if (is_array($it) && (($it["LINK"]??"")==="/marketplace/app/64/")) {
    $it["COUNTER_ID"] = "wa_cc_unread";
    $found=true;
  }
}
unset($it);
if ($found) {
  $ser = serialize($arr);
  echo "ser_has=".((strpos($ser,"wa_cc_unread")!==false)?"1":"0")." ser_len=".strlen($ser)."\n";
  $esc = $conn->getSqlHelper()->forSql($ser);
  $conn->queryExecute("UPDATE b_option SET VALUE='".$esc."' WHERE MODULE_ID='intranet' AND NAME='left_menu_items_marketplace_s1'");
  COption::SetOptionString("intranet", "left_menu_items_marketplace_s1", $ser);
  // clear option cache
  if (method_exists("COption", "clearCache")) { try { COption::clearCache(); } catch (Throwable $e) {} }
}
$r = $conn->query("SELECT VALUE FROM b_option WHERE MODULE_ID='intranet' AND NAME='left_menu_items_marketplace_s1'");
$row = $r->fetch();
echo "db2_has=".(strpos($row["VALUE"]??"","wa_cc_unread")!==false?"1":"0")."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_cid_db.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_cid_db.php 2>&1; rm -f /tmp/wa_cid_db.php", timeout=40)
sys.stdout.buffer.write(o.read()[:5000])
c.close()
