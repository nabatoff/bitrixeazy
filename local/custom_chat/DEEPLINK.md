# Deep-link КЦ + кнопка в сделке/лиде

## Query-параметры

| URL | Поведение |
|---|---|
| `/local/custom_chat/?chatId=123` | открыть чат по ID (как раньше) |
| `/local/custom_chat/?dialogId=chat123` | открыть по dialogId |
| `/local/custom_chat/?dealId=456` | найти OL-чат сделки → открыть |
| `/local/custom_chat/?leadId=789` | найти OL-чат лида → открыть |

Приоритет: `chatId` / `dialogId` → потом `dealId` / `leadId`.

Обычная страница КЦ без query — без изменений.

## Мобильное приложение Bitrix24

Локальное приложение (вкладки CRM + меню): см. [APP_MOBILE.md](APP_MOBILE.md).

## Кнопка в карточке сделки и лида

Файлы:

- `local/custom_chat/include_crm_button.php` — кнопка «WhatsApp чат» → SidePanel
- `local/php_interface/init.php` — подключает кнопку

Если на портале **уже есть** свой `local/php_interface/init.php`, не перезаписывай его — добавь одну строку:

```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/include_crm_button.php';
```

Залить на портал:

1. `local/custom_chat/index.php`
2. `local/custom_chat/include_crm_button.php`
3. `local/php_interface/init.php` (или строку require в существующий)

Проверка:

- сделка с WA → кнопка «WhatsApp чат» → SidePanel с диалогом клиента
- лид с WA → то же с `?leadId=`
- `/local/custom_chat/` без параметров — список чатов как раньше
