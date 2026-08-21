#!/usr/bin/env python3
import paramiko, sys, json
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('crm.artflowers.kz', username='bitrix', password=sys.argv[1], timeout=20)
php = """<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
require '/home/bitrix/www/local/custom_chat/app/wa_ticks.php';
$ok = waCcTicksApplyWebhook([
    'typeWebhook' => 'outgoingMessageStatus',
    'status' => 'read',
    'chatId' => '77710888089@c.us',
    'timestamp' => time(),
    'idMessage' => 'probe-test',
]);
echo 'apply=' . ($ok ? '1' : '0') . "\\n";
$path = waCcTicksStorePath();
echo 'path=' . $path . "\\n";
echo 'exists=' . (is_file($path) ? '1' : '0') . "\\n";
$row = waCcTicksBestForKeys(['77710888089', '77710888089@c.us']);
echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\\n";
@unlink($path);
"""
sftp = c.open_sftp()
with sftp.file('/tmp/wa_ticks_test.php','w') as f: f.write(php)
sftp.close()
_, o, _ = c.exec_command('php /tmp/wa_ticks_test.php 2>&1', timeout=20)
print(o.read().decode())
c.exec_command('rm -f /tmp/wa_ticks_test.php')
c.close()
