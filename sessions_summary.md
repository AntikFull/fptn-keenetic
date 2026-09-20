# Журнал сессий и решений fptn-keenetic

**Репозиторий:** `AntikFull/fptn-keenetic`  
**Назначение:** Клиент и инструменты FPTN VPN для роутеров Keenetic (Entware, MIPS MT7621AT / `mipsel-3.4_kn`, ARMv7, AArch64).

---

### Сессия 1. Полный аудит, исправление крашей и успешная мультиплатформенная кросс-компиляция (17-18.09.2026)

* **Запрос:** Аудит и восстановление работоспособности клиента FPTN на роутере Keenetic Giga KN-1011 (`mipsel-3.4_kn`, MT7621AT, MIPS32r2 little-endian, Entware). Ранее `/opt/bin/fptn-client-cli` немедленно завершался или падал. Настройка CI в GitHub Actions для сборки бинарников всех платформ.
* **Область:**
  - `src/common/network/tun/linux_tun_device.h`
  - `src/common/system/command.h`
  - `src/fptn-client/fptn-client-cli.cpp`
  - `src/fptn-client/routing/route_manager.cpp`
  - `src/fptn-client/vpn/vpn_manager.cpp`
  - `src/fptn-client/utils/speed_estimator/speed_estimator.cpp`
  - `src/fptn-passwd/CMakeLists.txt`
  - `conanfile.py`
  - `.conan/recipes/yaff/conanfile.py`
  - `.github/workflows/build-keenetic.yml`
  - `deploy/keenetic/` (init-скрипты, веб-панель Lighttpd/PHP, автомонитор)
* **Первопричины падений и сбоев:**
  1. *Сегфолт при запуске:* В `speed_estimator.cpp` вызов `std::thread().detach()` на пустом потоке приводил к `std::system_error` / падению runtime.
  2. *Несовместимость с Entware/KeeneticOS:* В `command.h` жесткий вызов `bash` ломал исполнение (в Keenetic установлен `/bin/sh` BusyBox). В `route_manager.cpp` парсинг шлюза ядра падал при PPPoE (интерфейс без поля `via`).
  3. *Падение компиляции Boost:* Библиотека `boost::cobalt` требовала нестандартных фич C++20, конфликтовала с профилем GCC 11 под MIPS.
  4. *Сбой YaFF на MIPS:*
     - В GCC 11 отсутствовал `std::bit_cast` (заменен на `std::memcpy`).
     - Метод `std::ostringstream::view()` отсутствовал в libstdc++ GCC 11 (заменен на `.str()`).
     - Бинарник кодогенератора `protoc-gen-yaff` ошибочно пытался компилироваться под целевую архитектуру MIPS и падал на линковке `abseil` из-за отсутствия 64-битных атомиков.
  5. *Сбой статической линковки `fptn-client-cli` на MIPS32r2:* Статическая библиотека `libabsl_*.a` требовала функции `__atomic_load_8`, `__atomic_store_8`, `__atomic_compare_exchange_8`. Однопроходный линкер GNU ld пропускал `libatomic.a`, так как флаг вставал в начало строки до появления ссылок на функции.
* **Решение:**
  1. Исправлена многопоточность в `speed_estimator.cpp` (joinable пул), парсер маршрутов PPPoE и вызов команд через `/bin/sh`.
  2. В `linux_tun_device.h` вызов `tun_->file_descriptor()` заменен на нативный метод libtuntap `tun_->native_handle()`, внедрено неблокирующее ожидание пакетов через `::poll()` (0% CPU в простое).
  3. В `fptn-client-cli.cpp` добавлен `#include <nlohmann/json.hpp>`.
  4. В `.conan/recipes/yaff/conanfile.py` сборка плагина `src/protoc-plugin` ограничена только хостом сборщика `(self.settings.arch == "x86_64")`, патчи `view() -> str()` и `std::memcpy` внедрены в рецепт.
  5. В `.github/workflows/build-keenetic.yml` настроена изоляция флагов линковки в матрице:
     - `aarch64`: `-static -pthread`
     - `armv7`: `-static -pthread -latomic`
     - `mipsel`: `-static -pthread -Wl,--whole-archive -latomic -Wl,--no-whole-archive`
  6. Добавлено сохранение Conan кэша сразу после этапа `Install Conan Dependencies`.
