<?php
/**
 * Установка:
 * 1. Скопируйте этот файл в /bitrix/php_interface/classes/DealStageGuard.php
 * 2. В /bitrix/php_interface/init.php добавьте:
 *    require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/php_interface/classes/DealStageGuard.php');
 *    \DealStageGuard::register();
 *
 * Что делает:
 * Проверяет обязательные поля ПЕРЕД сохранением сделки при смене стадии.
 * Если сделка перепрыгивает через стадии, проверяются условия ВСЕХ
 * пропущенных стадий, а не только целевой. Если условие не выполнено —
 * сохранение блокируется, пользователь видит ошибку, сделка остаётся
 * на месте.
 *
 * Технический момент: сохранение сделки в Битриксе может идти двумя
 * разными путями — через старый CCrmDeal::Update() (обычное перетаскивание
 * в Канбане) или через новый механизм на базе DealTable (в частности,
 * форма "заполните обязательные поля" перед сменой стадии использует
 * именно его). Старое событие OnBeforeCrmDealUpdate во втором случае
 * НЕ вызывается — поэтому здесь повешены сразу ДВА обработчика: старый
 * (подстраховка) и новый на уровне DealTable (основной, ловит оба пути).
 */

class DealStageGuard
{
    private static $stageOrder = ['PREPARATION', 'PREPAYMENT_INVOIC', 'EXECUTING', 'WON'];

    // временно: пишет в /upload/dealguard_test.log — удобно проверять,
    // какой из двух обработчиков реально сработал. Можно убрать вызовы
    // self::log(...) после того, как всё будет протестировано.
    private static function log($msg)
    {
        @file_put_contents(
            $_SERVER['DOCUMENT_ROOT'] . '/upload/dealguard_test.log',
            date('Y-m-d H:i:s') . ' ' . $msg . "\n",
            FILE_APPEND
        );
    }

    // register() выполняется на КАЖДОЙ загрузке страницы админки (в т.ч.
    // фоновые опросы каждую секунду) — поэтому здесь НЕЛЬЗЯ логировать
    // безусловно на каждый вызов, иначе лог-файл раздувается за часы до
    // миллионов строк и начинает тормозить сайт. Пишем только в catch —
    // то есть только если что-то реально пошло не так.
    public static function register()
    {
        try {
            AddEventHandler('crm', 'OnBeforeCrmDealUpdate', ['DealStageGuard', 'onBeforeUpdateLegacy']);
        } catch (\Throwable $e) {
            self::log('[init] legacy hook FAILED: ' . $e->getMessage());
        }

        try {
            if (!class_exists('\Bitrix\Main\Loader') || !\Bitrix\Main\Loader::includeModule('crm')) {
                return;
            }
            if (!class_exists('\Bitrix\Crm\DealTable')) {
                return;
            }
            // Правильный способ подписки на ORM-событие в Битриксе — через
            // EventManager с именем события "ИмяКласса::OnBeforeUpdate",
            // а не прямым вызовом метода у объекта сущности (его там нет).
            \Bitrix\Main\EventManager::getInstance()->addEventHandler(
                'crm',
                '\Bitrix\Crm\DealTable::OnBeforeUpdate',
                ['DealStageGuard', 'onBeforeUpdateOrm']
            );
        } catch (\Throwable $e) {
            self::log('[init] orm hook FAILED: ' . $e->getMessage());
        }

        // Add: ACL полей (strip), не проверка контакта/компании.
        try {
            AddEventHandler('crm', 'OnBeforeCrmDealAdd', ['DealStageGuard', 'onBeforeAddLegacy']);
        } catch (\Throwable $e) {
            self::log('[init] add-legacy hook FAILED: ' . $e->getMessage());
        }

        try {
            if (class_exists('\Bitrix\Main\Loader') && \Bitrix\Main\Loader::includeModule('crm')
                && class_exists('\Bitrix\Crm\DealTable')) {
                \Bitrix\Main\EventManager::getInstance()->addEventHandler(
                    'crm',
                    '\Bitrix\Crm\DealTable::OnBeforeAdd',
                    ['DealStageGuard', 'onBeforeAddOrm']
                );
            }
        } catch (\Throwable $e) {
            self::log('[init] add-orm hook FAILED: ' . $e->getMessage());
        }

        try {
            AddEventHandler('main', 'OnEpilog', ['DealStageGuard', 'printReloadListener']);
        } catch (\Throwable $e) {
            self::log('[init] epilog hook FAILED: ' . $e->getMessage());
        }
    }

