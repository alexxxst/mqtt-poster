# mqtt-poster

Два консольных инструмента для тестового спама MeshCore в MQTT в формате MQTT-наблюдателя:

- `poster.php` — group-text сообщения из файла;
- `drawer.php` — подписанные advert-пакеты с координатами из `dick.txt`, чтобы рисовать узлы на карте.

Публичные ключи из `identities` используются как идентификаторы наблюдателей: `origin_id`, часть топика и основа client id. Один пакет может быть опубликован сразу от нескольких наблюдателей — с одинаковым содержимым и hash, но разными путями, сигналом и статистикой.

## Требования

- PHP 8.2+
- Composer
- MQTT-брокер (для проверки — локальный Mosquitto)
- Расширения `openssl`, `json` и `sodium` (Ed25519-подпись advert); `mbstring` желателен для аккуратной обрезки UTF-8

## Установка

```bash
composer install
cp config.example.json config.json
```

`config.json` не коммитится. Рядом положите `luka.txt` (или укажите другой файл в `packet.message_file`) и `dick.txt` с координатами для `drawer.php`. Progress-файлы создаются автоматически и тоже не коммитятся.

## Быстрый старт

```bash
php poster.php --config=config.json
```

Подписчик:

```bash
mosquitto_sub -h 127.0.0.1 -p 1883 -t 'meshcore/LED/#' -v
```

В `config.example.json` указан локальный Mosquitto `127.0.0.1`. Для другого брокера поменяйте `host`, `username` и `password`.

Будут видны:

- retained `status` каждого наблюдателя в `meshcore/LED/<PUBKEY>/status`;
- group-text пакеты каждого наблюдателя в `meshcore/LED/<PUBKEY>/packets`.

Проверка без брокера:

```bash
php poster.php --config=config.json --dry-run --count=3
```

В stdout печатается JSON пакета, в stderr — расшифрованный текст вида `Nick: message`.

Адверты на карте:

```bash
php drawer.php --config=config.json --dry-run --count=3
php drawer.php --config=config.json
```

`drawer.php` берёт точки из `dick.txt`, подписывает advert-пакет ключом псевдо-узла и публикует его от всех наблюдателей. Progress хранится в `dick.txt.progress`.

Для локального Mosquitto укажите в `config.json`:

```json
"host": "127.0.0.1",
"username": null,
"password": null
```

Подписчик для локальной проверки:

```bash
mosquitto_sub -h 127.0.0.1 -p 1883 -t 'meshcore/LED/#' -v
```

## Конфиг

```json
{
  "mqtt": {
    "host": "127.0.0.1",
    "port": 1883,
    "client_id": null,
    "username": null,
    "password": null,
    "tls": false,
    "tls_verify": true,
    "topic": "meshcore/LED",
    "qos": 0,
    "interval_min_ms": 900,
    "interval_max_ms": 2900,
    "queue_max": 10
  },
  "identities": [
    {
      "public_key": "0A5EF9361B7CD7E4A9E08247D6D8CCA3A1D56B07833A1735E1C7CF160CF3B5A0",
      "node_name": "MOS_VarshW_Top_SPB_OBS",
      "snr": 5.5,
      "rssi": -86,
      "noise_floor": -110,
      "battery_mv": 3880,
      "uptime_secs": 12345
    },
    {
      "public_key": "43BE0D6EDC7AD029F71B67F54E151B40C1C421284A35ECF721DECC2329BE2AAD",
      "node_name": "MOS_VarshW_Top_SPB_OBS_2",
      "snr": 4.2,
      "rssi": -95,
      "noise_floor": -107,
      "battery_mv": 4010,
      "uptime_secs": 31842
    }
  ],
  "device": {
    "model": "Heltec V3",
    "firmware_version": "v1.16.0",
    "client_version": "mqtt-poster",
    "radio": "868.856,62.5,7,7"
  },
  "status": {
    "enabled": true,
    "retain": true,
    "interval_ms": 300000,
    "errors": 0,
    "queue_len": 1,
    "tx_air_secs": 0,
    "rx_air_secs": 0,
    "recv_errors": 0
  },
  "packet": {
    "channel_secret": "8b3387e9c5cdea6ac9e5edbaa115cd72",
    "message_file": "luka.txt",
    "progress_file": "luka.txt.progress",
    "loop": true,
    "retain": true,
    "direction": "rx",
    "snr_min": -3.0,
    "snr_max": 8.5,
    "snr_step": 0.6,
    "rssi_min": -104,
    "rssi_max": -74,
    "rssi_step": 3,
    "score": 1000,
    "duration": 120,
    "observer_delay_min_ms": 150,
    "observer_delay_max_ms": 450,
    "path": {
      "neighbors": [
        "A000", "A111", "ABCD", "0000", "5555", "BBBB", "DEAD", "8E8E", "77AA",
        "60D3", "AF5B", "C95E", "356E", "B00B", "5054", "5F5D", "B85E", "3D38"
      ],
      "min_hops": 3,
      "max_hops": 7,
      "direct_probability": 0.1
    }
  },
  "advert": {
    "coordinate_file": "dick.txt",
    "progress_file": "dick.txt.progress",
    "key_seed": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
    "node_type": 2,
    "loop": false,
    "publish_status": true,
    "direct_probability": 0.0
  },
  "template_file": "chat.tpl"
}
```

