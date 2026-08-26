#!/usr/bin/env python3
import sys

import paramiko


HOST = "crm.artflowers.kz"
USER = "bitrix"
KEY_PATH = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"

PHP = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
$applySettings = true;
function fetchJsonRetry($url, $attempts = 3) {
    for ($i = 0; $i < $attempts; $i++) {
        $raw = @file_get_contents($url, false, stream_context_create(["http" => [
            "timeout" => 15,
            "ignore_errors" => true,
        ]]));
        $data = json_decode((string)$raw, true);
        if (is_array($data)) {
            return $data;
        }
        if ($i + 1 < $attempts) {
            usleep(500000);
        }
    }
    return null;
}
$activeLines = [];
$db = \Bitrix\Main\Application::getConnection();
$activeRows = $db->query("SELECT s.LINE, c.LINE_NAME FROM b_imconnectors_status s
    INNER JOIN b_imopenlines_config c ON c.ID=s.LINE
    WHERE s.ACTIVE='Y' AND s.CONNECTION='Y' AND s.REGISTER='Y'
      AND s.CONNECTOR LIKE 'fos_green%' AND c.ACTIVE='Y'");
while ($activeRow = $activeRows->fetch()) {
    $activeLines[(int)$activeRow["LINE"]] = (string)$activeRow["LINE_NAME"];
}
$cfg = include "/home/bitrix/www/local/custom_chat/app/green_api_instances.local.php";
$instances = [];
foreach ((array)($cfg["lines"] ?? []) as $lineId => $cred) {
    if (!is_array($cred) || empty($cred["idInstance"]) || empty($cred["apiTokenInstance"])) {
        continue;
    }
    $instances[(string)$cred["idInstance"]] = [
        "lines" => array_merge($instances[(string)$cred["idInstance"]]["lines"] ?? [], [(int)$lineId]),
        "cred" => $cred,
    ];
}
foreach ($instances as $id => $item) {
    $cred = $item["cred"];
    $base = rtrim((string)($cred["apiUrl"] ?? "https://api.green-api.com"), "/");
    $getUrlBase = $base . "/waInstance" . $id . "/getSettings/" . $cred["apiTokenInstance"];
    $getUrl = $getUrlBase . "?ts=" . rawurlencode((string)microtime(true));
    $before = fetchJsonRetry($getUrl);
    $allowed = [
        "webhookUrl", "webhookUrlToken", "delaySendMessagesMilliseconds",
        "markIncomingMessagesReaded", "markIncomingMessagesReadedOnReply",
        "outgoingWebhook", "outgoingMessageWebhook", "outgoingAPIMessageWebhook",
        "incomingWebhook", "deviceWebhook", "stateWebhook", "keepOnlineStatus",
        "pollMessageWebhook", "incomingBlockWebhook", "incomingCallWebhook",
        "editedMessageWebhook", "deletedMessageWebhook", "catalogWebhook",
        "autoTyping", "linkPreview", "enableLidMode",
    ];
    $payload = [];
    foreach ($allowed as $key) {
        if (is_array($before) && array_key_exists($key, $before)) {
            $payload[$key] = $before[$key];
        }
    }
    $payload["editedMessageWebhook"] = "yes";
    $raw = "verify_only";
    $code = 0;
    $err = "";
    $ok = true;
    if ($applySettings && ($before["editedMessageWebhook"] ?? "no") !== "yes") {
        $url = $base . "/waInstance" . $id . "/setSettings/" . $cred["apiTokenInstance"];
        $ctx = stream_context_create(["http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json\r\nAccept: application/json\r\n",
            "content" => json_encode($payload),
            "timeout" => 20,
            "ignore_errors" => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = $http_response_header[0] ?? "";
        preg_match('/\s(\d{3})(?:\s|$)/', $status, $match);
        $code = isset($match[1]) ? (int)$match[1] : 0;
        $err = $raw === false ? "request_failed" : "";
        $ok = $code >= 200 && $code < 300;
    }
    $isActive = false;
    $lineNames = [];
    foreach ($item["lines"] as $lineId) {
        if (!empty($activeLines[(int)$lineId])) {
            $isActive = true;
            $lineNames[] = $activeLines[(int)$lineId];
        }
    }
    echo "instance=" . $id . " lines=" . implode(",", $item["lines"]) .
        " names=" . implode("|", $lineNames) .
        " active=" . ($isActive ? "yes" : "no") .
        " http=" . $code . " ok=" . ($ok ? "yes" : "no");
    if ($err !== "") {
        echo " error=" . $err;
    }
    $getUrl = $getUrlBase . "?ts=" . rawurlencode((string)microtime(true));
    $settings = fetchJsonRetry($getUrl);
    $edited = (string)($settings["editedMessageWebhook"] ?? "unknown");
    echo " edited=" . $edited;
    if ($edited !== "yes") {
        echo " response=" . substr(preg_replace('/\s+/', ' ', (string)$raw), 0, 300);
        $stateUrl = $base . "/waInstance" . $id . "/getStateInstance/" . $cred["apiTokenInstance"] .
            "?ts=" . rawurlencode((string)microtime(true));
        $stateRaw = @file_get_contents($stateUrl, false, stream_context_create(["http" => [
            "timeout" => 15,
            "ignore_errors" => true,
        ]]));
        $state = json_decode((string)$stateRaw, true);
        echo " account=" . (string)($settings["typeAccount"] ?? "unknown") .
            " wid=" . (!empty($settings["wid"]) ? "set" : "empty") .
            " webhook=" . (!empty($settings["webhookUrl"]) ? "set" : "empty") .
            " incoming=" . (string)($settings["incomingWebhook"] ?? "unknown") .
            " state=" . (string)($state["stateInstance"] ?? "unknown");
    }
    echo "\n";
}
'''

if "--verify" in sys.argv[2:]:
    PHP = PHP.replace("$applySettings = true;", "$applySettings = false;", 1)


client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, key_filename=KEY_PATH, timeout=25)
stdin, stdout, stderr = client.exec_command("php", timeout=180)
stdin.write(PHP)
stdin.channel.shutdown_write()
sys.stdout.buffer.write(stdout.read())
sys.stderr.buffer.write(stderr.read())
code = stdout.channel.recv_exit_status()
client.close()
raise SystemExit(code)
