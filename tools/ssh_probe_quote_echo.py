#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("im");
require_once "/home/bitrix/www/local/custom_chat/app/wa_ticks.php";

/* 1) какие webhook-настройки у инстанса линии 49 */
$cred = waCcTicksCredForLine(49);
if (!$cred) { echo "no cred line 49\n"; }
else {
  $url = rtrim($cred["apiUrl"], "/") . "/waInstance" . $cred["idInstance"] . "/getSettings/" . $cred["apiTokenInstance"];
  $ctx = stream_context_create(["http" => ["timeout" => 12, "ignore_errors" => true]]);
  $raw = @file_get_contents($url, false, $ctx);
  $d = json_decode((string)$raw, true);
  if (is_array($d)) {
    foreach ($d as $k => $v) {
      if (stripos($k, "webhook") !== false || stripos($k, "Url") !== false) {
        if (stripos($k, "Token") !== false) { continue; }
        $val = is_scalar($v) ? (string)$v : json_encode($v);
        if (stripos($k, "webhookUrl") !== false) { $val = $val === "" ? "(empty)" : "(set)"; }
        echo "  $k = $val\n";
      }
    }
  } else {
    echo "getSettings failed\n";
  }
}

/* 2) последние сообщения чата, где шли тесты */
$conn = \Bitrix\Main\Application::getConnection();
echo "\n=== last msgs in LINES chats (15:5x) ===\n";
$r = $conn->query("SELECT m.ID, m.CHAT_ID, m.AUTHOR_ID, m.DATE_CREATE, LEFT(m.MESSAGE,40) MSG
  FROM b_im_message m INNER JOIN b_im_chat c ON c.ID=m.CHAT_ID
  WHERE c.ENTITY_TYPE='LINES' AND m.DATE_CREATE > DATE_SUB(NOW(), INTERVAL 40 MINUTE)
  ORDER BY m.ID DESC LIMIT 15");
while ($x = $r->fetch()) {
  echo $x["ID"]." chat=".$x["CHAT_ID"]." author=".$x["AUTHOR_ID"]." ".$x["DATE_CREATE"]." ".$x["MSG"]."\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_echo.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_echo.php 2>&1; rm -f /tmp/wa_echo.php", timeout=90)
sys.stdout.buffer.write(o.read()[:8000])
sys.stdout.buffer.write(e.read()[:2000])

grep = (
    "grep -rn 'SKIP_CONNECTOR\\|SILENT_CONNECTOR' "
    "/home/bitrix/www/bitrix/modules/im/lib/ /home/bitrix/www/bitrix/modules/im/classes/ "
    "/home/bitrix/www/bitrix/modules/imopenlines/lib/ 2>/dev/null | head -20"
)
_, o2, _ = c.exec_command(grep, timeout=60)
print("\n=== SKIP_CONNECTOR support ===")
sys.stdout.buffer.write(o2.read()[:4000])
c.close()