    // Некоторые виджеты Битрикса (карточка сделки, клик по плашке стадии)
    // не показывают текст ошибки и не откатывают карточку визуально после
    // блокировки — сделка "зависает" на неверной стадии до ручной
    // перезагрузки. Чтобы не гадать про внутренности каждого виджета,
    // подписываемся на тот же pull-канал, что уже используется для
    // push-уведомлений, и просто перезагружаем страницу сделки, если
    // блокировка коснулась именно открытой сейчас сделки.
    public static function printReloadListener()
    {
        try {
            if (strpos($_SERVER['REQUEST_URI'] ?? '', '/crm/deal/') === false) {
                return;
            }
            self::injectFieldLockUi();
            ?>
<script>
(function() {
    if (typeof BX === 'undefined' || typeof BX.addCustomEvent !== 'function') {
        return;
    }
    // Pull-соединение общее на все открытые фреймы/вкладки (через
    // SharedWorker), поэтому один и тот же сигнал долетает и до фрейма
    // слайдера, и до родительской страницы позади него. Обрабатываем
    // только в самом верхнем окне — иначе оба обработчика реагируют
    // одновременно, и после мягкого обновления слайдера следом
    // срабатывает ещё и полная перезагрузка страницы.
    if (window.self !== window.top) {
        return;
    }

    // Никакого своего попапа с полями больше нет: слишком много
    // особенностей конкретной CRM-конфигурации, которые надёжно умеет
    // обрабатывать только сама карточка Битрикса (списки со своими
    // значениями, поиск/создание компании и т.п.). Вместо этого просто
    // открываем штатную карточку контакта в боковом слайдере — дальше
    // всё стандартное битриксовское: правильные поля, валидация, сохранение.
    function dealStageGuardOpenNative(contactId) {
        try {
            var topWin = window.top;
            if (contactId && topWin && topWin.BX && topWin.BX.SidePanel && topWin.BX.SidePanel.Instance) {
                topWin.BX.SidePanel.Instance.open('/crm/contact/details/' + contactId + '/');
            }
        } catch (e) {}
    }

    BX.addCustomEvent('onPullEvent-dealstageguard', function(command, params) {
        try {
            if (command !== 'dealstageguard_blocked') {
                return;
            }
            var topWin = window.top;
            var openSlider = null;
            try {
                if (topWin && topWin.BX && topWin.BX.SidePanel && topWin.BX.SidePanel.Instance) {
                    openSlider = topWin.BX.SidePanel.Instance.getTopSlider();
                }
            } catch (eGetSlider) {}

            // На странице конкретной сделки (details/<ID>/) реагируем только
            // если ID совпадает — чтобы не мешать другой открытой сделке.
            // Но чаще сделка открывается не прямой ссылкой, а слайдером
            // поверх Канбана/списка — тогда в адресе САМОЙ вкладки ID
            // сделки не видно вообще, хотя вкладка всё равно "смотрит" на
            // конкретную сделку. Поэтому дополнительно спрашиваем сам
            // слайдер, какой URL в нём сейчас открыт — без этого попап
            // показывался вообще на всех открытых вкладках подряд.
            var m = window.location.pathname.match(/\/crm\/deal\/details\/(\d+)/);
            var currentDealId = m ? parseInt(m[1], 10) : null;

            if (currentDealId === null && openSlider) {
                var sliderUrl = '';
                try {
                    if (typeof openSlider.getUrl === 'function') {
                        sliderUrl = openSlider.getUrl() || '';
                    } else if (openSlider.url) {
                        sliderUrl = openSlider.url;
                    } else if (typeof openSlider.getData === 'function') {
                        var sliderData = openSlider.getData();
                        sliderUrl = (sliderData && sliderData.url) || '';
                    }
                } catch (eSliderUrl) {}

                var sm = String(sliderUrl).match(/\/crm\/deal\/details\/(\d+)/);
                if (sm) {
                    currentDealId = parseInt(sm[1], 10);
                }
            }

            if (currentDealId !== null && (!params || parseInt(params.dealId, 10) !== currentDealId)) {
                return;
            }

            // Если сделка открыта в боковом слайдере — обновляем только его
            // содержимое через официальный API BX.SidePanel, без перезагрузки
            // всей страницы. Если слайдера нет (сделка открыта как отдельная
            // страница) — откатываемся на обычную перезагрузку.
            // Перезагрузка/обновление слайдера нужны только на странице
            // конкретной сделки — там карточка "зависает" сама, ничего не
            // откатывая. В Канбане/списке (currentDealId === null) карточка
            // и так корректно возвращается на место сама, а перезагрузка
            // всей доски только мешает прочитать текст ошибки — поэтому
            // там просто показываем сообщение, без reload. При блокировке
            // из-за клиента (kind === 'client') reload тоже не делаем —
            // сейчас поверх откроется карточка контакта, обновление сзади
            // только помешает.
            var isClientBlock = params && params.kind === 'client';
            if (currentDealId !== null && !isClientBlock) {
                var reloaded = false;
                try {
                    if (openSlider && typeof openSlider.reload === 'function') {
                        openSlider.reload();
                        reloaded = true;
                    }
                } catch (e2) {}

                if (!reloaded) {
                    window.location.reload();
                }
            }

            try {
                var msg = (params && params.message) ? params.message : 'Переход между стадиями заблокирован.';
                var title = isClientBlock ? 'Не заполнены обязательные поля клиента' : 'Сделку нельзя переместить';
                if (window.BX && window.BX.UI && window.BX.UI.Dialogs && window.BX.UI.Dialogs.MessageBox
                    && typeof window.BX.UI.Dialogs.MessageBox.alert === 'function') {
                    window.BX.UI.Dialogs.MessageBox.alert(msg, title);
                }
                // Не завязываемся на колбэк после закрытия alert (не факт,
                // что такой параметр поддерживается в этой версии) — просто
                // сразу открываем карточку контакта поверх, слайдер и
                // модальное окно спокойно уживаются одновременно.
                if (isClientBlock && params.contactId) {
                    dealStageGuardOpenNative(params.contactId);
                }
            } catch (e3) {}
        } catch (e) {}
    });
})();
</script>
            <?php
        } catch (\Throwable $e) {
            self::log('[epilog] EXCEPTION: ' . $e->getMessage());
        }
    }

    // Правила одинаковы для всех 6 воронок (C15-C20).
    // Вход в WON проверяется двумя независимыми условиями: WON (кладовщик
    // выдал заказ) и WON_PAYMENT (оплата получена ИЛИ выдача без оплаты разрешена).
    // 'yn'     — поле-чекбокс (Y/N). "Заполнено" значит строго значение 'Y'.
    //            Пустая строка, NULL и 'N' — всё это "не заполнено".
    // 'marker' — поле-список, куда бизнес-процесс/робот записывает ID пункта
    //            списка как отметку "готово". "Заполнено" значит просто не пусто.
    private static function getGates()
    {
        return [
            'PREPAYMENT_INVOIC' => [
                'type' => 'OR',
                'fields' => [
                    'UF_CRM_1764332847245' => 'yn',
                    'UF_CRM_1786008089' => 'marker',
                ],
                'error' => 'Нужно отметить либо получение предоплаты, либо одобрение закупа без предоплаты — прежде чем переходить к закупу.',
            ],
            'EXECUTING' => [
                'type' => 'AND',
                'fields' => [
                    'UF_CRM_1783486791226' => 'marker',
                ],
                'error' => 'Закупщик ещё не отметил, закуплен ли заказ полностью или с изменениями.',
            ],
            'WON' => [
                'type' => 'AND',
                'fields' => [
                    'UF_CRM_1784524115744' => 'marker',
                ],
                'error' => 'Заказ не выдан кладовщиком.',
            ],
            'WON_PAYMENT' => [
                'type' => 'OR',
                'fields' => [
                    'UF_CRM_1764577842986' => 'yn',
                    'UF_CRM_1786008172' => 'marker',
                ],
                'error' => 'Нужно отметить либо получение полной оплаты, либо разрешение на выдачу без полной оплаты.',
            ],
        ];
    }

