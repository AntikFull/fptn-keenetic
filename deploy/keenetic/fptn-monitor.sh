#!/bin/sh
# FPTN CPU and Process Monitor Daemon for Keenetic
# Проверяет нагрузку процессора и размножение процессов каждые 10 секунд.

LOG_FILE="/opt/var/log/fptn-monitor.log"
mkdir -p "/opt/var/log" 2>/dev/null || true

CONSECUTIVE_CPU_SPIKES=0
BOT_TOKEN="8690233007:AAEw2baXjyXvCUo_SVdqtw20BIruxIbQdBY"
CHAT_ID="758274393"

send_tg_notification() {
    _msg="$1"
    curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
         -d "chat_id=${CHAT_ID}" \
         -d "text=${_msg}" >/dev/null 2>&1 &
}

echo "$(date): FPTN Monitor started." >> "$LOG_FILE"

while true; do
    # 1. Проверяем количество процессов fptn-client-cli
    PROCS=$(pgrep -x "fptn-client-cli" | wc -l)
    
    # Нормальное количество процессов - 1 (или 2 при переходных состояниях)
    # Если процессов 3 или более - это признак размножения
    if [ "$PROCS" -gt 2 ]; then
        MSG="⚠️ [FPTN Monitor] Обнаружено размножение процессов fptn-client-cli ($PROCS шт.). Убиваем службу!"
        echo "$(date): $MSG" >> "$LOG_FILE"
        send_tg_notification "$MSG"
        
        # Кикаем все
        pkill -9 -f "fptn-client-cli"
        pkill -9 -f "fptn-watchdog"
        /opt/etc/init.d/S53fptn-client stop
        CONSECUTIVE_CPU_SPIKES=0
    fi

    # 2. Проверяем нагрузку на процессор (столбец %CPU в выводе top)
    # Суммируем CPU для всех процессов fptn-client-cli
    CPU_USAGE=$(top -b -n 1 | grep "fptn-client-cli" | grep -v grep | awk '{sum += $8} END {print sum}')
    if [ -z "$CPU_USAGE" ]; then
        CPU_USAGE=0
    fi
    
    # Округляем до целого числа для сравнения
    CPU_INT=$(echo "$CPU_USAGE" | cut -d'.' -f1)
    if [ -z "$CPU_INT" ]; then
        CPU_INT=0
    fi

    if [ "$CPU_INT" -gt 60 ]; then
        CONSECUTIVE_CPU_SPIKES=$((CONSECUTIVE_CPU_SPIKES + 1))
        # Если нагрузка превышает 60% более 3 проверок подряд (30 секунд)
        if [ "$CONSECUTIVE_CPU_SPIKES" -ge 3 ]; then
            MSG="⚠️ [FPTN Monitor] Высокая нагрузка на процессор (${CPU_USAGE}%) более 30 сек. Убиваем службу!"
            echo "$(date): $MSG" >> "$LOG_FILE"
            send_tg_notification "$MSG"
            
            pkill -9 -f "fptn-client-cli"
            pkill -9 -f "fptn-watchdog"
            /opt/etc/init.d/S53fptn-client stop
            CONSECUTIVE_CPU_SPIKES=0
        fi
    else
        CONSECUTIVE_CPU_SPIKES=0
    fi

    sleep 10
done
