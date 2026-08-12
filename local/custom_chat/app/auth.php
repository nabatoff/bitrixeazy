<?php
/**
 * Auth для локального приложения.
 *
 * ВАЖНО (BitrixMobile): в обработчике приложения НЕЛЬЗЯ подключать
 * prolog_before.php — на iOS/WebView это даёт белый экран.
 * Резолвим userId только через REST (AUTH_ID), сессию поднимаем
 * уже на /local/custom_chat/?wa_tok=… (обычная страница портала).
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
	$domain = preg_replace('/:(443|80)$/', '', $domain);

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
 * Достать ID из ответа REST (user.current / profile / …).
 *
 * @param mixed $result
 */
function waCcAppExtractUserIdFromRest($result)
{
	if (!is_array($result)) {
		return 0;
	}
	foreach (['ID', 'id', 'USER_ID', 'user_id'] as $k) {
		if (!empty($result[$k])) {
			return (int)$result[$k];
		}
	}
	return 0;
}

/**
 * userId по AUTH_ID без prolog / без $USER->Authorize (для BitrixMobile).
 *
 * Нужен scope «Пользователи» (user) у локального приложения.
 *
 * @return array{ok:bool,userId:int,error:string,debug:string}
 */
function waCcAppResolveUserIdNoProlog()
{
	$out = ['ok' => false, 'userId' => 0, 'error' => '', 'debug' => ''];

	$ctx = waCcAppRequestAuthContext();
	if ($ctx['authId'] === '') {
		$out['error'] = 'no_AUTH_ID (открой из меню приложения Bitrix24)';
		$out['debug'] = 'keys=' . implode(',', array_keys($_REQUEST));
		return $out;
	}

	$userId = 0;
	$lastErr = '';
	$hostHeader = $ctx['domain'] !== '' ? $ctx['domain'] : (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
	$methods = ['user.current', 'profile'];

	foreach ($methods as $method) {
		foreach (['http://127.0.0.1', 'http://localhost'] as $base) {
			$try = waCcAppRestCallAuthLocal($base, $hostHeader, $ctx['authId'], $method);
			if ($try['ok']) {
				$userId = waCcAppExtractUserIdFromRest($try['result']);
				if ($userId > 0) {
					$out['debug'] = 'loopback:' . $base . ':' . $method;
					break 2;
				}
			}
			$lastErr = (string)$try['error'];
		}

		$res = waCcAppRestCallAuth($ctx['scheme'], $ctx['domain'], $ctx['authId'], $method);
		if ($res['ok']) {
			$userId = waCcAppExtractUserIdFromRest($res['result']);
			if ($userId > 0) {
				$out['debug'] = 'public:' . $method;
				break;
			}
		} else {
			$lastErr = (string)($res['error'] ?: $lastErr);
		}
	}

	if ($userId <= 0) {
		$hint = '';
		if (stripos($lastErr, 'insufficient_scope') !== false) {
			$hint = ' → в локальном приложении добавь право «Пользователи» (user), Сохранить, затем Переустановить';
		}
		$out['error'] = 'user.current: ' . ($lastErr ?: 'empty') . $hint;
		return $out;
	}

	$out['ok'] = true;
	$out['userId'] = $userId;
	return $out;
}

/**
 * Entry для app handlers: REST без prolog; на desktop при scope-fail — сессия портала.
 *
 * @return array{ok:bool,userId:int,error:string,debug:string}
 */
function waCcAppResolveUserIdForApp()
{
	$rest = waCcAppResolveUserIdNoProlog();
	if ($rest['ok']) {
		return $rest;
	}

	// Desktop slider в браузере: cookie-сессия портала (prolog тут обычно ок)
	$isMobile = true;
	if (function_exists('waCcAppIsMobileClient')) {
		$isMobile = waCcAppIsMobileClient();
	} else {
		$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
		$isMobile = !preg_match('/Windows NT|Macintosh|X11;|CrOS/i', $ua)
			|| (bool)preg_match('/Mobile|BitrixMobile|BXMobileApp|Android|iPhone/i', $ua);
	}

	if (!$isMobile) {
		$session = waCcAppAuthorizeFromRequest();
		if ($session['ok']) {
			$session['debug'] = 'desktop_session_fallback:' . ($session['debug'] ?: '');
			return $session;
		}
		$rest['error'] .= ' | session: ' . ($session['error'] ?: 'fail');
	}

	return $rest;
}

/**
 * Legacy: prolog + Authorize. Только для desktop/отладки вне BitrixMobile handler.
 * В app/index.php и placement.php НЕ вызывать.
 *
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

	$resolved = waCcAppResolveUserIdNoProlog();
	if (!$resolved['ok']) {
		return $resolved;
	}
	$userId = (int)$resolved['userId'];
	$out['debug'] = (string)$resolved['debug'];

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
		'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
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
