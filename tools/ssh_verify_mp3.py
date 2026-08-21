#!/usr/bin/env python3
import paramiko
import sys

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=20)

_, o, _ = c.exec_command(
    "grep -n 'part.mp3\\|waCcTranscodeAudioToMp3\\|fmt., .mp3' /home/bitrix/www/local/custom_chat/index.php | head -10"
)
print("grep:", o.read().decode())

php = """<?php
$ffmpeg = '/home/bitrix/www/local/custom_chat/bin/ffmpeg';
$abs = '/home/bitrix/www/upload/imconnector/4fe/v01o1l6gc8e5eqs0d6pnzqx7wpttqk1v/f882e44f-181d-4410-8908-80815c2304dc.oga';
$dir = '/home/bitrix/www/upload/wa_cc_audio_cache';
@mkdir($dir, 0775, true);
$mtime = filemtime($abs);
$cache = $dir . '/f3748162_' . $mtime . '.mp3';
$tmp = $cache . '.part.mp3';
$cmd = escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($abs)
    . ' -vn -acodec libmp3lame -aq 4 ' . escapeshellarg($tmp);
exec($cmd, $out, $code);
echo "code=$code\\n";
if (is_file($tmp)) {
    rename($tmp, $cache);
    echo 'size=' . filesize($cache) . "\\n";
} else {
    echo "no tmp\\n";
}
"""

sftp = c.open_sftp()
with sftp.file("/tmp/vmp3.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/vmp3.php 2>&1", timeout=60)
print(o.read().decode("utf-8", "replace"))
c.exec_command("rm -f /tmp/vmp3.php")
c.close()
