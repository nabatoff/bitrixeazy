#!/usr/bin/env python3
"""Read-only probe: deal create ACL hole (Add vs Update field permissions)."""
import os, sys, paramiko
PASSWORD = sys.argv[1]
ROOT = os.path.dirname(__file__)
OUT = os.path.join(ROOT, "_create_acl.txt")
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = []

cmds.append(("FILES", r"""ls -la /home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php /home/bitrix/www/bitrix/php_interface/admin/dealstageguard_fields.php /home/bitrix/www/local/crm/deal_uf_lock.js /home/bitrix/www/local/crm/include_deal_uf_lock.php 2>&1; ls /home/bitrix/www/bitrix/php_interface/admin/ 2>/dev/null | head; ls /home/bitrix/www/local/crm/"""))

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";

echo "==== DSG OPTION dsg_field_permissions ====\n";
$raw = \Bitrix\Main\Config\Option::get("main", "dsg_field_permissions", "");
echo "len=".strlen($raw)."\n";
$cfg = json_decode($raw, true);
if(!is_array($cfg)){ echo "NOT JSON\n"; echo substr($raw,0,500)."\n"; }
else {
  echo "fields=".count($cfg)."\n";
  foreach($cfg as $code=>$spec){
    $label = is_array($spec) ? ($spec["label"]??"") : "";
    $users = is_array($spec) ? ($spec["users"]??[]) : [];
    $deps  = is_array($spec) ? ($spec["departments"]??[]) : [];
    echo $code." | ".$label." | users=".json_encode($users)." | deps=".json_encode($deps)."\n";
  }
}

echo "\n==== HANDLERS Deal Add/Update ====\n";
$em = \Bitrix\Main\EventManager::getInstance();
foreach([
  "OnBeforeCrmDealAdd","OnAfterCrmDealAdd","OnBeforeCrmDealUpdate","OnAfterCrmDealUpdate",
  "OnBeforeCrmLeadAdd","OnAfterCrmLeadAdd","OnBeforeCrmLeadUpdate",
] as $ev){
  $list = [];
  foreach(GetModuleEvents("crm", $ev, true) as $h){
    $to = $h["TO_CLASS"]."::".$h["TO_METHOD"];
    if(!$h["TO_CLASS"]) $to = $h["TO_NAME"] ?: $h["CALLBACK"];
    $list[] = $to." sort=".$h["SORT"];
  }
  echo $ev." => ".($list?implode(" | ",$list):"(none)")."\n";
}
foreach([
  "\\Bitrix\\Crm\\DealTable::OnBeforeAdd",
  "\\Bitrix\\Crm\\DealTable::OnAfterAdd",
  "\\Bitrix\\Crm\\DealTable::OnBeforeUpdate",
  "\\Bitrix\\Crm\\Item::onBeforeAdd",
] as $ev){
  echo "ORM $ev registered? (see dump below)\n";
}

echo "\n==== FACTORY / CONVERTER CLASSES ====\n";
foreach([
  "Bitrix\\Crm\\Conversion\\DealConversionWizard",
  "Bitrix\\Crm\\Conversion\\LeadConversionWizard",
  "Bitrix\\Crm\\Conversion\\EntityConversionWizard",
  "CCrmLeadConverter",
  "Bitrix\\Crm\\Service\\Operation\\Add",
  "Bitrix\\Crm\\Service\\Operation\\Update",
] as $cl){
  echo $cl." ".(class_exists($cl)?"YES":"NO")."\n";
}

echo "\n==== USER 69 DEPT vs PERMS (sample manager) ====\n";
$u = CUser::GetByID(69)->Fetch();
echo "69 ".$u["NAME"]." ".$u["LAST_NAME"]." groups=";
$g=[]; $rs=CUser::GetUserGroupList(69); while($x=$rs->Fetch()) $g[]=$x["GROUP_ID"];
echo implode(",",$g)."\n";
$rs=CUser::GetList(($by="id"),($order="asc"),["ID"=>69],["SELECT"=>["UF_DEPARTMENT"]]);
$row=$rs->Fetch();
echo "dept=".json_encode($row["UF_DEPARTMENT"]??null)."\n";

