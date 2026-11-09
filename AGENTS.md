# AGENTS.md

## Что это за проект

Два небольших PHP CLI-инструмента для публикации синтетических MeshCore-пакетов в MQTT:

- `poster.php` — публикует group-text сообщения из текстового файла;
- `drawer.php` — публикует подписанные advert-пакеты репитеров по координатам из файла, чтобы рисовать узлы на карте.

Проект намеренно простой: без фреймворка, без БД, без MQTT 5, без подписки на топики. Основная логика — в процедурных функциях.

Публичный репозиторий: <https://github.com/alexxxst/mqtt-poster>

## Быстрые команды

```bash
composer install
cp config.example.json config.json

# сообщения
php poster.php --config=config.json
php poster.php --config=config.json --dry-run --count=3

# адверты
php drawer.php --config=config.json
php drawer.php --config=config.json --dry-run --count=3

# полный набор тестов
php tests/meshcore-format.php
php tests/messages-format.php
php tests/observers-queue.php
php tests/config-clean.php
php tests/advert-format.php
bash tests/meshcore-integration.sh
bash tests/identities-integration.sh
bash tests/advert-integration.sh
bash tests/ack-timeout.sh
```

Для интеграционных тестов нужны `mosquitto`, `mosquitto_sub` и `nc`. Тесты используют локальные порты `18883`, `18887`, `18888` и фейковый брокер на `18886`. `ack-timeout.sh` идёт около 15–16 секунд.

## Структура

- `poster.php` — общий runtime: конфиг, наблюдатели, MQTT, очереди, progress, CLI `poster.php`.
- `drawer.php` — CLI `drawer.php`; использует общий runtime из `poster.php`.
- `src/meshcore.php` — group-text payload, MeshCore packet/path, observer JSON, topics, client id.
- `src/messages.php` — чтение файла сообщений и progress.
- `src/advert.php` — advert payload, Ed25519, имена, регионы, координаты.
- `config.example.json` — пример конфига; `config.json` — локальный и не коммитится.
- `tests/` — unit- и integration-тесты.
- `README.md` — пользовательская документация.

## Секреты и игнорируемые файлы

Никогда не коммитить:

- `config.json`;
- `luka.txt`, `dick.txt` и их `.progress`;
- `vendor/`.

В `config.example.json` должны быть только placeholder-значения. Реальный `advert.key_seed`, пароли и токены в репозиторий не попадают. `advert.key_seed` — это мастер-seed для приватных ключей синтетических advert-узлов.

Не добавлять в код и документацию названия конкретных сторонних сервисов/прошивок. Проект должен оставаться нейтральным: «MQTT-брокер», «MeshCore», «наблюдатель».

## Модель работы

`identities` — это MQTT-наблюдатели. У каждого:

- свой публичный ключ;
- свой MQTT client id;
- своё соединение;
- retained `status` и LWT `offline`;
- своя очередь и свои `SNR`/`RSSI`.

Один и тот же пакет публикуется от нескольких наблюдателей:

- payload и hash одинаковые;
- `raw`, путь, `len`, `SNR`, `RSSI` — разные;
- topic — `<prefix>/<PUBKEY>/packets` (или региональный topic для advert, см. ниже).

`poster.php` не создаёт отдельных MQTT-клиентов на сообщение: соединения живут в процессе.

## Очереди и progress

- У каждого наблюдателя очередь в памяти, максимум `mqtt.queue_max`.
- Progress двигается только после того, как строку опубликовали **все** наблюдатели.
- При ошибке отправки строка **не ставится в очередь повторно**. Уже стоящий пакет ретраится через `drainAllObserverQueues()`.
- Ретраи идут до 10 секунд, затем `exit 1` с `messages not delivered after retries`.
- QoS 1/2 ждёт PUBACK до 5 секунд. QoS 0 — без ожидания.
- `Ctrl+C` сохраняет progress и останавливает цикл; неотправленные очереди теряются.
- `--count=N` досушивает очереди; если что-то не ушло — exit 1.
- `--reset-progress` удаляет progress и начинает с первой строки.

Не убивать уже запущенный `poster.php` и не сбрасывать progress без явной просьбы пользователя.

## poster.php: group-text

Формат пакета:

```text
payload  = channel_hash(1) + MAC(2) + AES-128-ECB(timestamp + 0x00 + text)
hash     = SHA256(0x05 + payload)[0:8]
raw      = header + path_len + path + payload
```

- `header = 0x15` для flood;
- `header = 0x16`, `path_len = 0x00` для direct zero-hop;
- путь строится из `packet.path.neighbors`, `min_hops`, `max_hops`, `direct_probability`;
- в JSON flood-пакета `route = "F"`, поля `path` нет;
- в JSON direct-пакета с непустым путём `route = "D"`, есть `path`;
- `retain` берётся из `packet.retain`;
- `direction` — `rx` или `tx` из `packet.direction`.

