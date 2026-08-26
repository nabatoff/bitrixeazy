# Asterisk Beeline 3888 / 8099

Каталог на сервере: `/home/dockeradm/asterisk`
Контейнер: `asterisk-beeline` (host network, UDP 5060, RTP 10000-10100)

## Статус
```
docker exec asterisk-beeline asterisk -rx 'pjsip show registrations'
```
Ожидается `Registered` для `beeline-3888-reg` и `beeline-8099-reg`.

## Откат исходящих в Bitrix
Вернуть сервер офисных АТС на Билайн:
```
python tools/ssh_switch_bitrix_sip.py C:\Users\15bit\.ssh\id_rsa_bitrix --rollback
```
Это ставит `46.227.186.231:6050` обратно в `b_voximplant_sip` для 3888 и 8099.

Остановить Asterisk:
```
cd /home/dockeradm/asterisk && docker compose down
```

## Переключение на Asterisk снова
```
python tools/ssh_switch_bitrix_sip.py C:\Users\15bit\.ssh\id_rsa_bitrix
```

Облачные 10 линий не трогать.
