# CLAUDE.md
Еще тут мой проект форк https://github.com/AntikFull/fptn-keenetic для роутера Кинетик, Скачан в "F:\Antigravity\testovoe\fptn-master\fptn-keenetic-master"
Для андроид в "F:\Antigravity\testovoe\fptn-master\FptnClient-Android-develop"

## Локальная компиляция и кросс-компиляция на ПК (Windows 11 + WSL 2)

### 1. Настройка окружения на хосте (Windows 11)
Для сборки Windows-клиента должны быть установлены:
- **Visual Studio 2022 Community** (с рабочей нагрузкой "Разработка классических приложений на C++").
- **Python 3.x** и **Conan**: `pip install conan`.
- **CMake**: `winget install kitware.cmake`.

### 2. Сборка бинарника для Keenetic (ARM/MIPS)

> ⚠️ **Основной способ — GitHub Actions, а не локальная сборка.**
> В репозитории уже есть готовый рецепт `.github/workflows/build-keenetic.yml`, который собирает `fptn-client-cli` сразу под три архитектуры (`aarch64`, `armv7`, `mipsel`) со статической линковкой и кэшем Conan. Локальная кросс-сборка тех же зависимостей (boost 1.90, protobuf 5.29, mimalloc — всё из исходников под чужую архитектуру) занимает часы и легко расходится с тем, что уходит в релиз.

**Запуск сборки:**
- Вручную: вкладка Actions → workflow «Build FPTN Client for Keenetic» → Run workflow (событие `workflow_dispatch` уже настроено).
- Автоматически: любой push в `master`/`main`, а при push тега бинарники дополнительно прикрепляются к GitHub Release.

Готовые бинарники забираются из артефактов сборки (`fptn-client-cli-aarch64` и т.д.) и кладутся в `deploy/keenetic/bin/`. Начиная с текущей версии workflow прогоняет их через `strip --strip-unneeded`: раньше в релиз уходил бинарник с отладочной информацией на ~50 МБ.

**Локальная кросс-сборка (только если Actions недоступен).** Профиль обязан совпадать с CI, иначе результат будет отличаться от релизного. Точные значения флагов — в матрице `build-keenetic.yml`; ниже пример для `aarch64`:
```bash
wsl
sudo apt update
sudo apt install -y build-essential cmake ninja-build pkg-config gcc-aarch64-linux-gnu g++-aarch64-linux-gnu python3-pip
pip3 install conan --break-system-packages
cd /mnt/f/Antigravity/testovoe/fptn-master/fptn-keenetic-master
conan profile detect --force

# Профиль кросс-компиляции — копия того, что делает CI
echo 'set(MI_NO_OPT_ARCH ON CACHE BOOL "" FORCE)'  > ~/.conan2/profiles/global_user_toolchain.cmake
echo 'set(MI_OPT_ARCH OFF CACHE BOOL "" FORCE)'   >> ~/.conan2/profiles/global_user_toolchain.cmake
cat > ~/.conan2/profiles/aarch64_cross << 'EOF'
[settings]
os=Linux
arch=armv8
compiler=gcc
compiler.version=11
compiler.libcxx=libstdc++11
compiler.cppstd=gnu17
build_type=Release

[buildenv]
ASFLAGS=-DOPENSSL_PTHREADS -pthread -march=armv8-a

[conf]
tools.build:compiler_executables={"c": "aarch64-linux-gnu-gcc", "cpp": "aarch64-linux-gnu-g++"}
tools.build:cflags=["-DOPENSSL_PTHREADS", "-pthread", "-march=armv8-a", "-mno-outline-atomics"]
tools.build:cxxflags=["-DOPENSSL_PTHREADS", "-pthread", "-march=armv8-a", "-mno-outline-atomics"]
tools.cmake.cmaketoolchain:user_toolchain=["$HOME/.conan2/profiles/global_user_toolchain.cmake"]
EOF

conan install . --output-folder=build --build=missing -pr:h=aarch64_cross -pr:b=default
cd build
cmake .. -DCMAKE_TOOLCHAIN_FILE=conan_toolchain.cmake -DCMAKE_BUILD_TYPE=Release -DCMAKE_EXE_LINKER_FLAGS="-static"
cmake --build . --config Release --target fptn-client-cli -j$(nproc)
aarch64-linux-gnu-strip --strip-unneeded src/fptn-client/fptn-client-cli
file src/fptn-client/fptn-client-cli   # должно быть: ARM aarch64, statically linked, stripped
```

