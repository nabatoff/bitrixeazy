#!/usr/bin/env python3
import sys, paramiko, os
PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
out = os.path.join(ROOT, "tools", "_history_probe.txt")
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

cmds_file = r'''
grep -n "getTrackedRegularFieldNames\|FIELD_NAME_\|UF_" /home/bitrix/www/bitrix/modules/crm/lib/Service/EventHistory/TrackedObject/Item.php | head -60
echo "==== ITEM SNIP ===="
sed -n "1,200p" /home/bitrix/www/bitrix/modules/crm/lib/Service/EventHistory/TrackedObject/Item.php
echo "==== FACTORY ===="
grep -n "getTracked\|EventHistory\|createTrackedObject" /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory.php | head -40
echo "==== SETTINGS ===="
grep -rn "isEntityEvent\|HistorySettings\|modification" /home/bitrix/www/bitrix/modules/crm/lib/Settings/HistorySettings.php 2>/dev/null | head -30
sed -n "1,120p" /home/bitrix/www/bitrix/modules/crm/lib/Settings/HistorySettings.php 2>/dev/null
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_hist.sh", "w") as f:
    f.write(cmds_file)
sftp.close()
_, o, _ = c.exec_command("bash /tmp/wa_hist.sh > /tmp/wa_hist_out.txt 2>&1; wc -c /tmp/wa_hist_out.txt", timeout=40)
print(o.read().decode())
sftp = c.open_sftp()
sftp.get("/tmp/wa_hist_out.txt", out)
sftp.close()

# DB events sample via php writing utf8 file
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
global $DB;
$fp=fopen("/tmp/wa_crm_events.txt","w");
$r=$DB->Query("SELECT e.ID, e.EVENT_NAME, e.EVENT_TEXT_1, e.EVENT_TEXT_2, e.CREATED_BY_ID, e.DATE_CREATE, r.ENTITY_ID FROM b_crm_event e LEFT JOIN b_crm_event_relations r ON r.EVENT_ID=e.ID WHERE r.ENTITY_TYPE='DEAL' ORDER BY e.ID DESC LIMIT 20");
while($row=$r->Fetch()){
  fwrite($fp, $row["ID"]."\tdeal=".$row["ENTITY_ID"]."\tu=".$row["CREATED_BY_ID"]."\t".$row["EVENT_NAME"]."\t".str_replace(["\r","\n"]," ",(string)$row["EVENT_TEXT_1"])."\t".str_replace(["\r","\n"]," ",(string)$row["EVENT_TEXT_2"])."\n");
}
fclose($fp);
echo "ok\n";
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_ev.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_ev.php 2>&1", timeout=40)
print(o.read().decode("utf-8","replace")[:500])
sftp=c.open_sftp()
sftp.get("/tmp/wa_crm_events.txt", os.path.join(ROOT,"tools","_crm_events.txt"))
sftp.close()
c.exec_command("rm -f /tmp/wa_hist.sh /tmp/wa_hist_out.txt /tmp/wa_ev.php /tmp/wa_crm_events.txt")
c.close()
print("files ready")
