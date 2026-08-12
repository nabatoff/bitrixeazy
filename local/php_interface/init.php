<?php
/**
 * НЕ заливай этот файл поверх bitrix/php_interface/init.php!
 * У тебя init лежит в bitrix/php_interface/ — туда только строки ниже.
 *
 * В КОНЕЦ своего init.php:
 *
 *   $waCc = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/include_crm_button.php';
 *   if (is_file($waCc)) {
 *       require_once $waCc;
 *   }
 *
 *   $olLeads = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/include_ol_line_leads.php';
 *   if (is_file($olLeads)) {
 *       require_once $olLeads;
 *   }
 */