Список `packet.path.neighbors` в примере — заглушки. Замените их на реальные 2-байтные hex-префиксы соседей.

### Поля

| Поле | Описание |
|---|---|
| `mqtt.host`, `mqtt.port` | Адрес брокера |
| `mqtt.client_id` | Если `null`, генерируется `mqtt_meshcore-<первые 6 PUBKEY>`. При нескольких наблюдателях к явно заданному id добавляется `-<первые 6 PUBKEY>` |
| `mqtt.username`, `mqtt.password` | Учётные данные брокера; для локального Mosquitto — `null` |
| `mqtt.topic` | Префикс топика, обычно `meshcore/<IATA>` |
| `mqtt.qos` | QoS для пакетов, 0–2 |
| `mqtt.interval_min_ms`, `interval_max_ms` | Случайная задержка между пакетами, мс |
| `mqtt.queue_max` | Максимальная очередь |
| `identities[]` | Непустой массив наблюдателей. Старый одиночный `identity` поддерживается |
| `identities[].public_key` | 64 hex-символа, идентификатор наблюдателя |
| `identities[].node_name` | `origin` в JSON |
| `identities[].client_id` | MQTT client id наблюдателя; по умолчанию выводится из ключа |
| `identities[].snr`, `rssi` | Стартовый сигнал наблюдателя; необязательно |
| `identities[].noise_floor` | База `noise_floor` в status; необязательно |
| `identities[].battery_mv`, `uptime_secs` | База статистики наблюдателя; необязательно |
| `device.*` | Данные для `status` |
| `status.*` | База для генерации online-статистики |
| `packet.channel_secret` | 32 hex; по умолчанию публичный канал |
| `packet.message_file` | Файл сообщений: `<nick> <text>` на строку |
| `packet.progress_file` | Файл прогресса; по умолчанию `<message_file>.progress` |
| `packet.loop` | Зацикливать файл после последней строки |
| `packet.retain` | Retain для основного топика `packets` (последнее сообщение) |
| `packet.direction` | `rx` или `tx` |
| `packet.snr_min`, `snr_max`, `snr_step` | Границы и шаг random walk SNR |
| `packet.rssi_min`, `rssi_max`, `rssi_step` | Границы и шаг random walk RSSI |
| `packet.score`, `duration` | Поля качества в JSON |
| `packet.path.neighbors` | Массив 2-байтных hex-префиксов |
| `packet.path.min_hops`, `max_hops` | Диапазон длины пути в хопах |
| `packet.path.direct_probability` | Вероятность direct без пути (0..1); иначе flood с путём |
| `packet.observer_delay_min_ms`, `observer_delay_max_ms` | Случайная задержка между отправкой одному наблюдателю и следующему, мс |
| `advert.coordinate_file` | Файл с точками `<lat>, <lon>` |
| `advert.progress_file` | Progress `drawer.php`; по умолчанию `<coordinate_file>.progress` |
| `advert.key_seed` | 64 hex; мастер-seed для стабильных Ed25519-ключей и имён точек |
| `advert.node_type` | Тип advert-узла: `2` — repeater |
| `advert.loop` | Зацикливать файл точек |
| `advert.publish_status` | Публиковать ли status/LWT наблюдателей из `drawer.php` |
| `advert.direct_probability` | `0` — flood с путём; `1` — direct без пути; по умолчанию `0` |
| `template_file` | Файл шаблона тела сообщения |