Файл сообщений: одна строка `<nick> <text>`. Пустые строки пропускаются, строки без текста — с warning.

## drawer.php: advert-репитеры

Синтетический репитер **не подключается к MQTT**. Его advert публикуют наблюдатели как `rx`.

### Advert payload

```text
flags    = 0x92  # repeater + lat/lon + name
app_data = flags + lat_e6(4 LE) + lon_e6(4 LE) + name
signed   = pubkey(32) + timestamp(4 LE) + app_data
signature = Ed25519(signed, private_key)
payload  = pubkey(32) + timestamp(4 LE) + signature(64) + app_data
hash     = SHA256(0x04 + payload)[0:8]
```

Ключи выводятся детерминированно из `advert.key_seed`, номера строки и координат. Ключи и имена стабильны между запусками.

### Путь и публикация

- Путь фиксирован для наблюдателя: `min_hops` реальных префиксов из `packet.path.neighbors`, начиная со смещения по pubkey наблюдателя.
- Собственный префикс наблюдателя из пути исключается.
- Flood: `header = 0x11`, `path_len = 0x40 | hops`.
- Direct: `header = 0x12`, `path_len = 0x00`; для появления узла на карте не использовать.
- Пакеты advert публикуются с `retain = false`, QoS 1.
- `drawer.php` использует отдельный client id `mqtt_mc_drawer_<8 hex PUBKEY>`, поэтому может работать одновременно с `poster.php`.
- Один advert — одна `rx`-копия от каждого наблюдателя.

### Регион

Регион выбирается по координатам:

- Ленинградская область и Санкт-Петербург → `LED`;
- Великий Новгород и примерная Новгородская область (радиус 160 км) → `LED`;
- иначе — ближайший IATA-код из встроенной таблицы (`KLD`, `CEE`, `PKV`, `VLU`, `VGD`, `PES`, `MOW` и т.д.).

Топик advert-пакета:

```text
<prefix>/<REGION>/<OBSERVER_PUBKEY>/packets
```

Перед первым пакетом в чужом регионе наблюдатель публикует retained `status: online` в:

```text
<prefix>/<REGION>/<OBSERVER_PUBKEY>/status
```

Домашний статус наблюдателя остаётся в регионе из `mqtt.topic`.

### Имена

Правила именования: <https://meshcore.spb.ru/wiki/rules>

```text
СПб:              <DISTRICT>-<FemaleName>-<DIR>
Ленобласть:       LO-<DISTRICT>-<FemaleName>-<DIR>
Другой регион:    <IATA>-<FemaleName>-<DIR>
Новгород:         LED-<FemaleName>-<DIR>
```

- `DISTRICT` — трёхбуквенный код района из встроенной таблицы;
- `FemaleName` — русское женское имя латиницей, выбирается детерминированно, повторы допустимы;
- `DIR` — `N`, `NE`, `E`, `SE`, `S`, `SW`, `W`, `NW` или `Omni`;
- имя не длиннее 23 байт, без пробелов, кириллицы и спецсимволов.

## Конфиг

Основные поля:

- `mqtt.host`, `mqtt.port`, `mqtt.username`, `mqtt.password`, `mqtt.tls`;
- `mqtt.topic` — базовый префикс, обычно `meshcore/LED`;
- `mqtt.client_id` — если `null`, генерируется из pubkey;
- `mqtt.qos`, `mqtt.queue_max`, `mqtt.interval_min_ms`, `mqtt.interval_max_ms`;
- `identities[]` — `public_key`, `node_name`, `snr`, `rssi`, `noise_floor`, `battery_mv`, `uptime_secs`;
- `packet.*` — сообщения, progress, пути, сигнал, retain, direction;
- `advert.*` — координаты, progress, key_seed, node_type, loop, publish_status, direct_probability;
- `template_file` — шаблон текста сообщения.

Нормализация конфига находится в `normalizeConfig()` / `normalizeAdvertConfig()` в `poster.php`. Новые поля добавлять туда, а не читать сырой конфиг по коду.

## Тесты и проверки

Перед любым коммитом прогнать полный набор тестов из раздела «Быстрые команды». Все тесты должны быть `PASS`.

Не публиковать в реальный брокер без явной просьбы: это создаёт реальные пакеты/узлы. Для проверки использовать локальный Mosquitto и `--dry-run`.

## Git и публикация

- Основная ветка — `main`.
- История очищена от старых секретов; не добавлять их снова.
- Не переписывать историю и не пушить без явной просьбы.
- Коммитить только по явной просьбе пользователя.
- Remote: `origin` → `https://github.com/alexxxst/mqtt-poster.git`.
