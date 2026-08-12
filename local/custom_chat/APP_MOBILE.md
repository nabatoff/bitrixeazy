# WhatsApp в мобильном Bitrix24

## Почему был белый экран

Галка **«Поддерживает BitrixMobile»** обязательна (иначе пункта нет в меню телефона).

Но в **обработчике** локального приложения **нельзя** делать:
```php
require .../prolog_before.php;
```
На iOS/Bitrix WebView это → белый экран (известная тема на форуме Битрикс).  
`ping` с prolog тоже был белый — не баг ping.

## Как сейчас устроено

1. `app/index.php` / `placement.php` — **без prolog**
2. `user.current` по `AUTH_ID` (REST)
3. one-time `wa_tok` → `location.replace` на `/local/custom_chat/?wa_embed=1&wa_mobile=1&wa_tok=…`
4. КЦ — обычная страница портала (prolog там ок)

Галка BitrixMobile — **оставить**. Desktop — iframe с тем же tok.

## Залей

- `app/auth.php`, `app/shell.php`, `app/index.php`, `app/placement.php`, `app/ping.php`
- `index.php` (consume `wa_tok`)

Проверка с телефона: должно мелькнуть зелёное «Открываю…» и список чатов.

Диагностика без смены handler: временно handler → `.../app/ping.php` (теперь без prolog, зелёный JSON).

## Таймлайн

Placement ≠ запись в ленте. Старые лиды:  
`/local/custom_chat/ol_line_leads_run.php?leadId=332315&timeline=1`