Публичный ключ — это идентификатор. Для group-text приватный ключ не нужен: сообщения публичного канала не подписываются. Для advert `drawer.php` выводит Ed25519-ключи из `advert.key_seed`.

## Наблюдатели (identities)

Каждый элемент `identities` — отдельный observer со своим MQTT-соединением, client id, статусом и LWT. При старте каждый наблюдатель подключается и публикует retained `status: online` в свой топик.

Для каждой строки из файла сообщений:

1. Тело, timestamp, зашифрованный payload и `hash` генерируются **один раз**.
2. Каждый наблюдатель публикует этот же пакет от своего имени:
   - свой `origin`/`origin_id`;
   - свой путь в `raw` (поле `path` — только для direct с путём) и, следовательно, свой `len`;
   - свои `SNR`/`RSSI`;
   - в свой топик `.../<PUBKEY>/packets`.
3. Между наблюдателями выдерживается случайная задержка `observer_delay_min_ms..observer_delay_max_ms`.

`hash` у всех наблюдателей одинаковый: он считается от payload и не зависит от пути. Содержимое после расшифровки тоже одинаковое.

Каждый наблюдатель имеет собственную очередь. Если один наблюдатель недоступен, остальные продолжают публикацию; недоставленная строка остаётся в его очереди и повторяется без повторной постановки в очередь. Progress сдвигается только после того, как строку опубликовали все наблюдатели.

## Адверты на карте (drawer.php)

`drawer.php` читает `dick.txt`, по одной точке на строку:

```text
<lat>, <lon>
```

Для каждой точки генерируется стабильная пара ключей и имя:

```text
seed    = HMAC-SHA256(advert.key_seed, "advert-v1|<line>|<lat_e6>|<lon_e6>|<counter>")
keypair = Ed25519(seed)

# Санкт-Петербург
name = "<CODE>-<FemaleName>-<DIR>"
# Ленинградская область
name = "LO-<CODE>-<FemaleName>-<DIR>"
# Другой регион
name = "<IATA>-<FemaleName>-<DIR>"
```

`CODE` — трёхбуквенный код района/города по правилам `meshcore.spb.ru/wiki/rules`. Он выбирается по ближайшему центру района из встроенной таблицы. `IATA` — код ближайшего региона из встроенной таблицы (например `KLD`, `CEE`, `PKV`). Для Великого Новгорода и Новгородской области используется `LED-...`. `FemaleName` — русское женское имя в латинской транслитерации из встроенного списка; выбор стабилен для точки и `advert.key_seed`, повторы допустимы. `DIR` — `N`, `NE`, `E`, `SE`, `S`, `SW`, `W`, `NW` или `Omni`, вычисляется по азимуту от центра района к точке.

Ключи и имена не меняются между запусками и после `--reset-progress`, пока не изменён `advert.key_seed` или файл точек.

Advert-пакет собирается по правилам MeshCore:

```text
flags   = 0x92              # repeater + lat/lon + name
app_data = flags + lat_e6(4 LE) + lon_e6(4 LE) + name
signed   = pubkey(32) + timestamp(4 LE) + app_data
signature = Ed25519(signed, private_key)

payload  = pubkey(32) + timestamp(4 LE) + signature(64) + app_data
raw      = header + path_len + path + payload
hash     = SHA256(0x04 + payload)[0:8]
```

