#!/usr/bin/env python3
"""Read-only: map accountant/buyer/warehouse UF fields on deals."""
import sys, paramiko, json
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true);
define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
global $USER_FIELD_MANAGER;
$fields = $USER_FIELD_MANAGER->GetUserFields("CRM_DEAL", 0, LANGUAGE_ID);
$need = ["бухгалтер","закуп","клад","счет","предоплат","выдан","взят","работ","маркир"];
foreach ($fields as $id => $f) {
  $label = "";
  foreach (["EDIT_FORM_LABEL","LIST_COLUMN_LABEL","LIST_FILTER_LABEL"] as $k) {
    if (!empty($f[$k]) && is_array($f[$k])) {
      $label = (string)($f[$k]["ru"] ?? reset($f[$k]) ?: "");
      if ($label !== "") break;
    } elseif (!empty($f[$k]) && is_string($f[$k])) {
      $label = $f[$k]; break;
    }
  }
  $hay = mb_strtolower($id." ".$label);
  $hit = false;
  foreach ($need as $n) {
    if (mb_strpos($hay, $n) !== false) { $hit = true; break; }
  }
  // also known IDs from prior work
  $known = [
    "UF_CRM_1782797106378","UF_CRM_1785324070","UF_CRM_1785325552","UF_CRM_1787123117",
    "UF_CRM_1764332847245","UF_CRM_1783486791226","UF_CRM_1764577842986","UF_CRM_1784524115744",
  ];
  if (!$hit && !in_array($id, $known, true)) continue;
  $type = $f["USER_TYPE_ID"] ?? "?";
  echo $id." | ".$type." | ".$label;
  if ($type === "enumeration") {
    $enums = [];
    $rs = CUserFieldEnum::GetList([], ["USER_FIELD_ID"=>(int)$f["ID"]]);
    while ($e = $rs->Fetch()) {
      $enums[] = $e["ID"]."=".$e["VALUE"];
    }
    echo " | enums: ".implode(", ", $enums);
  }
  echo "\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_uf_map.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/wa_uf_map.php 2>&1", timeout=60)
print(o.read().decode("utf-8", "replace"))
c.exec_command("rm -f /tmp/wa_uf_map.php")

# check if BP already handles "taken into work"
_, o, _ = c.exec_command("grep -rn '1785324070\\|1785325552\\|1787123117\\|взято в работу\\|UF_CRM_1782797106378' /home/bitrix/www/local /home/bitrix/www/bitrix/php_interface --include='*.php' 2>/dev/null | head -40")
print("==== REFS ====")
print(o.read().decode("utf-8", "replace")[:4000])
c.close()
