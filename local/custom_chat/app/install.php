<?php
/**
 * Установка локального приложения WhatsApp.
 * placement.bind через PHP REST (AUTH_ID из POST) — BX24 CDN на коробке часто падает.
 */
require_once __DIR__ . '/config.php';

$handlerUrl = waCcAppPublicBaseUrl() . '/app/placement.php';
$placements = waCcAppPlacements();
$title = waCcAppTitle();

$authId = (string)($_REQUEST['AUTH_ID'] ?? $_REQUEST['auth'] ?? '');
$domain = (string)($_REQUEST['DOMAIN'] ?? $_REQUEST['domain'] ?? '');
if ($domain === '' && !empty($_SERVER['HTTP_HOST'])) {
	$domain = (string)$_SERVER['HTTP_HOST'];
}
$domain = preg_replace('#^https?://#i', '', $domain);
$domain = rtrim((string)$domain, '/');

$protocolFlag = $_REQUEST['PROTOCOL'] ?? '1';
$scheme = ((string)$protocolFlag === '0' || (string)$protocolFlag === 'http') ? 'http' : 'https';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
	$scheme = 'https';
} elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
	$scheme = 'https';
}

$logs = [];
$okCount = 0;
$error = '';

/**
 * @param array<string, mixed> $params
 * @return array{ok:bool,result:mixed,error:string}
 */
function waCcAppRestCall($scheme, $domain, $authId, $method, array $params)
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
			CURLOPT_CONNECTTIMEOUT => 8,
			CURLOPT_TIMEOUT => 20,
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
		$out['error'] = (string)$data['error'] . (!empty($data['error_description']) ? (': ' . $data['error_description']) : '');
		return $out;
	}
	$out['ok'] = true;
	$out['result'] = $data['result'] ?? true;
	return $out;
}

if ($authId === '') {
	$error = 'Нет AUTH_ID. Открой установку из карточки локального приложения Bitrix (не прямой URL в браузере).';
} else {
	$info = waCcAppRestCall($scheme, $domain, $authId, 'app.info', []);
	if ($info['ok'] && is_array($info['result'])) {
		$cid = (string)($info['result']['CODE'] ?? $info['result']['ID'] ?? '');
		// app.info часто отдаёт CODE вида local.xxx — это CLIENT_ID
		if ($cid === '' && !empty($info['result']['CLIENT_ID'])) {
			$cid = (string)$info['result']['CLIENT_ID'];
		}
		if ($cid !== '') {
			waCcAppSaveClientId($cid);
			$logs[] = 'client_id saved: ' . $cid;
		} else {
			$logs[] = 'app.info: no CODE/CLIENT_ID';
		}
	} else {
		$logs[] = 'app.info: ' . ($info['error'] ?: 'fail');
	}

	foreach ($placements as $row) {
		$placement = (string)$row['placement'];
		$tabTitle = (string)$row['title'];
		$res = waCcAppRestCall($scheme, $domain, $authId, 'placement.bind', [
			'PLACEMENT' => $placement,
			'HANDLER' => $handlerUrl,
			'TITLE' => $tabTitle,
			'LANG_ALL' => [
				'ru' => ['TITLE' => $tabTitle],
				'en' => ['TITLE' => $tabTitle],
			],
		]);
		$errLower = strtolower((string)$res['error']);
		$already = (!$res['ok'] && (
			strpos($errLower, 'already bind') !== false
			|| strpos($errLower, 'already bound') !== false
			|| strpos($errLower, 'handler already') !== false
		));
		if ($res['ok'] || $already) {
			$okCount++;
			$logs[] = $placement . ': ' . ($already ? 'already bound (ok)' : 'ok');
			continue;
		}

		// переустановка: снять старый handler и повесить заново
		waCcAppRestCall($scheme, $domain, $authId, 'placement.unbind', [
			'PLACEMENT' => $placement,
			'HANDLER' => $handlerUrl,
		]);
		$res2 = waCcAppRestCall($scheme, $domain, $authId, 'placement.bind', [
			'PLACEMENT' => $placement,
			'HANDLER' => $handlerUrl,
			'TITLE' => $tabTitle,
			'LANG_ALL' => [
				'ru' => ['TITLE' => $tabTitle],
				'en' => ['TITLE' => $tabTitle],
			],
		]);
		$err2 = strtolower((string)$res2['error']);
		$already2 = (!$res2['ok'] && (
			strpos($err2, 'already bind') !== false
			|| strpos($err2, 'already bound') !== false
			|| strpos($err2, 'handler already') !== false
		));
		if ($res2['ok'] || $already2) {
			$okCount++;
			$logs[] = $placement . ': rebound ok';
		} else {
			$error = 'placement.bind ' . $placement . ': ' . $res2['error'];
			$logs[] = $error;
			break;
		}
	}
}

$allOk = ($error === '' && $okCount === count($placements));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> — установка</title>
	<style>
		body { font-family: system-ui, sans-serif; margin: 24px; color: #1b1b1b; background: #f5f7f8; }
		.card { max-width: 560px; background: #fff; border-radius: 12px; padding: 20px 22px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
		h1 { margin: 0 0 12px; font-size: 20px; }
		p { margin: 0 0 10px; line-height: 1.45; }
		ul { margin: 8px 0 0; padding-left: 18px; font-size: 13px; color: #54656f; }
		#status { margin-top: 14px; font-weight: 600; }
		.err { color: #c62828; }
		.ok { color: #008069; }
		.muted { color: #667781; font-size: 13px; margin-top: 8px; }
	</style>
</head>
<body>
	<div class="card">
		<h1><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
		<p>Регистрация вкладок WhatsApp в сделке и лиде (PHP REST, без BX24 CDN).</p>
		<ul>
			<?php foreach ($logs as $line): ?>
				<li><?= htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
			<?php endforeach; ?>
		</ul>
		<div id="status" class="<?= $allOk ? 'ok' : 'err' ?>">
			<?= htmlspecialchars(
				$allOk ? 'Вкладки зарегистрированы. Завершаем установку…' : ($error ?: 'Ошибка установки'),
				ENT_QUOTES | ENT_SUBSTITUTE,
				'UTF-8'
			) ?>
		</div>
		<p class="muted" id="hint"></p>
	</div>
<?php if ($allOk): ?>
	<script src="https://api.bitrix24.com/api/v1/"></script>
	<script>
	(function () {
		var statusEl = document.getElementById('status');
		var hint = document.getElementById('hint');
		function doneMsg() {
			statusEl.className = 'ok';
			statusEl.textContent = 'Готово. Можно закрыть окно / нажать «Продолжить» в Bitrix.';
			hint.textContent = 'Если окно не закрылось само — это нормально на коробке. Вкладки уже привязаны.';
		}
		function tryFinish() {
			try {
				if (typeof BX24 !== 'undefined' && BX24 && typeof BX24.init === 'function') {
					BX24.init(function () {
						try {
							BX24.installFinish();
							doneMsg();
						} catch (e) {
							doneMsg();
						}
					});
					return;
				}
			} catch (e) { /* ignore */ }
			doneMsg();
		}
		// BX24 на коробке может кинуть Uncaught при загрузке — не блокируем успех
		setTimeout(tryFinish, 300);
	})();
	</script>
<?php endif; ?>
</body>
</html>
