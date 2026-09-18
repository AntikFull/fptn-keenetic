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