- `header = 0x11` и `path_len = 0x40 | hops` для flood с путём;
- `header = 0x12`, `path_len = 0x00` для direct без пути;
- путь фиксирован для каждого наблюдателя: берутся `min_hops` реальных префиксов из `packet.path.neighbors`, начиная со смещения по pubkey наблюдателя; собственный префикс наблюдателя исключается;
- `advert.direct_probability: 0` — flood, `1` — direct; промежуточные значения не используются;
- в JSON flood-пакета `route` = `"F"`, поля `path` нет; путь остаётся в `raw`.

Публикация:

- fake-узел не подключается к MQTT: у него нет своего топика и `status`;
- регион выбирается по координатам: для Ленобласти — `LED`, иначе ближайший IATA из встроенной таблицы (`PKV`, `VLU`, `CEE`, `VGD`, `PES`, `KLD`, `MOW` и т.д.);
- Великий Новгород и примерная область Новгородской области (радиус 160 км) тоже идут в `LED`: отдельного региона в списке нет;
- каждый наблюдатель публикует ровно одну `rx`-копию услышанного advert’а в `<prefix>/<REGION>/<OBSERVER_PUBKEY>/packets`;
- при первом пакете в новом регионе наблюдатель дополнительно публикует retained `status: online` в `<prefix>/<REGION>/<OBSERVER_PUBKEY>/status`;
- домашний статус наблюдателя остаётся в его регионе из `mqtt.topic`;
- у каждого наблюдателя свой фиксированный путь в `raw`, свой `len`, `SNR`/`RSSI`;
- `hash` и advert payload одинаковые у всех `rx`-копий;
- `raw` — это принятый пакет: `header + path_len + path + payload`;
- `retain = false`, QoS 1 — как в `MQTTUplink::publishPacket()`;
- между наблюдателями действует `packet.observer_delay_min_ms..observer_delay_max_ms`.

`drawer.php` использует отдельный client id:

```text
mqtt_mc_drawer_<первые 8 hex PUBKEY>
```

Поэтому его можно держать запущенным одновременно с `poster.php`: брокер не выбивает соединение poster’а. Формат advert-пакета при этом остаётся как у прошивки.

`advert.publish_status: true` заставляет `drawer.php` публиковать `status: online`/LWT для observer-соединений. Это статус реальных наблюдателей, а не fake-узлов.

Для появления fake-репитера в разделе «Узлы» его advert должен быть опубликован наблюдателями как flood `rx` с реальным путём из `packet.path.neighbors`. Direct-пакеты для регистрации узла не подходят.

Progress `drawer.php` хранится в `advert.progress_file` и двигается только после публикации точки всеми наблюдателями. Поддерживаются `--count=N`, `--dry-run` и `--reset-progress`.

## Файл сообщений и шаблон

Сообщения берутся из `packet.message_file` (по умолчанию `luka.txt`). Каждая непустая строка:

```text
<nick> <text>
```

- первое слово — ник отправителя;
- всё остальное — текст сообщения;
- пустые строки пропускаются;
- строки без текста (только ник) пропускаются с warning.

`chat.tpl` форматирует тело:

```text
{nick}: {text}
```

`nick` и `text` — значения из текущей строки файла. Случайные `{{seq}}`/`{{random}}` больше не используются.

## Прогресс

В `packet.progress_file` (для `poster.php`) и `advert.progress_file` (для `drawer.php`) хранится номер следующей строки:

```json
{"next_line": 42}
```

- прогресс сохраняется после успешной публикации сообщения **всеми** наблюдателями и self-advert’а fake-узла;
- пока хотя бы один наблюдатель или self-advert не опубликован, прогресс не двигается;
- при старте, остановке и ошибке прогресс сохраняется;
- `--dry-run` прогресс не двигает;
- после последней строки при `loop: true` чтение начинается заново, при `loop: false` — завершение.

Сброс:

```bash
php poster.php --config=config.json --reset-progress
php drawer.php --config=config.json --reset-progress
```

## Время и задержка

Пакеты используют реальное Unix-время (секунды). При задержке 0.5–2 секунды несколько пакетов могут иметь одинаковый `timestamp` — это допустимо.

