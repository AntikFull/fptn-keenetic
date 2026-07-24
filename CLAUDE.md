# CLAUDE.md
Еще тут мой проект форк https://github.com/AntikFull/fptn-keenetic для роутера Кинетик, Скачан в "F:\Antigravity\testovoe\fptn-master\fptn-keenetic-master"
Для андроид в "F:\Antigravity\testovoe\fptn-master\FptnClient-Android-develop"

## Локальная компиляция и кросс-компиляция на ПК (Windows 11 + WSL 2)

### 1. Настройка окружения на хосте (Windows 11)
Для сборки Windows-клиента должны быть установлены:
- **Visual Studio 2022 Community** (с рабочей нагрузкой "Разработка классических приложений на C++").
- **Python 3.x** и **Conan**: `pip install conan`.
- **CMake**: `winget install kitware.cmake`.

### 2. Кросс-компиляция для Keenetic (ARM/MIPS) через WSL 2 (Ubuntu)
Так как для роутеров нужен Linux-компилятор, кросс-компиляцию удобнее всего запускать в WSL Ubuntu:
1. Запустите консоль Ubuntu в WSL: `wsl`
2. Установите зависимости в WSL:
   ```bash
   sudo apt update
   sudo apt install -y build-essential cmake ninja-build pkg-config gcc-aarch64-linux-gnu g++-aarch64-linux-gnu python3-pip
   pip3 install conan --break-system-packages
   ```
3. Перейдите в папку проекта в WSL:
   ```bash
   cd /mnt/f/Antigravity/testovoe/fptn-master/fptn-keenetic-master
   ```
4. Инициализируйте профиль Conan:
   ```bash
   conan profile detect --force
   ```
5. Установите Conan-зависимости под x86_64 Linux (для проверки синтаксиса и сборки):
   ```bash
   conan install . --output-folder=build --build=missing -s compiler.cppstd=17 -o with_gui_client=False --settings build_type=Release
   ```
6. Перейдите в папку `build` и соберите проект:
   ```bash
   cd build
   cmake .. -DCMAKE_TOOLCHAIN_FILE=conan_toolchain.cmake -DCMAKE_BUILD_TYPE=Release
   cmake --build . --config Release
   ```

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