echo "\n==== SAMPLE CREATE DEALS TODAY: prepay at DATE_CREATE ====\n";
$conn=\Bitrix\Main\Application::getConnection();
$sql="SELECT d.ID, d.DATE_CREATE, d.CREATED_BY, d.LEAD_ID, d.STAGE_ID, u.UF_CRM_1764332847245 PREPAY, u.UF_CRM_1785326361467 ACC_TAKEN, u.UF_CRM_1784636341021 INVOICE, u.UF_CRM_1783486791226 PURCH
FROM b_crm_deal d
LEFT JOIN b_uts_crm_deal u ON u.VALUE_ID=d.ID
WHERE d.DATE_CREATE >= '2026-08-24 00:00:00' AND d.CATEGORY_ID=17
ORDER BY d.ID DESC LIMIT 25";
try {
  foreach($conn->query($sql) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n==== LEAD_ID filled among C17 today ====\n";
try {
  $r=$conn->query("SELECT SUM(LEAD_ID>0) FROMLEAD, COUNT(*) CNT FROM b_crm_deal WHERE DATE_CREATE>='2026-08-24 00:00:00' AND CATEGORY_ID=17")->fetch();
  echo json_encode($r)."\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; }
'''

sftp = c.open_sftp()
with sftp.file("/tmp/wa_create_acl.php", "w") as f:
    f.write(php)
sftp.close()

chunks = []
_, o, _ = c.exec_command(cmds[0][1])
chunks.append("==== FILES ====\n" + o.read().decode("utf-8", "replace"))

_, o, _ = c.exec_command("php /tmp/wa_create_acl.php 2>&1")
chunks.append(o.read().decode("utf-8", "replace"))

# extract functions from DealStageGuard
_, o, _ = c.exec_command(r"""python3 - <<'PY'
from pathlib import Path
text=Path('/home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php').read_text(encoding='utf-8', errors='replace')
print('bytes', len(text), 'lines', text.count('\n')+1)
print('\n===== register() add hooks snippet =====')
i=text.find('public static function register')
print(text[i:i+4200])
print('\n===== evaluateFieldEditPermissions FULL =====')
i=text.find('function evaluateFieldEditPermissions')
print(text[i:i+7800])
print('\n===== onBeforeAddLegacy + onBeforeAddOrm =====')
i=text.find('function onBeforeAddLegacy')
print(text[i:i+2800])
print('\n===== printReloadListener field lock JS? =====')
print('dealstageguard in js', 'dsg_field' in text, 'disabled' in text.lower())
PY""")
chunks.append("\n==== DSG PHP EXTRACT ====\n" + o.read().decode("utf-8", "replace"))

_, o, _ = c.exec_command("wc -l /home/bitrix/www/bitrix/php_interface/admin/dealstageguard_fields.php 2>/dev/null; head -c 2500 /home/bitrix/www/bitrix/php_interface/admin/dealstageguard_fields.php 2>/dev/null; echo; ls /home/bitrix/www/local/crm/; echo '--- lock js ---'; wc -l /home/bitrix/www/local/crm/deal_uf_lock.js 2>/dev/null; grep -n -E 'create|Add|details|disabled|readonly' /home/bitrix/www/local/crm/deal_uf_lock.js 2>/dev/null | head -40")
chunks.append("\n==== ADMIN UI + JS LOCK ====\n" + o.read().decode("utf-8", "replace"))

_, o, _ = c.exec_command(r"""python3 - <<'PY'
from pathlib import Path
p=Path('/home/bitrix/www/local/crm/deal_uf_lock.js')
print('exists', p.exists(), 'size', p.stat().st_size if p.exists() else 0)
if p.exists():
    t=p.read_text(encoding='utf-8', errors='replace')
    print(t[:4000])
print('\n===== grep DSG js inject =====')
g=Path('/home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php').read_text(encoding='utf-8', errors='replace')
for key in ['field-permissions','disabled','readonly','EDITOR','entity-editor','create']:
    print(key, g.lower().count(key.lower()) if False else g.lower().count(key.lower()))
# find JS related to field permissions in DSG
idx=0
n=0
low=g.lower()
while n<15:
    j=low.find('field-permissions', idx)
    if j<0: break
    line=g[:j].count('\n')+1
    print('L',line, g[j:j+160].replace('\n',' | '))
    idx=j+1; n+=1
PY""")
chunks.append("\n==== LOCK JS + DSG JS MENTIONS ====\n" + o.read().decode("utf-8", "replace"))

_, o, _ = c.exec_command("ls /home/bitrix/www/bitrix/php_interface/; echo '---'; grep -n DealStageGuard /home/bitrix/www/bitrix/php_interface/init.php; echo '--- other field js ---'; ls /home/bitrix/www/local/crm/*.js 2>/dev/null; grep -l -r 'dsg_field\\|evaluateFieldEdit\\|field-permissions' /home/bitrix/www/local /home/bitrix/www/bitrix/php_interface --include='*.js' --include='*.php' 2>/dev/null | head")
chunks.append("\n==== INIT + JS FILES ====\n" + o.read().decode("utf-8", "replace"))

text = "\n".join(chunks)
open(OUT, "w", encoding="utf-8").write(text)
print(text[:18000])
print("\n... wrote", OUT, "total", len(text))
c.exec_command("rm -f /tmp/wa_create_acl.php")
c.close()
