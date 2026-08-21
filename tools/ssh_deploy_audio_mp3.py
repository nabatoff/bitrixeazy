#!/usr/bin/env python3
import os
import paramiko
import sys

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1]
BASE_LOCAL = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "local", "custom_chat"))
BASE_REMOTE = "/home/bitrix/www/local/custom_chat"

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=20)
sftp = c.open_sftp()
sftp.put(os.path.join(BASE_LOCAL, "index.php"), BASE_REMOTE + "/index.php")
print("ok index.php")
sftp.close()

php = r"""<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
// load helpers from index without running the page: include only functions by requiring after defining GET
$_GET['fmt'] = 'mp3';
$_SERVER['HTTP_USER_AGENT'] = 'BitrixMobile/test';
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

// Pull function defs by including a sliced approach: require mobile helpers file inline
$src = file_get_contents('/home/bitrix/www/local/custom_chat/index.php');
// Extract only our new functions by requiring through a bootstrap
function waCcFfmpegPath() {
  $local = '/home/bitrix/www/local/custom_chat/bin/ffmpeg';
  return is_executable($local) ? $local : '';
}
function waCcAudioCacheDir() {
  $dir = '/home/bitrix/www/upload/wa_cc_audio_cache';
  if (!is_dir($dir)) mkdir($dir, 0775, true);
  return $dir;
}
function waCcTranscodeAudioToMp3($absPath, $fileId) {
  $ffmpeg = waCcFfmpegPath();
  $mtime = (int)@filemtime($absPath);
  $cache = waCcAudioCacheDir() . '/f' . (int)$fileId . '_' . $mtime . '.mp3';
  if (is_file($cache) && filesize($cache) > 64) { echo "cache_hit=$cache\n"; return $cache; }
  $tmp = $cache . '.tmp';
  $cmd = escapeshellarg($ffmpeg).' -hide_banner -loglevel error -y -i '.escapeshellarg($absPath).' -vn -acodec libmp3lame -aq 4 '.escapeshellarg($tmp);
  exec($cmd, $o, $code);
  echo "code=$code\n";
  if ($code===0 && is_file($tmp)) { rename($tmp, $cache); echo "ok size=".filesize($cache)."\n"; return $cache; }
  echo "fail\n"; return null;
}
$abs = '/home/bitrix/www/upload/imconnector/4fe/v01o1l6gc8e5eqs0d6pnzqx7wpttqk1v/f882e44f-181d-4410-8908-80815c2304dc.oga';
echo 'ffmpeg='.waCcFfmpegPath()."\n";
waCcTranscodeAudioToMp3($abs, 3748162);
echo 'fmt_in_index='.(strpos(file_get_contents('/home/bitrix/www/local/custom_chat/index.php'),'waCcTranscodeAudioToMp3')!==false?'Y':'N')."\n";
echo 'js_fmt='.(strpos(file_get_contents('/home/bitrix/www/local/custom_chat/index.php'),"fmt', 'mp3'")!==false?'Y':'N')."\n";
"""
sftp = c.open_sftp()
with sftp.file("/tmp/verify_mp3.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/verify_mp3.php 2>&1", timeout=60)
print(o.read().decode("utf-8", "replace"))
c.exec_command("rm -f /tmp/verify_mp3.php")
c.close()
