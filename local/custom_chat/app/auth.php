<?php
/**
 * Авторизация портальной сессии из AUTH_ID локального приложения (мобильный WebView).
 */
require_once __DIR__ . '/config.php';

/**
 * @return array{authId:string,domain:string,scheme:string,memberId:string}
 */
function waCcAppRequestAuthContext()
{
	$authId = (string)($_REQUEST['AUTH_ID'] ?? $_REQUEST['auth'] ?? '');
	$domain = (string)($_REQUEST['DOMAIN'] ?? $_REQUEST['domain'] ?? '');
	if ($domain === '' && !empty($_SERVER['HTTP_HOST'])) {
		$domain = (string)$_SERVER['HTTP_HOST'];
	}
	$domain = preg_replace('#^https?://#i', '', (string)$domain);
	$domain = rtrim((string)$domain, '/');

	$protocolFlag = $_REQUEST['PROTOCOL'] ?? '1';
	$scheme = ((string)$protocolFlag === '0' || (string)$protocolFlag === 'http') ? 'http' : 'https';
	if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
		$scheme = 'https';
	} elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
		$scheme = 'https';
	}

	return [
		'authId' => $authId,
		'domain' => $domain,
		'scheme' => $scheme,
		'memberId' => (string)($_REQUEST['member_id'] ?? ''),
	];
}

/**
 * @param array<string, mixed> $params
 * @return array{ok:bool,result:mixed,error:string}
 */
function waCcAppRestCallAuth($scheme, $domain, $authId, $method, array $params = [])
{
	$out = ['ok' => false, 'result' => null, 'error' => ''];
	if ($domain === '' || $authId === '') {
		$out['error'] = 'no_auth_or_domain';
		return $out;
	}

	$url = $scheme . '://' . $domain . '/rest/' . $method . '.json';
	$params['auth'] = $authId;
	$body = http_build_query($params);
	$raw = false;

	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => 6,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
		]);
		$raw = curl_exec($ch);
		if ($raw === false) {
			$out['error'] = 'curl: ' . curl_error($ch);
			curl_close($ch);
			return $out;
		}
		curl_close($ch);
	} else {
		$ctx = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
				'content' => $body,
				'timeout' => 20,
				'ignore_errors' => true,
			],
			'ssl' => [
				'verify_peer' => false,
				'verify_peer_name' => false,
			],
		]);
		$raw = @file_get_contents($url, false, $ctx);
		if ($raw === false) {
			$out['error'] = 'http_request_failed';
			return $out;
		}
	}

	$data = json_decode((string)$raw, true);
	if (!is_array($data)) {
		$out['error'] = 'bad_json';
		return $out;
	}
	if (!empty($data['error'])) {
		$out['error'] = (string)$data['error']
			. (!empty($data['error_description']) ? (': ' . $data['error_description']) : '');
		return $out;
	}
	$out['ok'] = true;
	$out['result'] = $data['result'] ?? null;
	return $out;
}

/**
 * Логинит текущего пользователя портала по AUTH_ID приложения.
 * @return array{ok:bool,userId:int,error:string,debug:string}
 */