### 2a. Быстрая проверка компиляции под x86_64 Linux
Роутерный бинарник этим **не** получить — это только проверка, что код собирается:
```bash
conan install . --output-folder=build-x86 --build=missing -s compiler.cppstd=17 -o with_gui_client=False --settings build_type=Release
cd build-x86
cmake .. -DCMAKE_TOOLCHAIN_FILE=conan_toolchain.cmake -DCMAKE_BUILD_TYPE=Release
cmake --build . --config Release --target fptn-client-cli -j$(nproc)
```

> ℹ️ `fptn-passwd` собирается в `build-keenetic.yml` начиная с v1.2.0. До этого workflow делал только цель `fptn-client-cli`, поэтому лежавший в репозитории `deploy/keenetic/bin/fptn-passwd-aarch64` был фактически собран под x86-64 и на роутере не запускался. Цель объявлена в `CMakeLists.txt` внутри `if (APPLE OR UNIX)`, что при кросс-компиляции под Linux выполняется, поэтому она доступна для всех трёх архитектур роутера.
>
> Проверять архитектуру бинарников после сборки обязательно: `file deploy/keenetic/bin/*` должен давать `ARM aarch64 ... statically linked ... stripped`.

### 3. Выпуск нового релиза (Правило для ИИ и разработчика)
При выпуске новой версии FPTN Keenetic **ОБЯЗАТЕЛЬНО** обновите версию в следующих файлах:
1.  **`deploy/keenetic/version.txt`** — запишите туда новый тег версии целиком (например, `v1.0.2-keenetic`). Это критически важно, так как веб-панель использует этот файл для проверки обновлений!
2.  **`deploy/keenetic/index.php`** — обновите константу `define('CURRENT_VERSION', 'v1.0.X-keenetic');`
3.  **`deploy/keenetic/install.sh`** — обновите переменную `DOWNLOAD_URL` на новый тег.
4.  **`deploy/keenetic/README.md`** — обновите ссылки для скачивания бинарников на новые теги.

---

## 📝 Журнал выполненных работ и архитектурных решений (Релиз v1.0.5-keenetic)

### 1. 🚀 Мультивыбор серверов и приоритетное переключение (Failover & Failback)
- **C++ CLI (`fptn-client-cli.cpp`):** Добавлена поддержка списка серверов через запятую в параметре `--preferred-server "Germany-1,Netherlands-2,Finland-1"`. Функция `SplitCommaSeparated` разбирает приоритетный список. Клиент подключается к первому доступному серверу.
- **PHP Web UI (`index.php`):** Разработан динамический UI управления очередью серверов с кнопками добавления серверов, изменения их приоритетов (⬆ Вверх / ⬇ Вниз), удаления (❌) и кнопкой очистки до Авто-выбора.
- **Init-скрипт (`S53fptn-client`):** Передает переменную `PREFERRED_SERVER` из конфига в параметрах командной строки CLI.
- **Watchdog & Failback (`fptn-watchdog.sh`):** Добавлена логика автоматического возврата (Failback): если клиент работает на резервном сервере (приоритет №2 или №3), watchdog периодически проверяет доступность сервера №1 и автоматически возвращает соединение на главный сервер при его восстановлении.

### 2. ⚡ Realtime Auto-Polling статуса службы
- **PHP Web UI (`index.php`):** Реализован роут `?ajax=get_status` и JavaScript `setInterval` таймер (3 секунды). Веб-панель отображает реальный статус службы, PID процесса и активный IP-адрес TUN без необходимости вручную обновлять страницу в браузере (F5).

### 3. 🛡️ Усиление и доработка интерактивного установщика (`install.sh`)
- **Проверка занятости TCP-портов (`is_port_busy`):** Автоматическое обнаружение занятых портов через `netstat`/`ss`. В случае конфликтов дефолтного порта 8088 установщик автоматически подбирает свободный порт (8089+).
- **Защита от конфликта туннельных интерфейсов KeeneticOS:** Автоматическая проверка интерфейсов `OpkgTun` через `ndmc`. Если `OpkgTun1` принадлежит сторонней утилите, скрипт выбирает свободный `OpkgTun2` без риска перезаписать чужую конфигурацию.
- **Сохранение существующих настроек при обновлении:** При переустановке или обновлении версии скрипт сохраняет существующий `/opt/etc/fptn-client.conf` (токен подписки, пароль веб-панели, список приоритетов).
- **Каскадный перебор 4 зеркал для РФ (`download_file`):** Автоматический фоллбэк скачивания при блокировках: `github.com` ➔ `ghproxy.net` ➔ `ghfast.top` ➔ `cdn.jsdelivr.net`.
- **Двуязычность (Bilingual RU & EN):** Все диалоги, подсказки ввода и финальная инструкция в установщике полностью дублированы на двух языках.