Задержка между сообщениями случайная:

```text
random(interval_min_ms..interval_max_ms)
```

В примере — 900–2900 мс. Дополнительно между наблюдателями одного пакета выдерживается `observer_delay_min_ms..observer_delay_max_ms`.

- `timestamp`, `time` и `date` в JSON и timestamp внутри зашифрованного пакета берутся из одного значения;
- `status.timestamp` — текущее время;
- `uptime_secs` монотонно растёт от базового значения у каждого наблюдателя;
- счётчики `tx_air_secs`/`rx_air_secs` монотонно растут.

## Сигнал и статистика

RSSI и SNR каждого наблюдателя меняются не независимо, а random walk от его стартовых значений:

```text
snr  = clamp(snr  + random(-snr_step..+snr_step),  snr_min,  snr_max)
rssi = clamp(rssi + random(-rssi_step..+rssi_step), rssi_min, rssi_max)
```

Стартовые `snr`/`rssi` задаются в `identities[]`; если поле не указано, используется значение по умолчанию с небольшим разбросом. Для каждого пакета значения попадают в JSON как строки, например `"SNR":"4.9"`, `"RSSI":"-84"`.

Статистика `status` каждого наблюдателя генерируется от его базы (`identities[].battery_mv`, `uptime_secs`, `noise_floor`):

- `uptime_secs` = база + прошедшие секунды;
- `battery_mv` очень медленно снижается;
- `noise_floor` = база ± 2;
- `errors` и `recv_errors` остаются 0;
- `queue_len` = 1;
- `tx_air_secs` = база + ~1% прошедшего времени, монотонно;
- `rx_air_secs` = база + ~5% прошедшего времени, монотонно;
- оба air-счётчика всегда сильно меньше `uptime_secs`.

## Пути пакетов

`packet.path.direct_probability` — вероятность **direct без пути**. Иначе пакет идёт flood с путём из `min_hops..max_hops` соседей. При `0.1` примерно 10% пакетов — direct без пути, 90% — flood с путём.

Flood с путём:

```text
header   = 0x15              # GRP_TXT + ROUTE_TYPE_FLOOD
path_len = 0x40 | hop_count  # 2-байтные префиксы
raw      = 0x15 + path_len + path_bytes + payload
```

В JSON:

```json
"route": "F"
```

Поля `path` нет — путь остаётся только в `raw`. Так же поступает прошивка MeshCore в `MQTTUplink::buildPacketJson`: `path` выводится только для direct-пакета с непустым путём.

Direct с путём (наш генератор сейчас его не создаёт, но формат поддержан):

```json
"route": "D",
"path": "path_3x2_6b"
```

Direct / zero-hop без пути:

```text
header   = 0x16
path_len = 0x00
raw      = 0x16 0x00 + payload
```

В JSON:

```json
"route": "F"
```

Поля `path` нет. В прошивке `route: "D"` появляется только при `route_type = DIRECT` **и** непустом пути; zero-hop direct в JSON выглядит как flood.

`len` для flood включает байты пути; `hash` по-прежнему считается от `0x05 + payload`, путь в него не входит.

Если список соседей пуст, путь построить нельзя — пакет идёт direct без пути.

## Формат group-text пакета

Используется публичный PSK:

```text
8b3387e9c5cdea6ac9e5edbaa115cd72
```

Сборка:

```text
body      = "{nick}: {text}"
plaintext = timestamp_le(4) + 0x00 + body
cipher    = AES-128-ECB(plaintext, PSK[0..15], zero padding)
mac       = HMAC-SHA256(cipher, PSK + 16 zero bytes)[0..1]
payload   = channel_hash(1) + mac(2) + cipher
raw       = header + path_len + path + payload
hash      = SHA256(0x05 + payload)[0..7]
```

`packet_type` = `5`. Поля `len`, `packet_type`, `payload_len`, `SNR`, `RSSI`, `score`, `duration` в JSON — строки, как в прошивке.