function waCcAppAuthorizeFromRequest()
{
	$out = ['ok' => false, 'userId' => 0, 'error' => '', 'debug' => ''];

	try {
		if (!defined('B_PROLOG_INCLUDED')) {
			if (!defined('NO_KEEP_STATISTIC')) {
				define('NO_KEEP_STATISTIC', true);
			}
			if (!defined('NO_AGENT_CHECK')) {
				define('NO_AGENT_CHECK', true);
			}
			if (!defined('NOT_CHECK_PERMISSIONS')) {
				define('NOT_CHECK_PERMISSIONS', true);
			}
			require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
		}
	} catch (\Throwable $e) {
		$out['error'] = 'prolog: ' . $e->getMessage();
		return $out;
	}

	global $USER;
	if (is_object($USER) && method_exists($USER, 'IsAuthorized') && $USER->IsAuthorized()) {
		$out['ok'] = true;
		$out['userId'] = (int)$USER->GetID();
		$out['debug'] = 'session';
		return $out;
	}

	$ctx = waCcAppRequestAuthContext();
	if ($ctx['authId'] === '') {
		$out['error'] = 'no_AUTH_ID (открой из меню приложения Bitrix24)';
		$out['debug'] = 'keys=' . implode(',', array_keys($_REQUEST));
		return $out;
	}

	$userId = 0;
	$lastErr = '';

	// 1) loopback — без внешнего DNS/SSL (часто белый экран из‑за hang curl на себя)
	$hostHeader = $ctx['domain'] !== '' ? $ctx['domain'] : (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
	foreach (['http://127.0.0.1', 'http://localhost'] as $base) {
		$try = waCcAppRestCallAuthLocal($base, $hostHeader, $ctx['authId'], 'user.current');
		if ($try['ok'] && is_array($try['result'])) {
			$userId = (int)($try['result']['ID'] ?? $try['result']['id'] ?? 0);
			$out['debug'] = 'loopback:' . $base;
			break;
		}
		$lastErr = (string)$try['error'];
	}

	// 2) публичный URL портала
	if ($userId <= 0) {
		$res = waCcAppRestCallAuth($ctx['scheme'], $ctx['domain'], $ctx['authId'], 'user.current');
		if ($res['ok'] && is_array($res['result'])) {
			$userId = (int)($res['result']['ID'] ?? $res['result']['id'] ?? 0);
			$out['debug'] = 'public';
		} else {
			$lastErr = (string)($res['error'] ?: $lastErr);
		}
	}

	if ($userId <= 0) {
		$out['error'] = 'user.current: ' . ($lastErr ?: 'empty');
		return $out;
	}

	if (!is_object($USER) || !method_exists($USER, 'Authorize')) {
		$out['error'] = 'no_USER';
		return $out;
	}

	try {
		$USER->Authorize($userId);
	} catch (\Throwable $e) {
		$out['error'] = 'Authorize: ' . $e->getMessage();
		return $out;
	}

	if (!$USER->IsAuthorized() || (int)$USER->GetID() !== $userId) {
		$out['error'] = 'authorize_failed';
		return $out;
	}

	$out['ok'] = true;
	$out['userId'] = $userId;
	return $out;
}

/**
 * REST через 127.0.0.1 + Host header (короткий timeout).
 *
 * @return array{ok:bool,result:mixed,error:string}
 */
function waCcAppRestCallAuthLocal($baseUrl, $hostHeader, $authId, $method, array $params = [])
{
	$out = ['ok' => false, 'result' => null, 'error' => ''];
	$url = rtrim($baseUrl, '/') . '/rest/' . $method . '.json';
	$params['auth'] = $authId;
	$body = http_build_query($params);

	if (!function_exists('curl_init')) {
		$out['error'] = 'no_curl';
		return $out;
	}

	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => $body,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 2,
		CURLOPT_TIMEOUT => 4,
		CURLOPT_HTTPHEADER => [
			'Content-Type: application/x-www-form-urlencoded',
			'Host: ' . $hostHeader,
		],
	]);
	$raw = curl_exec($ch);
	if ($raw === false) {
		$out['error'] = 'curl: ' . curl_error($ch);
		curl_close($ch);
		return $out;
	}
	curl_close($ch);

	$data = json_decode((string)$raw, true);
	if (!is_array($data)) {
		$out['error'] = 'bad_json';
		return $out;
	}
	if (!empty($data['error'])) {
		$out['error'] = (string)$data['error']
			. (!empty($data['error_description']) ? (': ' . $data['error_description']) : '');
		return $out;
	}
	$out['ok'] = true;
	$out['result'] = $data['result'] ?? null;
	return $out;
}

/**
 * Открыть КЦ в ЭТОМ же HTTP-запросе (без redirect/iframe — мобильный WebView).
 *
 * @param array<string, scalar> $query
 */
function waCcAppBootChat(array $query = [])
{
	foreach ($query as $k => $v) {
		$_GET[$k] = (string)$v;
		$_REQUEST[$k] = (string)$v;
	}
	$_GET['wa_embed'] = '1';
	$_GET['wa_mobile'] = '1';
	$_REQUEST['wa_embed'] = '1';
	$_REQUEST['wa_mobile'] = '1';

	if (!defined('WA_CC_APP_BOOT')) {
		define('WA_CC_APP_BOOT', true);
	}

	$chat = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/index.php';
	if (!is_file($chat)) {
		waCcAppRenderError('Не найден /local/custom_chat/index.php');
	}

	require $chat;
	die();
}

/**
 * @param string $message
 */
function waCcAppRenderError($message)
{
	if (!headers_sent()) {
		http_response_code(200);
		header('Content-Type: text/html; charset=UTF-8');
		header('Cache-Control: no-store');
	}
	$msg = htmlspecialchars((string)$message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$req = htmlspecialchars(json_encode([
		'PLACEMENT' => $_REQUEST['PLACEMENT'] ?? null,
		'has_AUTH_ID' => !empty($_REQUEST['AUTH_ID']) || !empty($_REQUEST['auth']),
		'DOMAIN' => $_REQUEST['DOMAIN'] ?? ($_SERVER['HTTP_HOST'] ?? null),
		'method' => $_SERVER['REQUEST_METHOD'] ?? '',
	], JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

	echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
		. '<title>WhatsApp чат</title></head>'
		. '<body style="margin:0;font:15px/1.45 system-ui;background:#fff3cd;color:#1b1b1b">'
		. '<div style="margin:20px;padding:14px 16px;background:#fff;border-radius:12px;border:2px solid #c62828">'
		. '<div style="font-weight:700;margin-bottom:8px;color:#c62828">WhatsApp чат — ошибка</div>'
		. '<div>' . $msg . '</div>'
		. '<pre style="margin-top:12px;font-size:11px;white-space:pre-wrap;color:#667781">' . $req . '</pre>'
		. '</div></body></html>';
	die();
}
