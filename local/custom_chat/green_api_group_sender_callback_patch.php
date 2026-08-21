<?php
/**
 * Патч в callback.php (fos.green.api / 1os.su), НЕ отдельный URL.
 *
 * Сразу после json_decode webhook:
 *
 *   $data = json_decode($raw, true);
 *   gaPrefixGroupSenderInWebhook($data);
 *   gaApplyGroupChatNameFromWebhook($data);
 *   gaApplyOutgoingMessageStatus($data);
 *
 * Дальше их обычная логика без изменений.
 *
 * В кабинете Green API должны быть включены:
 * outgoingWebhook + outgoingMessageWebhook + outgoingAPIMessageWebhook
 */

function gaPrefixGroupSenderInWebhook(array &$hook): void
{
	if (($hook['typeWebhook'] ?? '') !== 'incomingMessageReceived') {
		return;
	}
	$sender = is_array($hook['senderData'] ?? null) ? $hook['senderData'] : [];
	$chatId = (string)($sender['chatId'] ?? '');
	if ($chatId === '' || !preg_match('/@g\.us$/i', $chatId)) {
		return;
	}
	$label = gaBuildGroupSenderLabel($sender);
	if ($label === '') {
		return;
	}
	$prefix = '[b]' . $label . '[/b]';
	if (!isset($hook['messageData']) || !is_array($hook['messageData'])) {
		$hook['messageData'] = [];
	}
	gaPrefixMessageData($hook['messageData'], $prefix);
}

function gaBuildGroupSenderLabel(array $sender): string
{
	$name = trim((string)($sender['senderContactName'] ?? ''));
	if ($name === '') {
		$name = trim((string)($sender['senderName'] ?? ''));
	}
	if ($name !== '' && preg_match('/whatsapp|green-?api/i', $name)) {
		$name = '';
	}
	$phone = preg_replace('/\D+/', '', (string)($sender['sender'] ?? ''));
	$parts = [];
	if ($name !== '') {
		$parts[] = $name;
	}
	if ($phone !== '') {
		$parts[] = $phone;
	}
	return trim(implode(' ', $parts));
}

function gaPrefixMessageData(array &$messageData, string $prefix): void
{
	$type = (string)($messageData['typeMessage'] ?? '');
	$map = [
		'textMessage' => ['textMessageData', 'textMessage'],
		'extendedTextMessage' => ['extendedTextMessageData', 'text'],
		'quotedMessage' => ['extendedTextMessageData', 'text'],
		'imageMessage' => ['imageMessageData', 'caption'],
		'videoMessage' => ['videoMessageData', 'caption'],
		'documentMessage' => ['documentMessageData', 'caption'],
		'audioMessage' => ['audioMessageData', 'caption'],
		'stickerMessage' => ['stickerMessageData', 'caption'],
	];
	if (isset($map[$type])) {
		[$block, $field] = $map[$type];
		if (!isset($messageData[$block]) || !is_array($messageData[$block])) {
			$messageData[$block] = [];
		}
		gaPrefixField($messageData[$block][$field], $prefix);
		return;
	}
	foreach (['textMessageData' => 'textMessage', 'extendedTextMessageData' => 'text'] as $block => $field) {
		if (!empty($messageData[$block]) && is_array($messageData[$block])) {
			gaPrefixField($messageData[$block][$field], $prefix);
			return;
		}
	}
}

function gaPrefixField(&$value, string $prefix): void
{
	$text = trim((string)($value ?? ''));
	if ($text !== '' && preg_match('/^\s*\[(?:b|B)\][^\]]+\[\/(?:b|B)\]/u', $text)) {
		return;
	}
	$value = $text === '' ? $prefix : ($prefix . "\n" . $text);
}

function gaApplyGroupChatNameFromWebhook(array $hook): void
{
	$file = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/app/wa_group_titles.php';
	if (!is_file($file)) {
		return;
	}
	require_once $file;
	waCcGroupTitlesApplyWebhook($hook);
}

function gaApplyOutgoingMessageStatus(array $hook): void
{
	if (strtolower((string)($hook['typeWebhook'] ?? '')) !== 'outgoingmessagestatus') {
		return;
	}
	$file = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/app/wa_ticks.php';
	if (!is_file($file)) {
		return;
	}
	require_once $file;
	waCcTicksApplyWebhook($hook);
}
