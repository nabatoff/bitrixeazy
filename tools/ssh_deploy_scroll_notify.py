#!/usr/bin/env python3
import os
import sys
import time
import paramiko

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1]
BASE_LOCAL = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "local", "custom_chat"))
BASE_REMOTE = "/home/bitrix/www/local/custom_chat"
STAMP = time.strftime("%Y%m%d_%H%M%S")
FILES = [
    "index.php",
    "include_crm_button.php",
    "include_portal_widget.php",
    "portal_unread.php",
    "portal_widget.js",
    "portal_widget.css",
    "img/wa-menu.svg",
]

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=25)
sftp = c.open_sftp()

def mkdir_p(path):
    parts = path.strip("/").split("/")
    cur = ""
    for p in parts:
        cur += "/" + p
        try:
            sftp.stat(cur)
        except IOError:
            sftp.mkdir(cur)

mkdir_p(BASE_REMOTE + "/img")

for rel in FILES:
    local = os.path.join(BASE_LOCAL, rel.replace("/", os.sep))
    remote = BASE_REMOTE + "/" + rel
    try:
        sftp.stat(remote)
        bak = remote + ".bak_scroll_" + STAMP
        _, o, _ = c.exec_command("cp -a %s %s" % (remote, bak))
        o.channel.recv_exit_status()
        print("bak", bak)
    except IOError:
        pass
    sftp.put(local, remote)
    st = sftp.stat(remote)
    print("ok", rel, st.st_size)
sftp.close()

cmd = (
    "php -l /home/bitrix/www/local/custom_chat/index.php; "
    "php -l /home/bitrix/www/local/custom_chat/include_crm_button.php; "
    "php -l /home/bitrix/www/local/custom_chat/include_portal_widget.php; "
    "php -l /home/bitrix/www/local/custom_chat/portal_unread.php; "
    "grep -n 'include_portal_widget\\|waCcOnEpilogPortalWidget' /home/bitrix/www/local/custom_chat/include_crm_button.php; "
    "grep -c 'scheduleOpenScrollKeep\\|min-height: 0' /home/bitrix/www/local/custom_chat/index.php; "
    "ls -la /home/bitrix/www/local/custom_chat/portal_widget.js "
    "/home/bitrix/www/local/custom_chat/img/wa-menu.svg; "
    "grep -n include_crm_button /home/bitrix/www/bitrix/php_interface/init.php"
)
_, stdout, stderr = c.exec_command(cmd, timeout=60)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR", err[:2000])
c.close()
