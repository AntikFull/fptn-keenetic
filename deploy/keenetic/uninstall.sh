#!/bin/sh
# Скрипт удаления FPTN-клиента и веб-панели с Keenetic (Entware) / FPTN Uninstaller for Keenetic
# Автор / Author: Antigravity
# Поддержка языков / Language: Русский (RU) & English (EN)

set -e

export LANG="${LANG:-ru_RU.UTF-8}"
export LC_ALL="${LC_ALL:-ru_RU.UTF-8}"

echo "==========================================================="
echo "  Удаление FPTN-клиента и веб-панели с Keenetic (Entware)"
echo "  Uninstalling FPTN Client & Web Panel from Keenetic"
echo "==========================================================="
echo ""

# Читаем имя интерфейса из сохраненного конфига
KTUN_NAME="OpkgTun1"
if [ -f "/opt/etc/fptn-client.conf" ]; then
    . "/opt/etc/fptn-client.conf" 2>/dev/null || true
    if [ -n "$TUN_INTERFACE" ]; then
        KTUN_NAME=$(echo "$TUN_INTERFACE" | sed -E 's/opkgtun([0-9]+)/OpkgTun\1/i')
    fi
fi

# 1. Остановка процессов и служб / Stop services
echo "[1/5] Остановка служб FPTN / Stopping FPTN services..."
if [ -f "/opt/etc/init.d/S53fptn-client" ]; then
    /opt/etc/init.d/S53fptn-client stop >/dev/null 2>&1 || true
fi
pkill -9 -f "fptn-client-cli" >/dev/null 2>&1 || true
pkill -9 -f "fptn-watchdog" >/dev/null 2>&1 || true

# 2. Удаление файлов / Remove files
echo "[2/5] Удаление бинарников и файлов веб-панели / Removing binary & web files..."
rm -f /opt/bin/fptn-client-cli
rm -f /opt/etc/fptn-client.conf
rm -f /opt/etc/init.d/S53fptn-client
rm -f /opt/etc/fptn-watchdog.sh
rm -f /opt/etc/lighttpd/conf.d/85-fptn.conf
rm -rf /opt/share/www/fptn

# 3. Перезапуск веб-сервера Lighttpd / Restart Lighttpd
echo "[3/5] Обновление конфигурации Lighttpd / Updating Lighttpd..."
if [ -f "/opt/etc/init.d/S80lighttpd" ]; then
    /opt/etc/init.d/S80lighttpd restart >/dev/null 2>&1 || true
fi

# 4. Удаление из планировщика Cron / Clean Crontab
echo "[4/5] Очистка планировщика Cron / Cleaning Crontab..."
if which crontab >/dev/null 2>&1; then
    (crontab -l 2>/dev/null | grep -v "fptn-watchdog" | crontab - 2>/dev/null) || true
fi

# 5. Удаление интерфейса из KeeneticOS / Remove KeeneticOS interface
echo "[5/5] Удаление туннельного интерфейса $KTUN_NAME в KeeneticOS / Removing $KTUN_NAME interface..."
if which ndmc >/dev/null 2>&1; then
    ndmc -c "no interface $KTUN_NAME" 2>/dev/null || true
    ndmc -c "system configuration save" 2>/dev/null || true
fi

echo ""
echo "==========================================================="
echo "  Удаление успешно завершено! / Uninstallation finished!"
echo "  FPTN полностью удален с роутера. / FPTN successfully removed."
echo "==========================================================="