### 4. 🐛 Исправление выявленных ошибок и багов
- **Спуфинг TLS:** Исправлена опечатка в имени метода спуфинга Firefox 149 (`"sni-spoofing-firefox149"` ➔ `"sni-spoofing-firefox-149"`).
- **JSON парсинг в PHP:** Исправлена вырезка валидного JSON из ответа CLI с помощью `strrpos($json_str, '}')`, устранившая сбои от лог-сообщений `spdlog`.
- **Кавычки в S53fptn-client:** Удалены экранированные кавычки `\"$TOKEN\"` из переменной `ARGS`, предотвратившие загрязнение строк токенов.
- **Watchdog TUN Check:** Заменена сбойная команда `ping -I opkgtun1` на универсальную системную проверку `ip addr show "$TUN"`.
- **UTF-8 Локаль:** В `install.sh` добавлен экспорт `LANG` и `LC_ALL` `ru_RU.UTF-8` для 100% предотвращения крякозябр на любом SSH-клиенте.

### 5. 🛠️ Улучшения надежности, Деинсталляция и Защита от сбоев
- **Встроенный бинарник и проверка целостности (`check_binary_valid`):**
  В `deploy/keenetic/bin/` встроен готовый C++ бинарник `fptn-client-cli-aarch64`. В `install.sh` внедрена валидация `check_binary_valid` (размер >1 МБ, отсутствие 404 HTML). Каскад скачивания: Репозиторий `master` ➔ `releases/download/${REMOTE_VER}` ➔ Динамический `releases/latest/download` ➔ CDN-зеркала.
- **Чистая деинсталляция (`uninstall.sh` и флаг `--uninstall`):**
  Разработан скрипт `deploy/keenetic/uninstall.sh` и поддержан флаг `--uninstall` (`-u`) в `install.sh`. Бесследно удаляет файлы клиенты, веб-панели, записи cron и интерфейсы `OpkgTun` из KeeneticOS.
- **Защита от нагрузки CPU и сбоев `set -e`:**
  Все вызовы `ndmc` защищены от аварийного завершения `set -e` через `|| true`. Поиск свободных интерфейсов `OpkgTun` ограничен жестким максимумом в 10 итераций, устранившим нагрузку на системный демон KeeneticOS (`ndm`).
- **Неинтерактивная установка (`-y` / `--auto`):**
  Добавлен флаг `-y` в `install.sh` и поддержка ввода по умолчанию, исключившие зависание в неинтерактивных терминалах (WinSCP, PuTTY exec).
- **Фикс JS Scope в веб-панели (`index.php`):**
  Добавлена пропущенная скобка `}` в `installUpdate()`, восстановившая работу интерактивного добавления и сортировки приоритетных серверов (`addServerToPriority`).
- **Автоустановка зависимостей Entware:**
  В список пакетов `install.sh` добавлены `libxml2` и `lighttpd-mod-cgi`, устранившие ошибки `libxml2.so.16: not found` и расхождение версий плагинов Lighttpd. Добавлен `opkg update || true` для устойчивости к заблокированным сторонним фидам.

