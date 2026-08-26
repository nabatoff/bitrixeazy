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

$opt = "left_menu_items_marketplace_s1";
$raw = COption::GetOptionString("intranet", $opt, "");
$arr = unserialize($raw, ["allowed_classes"=>false]);
echo "count=".count($arr)."\n";
$changed=false;
foreach ($arr as $k=>&$it) {
  if (!is_array($it)) { echo "not array $k ".gettype($it)."\n"; continue; }
  $link = (string)($it["LINK"] ?? "");
  $text = (string)($it["TEXT"] ?? "");
  echo "k=$k link=$link text=$text cid=".($it["COUNTER_ID"]??"")."\n";
  if ($link === "/marketplace/app/64/" || strpos($link, "/marketplace/app/64")!==false || $text === "Ватсап чат") {
    $it["COUNTER_ID"] = "wa_cc_unread";
    $changed = true;
    echo "HIT $k\n";
  }
}
unset($it);
echo "changed=".($changed?"1":"0")."\n";
if ($changed) {
  $ok = COption::SetOptionString("intranet", $opt, serialize($arr));
  echo "set=".($ok?"1":"0")."\n";
}
$raw2 = COption::GetOptionString("intranet", $opt, "");
$arr2 = unserialize($raw2, ["allowed_classes"=>false]);
foreach ($arr2 as $it) {
  if (($it["LINK"]??"") === "/marketplace/app/64/") {
    echo "after=".json_encode($it, JSON_UNESCAPED_UNICODE)."\n";
  }
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_set_cid.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_set_cid.php 2>&1; rm -f /tmp/wa_set_cid.php", timeout=40)
sys.stdout.buffer.write(o.read()[:8000])
sys.stdout.buffer.write(e.read()[:1500])
c.close()
