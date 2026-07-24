#!/bin/sh
# FPTN Watchdog - скрипт-наблюдатель соединения для Keenetic
# Автор: Antigravity

CONF="/opt/etc/fptn-client.conf"
STATUS_FILE="/tmp/fptn-watchdog.status"

if [ ! -f "$CONF" ]; then
    exit 0
fi

# Загружаем конфигурацию
. "$CONF"

# Проверяем, включена ли служба и активирован ли вотчдог
if [ "$ENABLED" != "yes" ] || [ "$WATCHDOG" != "yes" ]; then
    exit 0
fi

LOG_DIR="/opt/var/log"
mkdir -p "$LOG_DIR"

# 1. Проверяем, запущен ли сам процесс fptn-client-cli
if ! (pgrep -x fptn-client-cli || pgrep -f "/opt/bin/fptn-client-cli") >/dev/null 2>&1; then
    # Если процесс упал, но служба включена - запускаем
    /opt/etc/init.d/S53fptn-client start
    rm -f "$STATUS_FILE"
    exit 0
fi

# 2. [ЗАЩИТА CPU] Проверяем физический WAN (прямой интернет без VPN)
# Пингуем в обход туннеля через дефолтный WAN роутера
if ! ping -c 2 -W 2 1.1.1.1 >/dev/null 2>&1; then
    # Физического интернета нет - ничего не делаем, чтобы не нагружать роутер
    exit 0
fi

# 3. Проверяем туннельный интерфейс FPTN
TUN="${TUN_INTERFACE:-opkgtun1}"
if ! ip addr show "$TUN" 2>/dev/null | grep -q "inet"; then
    # Интерфейс не имеет активного IP или не готов
    sleep 3
    if ! ip addr show "$TUN" 2>/dev/null | grep -q "inet"; then
        
        # Туннель действительно не отвечает.
        # 4. [ЗАЩИТА CPU] Проверка лимита перезапусков
        NOW=$(date +%s)
        RESTARTS=0
        LAST_RESTART=0
        
        if [ -f "$STATUS_FILE" ]; then
            . "$STATUS_FILE"
        fi
        
        # Если последняя попытка была более 20 минут (1200 сек) назад - сбрасываем счетчик
        TIME_DIFF=$((NOW - LAST_RESTART))
        if [ $TIME_DIFF -gt 1200 ]; then
            RESTARTS=0
        fi
        
        if [ $RESTARTS -ge 3 ]; then
            # Мы уже пробовали 3 раза перезапустить службу, но безуспешно.
            # Блокируем перезапуски на 20 минут, чтобы не нагружать процессор роутера.
            echo "$(date): VPN tunnel is down, but limit of 3 restarts exceeded. Postponing next retry..." >> "$LOG_DIR/fptn-watchdog.log"
            exit 0
        fi
        
        # Увеличиваем счетчик и сохраняем статус
        RESTARTS=$((RESTARTS + 1))
        echo "RESTARTS=$RESTARTS" > "$STATUS_FILE"
        echo "LAST_RESTART=$NOW" >> "$STATUS_FILE"
        
        echo "$(date): FPTN connection lost on $TUN (attempt $RESTARTS/3). Restarting service..." >> "$LOG_DIR/fptn-watchdog.log"
        /opt/etc/init.d/S53fptn-client restart
    fi
else
    # Если туннель ожил и работает стабильно - сбрасываем статус перезапусков
    if [ -f "$STATUS_FILE" ]; then
        rm -f "$STATUS_FILE"
    fi
    
    # 4. [FAILBACK] Проверка возможности возврата на 1-й (основной) сервер
    if [ -n "$PREFERRED_SERVER" ]; then
        MAIN_SERVER=$(echo "$PREFERRED_SERVER" | cut -d',' -f1 | tr -d ' ')
        if [ -n "$MAIN_SERVER" ]; then
            LAST_FAILBACK_CHECK_FILE="/tmp/fptn-failback.last"
            NOW=$(date +%s)
            LAST_CHECK=0
            if [ -f "$LAST_FAILBACK_CHECK_FILE" ]; then
                LAST_CHECK=$(cat "$LAST_FAILBACK_CHECK_FILE")
            fi
            # Выполняем проверку возврата не чаще раз в 15 минут (900 сек)
            if [ $((NOW - LAST_CHECK)) -ge 900 ]; then
                echo "$NOW" > "$LAST_FAILBACK_CHECK_FILE"
                # Если служба сейчас работает на резервном сервере, перезапускаем для автовозврата на №1
                if grep -q "Selected preferred server by priority" /opt/var/log/fptn-client.log 2>/dev/null; then
                    if ! grep -q "Selected preferred server by priority: ${MAIN_SERVER}" /opt/var/log/fptn-client.log 2>/dev/null; then
                        echo "$(date): Attempting failback to primary server ${MAIN_SERVER}..." >> "$LOG_DIR/fptn-watchdog.log"
                        /opt/etc/init.d/S53fptn-client restart
                    fi
                fi
            fi
        fi
    fi
fi
