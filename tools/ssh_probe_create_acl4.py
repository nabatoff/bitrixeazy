#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

php=r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
$conn=\Bitrix\Main\Application::getConnection();

echo "==== C17 TODAY CREATE vs PREPAY vs LEAD ====\n";
$sql="SELECT d.ID, d.DATE_CREATE, d.CREATED_BY_ID, d.LEAD_ID, d.STAGE_ID,
  u.UF_CRM_1764332847245 PREPAY, u.UF_CRM_1784636341021 INV, u.UF_CRM_1783486791226 PURCH,
  u.UF_CRM_1785326361467 ACC_TAKEN, u.UF_CRM_1785324070 ACC_EMP
FROM b_crm_deal d
LEFT JOIN b_uts_crm_deal u ON u.VALUE_ID=d.ID
WHERE d.DATE_CREATE >= '2026-08-24 00:00:00' AND d.CATEGORY_ID=17
ORDER BY d.ID DESC LIMIT 30";
foreach($conn->query($sql) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";

echo "\n==== CREATED_BY depts of those with PREPAY=1 ====\n";
$sql="SELECT d.CREATED_BY_ID, COUNT(*) CNT,
  SUM(u.UF_CRM_1764332847245 IN ('1','Y')) PREPAY_YES
FROM b_crm_deal d
LEFT JOIN b_uts_crm_deal u ON u.VALUE_ID=d.ID
WHERE d.DATE_CREATE >= '2026-08-24 00:00:00' AND d.CATEGORY_ID=17
GROUP BY d.CREATED_BY_ID";
foreach($conn->query($sql) as $r){
  $uid=(int)$r["CREATED_BY_ID"];
  $row=CUser::GetByID($uid)->Fetch();
  $dept=$row["UF_DEPARTMENT"]??null;
  echo "user=$uid ".$row["NAME"]." ".$row["LAST_NAME"]." dept=".json_encode($dept)." cnt=".$r["CNT"]." prepayYes=".$r["PREPAY_YES"]."\n";
}

echo "\n==== ORM Add handlers ====\n";
$em=\Bitrix\Main\EventManager::getInstance();
foreach(["\\Bitrix\\Crm\\DealTable::OnBeforeAdd","\\Bitrix\\Crm\\DealTable::OnAfterAdd","\\Bitrix\\Crm\\DealTable::OnBeforeUpdate"] as $ev){
  $h=$em->findEventHandlers("crm", $ev);
  echo $ev." n=".count($h)." ".json_encode($h)."\n";
}

echo "\n==== Operation\\\\Add events from reflection ====\n";
$f="/home/bitrix/www/bitrix/modules/crm/lib/service/operation/add.php";
echo "exists=".is_file($f)."\n";
'''
sftp=c.open_sftp();
with sftp.file("/tmp/wa_create_acl4.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_create_acl4.php 2>&1")
print(o.read().decode("utf-8","replace")[:12000])

_,o,_=c.exec_command("grep -n 'OnBeforeCrmDealAdd\\|userField\\|UF_' /home/bitrix/www/bitrix/modules/crm/lib/service/operation/add.php | head -40")
print("===== Operation/Add.php =====")
print(o.read().decode("utf-8","replace"))

_,o,_=c.exec_command("grep -n 'mapEntityFields\\|USER_FIELD\\|getUserFields\\|UF_CRM' /home/bitrix/www/bitrix/modules/crm/lib/conversion/leadconverter.php | head -40")
print("===== LeadConverter =====")
print(o.read().decode("utf-8","replace"))

_,o,_=c.exec_command("grep -n 'mapEntityFields\\|userFieldMap\\|UF_' /home/bitrix/www/bitrix/modules/crm/lib/conversion/entityconverter.php | head -40")
print("===== EntityConverter =====")
print(o.read().decode("utf-8","replace"))

_,o,_=c.exec_command("head -80 /home/bitrix/www/local/admin/dealstageguard_fields.php")
print("===== admin fields php head =====")
print(o.read().decode("utf-8","replace")[:3500])

c.exec_command("rm -f /tmp/wa_create_acl4.php")
c.close()
