#!/usr/bin/env python3
"""Deploy ticks fix + unit-test apply logic on server (no live WhatsApp)."""
import os, sys, paramiko, textwrap

PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
FILES = [
    ("local/custom_chat/app/wa_ticks.php", "/home/bitrix/www/local/custom_chat/app/wa_ticks.php"),
    ("local/custom_chat/index.php", "/home/bitrix/www/local/custom_chat/index.php"),
]

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
sftp = c.open_sftp()
# backup store
try:
    sftp.get("/home/bitrix/www/local/custom_chat/var/wa_ticks.json", os.path.join(ROOT, "tools", "_wa_ticks_backup.json"))
    print("backed up wa_ticks.json locally to tools/_wa_ticks_backup.json")
except Exception as e:
    print("backup skip", e)

for rel, rem in FILES:
    sftp.put(os.path.join(ROOT, rel.replace("/", os.sep)), rem)
    print("up", rem, sftp.stat(rem).st_size)
sftp.close()

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
$src = file_get_contents("/home/bitrix/www/local/custom_chat/app/wa_ticks.php");
$src = str_replace(
  "return $dir . '/wa_ticks.json';",
  "return '/tmp/wa_ticks_unit_test.json';",
  $src
);
file_put_contents("/tmp/wa_ticks_unit_lib.php", $src);
@unlink("/tmp/wa_ticks_unit_test.json");
require "/tmp/wa_ticks_unit_lib.php";

$chat="77001112233@c.us";
waCcTicksApplyStatus($chat, "read", 1000, "MSG_OLD");
$row=waCcTicksBestForKeys(["77001112233"]);
echo "1 status={$row['status']} readTs={$row['readTs']} ts={$row['ts']}\n";

waCcTicksApplyStatus($chat, "delivered", 2000, "MSG_NEW");
$row=waCcTicksBestForKeys(["77001112233"]);
echo "2 status={$row['status']} readTs={$row['readTs']} ts={$row['ts']} id={$row['idMessage']}\n";
$ok = ($row["status"]==="delivered" && (int)$row["readTs"]===1000 && (int)$row["ts"]===2000);
echo $ok ? "PASS_downgrade\n" : "FAIL_downgrade\n";

waCcTicksApplyStatus($chat, "read", 2000, "MSG_NEW");
$row=waCcTicksBestForKeys(["77001112233"]);
echo "3 status={$row['status']} readTs={$row['readTs']}\n";
$ok2 = ($row["status"]==="read" && (int)$row["readTs"]===2000);
echo $ok2 ? "PASS_reread\n" : "FAIL_reread\n";

passthru("php -l /home/bitrix/www/local/custom_chat/app/wa_ticks.php");
@unlink("/tmp/wa_ticks_unit_test.json");
@unlink("/tmp/wa_ticks_unit_lib.php");
'''

sftp = c.open_sftp()
with sftp.file("/tmp/wa_ticks_unit.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/wa_ticks_unit.php 2>&1", timeout=30)
print(o.read().decode("utf-8", "replace"))
# grep UI markers
_, o, _ = c.exec_command("grep -n 'waChatReadTs\\|markLocalOutgoingPending\\|readTs' /home/bitrix/www/local/custom_chat/index.php | head -25")
print(o.read().decode("utf-8", "replace"))
c.exec_command("rm -f /tmp/wa_ticks_unit.php")
c.close()
print("DONE")