### 6. 📦 Сборка и Деплой
- Версионные теги обновлены до `v1.0.5-keenetic` во всех обязательных файлах (`version.txt`, `index.php`, `install.sh`, `README.md`).
- Выполнена компиляция релиза под MSVC (`fptn-client-cli.exe`, 17.7 МБ) со 100% кэшированием Conan.
- Все изменения закоммичены и задеплоены в репозиторий GitHub [AntikFull/fptn-keenetic](https://github.com/AntikFull/fptn-keenetic).

---

## 📝 Журнал выполненных работ (Релиз v1.0.6-keenetic)
### 🔄 Восстановление стабильности KeeneticOS
- **Удаление fptn-client-wrapper.sh:** Отказались от запуска CLI в фоне через шелл-обертку, устранив зависание фоновых процессов и 100% загрузку CPU.
- **Восстановление прямого управления службой:** В `S53fptn-client` возвращен прямой запуск `/opt/bin/fptn-client-cli` под управлением Entware `rc.func`.
- **Восстановление ip global 50000:** Приоритет интерфейса NDM снижен с 700 до 50000, исключив закольцовывание системных сокетов.
- **Очистка iptables:** Удалены жесткие вызовы `MASQUERADE` и `TCPMSS`, передав управление NAT роутера демону KeeneticOS `ndm`.
- **Синтаксис и форматирование:** Все shell-скрипты проверены утилитой `sh -n` и приведены к формату UNIX LF.

> ⚠️ **Пробел в журнале:** релизы с `v1.0.7` по `v1.1.22` не задокументированы. Именно из-за этого осталась незамеченной регрессия: удалённые в v1.0.6 вызовы `MASQUERADE` и `TCPMSS` вернулись в `S53fptn-client` и накапливались дубликатами при каждом перезапуске. При выпуске релиза журнал заполнять обязательно.

---

## 📝 Журнал выполненных работ (Промежуточные релизы v1.0.7 - v1.1.22)
Задокументирована история изменений, которая ранее отсутствовала в журнале:
- **v1.1.5:** Исправлены ошибки сборки GitHub Actions, добавлена POSTCMD команда `ndmc -c 'interface OpkgTun1 up'` в `S53fptn-client` для устранения проблемы со статусом NO-CARRIER (link: down). Исправлено автообновление в `index.php`.
- **v1.1.13:** Добавлен 3-кратный замер пинга (Ping RTT) до серверов по TCP Handshake с выводом цветных бейджей, а также режим умного автопереключения `Fallback to Auto` при недоступности всех выбранных серверов.
- **v1.1.16:** Добавлена автопроверка серверов при сохранении для защиты от зависания службы в `dead`, исправлен разбор аргументов `PREFERRED_SERVER` в `S53fptn-client`, добавлена установка NAT-маскарада `iptables MASQUERADE`.
- **v1.1.20:** Решена проблема таймаута HTTPS/TLS пакетов на ПК (из-за жесткого исключения `10.0.0.0/8` внутри С++ клиента FPTN). Адрес интерфейса в `S53fptn-client` переведен на изолированный `172.31.254.1/32` для предотвращения отбрасывания пакетов самим VPN-клиентом.
- **v1.1.22:** Возврат к туннельному адресу `10.0.0.1/8` после того, как пользователь освободил подсеть `10.0.0.0/8` в настройках Гостевой сети Keenetic.

---

## 📝 Журнал выполненных работ (Релиз v1.2.0-keenetic)

Полный разбор причин и порядок проверки — в `OPTIMIZATION_PLAN.md`.
Причиной зависаний роутера была сумма пяти независимых причин, приводящих к перегрузке CPU и утечке RAM.

### 1. 🔥 Устранён busy-spin в C++ (основной постоянный расход CPU)
- **`websocket_client.cpp`:** цикл `ioc_.poll_one()` + `sleep_for(1ms)` заменён на блокирующий `ioc_.run_one()`. Прежний вариант давал около 2000 системных вызовов в секунду даже при полном простое туннеля. В `Stop()` добавлен `ioc_.stop()`, чтобы разблокировать `Run()`.
- **`net_interface.h` / `linux_tun_device.h` / `darwin_tun_device.h` / `win_tun_device.h`:** в `RunReader()` вместо `sleep_for(1ms)` на неблокирующем дескрипторе добавлен метод `WaitForReadable(timeout_ms)` — ожидание события через `poll()` (на Windows — через событие Wintun). Кроме расхода CPU это убирает ограничение пропускной способности гранулярностью 1 мс.

### 2. 💾 Логи убраны из оперативной памяти роутера
- **`logger.h`:** каталог логов переопределяется через `FPTN_LOG_DIR`, размер ротации — через `FPTN_LOG_MAX_SIZE_MB` и `FPTN_LOG_MAX_FILES`. Раньше путь был жёстко задан как `/var/log/fptn/` с ротацией 12 МБ × 3, а на KeeneticOS `/var` — это tmpfs, то есть до 36 МБ логов в ОЗУ роутера и прямой путь к нехватке памяти.
- `flush_on(debug)` заменён на `flush_on(err)`: сброс буфера на каждой строке дорог для флеш-накопителя.
- **`S53fptn-client`:** экспортирует `FPTN_LOG_DIR=/opt/var/log/fptn` и ротацию 1 МБ × 2; каталог создаётся в `install.sh`.

### 3. 🌐 Устранён конфликт адресации туннеля («шторм» демона `ndm`)
- Установлен единственный владелец настроек интерфейса — сам клиент. Он назначает `10.0.0.1/32` (`FPTN_CLIENT_DEFAULT_ADDRESS_IP4`), MTU передаётся ему через `--mtu-size 1360`.
- **`install.sh`:** удалён повторный блок настройки, который поверх уже заданного `10.0.0.1/255.255.255.255` назначал `172.20.0.2/255.255.0.0` и `ip defaultgateway 172.20.0.1` — шлюз, которого не существует. Три разных адреса с разными масками на одном интерфейсе заставляли `ndm` непрерывно сверять свою конфигурацию с ядром.
- **`S53fptn-client`:** из `POSTCMD` убраны `ip addr flush`, `ndmc ... ip address`, `ip addr add 10.0.0.1/8`, `ip link set mtu/up`. Осталось только то, чего клиент не делает: `ip global 50000` и access-list (KeeneticOS удаляет его по `auto-delete`).

### 4. 🧹 Прекращено накопление правил iptables
- Из `POSTCMD` удалены 4 правила `iptables -I` (`MASQUERADE`, два `FORWARD`, `TCPMSS`): они добавлялись при каждом запуске и никогда не удалялись, поэтому цепочки росли неограниченно. NAT отдан `ndm`, подгонка MSS — через `ndmc ... ip tcp adjust-mss pmtu` в `install.sh`. Это возврат к решению, принятому в v1.0.6.
- Добавлена функция `cleanup_legacy_iptables`, которая при `start`/`restart` вычищает дубликаты, оставленные прежними версиями. Вызывается напрямую, а не через `PRECMD`, поскольку разные версии Entware `rc.func` трактуют `PRECMD` по-разному.

### 5. ⚙️ Снижена фоновая нагрузка watchdog и веб-панели
- **`index.php`:** удалён второй таймер `setInterval`, дублировавший `fetchRealtimeStatus` (оба обновляли одни и те же элементы, вместе — 32 запроса в минуту, каждый со запуском `php-cgi`, двумя `pgrep` и `ip addr`). У оставшегося интервал поднят с 3 до 10 секунд, опрос останавливается при `document.hidden`.
- **`install.sh`:** cron watchdog переведён с `*/1` на `*/5`, старая запись удаляется при обновлении.
- **`fptn-watchdog.sh`:** `ping -c 2` → `ping -c 1` (было до 4 секунд на запуск). Исправлен путь к логу клиента: скрипт читал несуществующий `/opt/var/log/fptn-client.log`, из-за чего автовозврат на приоритетный сервер (failback) не срабатывал никогда.

### 6. 🚦 Ограничен параллелизм подбора сервера и добавлен backoff
- **`speed_estimator.cpp`:** `FindFastestServer` и `FindServerByLogin` запускали по потоку на каждый сервер — до десяти одновременных TLS-рукопожатий при каждом старте, причём потоки были `detach`-нутыми и продолжали работу после получения результата. Введён пул из `kMaxProbeConcurrency = 2` воркеров, разбирающих серверы по очереди, с флагом `done` для прекращения работы сразу после результата.
- **`client.cpp`:** постоянная задержка переподключения 300 мс (35 попыток, то есть 35 TLS-рукопожатий за 10 секунд) заменена на экспоненциальный backoff 500 мс → 30 с. Ожидание разбито на кванты по 100 мс, чтобы `Stop()` не ждал окончания всей задержки.

### 7. 🏗️ Сборка утилиты `fptn-passwd` под AArch64 и сжатие бинарников
- В GitHub Actions workflow добавлена компиляция `fptn-passwd` под целевые архитектуры роутера (включая `aarch64`). Ранее в репозитории лежал `fptn-passwd-aarch64`, ошибочно собранный под x86_64.
- Все бинарники (`fptn-client-cli` и `fptn-passwd`) прогнаны через `strip --strip-unneeded`, что уменьшило размер клиента `fptn-client-cli-aarch64` с 49.7 МБ до 22.3 МБ.

### 📋 Гигиена репозитория
- Добавлен `.gitattributes` для нормализации окончаний строк LF/CRLF.
- Обновлен `.gitignore` для полной фильтрации временных файлов Conan, логов и тестовых скриптов.

### ✅ Что проверено и подтверждено:
- **Локальная компиляция:** Успешно протестирована компиляция C++ под Windows (Conan v2 + CMake + MSVC 19).
- **Сборка в CI (GitHub Actions):** Кросс-компиляция целей `fptn-client-cli` и `fptn-passwd` для `mipsel`, `armv7`, `aarch64` завершилась успешно на серверах сборки.
- **Размер и архитектура бинарников:** Бинарники aarch64 успешно скачаны и проверены по размерам (клиент: 22.3 МБ, passwd: 3.0 МБ).

---

## 📝 Журнал выполненных работ (Релиз v1.3.0-keenetic)

После установки v1.2.0 туннель поднимался, но трафик через него не ходил, а роутер приходилось перезагружать. Разбор велся по self-test роутера и по сравнению форка с рабочим референсом — libfptn из Android-клиента (`FptnClient-Android-develop/app/src/main/cpp/libs/fptn/`). Почти все находки оказались расхождениями с этим референсом, внесёнными в форк.

### 1. 🔴 Адрес туннеля перезаписывался src-адресом каждого пакета
- **`vpn_manager.cpp`:** из `HandleOnPacketFromVirtualNetworkInterface` удалён вызов `UpdateTunInterfaceAddressIPv4(src_ip)` (внесён коммитом `730bc24`). Он объявлял адресом интерфейса отправителя произвольного пакета. На роутере через TUN идёт форвардинг всей локальной сети, и любой пакет, не прошедший MASQUERADE, подставлял туда адрес хоста из LAN — после чего весь входящий трафик переписывался на этот один адрес. В референсе такого вызова нет. Адрес задаётся один раз в `Start()`, из фактического адреса ядра.

### 2. 🔴 Развилка в переписывании получателя ломала NAT
- **`websocket_client.cpp`:** убрано условие `!= FPTN_CLIENT_DEFAULT_ADDRESS_IP4`, по которому при дефолтном адресе получателем ставился `assigned_ipv4_`. Референс (`websocket_client.cpp:608` в Android) переписывает получателя в адрес туннеля безусловно. `ndm` транслирует трафик LAN в адрес интерфейса, и обратный пакет с чужим получателем conntrack уже не сопоставляет — то есть ответы не доходили вообще.
- Адрес туннеля вынесен в `TUN_ADDRESS` (`fptn-client.conf`) и передаётся клиенту через `--tun-interface-ip`; `install.sh` назначает ndm то же самое значение.

### 3. 🔴 POSTCMD не выполнялся никогда
- **`S53fptn-client`:** `rc.func` подставляет `$POSTCMD` без `eval`, поэтому кавычки, `;` и перенаправления теряют смысл и попадают в аргументы как слова. Прежняя строка из четырёх `ndmc -c '...'` сворачивалась в один вызов, которому в `-c` прилетал литерал `'interface`. В логе роутера: `[E] ndm: Command::Base: no such command: 'interface.` Ни `ip global`, ни access-list не применялись — интерфейс оставался с `security-level public` без разрешающего списка, и трафик через него резал файрвол ndm.
- Настройка вынесена в отдельный `fptn-ndm-setup.sh`, `POSTCMD` теперь одно слово. Скрипт ждёт появления устройства (клиент до создания TUN успевает сходить за списком серверов и авторизоваться) и уходит в фон, чтобы не задерживать последовательный запуск остальных служб.

### 4. 🔴 NTP: при недоступном сервере каждый запрос времени уходил в сеть
- **`time_provider.cpp`:** `last_sync_time_` обновлялось только при успехе, поэтому при недоступном NTP условие «пора синхронизироваться» было истинным всегда, и **каждый** вызов `NowTimestamp()` уходил в блокирующий опрос всех серверов под мьютексом. Вызывается он из построения TLS-рукопожатия (`tls.cpp`, 9 мест). У Keenetic нет батарейного RTC: при старте до поднятия WAN время заведомо неверное, и клиент вставал намертво. Добавлен отдельный интервал повтора 30 с и флаг `synchronized_`.

### 5. 🔄 Возвращён супервизор переподключения из референса
- **`vpn_manager.cpp` / `.h`:** портирован `Supervise()` — поток, который при неподнимающемся соединении пересоздаёт сессию целиком с паузами 2 → 5 → 15 → 30 с и пере-синхронизацией NTP. В форке его не было вовсе, отсюда и потребность во внешнем cron-watchdog.
- `IsStarted()` теперь возвращает `true` после первого успешного подключения (флаг `ever_connected_`). Раньше `WaitForSignal` убивал процесс на первом же исчерпании попыток.
- Адаптация под роутер: `Config::max_full_restarts`, при `--disable-routing` равен `0` — «не сдаваться никогда», поскольку за клиентом стоит вся локальная сеть. Все обращения к `route_manager` защищены проверкой на null: при `--disable-routing` он не создаётся.
- **`client.h`:** `kMaxReconnectionAttempts_` возвращён с 35 на 3 (значение референса). Тридцать пять были компенсацией отсутствующего супервизора. Восстановлен `Client::IsConnected()`.

### 6. ⚡ Датапуть: снят лишний пересчёт, возвращены батчинг и раздельные мьютексы
- **`websocket_client.cpp`:** убраны 4 вызова `ComputeCalculateFields()` — сеттеры `SetDst*/SetSrc*Address` уже вызывают `RecalculateChecksums` сами (`ip_packet.h`). Был двойной пересчёт контрольных сумм на каждом пакете в обе стороны.
- **`vpn_manager.cpp`:** очередь websocket → TUN возвращена под собственный `queue_mutex_` и снова вычерпывается целиком за одно пробуждение. Общий `mutex_` держится в том числе во время отправки пакета, и объединение блокировок сериализовало весь датапуть на одном локе. Размер очереди 512 → 16384.
- **`channel.h`:** ёмкость 2048 → 4096, батч 64 → 256 (значения референса).

### 7. 🌐 Сетевые тайм-ауты
- **`websocket_client.cpp`:** возвращён блок TCP keepalive для Linux (`TCP_KEEPIDLE=5`, `TCP_KEEPINTVL=2`, `TCP_KEEPCNT=3`, `TCP_USER_TIMEOUT=10000`). В форке он был вырезан вместе с ветками для Windows, остался только `#ifdef __APPLE__`. Без него молча оборванное соединение за PPPoE/CGNAT висит до системного дефолта, то есть около двух часов.
- `idle_timeout` websocket возвращён с 5 на 15 с (значение референса).

### 8. 🔁 Гонка при загрузке роутера
- **`S53fptn-client`:** перед стартом добавлено ожидание маршрута по умолчанию (по умолчанию до 45 с, `FPTN_WAIT_NETWORK`). Клиент до создания туннеля должен отрезолвить хост, выполнить TLS-логин и получить DNS; без WAN любая из этих операций завершается выходом, и `rc.func` рапортует `exit code 255` — ровно это и было в логе рядом с `S60wdtt: PPPoE not ready`. Не дождались — выход с нулём, дальше подхватывает watchdog.

### 9. 🧰 Установщик
- **`install.sh`:** исправлена проверка существования интерфейса. `ndmc` возвращает 0 и для несуществующего интерфейса, а текст ошибки пишет в stderr — прежняя проверка по коду возврата всегда уходила в ветку «уже существует», и интерфейс не создавался. Тот же дефект был в блоке автоподбора свободного имени.
- Задание cron чистится в обоих местах. Прежние версии добавляли его через `crontab -`, то есть в `/opt/var/spool/cron/crontabs/root`, а правки только в `/opt/etc/crontab` его не трогали — на роутере оставалась запись с интервалом `*/1`, и watchdog запускался каждую минуту вместо пяти.
- Устанавливается и удаляется новый `fptn-ndm-setup.sh`.

### ✅ Что проверено, а что нет
- **Проверено:** `sh -n` всех скриптов; функциональный прогон на заглушках `ndmc`/`ip`/`iptables`/`rc.func` — 16 проверок, в том числе воспроизведение подстановки `$POSTCMD` без `eval` (ndmc получает 5 корректных вызовов вместо одного битого), ожидание сети с таймаутом и без него, идемпотентность крона. Изолированная проверка изменённой логики C++ на реальном компиляторе — 16 проверок: цикл записи в TUN, гейтинг NTP, вычерпывание очереди, машина состояний супервизора, таблица пауз. Форматирование сверено с `.clang-format`.
- **НЕ проверено:** полная сборка проекта (локально недоступна) и поведение на живом роутере. Это задача CI и первой установки.

---

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.