    // Конфиг ограничений на редактирование отдельных полей сделки —
    // хранится через штатный Bitrix Option API (без новой таблицы),
    // редактируется через admin/dealstageguard_fields.php. Формат:
    //   'КОД_ПОЛЯ' => ['label' => '...', 'users' => [ID,...], 'departments' => [ID,...]]
    // При любой ошибке чтения — пустой массив (ничего не ограничивает),
    // а не падение: конфиг некритичен для остальной логики файла.
    private static function getFieldEditPermissions()
    {
        try {
            if (!class_exists('\Bitrix\Main\Config\Option')) {
                return [];
            }
            $raw = \Bitrix\Main\Config\Option::get('main', 'dsg_field_permissions', '');
            if ($raw === '') {
                return [];
            }
            $config = json_decode($raw, true);
            return is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            self::log('[field-permissions] config read EXCEPTION: ' . $e->getMessage());
            return [];
        }
    }

    private static function normalizeFieldCompareValue($v)
    {
        if (is_array($v)) {
            if (array_key_exists('VALUE', $v)) {
                return self::normalizeFieldCompareValue($v['VALUE']);
            }
            if (array_key_exists('ID', $v)) {
                return self::normalizeFieldCompareValue($v['ID']);
            }
            if (count($v) === 1) {
                return self::normalizeFieldCompareValue(reset($v));
            }
            $parts = [];
            foreach ($v as $item) {
                $parts[] = self::normalizeFieldCompareValue($item);
            }
            sort($parts);
            return implode(',', $parts);
        }
        $v = $v === null ? '' : trim((string)$v);
        if ($v === '' || $v === '0' || strcasecmp($v, 'N') === 0) {
            return "\0OFF";
        }
        if ($v === '1' || strcasecmp($v, 'Y') === 0) {
            return "\0ON";
        }
        return $v;
    }

    /**
     * UF, которые можно оставить при копировании сделки (скрин менеджера).
     * Всё остальное UF на Add у не-админа вычищаем.
     */
    private static function getDealCopyKeepUf()
    {
        return [
            'UF_CRM_1764676465' => 1, // Сумма предоплаты
            'UF_CRM_1785499867031' => 1, // Комментарий по предоплате
            'UF_CRM_1725623607217' => 1, // Договор с клиентом
            'UF_CRM_1750940585581' => 1, // Маркировка
            'UF_CRM_1785755137079' => 1, // Комментарий к маркировке
            'UF_CRM_1731673902240' => 1, // Город опт Алматы
        ];
    }