* **Доказательства верификации:**
  - GitHub Actions run [#35197313146](https://github.com/AntikFull/fptn-keenetic/actions/runs/35197313146):
    - `armv7`: успех за 3м 47с.
    - `aarch64`: успех за 4м 31с.
    - `mipsel`: успех за 9м 37с.
  - Сформированы и скачаны бинарники:
    - `fptn-client-cli-mipsel` (25 225 020 байт)
    - `fptn-passwd-mipsel` (3 425 872 байт)
    - `fptn-client-cli-armv7` (18 548 928 байт)
    - `fptn-passwd-armv7` (1 889 500 байт)
    - `fptn-client-cli-aarch64` (22 660 896 байт)
    - `fptn-passwd-aarch64` (3 033 808 байт)
  - Архив `fptn-keenetic-mipsel.zip` (13.19 МБ) доставлен в Telegram (message_id: 6066).

---

### Сессия 2. Интеграция нативного OpkgTun, поддержка MIPS soft-float, устранение Segfault и адаптация веб-панели (20.09.2026)

* **Запрос:** Разбор отчетов и логов пользователей Kir (падение с Segmentation Fault на Keenetic Giga KN-1011 v1.3.1) и Константина Николаевича (наработки для soft-float MIPS MT7628 / KN-1713, связывание с OpkgTun). Устранение падений, интеграция патчей именованного TUN в `libtuntap`, добавление таргета `mipselsf`, восстановление списка серверов в веб-панели и поддержка точечной маршрутизации.
* **Область:**
  - `depends/patches/0003-libtuntap-prestart-name.patch`
  - `depends/cmake/FetchLibTunTap.cmake`
  - `src/common/network/tun/linux_tun_device.h`
  - `src/fptn-client/utils/speed_estimator/speed_estimator.cpp`
  - `.github/workflows/build-keenetic.yml`
  - `deploy/keenetic/install.sh`
  - `deploy/keenetic/S53fptn-client`
  - `deploy/keenetic/index.php`
* **Первопричины и решения:**
  1. *Segmentation Fault при запуске v1.3.1:* В статическом бинарнике GCC 11 / glibc на MIPS32r2 вызов `jalr $t9` по адресу `ra = 0x00e047a8` в `FindServerByLogin` переходил на нулевой указатель `0x00000000` в GOT из-за слабого связывания pthread-функций в glibc. В `build-keenetic.yml` в флаги статической линковки добавлен `-Wl,--whole-archive -lpthread -latomic -Wl,--no-whole-archive`. В `speed_estimator.cpp` внедрен безопасный RNG и последовательный fallback без создания потоков при любых отказах threading.
  2. *Несовместимость с OpkgTun:* В апстриме `libtuntap` открытие девайса жестко создавало `tun0`. Добавлен патч Константина Николаевича в `FetchLibTunTap.cmake` и обновлен `linux_tun_device.h` на вызов `std::make_unique<tuntap::tun>(name)`, позволяющий FPTN подключаться напрямую к `opkgtunN`.
  3. *Поддержка MIPS soft-float:* Добавлен матричный таргет `mipselsf` с флагами `-msoft-float` для устройств MT7628 / KN-1713 (`mipselsf-k3.4`). В `install.sh` внедрено автоопределение soft-float фида Entware.
  4. *Список серверов в веб-панели:* В `index.php` добавлен прямой декодер токенов `fptnb:` (Brotli) и запуск через dynamic loader `/opt/lib/ld.so.1`, если прямой вызов CLI возвращает ошибку.
  5. *Точечная маршрутизация:* В `S53fptn-client` добавлено ожидание `carrier=1` перед финальной регистрацией параметров OpkgTun в NDM без затирания маршрутов пользователя. В `install.sh` перехват default route оставлен строго опциональным.
* **Доказательства верификации:**
  - Синтаксис shell-скриптов: `sh -n deploy/keenetic/install.sh` (код 0), `sh -n deploy/keenetic/S53fptn-client` (код 0).
  - Сводный Pre-Flight (`preflight.cmd`): все проверки (стиль, секреты, переносы строк LF, права 0755, скобки, типографика) успешно пройдены (код возврата 0).

