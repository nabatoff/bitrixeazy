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
 *
 *   $waKanbanPaint = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_kanban_deal_paint.php';
 *   if (is_file($waKanbanPaint)) {
 *       require_once $waKanbanPaint;
 *   }
 *
 *   $waDealUfLock = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_deal_uf_lock.php';
 *   if (is_file($waDealUfLock)) {
 *       require_once $waDealUfLock;
 *   }
 *
 *   $waDealAutoTake = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_deal_auto_take.php';
 *   if (is_file($waDealAutoTake)) {
 *       require_once $waDealAutoTake;
 *   }
 *
 *   $waDealUfHist = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_deal_uf_history.php';
 *   if (is_file($waDealUfHist)) {
 *       require_once $waDealUfHist;
 *   }
 */