    private static function isDealCopyContext()
    {
        foreach (['copy', 'COPY', 'copy_id', 'COPY_ID', 'from_id', 'FROM_ID'] as $k) {
            if (!empty($_REQUEST[$k])) {
                return true;
            }
        }
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref !== '' && preg_match('/(?:\?|&)copy=\d+/i', $ref)) {
            return true;
        }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($uri !== '' && preg_match('/(?:\?|&)copy=\d+/i', $uri)) {
            return true;
        }
        return false;
    }

    /**
     * При создании сделки менеджером оставляем только поля со скрина
     * (ответственный/контакт/компания/сумма — системные; + UF whitelist).
     * Бухгалтер/закуп/склад/руководитель и прочий процесс — вычищаем.
     *
     * @return string[] вычищенные коды
     */
    private static function applyCopyFieldWhitelist(array &$incomingFields, $tag)
    {
        global $USER;
        $userId = (isset($USER) && is_object($USER)) ? (int)$USER->GetId() : 0;
        if ($userId <= 0) {
            return [];
        }
        if (self::isUserAdminGroup($userId)) {
            return [];
        }

        // Копия (?copy=) или любая Add с «чужими» процессными UF —
        // иначе AJAX save теряет copy= и поля снова пролезают.
        $keepUf = self::getDealCopyKeepUf();
        $hasForeignUf = false;
        foreach ($incomingFields as $name => $_) {
            if (strpos((string)$name, 'UF_') !== 0) {
                continue;
            }
            if (!isset($keepUf[$name])) {
                $norm = self::normalizeFieldCompareValue($incomingFields[$name]);
                if ($norm !== "\0OFF") {
                    $hasForeignUf = true;
                    break;
                }
            }
        }
        if (!self::isDealCopyContext() && !$hasForeignUf) {
            return [];
        }

        $cleared = [];
        foreach (array_keys($incomingFields) as $name) {
            $name = (string)$name;
            if (strpos($name, 'UF_') !== 0) {
                continue;
            }
            if (isset($keepUf[$name])) {
                continue;
            }
            unset($incomingFields[$name]);
            $cleared[] = $name;
        }
        if ($cleared) {
            self::log("[$tag]   => COPY-WHITELIST cleared UF (" . count($cleared) . "): "
                . implode(', ', $cleared)
                . (self::isDealCopyContext() ? ' [copy-ctx]' : ' [foreign-uf]'));
        } else {
            self::log("[$tag]   => COPY-WHITELIST: nothing to clear"
                . (self::isDealCopyContext() ? ' [copy-ctx]' : ''));
        }
        return $cleared;
    }

    private static function loadUserDepartments($userId)
    {
        static $cache = [];
        $userId = (int)$userId;
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }
        try {
            $userRow = class_exists('\CUser') ? \CUser::GetByID($userId)->Fetch() : false;
            $cache[$userId] = (is_array($userRow) && is_array($userRow['UF_DEPARTMENT'] ?? null))
                ? array_map('intval', $userRow['UF_DEPARTMENT'])
                : [];
        } catch (\Throwable $e) {
            self::log('[field-permissions] UF_DEPARTMENT EXCEPTION: ' . $e->getMessage());
            $cache[$userId] = [];
        }
        return $cache[$userId];
    }

    private static function isUserAdminGroup($userId)
    {
        try {
            if (class_exists('\CUser')) {
                $adminGroups = \CUser::GetUserGroup((int)$userId);
                return is_array($adminGroups) && in_array(1, $adminGroups, false);
            }
        } catch (\Throwable $e) {
            self::log('[field-permissions] admin check EXCEPTION: ' . $e->getMessage());
        }
        return false;
    }

    /**
     * Может ли пользователь писать поле из dsg_field_permissions.
     * Поля вне конфига — да. userId=0 — нет (auto-take не должен штамповать «робота»).
     */
    public static function userCanEditField($field, $userId = null)
    {
        $permissions = self::getFieldEditPermissions();
        if (!isset($permissions[$field])) {
            return true;
        }
        if ($userId === null) {
            global $USER;
            $userId = (isset($USER) && is_object($USER)) ? (int)$USER->GetId() : 0;
        }
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        if (self::isUserAdminGroup($userId)) {
            return true;
        }
        $spec = $permissions[$field];
        $allowedUsers = isset($spec['users']) && is_array($spec['users']) ? $spec['users'] : [];
        if (in_array($userId, $allowedUsers, false)) {
            return true;
        }
        $allowedDepartments = isset($spec['departments']) && is_array($spec['departments']) ? $spec['departments'] : [];
        if ($allowedDepartments && array_intersect($allowedDepartments, self::loadUserDepartments($userId))) {
            return true;
        }
        return false;
    }

    /** UF, которые текущий пользователь не имеет права менять (для UI-лока). */
    public static function getLockedFieldsForCurrentUser()
    {
        $permissions = self::getFieldEditPermissions();
        if (!$permissions) {
            return [];
        }
        global $USER;
        $userId = (isset($USER) && is_object($USER)) ? (int)$USER->GetId() : 0;
        if ($userId <= 0 || self::isUserAdminGroup($userId)) {
            return [];
        }
        $locked = [];
        foreach ($permissions as $field => $spec) {
            if (!self::userCanEditField($field, $userId)) {
                $locked[] = $field;
            }
        }
        return $locked;
    }

    private static function injectFieldLockUi()
    {
        try {
            $locked = self::getLockedFieldsForCurrentUser();
            $json = class_exists('\Bitrix\Main\Web\Json')
                ? \Bitrix\Main\Web\Json::encode($locked)
                : json_encode($locked, JSON_UNESCAPED_UNICODE);
            echo '<script>window.__DSG_FIELD_LOCK=' . $json . ';</script>' . "\n";
            if ($locked && class_exists('\Bitrix\Main\Page\Asset')) {
                $js = '/local/crm/deal_uf_lock.js';
                $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
                $ver = (string)@filemtime($docRoot . $js);
                \Bitrix\Main\Page\Asset::getInstance()->addJs($js . ($ver !== '' ? '?v=' . $ver : ''));
            }
        } catch (\Throwable $e) {
            self::log('[field-lock-ui] EXCEPTION: ' . $e->getMessage());
        }
    }

    /**
     * @return array[] list of ['field'=>,'label'=>,'old'=>,'new'=>]
     */
    private static function collectForbiddenFieldChanges($id, array $incomingFields, $tag)
    {
        $permissions = self::getFieldEditPermissions();
        if (empty($permissions)) {
            return [];
        }
        $fieldsToCheck = array_intersect_key($permissions, $incomingFields);
        if (empty($fieldsToCheck)) {
            return [];
        }

        global $USER;
        $userId = (isset($USER) && is_object($USER)) ? (int)$USER->GetId() : 0;
        if ($userId <= 0) {
            self::log("[$tag]   field-permissions: нет активного пользователя (userId=0), пропускаем проверку (робот/API)");
            return [];
        }
        if (self::isUserAdminGroup($userId)) {
            return [];
        }

        $id = (int)$id;
        $deal = null;
        if ($id > 0) {
            $deal = \CCrmDeal::GetByID($id, false);
            if (!$deal) {
                return [];
            }
        }

        $blocked = [];

        foreach ($fieldsToCheck as $field => $spec) {
            $newValue = $incomingFields[$field];
            if ($id > 0) {
                if (strpos($field, 'UF_') === 0) {
                    $oldValue = self::getUfFieldValue('\Bitrix\Crm\DealTable', $id, $field);
                } else {
                    $oldValue = $deal[$field] ?? null;
                }
            } else {
                $oldValue = null;
            }

            if (self::normalizeFieldCompareValue($newValue) === self::normalizeFieldCompareValue($oldValue)) {
                continue;
            }

            if (self::userCanEditField($field, $userId)) {
                continue;
            }

            $label = (isset($spec['label']) && $spec['label'] !== '') ? $spec['label'] : $field;
            $blocked[] = ['field' => $field, 'label' => $label, 'old' => $oldValue, 'new' => $newValue];
            self::log("[$tag]   field-permissions: user #$userId NOT allowed to change $field ($label): '"
                . (string)$oldValue . "' => '" . (string)$newValue . "'");
        }

        return $blocked;
    }

    /** Add: вырезать запрещённые заполненные поля, сделку не валить. */
    private static function stripForbiddenIncomingFields($id, array &$incomingFields, $tag)
    {
        $blocked = self::collectForbiddenFieldChanges($id, $incomingFields, $tag);
        if (!$blocked) {
            return [];
        }
        $codes = [];
        foreach ($blocked as $row) {
            $field = $row['field'];
            unset($incomingFields[$field]);
            $codes[] = $field;
        }
        self::log("[$tag]   => STRIPPED (add field permissions): " . implode(', ', $codes));
        return $codes;
    }

    private static function evaluateFieldEditPermissions($id, array $incomingFields, $tag)
    {
        try {
            $blocked = self::collectForbiddenFieldChanges($id, $incomingFields, $tag);
            if (!$blocked) {
                return null;
            }
            $blockedLabels = array_column($blocked, 'label');
            $title = 'Нельзя сохранить — у вас нет прав менять следующие поля:';
            $errors = array_map(function ($label) {
                return 'Поле «' . $label . '» может менять только ограниченный список сотрудников.';
            }, $blockedLabels);
            $combined = $title . "\n- " . implode("\n- ", $errors);
            $combinedHtml = self::buildErrorHtml($title, $errors);
            self::log("[$tag]   => BLOCKED (field permissions): " . implode(' || ', $blockedLabels));
            self::pushReloadSignal($id, $combinedHtml);
            return $combined;
        } catch (\Throwable $e) {
            self::log('[field-permissions] EXCEPTION: ' . $e->getMessage());
            return null;
        }
    }

    // Шлёт сигнал через pull-канал, чтобы открытая
    // прямо сейчас страница этой сделки перезагрузилась сама и показала
    // реальную (неизменившуюся) стадию, вместо зависшего "как будто применилось".
    private static function pushReloadSignal($id, $error, array $extraParams = [])
    {
        try {
            if (!class_exists('\Bitrix\Main\Loader') || !\Bitrix\Main\Loader::includeModule('pull')) {
                return;
            }
            if (!class_exists('\Bitrix\Pull\Event')) {
                return;
            }
            global $USER;
            $userId = (isset($USER) && is_object($USER)) ? (int)$USER->GetId() : 0;
            if ($userId <= 0) {
                return;
            }
            \Bitrix\Pull\Event::add($userId, [
                'module_id' => 'dealstageguard',
                'command' => 'dealstageguard_blocked',
                'params' => array_merge(['dealId' => (int)$id, 'message' => (string)$error], $extraParams),
            ]);
        } catch (\Throwable $e) {
            self::log('[pull] EXCEPTION: ' . $e->getMessage());
        }
    }

    // Единый формат HTML для попапа: заголовок + список пунктов (даже
    // если пункт один) — чтобы все блокировки выглядели одинаково. Красная
    // плашка с рамкой и маркерами у каждого пункта — чтобы ошибка сразу
    // бросалась в глаза, а не терялась обычным чёрным текстом.
    private static function buildErrorHtml($title, array $errors)
    {
        $itemsHtml = implode('', array_map(function ($e) {
            return '<li style="margin-bottom:8px;color:#B42318;list-style:none;">'
                . '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#E02424;margin-right:8px;"></span>'
                . htmlspecialchars($e, ENT_QUOTES, 'UTF-8')
                . '</li>';
        }, $errors));
        return '<div style="background:#FEF2F2;border:1px solid #F5B5B0;border-left:4px solid #E02424;border-radius:6px;padding:14px 16px;">'
            . '<div style="font-weight:700;color:#B42318;margin-bottom:10px;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<ul style="margin:0;padding:0;">' . $itemsHtml . '</ul>'
            . '</div>';
    }

    private static function isFieldFilled($raw, $fieldType)
    {
        if ($fieldType === 'yn') {
            return $raw === 'Y';
        }
        return !empty($raw);
    }

    private static function stageCodeOf($stageId)
    {
        $colonPos = strpos((string)$stageId, ':');
        return $colonPos !== false ? substr($stageId, $colonPos + 1) : $stageId;
    }

    // Общее ядро проверки. Возвращает null, если переход разрешён,
    // или текст ошибки, если нужно заблокировать.
    private static function evaluate($id, array $incomingFields, $tag)
    {
        if (empty($incomingFields['STAGE_ID'])) {
            return null;
        }

        self::log("[$tag] fired, ID=$id, target=" . $incomingFields['STAGE_ID']);

        $deal = \CCrmDeal::GetByID($id, false);
        if (!$deal) {
            return null;
        }
        $data = array_merge($deal, $incomingFields);

        self::log("[$tag]   current stage in DB: " . ($deal['STAGE_ID'] ?? '?'));

        $targetIndex = array_search(self::stageCodeOf($incomingFields['STAGE_ID']), self::$stageOrder);
        if ($targetIndex === false) {
            return null;
        }

        $currentIndex = array_search(self::stageCodeOf($deal['STAGE_ID'] ?? ''), self::$stageOrder);
        if ($currentIndex === false) {
            $currentIndex = -1; // нестандартная текущая стадия — проверяем с начала
        }

        if ($targetIndex <= $currentIndex) {
            self::log("[$tag]   backward/same move, skipping gates");
            return null;
        }

        $gates = self::getGates();
        $targetCode = self::$stageOrder[$targetIndex];

        if ($targetCode === 'WON') {
            // Финальный аудит перед "Успешно завершена": проверяем ВСЕ условия
            // за весь путь сделки разом, независимо от того, какие стадии были
            // пройдены честно, а какие пропущены. Если что-то не так — собираем
            // ВСЕ проблемы в одно сообщение, а не только первую попавшуюся.
            $codesToCheck = ['PREPAYMENT_INVOIC', 'EXECUTING', 'WON', 'WON_PAYMENT'];
            self::log("[$tag]   FINAL REVIEW before WON, gates: " . implode(',', $codesToCheck));

            $errors = [];
            foreach ($codesToCheck as $code) {
                if (!isset($gates[$code])) {
                    continue;
                }
                $gate = $gates[$code];
                $filled = [];
                $dump = [];
                foreach ($gate['fields'] as $f => $fieldType) {
                    $raw = $data[$f] ?? null;
                    $isFilled = self::isFieldFilled($raw, $fieldType);
                    $dump[] = $f . '(' . $fieldType . ')=' . var_export($raw, true) . ($isFilled ? '[+]' : '[-]');
                    $filled[] = $isFilled;
                }
                $passed = $gate['type'] === 'OR' ? in_array(true, $filled, true) : !in_array(false, $filled, true);

                self::log("[$tag]   gate $code (" . $gate['type'] . '): ' . implode(' | ', $dump) . ' => ' . ($passed ? 'PASSED' : 'FAILED'));

                if (!$passed) {
                    $errors[] = $gate['error'];
                }
            }

            if (!empty($errors)) {
                $combined = "Нельзя завершить сделку — не выполнены условия:\n- " . implode("\n- ", $errors);
                $combinedHtml = self::buildErrorHtml('Нельзя завершить сделку — не выполнены условия:', $errors);
                self::log("[$tag]   => BLOCKED (final review): " . implode(' || ', $errors));
                self::pushReloadSignal($id, $combinedHtml);
                return $combined;
            }

            self::log("[$tag]   => ALLOWED (final review)");
            return null;
        }

        $codesToCheck = [];
        foreach (self::$stageOrder as $i => $code) {
            if ($i <= $currentIndex || $i > $targetIndex) {
                continue;
            }
            $codesToCheck[] = $code;
        }

        self::log("[$tag]   gates to check: " . implode(',', $codesToCheck));

        foreach ($codesToCheck as $code) {
            if (!isset($gates[$code])) {
                continue;
            }
            $gate = $gates[$code];
            $filled = [];
            $dump = [];
            foreach ($gate['fields'] as $f => $fieldType) {
                $raw = $data[$f] ?? null;
                $isFilled = self::isFieldFilled($raw, $fieldType);
                $dump[] = $f . '(' . $fieldType . ')=' . var_export($raw, true) . ($isFilled ? '[+]' : '[-]');
                $filled[] = $isFilled;
            }
            $passed = $gate['type'] === 'OR' ? in_array(true, $filled, true) : !in_array(false, $filled, true);

            self::log("[$tag]   gate $code (" . $gate['type'] . '): ' . implode(' | ', $dump) . ' => ' . ($passed ? 'PASSED' : 'FAILED'));

            if (!$passed) {
                self::log("[$tag]   => BLOCKED: " . $gate['error']);
                $singleHtml = self::buildErrorHtml('Нельзя перейти на эту стадию:', [$gate['error']]);
                self::pushReloadSignal($id, $singleHtml);
                return $gate['error'];
            }
        }

        self::log("[$tag]   => ALLOWED");
        return null;
    }

    // Обязательные поля контакта/компании, привязываемых к сделке.
    // Проверяются отдельно от стадий: при создании сделки и при смене
    // привязанного контакта/компании на уже существующей сделке.
    private static function getClientFieldGates()
    {
        return [
            'CONTACT' => [
                'entityId' => 'CONTACT',
                'label' => 'Контакт',
                'scalarFields' => [
                    'NAME' => 'Имя',
                    'UF_CRM_656579B501CAF' => 'Пол',
                ],
                'phoneLabel' => 'Телефон',
            ],
            'COMPANY' => [
                'entityId' => 'COMPANY',
                'label' => 'Компания',
                'scalarFields' => [
                    'TITLE' => 'Название',
                ],
                'phoneLabel' => 'Телефон',
            ],
        ];
    }

    // Мультиполя (телефон, email) в Битриксе хранятся отдельно от основной
    // записи контакта/компании — читаются через CCrmFieldMulti::GetList().
    // Если по какой-то причине проверить не получилось (класс недоступен,
    // исключение) — считаем поле заполненным, чтобы не блокировать
    // сохранения на пустом месте из-за сбоя самой проверки.
    private static function hasFilledMultiField($entityId, $elementId, $typeId)
    {
        try {
            if (!class_exists('\CCrmFieldMulti')) {
                return true;
            }
            $rs = \CCrmFieldMulti::GetList(
                ['ID' => 'ASC'],
                ['ENTITY_ID' => $entityId, 'ELEMENT_ID' => (int)$elementId, 'TYPE_ID' => $typeId]
            );
            while ($row = $rs->Fetch()) {
                if (!empty($row['VALUE'])) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            self::log('[multifield] EXCEPTION: ' . $e->getMessage());
            return true;
        }
    }

    // Простая проверка (взамен временно отключённой выше): у сделки
    // должны быть указаны контакт с телефоном и компания с названием и
    // телефоном. Проверяется при КАЖДОМ сохранении/переносе сделки, а не
    // только при смене контакта/компании — блокирует и показывает список
    // недостающих пунктов тем же способом, что и стадийные условия
    // (buildErrorHtml + pushReloadSignal без kind=client — обычный попап,
    // никакой карточки контакта поверх не открываем).
    private static function evaluateBasicClientInfo($id, array &$incomingFields, $tag)
    {
        $deal = \CCrmDeal::GetByID($id, false);
        if (!$deal) {
            return null;
        }
        $data = array_merge($deal, $incomingFields);

        $contactId = (int)($data['CONTACT_ID'] ?? 0);
        $companyId = (int)($data['COMPANY_ID'] ?? 0);

        $errors = [];
        $contactHasPhone = false;
        $hasContactError = false;
        $hasCompanyError = false;

        if ($contactId <= 0) {
            $errors[] = 'Укажите имя клиента и номер телефона клиента.';
            $hasContactError = true;
        } else {
            $contact = class_exists('\CCrmContact') ? \CCrmContact::GetByID($contactId, false) : null;

            $contactHasPhone = self::hasFilledMultiField('CONTACT', $contactId, 'PHONE');
            if (!$contactHasPhone) {
                $errors[] = 'Укажите номер телефона клиента.';
                $hasContactError = true;
            }

            // Имя/фамилия должны быть на кириллице — если менеджер ввёл их
            // латиницей (транслитом), это тоже блокирует сохранение.
            $nameCombined = trim(($contact['NAME'] ?? '') . ' ' . ($contact['LAST_NAME'] ?? ''));
            if ($nameCombined !== '' && preg_match('/[A-Za-z]/', $nameCombined)) {
                $errors[] = 'Имя и фамилия клиента должны быть указаны на русском языке (кириллицей).';
                $hasContactError = true;
            }

            // У сделки своё поле "Компания", отдельное от поля "Компания" у
            // самого контакта — они не связаны автоматически. Если в сделке
            // компания не указана, но она есть у контакта — подставляем её
            // сюда же (и в саму сделку через $incomingFields по ссылке, и в
            // проверку ниже), вместо того чтобы заставлять менеджера искать
            // и выбирать то же самое вручную ещё раз.
            if ($companyId <= 0) {
                $contactCompanyId = (int)($contact['COMPANY_ID'] ?? 0);
                if ($contactCompanyId > 0) {
                    $companyId = $contactCompanyId;
                    $incomingFields['COMPANY_ID'] = $contactCompanyId;
                    self::log("[$tag]   company auto-filled from contact #$contactId: $contactCompanyId");
                }
            }
        }

        if ($companyId <= 0) {
            $errors[] = 'Укажите в карточке клиента в поле Компания название компании клиента или название магазина клиента.';
            $hasCompanyError = true;
        } else {
            $companyTitleFilled = true;
            if (class_exists('\CCrmCompany')) {
                $company = \CCrmCompany::GetByID($companyId, false);
                $companyTitleFilled = $company && !empty($company['TITLE']);
            }
            // Телефона у самой компании может не быть — этого достаточно,
            // если телефон уже есть у контакта (мы и пишем менеджеру, что
            // так можно), поэтому во втором случае это не блокирует.
            $companyHasPhone = self::hasFilledMultiField('COMPANY', $companyId, 'PHONE');

            if (!$companyTitleFilled) {
                $errors[] = 'Укажите в карточке клиента в поле Компания название компании клиента или название магазина клиента.';
                $hasCompanyError = true;
            }
            if (!$companyHasPhone && !$contactHasPhone) {
                $errors[] = 'Укажите номер телефона компании в карточке клиента (можно указать номер телефона клиента).';
                $hasCompanyError = true;
            }
        }

        if (empty($errors)) {
            return null;
        }

        // Заголовок нарочно не утверждает "не заполнено" — поле может быть
        // заполнено, но неправильно (например, латиницей вместо кириллицы),
        // и такая формулировка сбивала с толку. Заголовок только указывает,
        // в каком разделе смотреть, а точную причину называют пункты ниже.
        if ($hasCompanyError && $hasContactError) {
            $title = 'Нельзя сохранить сделку — проверьте данные контакта и компании в карточке клиента:';
        } elseif ($hasCompanyError) {
            $title = 'Нельзя сохранить сделку — проверьте данные компании в карточке клиента:';
        } else {
            $title = 'Нельзя сохранить сделку — проверьте данные контакта в карточке клиента:';
        }
        $combined = $title . "\n- " . implode("\n- ", $errors);
        $combinedHtml = self::buildErrorHtml($title, $errors);
        self::log("[$tag]   => BLOCKED (basic client info): " . implode(' || ', $errors));
        self::pushReloadSignal($id, $combinedHtml);
        return $combined;
    }

    // Проверяет обязательные поля привязанного контакта/компании.
    // Значения кастомных UF_CRM_* полей "GetByID"-методы старого API
    // (CCrmContact::GetByID и т.п.) в этой инсталляции не отдают вовсе —
    // читаем такие поля через D7 ORM, которая их всегда подтягивает
    // автоматически как обычные поля сущности.
    private static function getUfFieldValue($entityTableClass, $id, $fieldCode)
    {
        try {
            if (!class_exists($entityTableClass)) {
                return null;
            }
            $row = $entityTableClass::getList([
                'filter' => ['=ID' => $id],
                'select' => ['ID', $fieldCode],
            ])->fetch();
            if (!is_array($row) || !array_key_exists($fieldCode, $row)) {
                self::log("[uf] $entityTableClass #$id: поле $fieldCode не найдено в ответе ORM, row=" . var_export($row, true));
                return null;
            }
            return $row[$fieldCode];
        } catch (\Throwable $e) {
            self::log('[uf] EXCEPTION: ' . $e->getMessage());
            return null;
        }
    }

    // Возвращает null, если ок (или клиент не привязан вовсе — это не
    // повод блокировать), или готовый текст ошибки для ThrowException/EventResult.
    // $dealId — ID сделки для pull-сигнала (0, если сделка ещё не создана).
    // Дальше сам попап не показываем — вместо него открываем штатную
    // карточку контакта в Битриксе (см. dealStageGuardOpenNative в JS):
    // там списки, поиск компании и валидация — всё родное, битриксовское.
    private static function evaluateClientFields($dealId, $contactId, $companyId, $tag)
    {
        $contactId = (int)$contactId;
        $companyId = (int)$companyId;
        if ($contactId <= 0 && $companyId <= 0) {
            return null;
        }

        if (!class_exists('\Bitrix\Main\Loader') || !\Bitrix\Main\Loader::includeModule('crm')) {
            return null;
        }

        self::log("[$tag] client fields check, contactId=$contactId, companyId=$companyId");

        $gates = self::getClientFieldGates();
        $errors = [];

        if ($contactId > 0 && $companyId <= 0) {
            $errors[] = 'К сделке не привязана компания — привяжите компанию через карточку сделки.';
        }

        if ($contactId > 0 && class_exists('\CCrmContact')) {
            $contact = \CCrmContact::GetByID($contactId, false);
            if ($contact) {
                $gate = $gates['CONTACT'];
                foreach ($gate['scalarFields'] as $field => $label) {
                    if (strpos($field, 'UF_') === 0) {
                        $raw = self::getUfFieldValue('\Bitrix\Crm\ContactTable', $contactId, $field);
                    } else {
                        $raw = $contact[$field] ?? null;
                    }
                    $isFilled = !empty($raw);
                    self::log("[$tag]   contact.$field=" . var_export($raw, true) . ($isFilled ? '[+]' : '[-]'));
                    if (!$isFilled) {
                        $errors[] = $gate['label'] . ': не заполнено поле «' . $label . '».';
                    }
                }
                $hasPhone = self::hasFilledMultiField('CONTACT', $contactId, 'PHONE');
                self::log("[$tag]   contact.PHONE filled=" . ($hasPhone ? '[+]' : '[-]'));
                if (!$hasPhone) {
                    $errors[] = $gate['label'] . ': не указан ' . mb_strtolower($gate['phoneLabel']) . '.';
                }
            }
        }

        if ($companyId > 0 && class_exists('\CCrmCompany')) {
            $company = \CCrmCompany::GetByID($companyId, false);
            if ($company) {
                $gate = $gates['COMPANY'];
                foreach ($gate['scalarFields'] as $field => $label) {
                    if (strpos($field, 'UF_') === 0) {
                        $raw = self::getUfFieldValue('\Bitrix\Crm\CompanyTable', $companyId, $field);
                    } else {
                        $raw = $company[$field] ?? null;
                    }
                    $isFilled = !empty($raw);
                    self::log("[$tag]   company.$field=" . var_export($raw, true) . ($isFilled ? '[+]' : '[-]'));
                    if (!$isFilled) {
                        $errors[] = $gate['label'] . ': не заполнено поле «' . $label . '».';
                    }
                }
                $hasPhone = self::hasFilledMultiField('COMPANY', $companyId, 'PHONE');
                self::log("[$tag]   company.PHONE filled=" . ($hasPhone ? '[+]' : '[-]'));
                if (!$hasPhone) {
                    $errors[] = $gate['label'] . ': не указан ' . mb_strtolower($gate['phoneLabel']) . '.';
                }
            }
        }

        if (empty($errors)) {
            self::log("[$tag]   client fields => OK");
            return null;
        }

        $title = 'Нельзя сохранить — не заполнены обязательные поля клиента:';
        $combined = $title . "\n- " . implode("\n- ", $errors);
        self::log("[$tag]   => BLOCKED (client fields): " . implode(' || ', $errors));
        self::pushReloadSignal($dealId, $combined, ['kind' => 'client', 'contactId' => $contactId]);
        return $combined;
    }

    // Для уже существующей сделки проверяем контакт/компанию только если
    // они реально меняются этим сохранением — иначе каждое сохранение
    // старой сделки со старым неполным контактом внезапно начало бы
    // блокироваться, а этого не требовалось.
    private static function evaluateClientFieldsOnChange($id, array $incomingFields, $tag)
    {
        $contactChanged = array_key_exists('CONTACT_ID', $incomingFields);
        $companyChanged = array_key_exists('COMPANY_ID', $incomingFields);
        if (!$contactChanged && !$companyChanged) {
            return null;
        }

        $deal = \CCrmDeal::GetByID($id, false);
        if (!$deal) {
            return null;
        }

        $oldContactId = (int)($deal['CONTACT_ID'] ?? 0);
        $oldCompanyId = (int)($deal['COMPANY_ID'] ?? 0);
        $newContactId = $contactChanged ? (int)$incomingFields['CONTACT_ID'] : $oldContactId;
        $newCompanyId = $companyChanged ? (int)$incomingFields['COMPANY_ID'] : $oldCompanyId;

        if ($newContactId === $oldContactId && $newCompanyId === $oldCompanyId) {
            return null;
        }

        return self::evaluateClientFields($id, $newContactId, $newCompanyId, $tag . '-client');
    }

    // Старый путь (например прямое перетаскивание карточки в Канбане).
    public static function onBeforeUpdateLegacy(&$arFields)
    {
        global $APPLICATION;

        $id = isset($arFields['ID']) ? (int)$arFields['ID'] : 0;
        if ($id <= 0) {
            return true;
        }

        // Чужие UF тихо вырезаем (копия Add→Update и «залипшие» значения в форме),
        // а не валим всё сохранение.
        self::stripForbiddenIncomingFields($id, $arFields, 'upd-legacy');

        $permError = self::evaluateFieldEditPermissions($id, $arFields, 'legacy');
        if ($permError !== null) {
            $APPLICATION->ThrowException($permError);
            return false;
        }

        $error = self::evaluate($id, $arFields, 'legacy');
        if ($error !== null) {
            $APPLICATION->ThrowException($error);
            return false;
        }

        // ВРЕМЕННО ОТКЛЮЧЕНО по просьбе пользователя (17.08) — см. пометку в register().
        // $clientError = self::evaluateClientFieldsOnChange($id, $arFields, 'legacy');
        // if ($clientError !== null) {
        //     $APPLICATION->ThrowException($clientError);
        //     return false;
        // }

        $basicError = self::evaluateBasicClientInfo($id, $arFields, 'legacy');
        if ($basicError !== null) {
            $APPLICATION->ThrowException($basicError);
            return false;
        }

        return true;
    }

    // Создание сделки: whitelist при копировании + ACL strip.
    public static function onBeforeAddLegacy(&$arFields)
    {
        if (!is_array($arFields)) {
            return true;
        }
        self::applyCopyFieldWhitelist($arFields, 'add-legacy');
        self::stripForbiddenIncomingFields(0, $arFields, 'add-legacy');
        return true;
    }

    public static function onBeforeAddOrm($event)
    {
        $result = new \Bitrix\Main\ORM\EventResult();

        try {
            $fields = $event->getParameter('fields');
            if (empty($fields) || !is_array($fields)) {
                return $result;
            }

            $cleared = self::applyCopyFieldWhitelist($fields, 'add-orm');
            $stripped = self::stripForbiddenIncomingFields(0, $fields, 'add-orm');
            $unset = array_values(array_unique(array_merge($cleared, $stripped)));
            if ($unset) {
                $result->unsetFields($unset);
            }
        } catch (\Throwable $e) {
            self::log('[add-orm] EXCEPTION: ' . $e->getMessage());
        }

        return $result;
    }

    // Новый путь (в т.ч. форма "заполните обязательные поля").
    // Без типизации параметра намеренно — чтобы не зависеть от точного
    // названия класса события в конкретной версии ядра.
    public static function onBeforeUpdateOrm($event)
    {
        $result = new \Bitrix\Main\ORM\EventResult();

        try {
            $primary = $event->getParameter('id');
            $id = is_array($primary) ? (int)reset($primary) : (int)$primary;
            $fields = $event->getParameter('fields');

            if ($id <= 0 || empty($fields) || !is_array($fields)) {
                return $result;
            }

            $stripped = self::stripForbiddenIncomingFields($id, $fields, 'upd-orm');
            if ($stripped) {
                $result->unsetFields($stripped);
            }

            $permError = self::evaluateFieldEditPermissions($id, $fields, 'orm');
            if ($permError !== null) {
                $result->addError(new \Bitrix\Main\Error($permError));
                return $result;
            }

            $error = self::evaluate($id, $fields, 'orm');
            if ($error !== null) {
                $result->addError(new \Bitrix\Main\Error($error));
                return $result;
            }

            // ВРЕМЕННО ОТКЛЮЧЕНО по просьбе пользователя (17.08) — см. пометку в register().
            // $clientError = self::evaluateClientFieldsOnChange($id, $fields, 'orm');
            // if ($clientError !== null) {
            //     $result->addError(new \Bitrix\Main\Error($clientError));
            // }

            $basicError = self::evaluateBasicClientInfo($id, $fields, 'orm');
            if ($basicError !== null) {
                $result->addError(new \Bitrix\Main\Error($basicError));
            }
        } catch (\Throwable $e) {
            self::log('[orm] EXCEPTION: ' . $e->getMessage());
        }

        return $result;
    }
}
