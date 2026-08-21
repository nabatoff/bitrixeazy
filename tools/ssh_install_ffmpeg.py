#!/usr/bin/env python3
import paramiko
import sys

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=30)

cmds = [
    "uname -m; free -h | head -2; df -h /home/bitrix | tail -1",
    "mkdir -p /home/bitrix/www/local/custom_chat/bin /home/bitrix/www/upload/wa_cc_audio_cache",
    # download static ffmpeg if missing
    """
if [ -x /home/bitrix/www/local/custom_chat/bin/ffmpeg ]; then
  echo HAVE_FFMPEG
  /home/bitrix/www/local/custom_chat/bin/ffmpeg -version | head -1
else
  cd /tmp
  rm -rf ffmpeg-static ffmpeg-release-amd64-static.tar.xz
  wget -q -O ffmpeg-release-amd64-static.tar.xz https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz && echo WGET_OK || curl -fsSL -o ffmpeg-release-amd64-static.tar.xz https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz && echo CURL_OK
  tar -xf ffmpeg-release-amd64-static.tar.xz
  DIR=$(ls -d ffmpeg-*-amd64-static | head -1)
  cp "$DIR/ffmpeg" /home/bitrix/www/local/custom_chat/bin/ffmpeg
  chmod +x /home/bitrix/www/local/custom_chat/bin/ffmpeg
  /home/bitrix/www/local/custom_chat/bin/ffmpeg -version | head -1
  # quick convert test
  SRC=/home/bitrix/www/upload/imconnector/4fe/v01o1l6gc8e5eqs0d6pnzqx7wpttqk1v/f882e44f-181d-4410-8908-80815c2304dc.oga
  OUT=/tmp/wa_test.mp3
  /home/bitrix/www/local/custom_chat/bin/ffmpeg -y -i "$SRC" -vn -acodec libmp3lame -aq 4 "$OUT" 2>&1 | tail -5
  ls -la "$OUT"
fi
""",
]
for cmd in cmds:
    print("=== CMD ===")
    _, o, e = c.exec_command(cmd, timeout=300)
    print(o.read().decode("utf-8", "replace"))
    err = e.read().decode("utf-8", "replace")
    if err.strip():
        print("ERR", err[:1000])
c.close()