Текст сообщения лежит внутри зашифрованного payload; в JSON виден только `raw`. Для проверки используйте `tests/meshcore-format.php` или `--dry-run` (он печатает текст в stderr).

## Status

При старте каждый наблюдатель публикует retained `status: online` в свой топик:

```text
<topic>/<PUBKEY>/status
```

У каждого наблюдателя свой LWT — retained `status: offline` на случай аварийного обрыва. При штатном завершении (`--count` или `Ctrl+C`) приложение закрывает MQTT-соединения, LWT не срабатывает, и retained status может остаться `online`.

Пакеты публикуются в:

```text
<topic>/<PUBKEY>/packets
```

для каждого наблюдателя отдельно.

При `packet.retain: true` последний пакет остаётся в этом топике. Публикуется ровно одна копия пакета — как в прошивке MeshCore. Архивных подтопиков `packets/...` больше нет: они приводили к повторной отправке и повторному приёму пакетов сервером.

История сообщений хранится на стороне сервера и доступна через его API/интерфейс. MQTT retain оставляет только последнее сообщение в `packets`.

## Очередь и ошибки

- У каждого наблюдателя своя очередь в памяти, максимум `queue_max`.
- При недоступном брокере сообщения копятся и потом уходят FIFO; остальные наблюдатели продолжают работу.
- При переполнении очереди любого наблюдателя — ошибка в stderr и exit 1.
- `--count=N` досушивает очереди; если что-то не ушло — exit 1.
- `Ctrl+C` сохраняет прогресс и останавливает цикл; неотправленные очереди теряются, но при следующем запуске сообщения начнутся с последней сохранённой строки. Возможны дубликаты у наблюдателей, которые уже успели опубликовать строку.

QoS 1/2 ждёт ack до 5 секунд; при таймауте сообщение остаётся в очереди. Повторяется уже стоящий в очереди пакет, а не создаётся новый. На повторные попытки даётся до 10 секунд, затем exit 1. QoS 0 — без ожидания.

TLS: `tls: true`, `tls_verify: false` разрешает self-signed. Кастомный CA пока не поддерживается.

## CLI

```bash
php poster.php [--config=path] [--dry-run] [--count=N] [--reset-progress] [--help]
```

- `--reset-progress` — удалить progress-файл и начать с первой строки;
- `--count=N` — отправить N сообщений и остановиться.

Коды выхода: `0` — успех, `1` — ошибка.

## Тесты

```bash
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

- `meshcore-format.php` — сборка/дешифрование group-text, пути, сигнал, статистика, hash, JSON, topics;
- `messages-format.php` — парсинг файла сообщений, пропуск пустых строк, progress load/save/reset;
- `observers-queue.php` — общий payload/hash, разные пути, отдельные очереди и progress после всех наблюдателей;
- `config-clean.php` — конфиг без глобальных `snr`/`rssi`/статистики, legacy `identity`, блок `advert`;
- `advert-format.php` — app_data, координаты, имена, Ed25519-подпись, advert payload/packet/hash;
- `meshcore-integration.sh` — локальный Mosquitto, одиночный `identity`: status + 3 пакета, retained;
- `identities-integration.sh` — два наблюдателя: отдельные status/топики/сигналы, одинаковый hash, разные пути, progress после обоих;
- `advert-integration.sh` — локальный Mosquitto, 3 точки и 2 наблюдателя, подпись, координаты, стабильность ключей и progress;
- `ack-timeout.sh` — фейковый брокер без PUBACK.

## Ограничения

- Пакеты синтетические: они не отправляются в радиоэфир, только в MQTT.
- Advert-узлы тоже синтетические: ключи выводятся из `advert.key_seed`, реальные устройства за ними не стоят.
- JWT, WSS и letsmesh не поддерживаются.
- Один топик-префикс, один публичный канал; `channel_secret` можно заменить вручную.
- MQTT retain хранит только последнее сообщение в `packets`; история сообщений хранится на стороне сервера.
- Очередь не сохраняется на диск; progress сохраняется.
- Нет MQTT 5 и подписки.
- В консоль пишется краткая строка об отправленном сообщении.
