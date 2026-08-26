#!/usr/bin/env python3
"""Fix init.php include for vox CID fix."""
import paramiko
import sys

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
HOST = "crm.artflowers.kz"
USER = "bitrix"
INIT = "/home/bitrix/www/bitrix/php_interface/init.php"
BLOCK = """
$waVoxCidFix = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_vox_cid_fix.php';
if (is_file($waVoxCidFix)) {
    require_once $waVoxCidFix;
}
"""

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, key_filename=KEY, timeout=30)
sftp = client.open_sftp()
with sftp.open(INIT, "r") as f:
    text = f.read().decode("utf-8", errors="replace")

# Remove corrupted lines
lines = text.splitlines(keepends=True)
clean = []
for line in lines:
    if "include_vox_cid_fix" in line or line.strip() in (
        r"\ = \[\ DOCUMENT_ROOT\] . \/local/crm/include_vox_cid_fix.php\;",
        r"if (is_file(\)) { require_once \; }",
    ):
        continue
    if line.strip().startswith(r"\ = \[\ DOCUMENT_ROOT\]"):
        continue
    if "is_file(\\))" in line:
        continue
    clean.append(line)

text = "".join(clean).rstrip() + "\n"
if "include_vox_cid_fix.php" not in text:
    text += "\n" + BLOCK.strip() + "\n"

with sftp.open(INIT, "w") as f:
    f.write(text.encode("utf-8"))
sftp.close()

_, stdout, _ = client.exec_command(f"tail -8 {INIT}")
print(stdout.read().decode())
client.close()
print("init.php fixed")
