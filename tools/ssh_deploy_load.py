#!/usr/bin/env python3
import os
import sys
import paramiko

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))

FILES = [
    ("local/custom_chat/portal_unread.php", "/home/bitrix/www/local/custom_chat/portal_unread.php"),
    ("local/custom_chat/portal_widget.js", "/home/bitrix/www/local/custom_chat/portal_widget.js"),
    ("local/custom_chat/ajax_ticks.php", "/home/bitrix/www/local/custom_chat/ajax_ticks.php"),
    ("local/custom_chat/ajax_ffmpeg.php", "/home/bitrix/www/local/custom_chat/ajax_ffmpeg.php"),
    ("local/custom_chat/ajax_wa_lines.php", "/home/bitrix/www/local/custom_chat/ajax_wa_lines.php"),
    ("local/custom_chat/ajax_wa_attach.php", "/home/bitrix/www/local/custom_chat/ajax_wa_attach.php"),
    ("local/custom_chat/ajax_wa_start.php", "/home/bitrix/www/local/custom_chat/ajax_wa_start.php"),
    ("local/custom_chat/index.php", "/home/bitrix/www/local/custom_chat/index.php"),
    ("local/custom_chat/mobile.php", "/home/bitrix/www/local/custom_chat/mobile.php"),
    ("local/custom_chat/app/wa_ticks.php", "/home/bitrix/www/local/custom_chat/app/wa_ticks.php"),
    ("local/crm/kanban_deal_paint.js", "/home/bitrix/www/local/crm/kanban_deal_paint.js"),
    ("local/crm/kanban_deal_paint_ajax.php", "/home/bitrix/www/local/crm/kanban_deal_paint_ajax.php"),
]

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=25)
sftp = c.open_sftp()
for rel, remote in FILES:
    local = os.path.join(ROOT, rel.replace("/", os.sep))
    sftp.put(local, remote)
    print("ok", rel)
sftp.close()

php = r"""<?php
$files = [
  '/home/bitrix/www/local/custom_chat/portal_unread.php',
  '/home/bitrix/www/local/custom_chat/ajax_ticks.php',
  '/home/bitrix/www/local/custom_chat/ajax_ffmpeg.php',
  '/home/bitrix/www/local/custom_chat/ajax_wa_lines.php',
  '/home/bitrix/www/local/custom_chat/ajax_wa_attach.php',
  '/home/bitrix/www/local/custom_chat/ajax_wa_start.php',
  '/home/bitrix/www/local/custom_chat/mobile.php',
  '/home/bitrix/www/local/custom_chat/app/wa_ticks.php',
  '/home/bitrix/www/local/crm/kanban_deal_paint_ajax.php',
];
foreach ($files as $f) {
  $out = [];
  $code = 0;
  exec('php -l '.escapeshellarg($f).' 2>&1', $out, $code);
  echo ($code === 0 ? 'OK ' : 'FAIL ').basename($f).' '.implode(' ', $out)."\n";
}
echo 'ticks_fn='.(is_file('/home/bitrix/www/local/custom_chat/ajax_ticks.php')?'1':'0')."\n";
"""
sftp = c.open_sftp()
with sftp.file("/tmp/wa_load_lint.php", "w") as f:
    f.write(php)
sftp.close()
_, stdout, stderr = c.exec_command("php /tmp/wa_load_lint.php 2>&1", timeout=30)
print(stdout.read().decode("utf-8", errors="replace"))
err = stderr.read().decode("utf-8", errors="replace")
if err:
    print(err)
c.exec_command("rm -f /tmp/wa_load_lint.php")
c.close()
