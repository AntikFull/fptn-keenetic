# CLAUDE.md
Еще тут мой проект форк https://github.com/AntikFull/fptn-keenetic для роутера Кинетик, Скачан в "F:\Antigravity\testovoe\fptn-master\fptn-keenetic-master"
Для андроид в "F:\Antigravity\testovoe\fptn-master\FptnClient-Android-develop"

Тестовый токен:

fptnb:H+QXAKyKdwjvRjoxlH6U8usVY3OsRkgyWyJ7pszm7ARZel8fYzAKFscilT/3Pwo2qRwqxuAYkmWqK3RsudLZqh3+gJPhIMZ1vrkftHukRSI0fvuh4tOElaAhTw4V3w5Ra6IRL9Vb+CV93t1aXlyJPxIJIeK2odZfFHC8imwG1z90qGZWf+MDP8rJrWLA4/Ha2++/+fyHiJvmQQorSnpv05RjWtpRzePc68f48JW/f3zl/x849mt8fNaPYU+X+NyBmcQtKYVYr+EpROx7G5U/tX51HhFZaFFvla0nbt7Yw8o5mCNwIptkaX8otb3uD7H+ce7wQS/ytSIEEDtFgE6wA2HKLwrN12067nLpcXpCHPYB9rAI2lhn7QkaiTaxw3/Fj2T0hpWIRx028adeczoISteCMHOwCg9CAqZABztri9e1QU7RcgEXn0dFNUQPDqLv2T+A+C/Dx6TKRqFjZxpufSPty3eUtmRwZDAvUfpiZDc+CsWNzX02DVaAHVgpM23X11oh7foipLSaNtcBBwQIq4aBrKiVgGnY0XwefspO0HdpS3MAK+uKJBFDOWqbN1YXIJXqWtmpf/vGb0G3eX1EajLXhjy3Gc4NxDSoKwc6i6ItfA5PRzzpSRM8hL77BmDExQW43HYkMjjx5OqgsnXcZOGEJjcvGrjAJStcx8hRYXDz1/j5oBeBJLEbcsivF23Nrct3Rzl8HFxnLRWqFpPYcR2qqF2CFlnkBDTR2PW0fjYA38XP3wV/wDu6oQHq9a1JcnWJVFmN5moFUbsQMKZFWVzbWl9sAjCrbC66Z/A5MnHnAUI30KobLH1GlUjbJb9CHNe7CIuhHI1VoKx/LJ4hAVWAyZkO3btFGrSjSnGDQvg3BlEFbqdQHLIr0wLdMIdXjqbL1nEZC9fdXstFqCNYGVF72OH9MgJYHGpMrjqNFNtDBN859uDK1AupVR920MxmOV7FCb+NHE9ZaHmQ28Rg2z1x08MvXwfypcWF+86us09sjZSw5hBdBrIaUCPFuWNj7ZdfXNBs06XoBJXedToaW9mAZA9dwQrSgDW1XCbVdOzO6FZN3h1APphG3WoirZV1GmkQRh9wn1KhNuebbC0ladUmBt0294EDExg1iVFz7rW+bdBAb5jl8SbESF3/BJyZuWzseL86Ju9Yi/JVUgqvkop8E0WIno/UmCq9O4BmnI2dPIHs/Bziu+jPYO4S6odBR3Nww+vx59/fRUcI7N5pRHAMYtgP0a9QfEsZFFEHOSM3Vxp4wUnuJm73SZFLzldDi7stU/2VikSmTNtPhl5TCXS6MrTQO7RCk9JYeoNdwOYhLTjyVwtInZbQ4A7JC5qPTss6aInWcBHwkWEO4OgUOXL5JVhANFAZNOZIEICBjDEhXDyhkqvTJKjoFid6EkXNhhQKwDSVIZyhUklW4wccIpMEBU1HI2X710UBBSiLW6bBYdm3f1HFWgW0Bc7KIYMYNW4XsdcHw5pLfbtMlvxVIIDGHLQJ4BQlkEYMUbjmWWRz/Sxj0uVFkm7cAV+6MRWKaGMXgAPGQAw445sOHYWivXmgN+y24TQc1WmsdQBzzoZgTsMO+eGatl6ZQJJUV9cGX2QKNcg7B2sN4qnNhxCBlQAT4gz5Nq2ATHjH5vjlxS6zU18d0JgrDY8FeZg4C9oaB9lOjHYzqkceldlpZ6KbhYRbeUrHTjoYreoCcGamvyHsWB6RjmIoi/CP/Prdz+NZHYD2WaG9YSeYMvUenIy02eGIsCzKHTpnAFeQeKZKHySKPiCavdtGC8NcZ89Z2ZMgJPyCyhYR80NJzc9/RN70Vwv8Z/pqUTicbSv5/vL3L/PZ+d3vf/wwDTS67ujHeNWhaZ3yWj6TnnluyJk4xikNFfcH 

Его можешь использовать для подключения к серверам.

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

### 5. 📦 Сборка и Деплой
- Версионные теги обновлены до `v1.0.5-keenetic` во всех 4 обязательных файлах (`version.txt`, `index.php`, `install.sh`, `README.md`).
- Выполнена компиляция релиза под MSVC (`fptn-client-cli.exe`, 17.7 МБ) со 100% кэшированием Conan.
- Закоммичено и задеплоено в репозиторий GitHub [AntikFull/fptn-keenetic](https://github.com/AntikFull/fptn-keenetic) с релизным тегом `v1.0.5-keenetic`.

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