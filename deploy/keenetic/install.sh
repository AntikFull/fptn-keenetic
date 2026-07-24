#!/bin/sh
# Интерактивный установщик FPTN-клиента для Keenetic (Entware)
# Автор: Antigravity
# Все комментарии и вывод на русском языке

set -e

echo "==========================================================="
echo "  Установка FPTN-клиента и веб-панели для Keenetic (Entware)"
echo "==========================================================="
echo ""

# 1. Проверка среды Entware
if [ ! -d "/opt/etc" ] || [ ! -x "/opt/bin/opkg" ]; then
    echo "Ошибка: Среда Entware не найдена на роутере!"
    echo "Убедитесь, что Entware установлен и работает (директория /opt доступна)."
    exit 1
fi

GITHUB_RAW_BASE="https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master"
# Динамическое определение версии релиза из version.txt
REMOTE_VER=$(curl -sL --connect-timeout 5 "${GITHUB_RAW_BASE}/deploy/keenetic/version.txt" 2>/dev/null | tr -d '\r\n')
if [ -z "$REMOTE_VER" ]; then
    REMOTE_VER="v1.0.5-keenetic"
fi

# Универсальная функция скачивания с каскадом прокси-зеркал для РФ
download_file() {
    _url="$1"
    _dest="$2"
    _timeout="${3:-120}"

    if curl -sSL --connect-timeout 10 --max-time "$_timeout" -o "$_dest" "$_url"; then
        if [ -s "$_dest" ]; then return 0; fi
    fi

    # Пробуем каскад зеркал
    for _prefix in "https://ghproxy.net/" "https://ghfast.top/" "https://cdn.jsdelivr.net/gh/AntikFull/fptn-keenetic@master/"; do
        _mirror_url="${_prefix}${_url}"
        if curl -sSL --connect-timeout 10 --max-time "$_timeout" -o "$_dest" "$_mirror_url"; then
            if [ -s "$_dest" ]; then return 0; fi
        fi
    done
    return 1
}

# Функция проверки занятости порта
is_port_busy() {
    _p="$1"
    if netstat -tuln 2>/dev/null | grep -E ":${_p}\s" >/dev/null 2>&1 || ss -tuln 2>/dev/null | grep -E ":${_p}\s" >/dev/null 2>&1; then
        return 0
    fi
    return 1
}

# 2. Интерактивный опрос параметров
# Пытаемся определить текущий порт веб-сервера lighttpd
DEFAULT_PORT=8088
if [ -f "/opt/etc/lighttpd/conf.d/80-nfqws.conf" ]; then
    NFQWS_PORT=$(grep -oE "server.port := [0-9]+" /opt/etc/lighttpd/conf.d/80-nfqws.conf | awk '{print $3}')
    if [ -n "$NFQWS_PORT" ]; then
        DEFAULT_PORT=$NFQWS_PORT
    fi
fi

# Проверяем, свободен ли дефолтный порт
if is_port_busy "$DEFAULT_PORT"; then
    echo "Предупреждение: Порт $DEFAULT_PORT уже занят другим сервисом в системе!"
    # Ищем первый свободный порт начиная с 8089
    CHECK_P=8089
    while is_port_busy "$CHECK_P"; do
        CHECK_P=$((CHECK_P + 1))
    done
    DEFAULT_PORT=$CHECK_P
    echo "Автоматически выбран свободный порт: $DEFAULT_PORT"
fi

printf "Введите порт для веб-панели FPTN (по умолчанию %s): " "$DEFAULT_PORT"
read -r USER_PORT
USER_PORT=${USER_PORT:-$DEFAULT_PORT}

if is_port_busy "$USER_PORT"; then
    echo "ВНИМАНИЕ: Выбранный порт $USER_PORT сейчас занят! Убедитесь, что нет конфликтов."
fi

# Автоподбор свободного имени туннельного интерфейса в KeeneticOS
DEFAULT_KTUN="OpkgTun1"
DEFAULT_LTUN="opkgtun1"

if which ndmc >/dev/null 2>&1; then
    TUN_IDX=1
    while true; do
        C_KTUN="OpkgTun${TUN_IDX}"
        C_LTUN="opkgtun${TUN_IDX}"
        IF_INFO=$(ndmc -c "show interface $C_KTUN" 2>/dev/null || true)
        if echo "$IF_INFO" | grep -q "Command error"; then
            # Интерфейс свободен
            DEFAULT_KTUN=$C_KTUN
            DEFAULT_LTUN=$C_LTUN
            break
        elif echo "$IF_INFO" | grep -qi "Fptn"; then
            # Этот интерфейс уже принадлежит FPTN
            DEFAULT_KTUN=$C_KTUN
            DEFAULT_LTUN=$C_LTUN
            break
        fi
        TUN_IDX=$((TUN_IDX + 1))
    done
fi

printf "Введите имя интерфейса в KeeneticOS (по умолчанию %s): " "$DEFAULT_KTUN"
read -r USER_KTUN
USER_KTUN=${USER_KTUN:-$DEFAULT_KTUN}

printf "Введите имя интерфейса в Linux/TUN (по умолчанию %s): " "$DEFAULT_LTUN"
read -r USER_LTUN
USER_LTUN=${USER_LTUN:-$DEFAULT_LTUN}

# Проверяем наличие уже существующего конфига и сохраняем введенный ранее токен
PREV_TOKEN=""
if [ -f "/opt/etc/fptn-client.conf" ]; then
    . "/opt/etc/fptn-client.conf" 2>/dev/null || true
    PREV_TOKEN="$TOKEN"
fi

if [ -n "$PREV_TOKEN" ]; then
    printf "Найден существующий токен подписки [%s...]. Нажмите Enter для сохранения или введите новый: " "$(echo "$PREV_TOKEN" | cut -c 1-12)"
    read -r USER_TOKEN
    USER_TOKEN=${USER_TOKEN:-$PREV_TOKEN}
else
    printf "Введите токен подписки FPTN (опционально, можно ввести позже в панели): "
    read -r USER_TOKEN
fi

echo ""
echo "Параметры установки:"
echo "  Веб-порт:               $USER_PORT"
echo "  Интерфейс KeeneticOS:   $USER_KTUN"
echo "  Интерфейс Linux TUN:    $USER_LTUN"
if [ -n "$USER_TOKEN" ]; then
    echo "  Токен подписки:         [Указан]"
else
    echo "  Токен подписки:         [Не указан, введите позже]"
fi
echo ""
printf "Продолжить установку? (y/n, по умолчанию y): "
read -r CONFIRM
CONFIRM=${CONFIRM:-y}
if [ "$CONFIRM" != "y" ] && [ "$CONFIRM" != "Y" ]; then
    echo "Установка отменена."
    exit 0
fi

# 3. Установка необходимых системных пакетов
echo ""
echo "[1/7] Обновление пакетов и установка зависимостей..."
opkg update
opkg install lighttpd php8-cgi php8-mod-openssl php8-mod-session procps-ng-pgrep procps-ng-pkill curl ca-bundle ca-certificates cron

# 4. Автоопределение архитектуры и скачивание бинарника fptn-client-cli
echo ""
echo "[2/7] Определение архитектуры процессора..."
RAW_ARCH=$(uname -m)
case "$RAW_ARCH" in
    aarch64)
        ARCH_SUFFIX="aarch64"
        ;;
    armv7*)
        ARCH_SUFFIX="armv7"
        ;;
    mips*el)
        ARCH_SUFFIX="mipsel"
        ;;
    *)
        echo "Ошибка: Неподдерживаемая архитектура процессора: $RAW_ARCH"
        echo "Вам необходимо собрать бинарный файл fptn-client-cli вручную."
        exit 1
        ;;
esac

echo "Архитектура процессора: $RAW_ARCH ($ARCH_SUFFIX)"

# Останавливаем запущенную службу, чтобы избежать ошибки "Text file busy"
if [ -f "/opt/etc/init.d/S53fptn-client" ]; then
    echo "Остановка запущенной службы VPN перед обновлением бинарника..."
    /opt/etc/init.d/S53fptn-client stop >/dev/null 2>&1 || true
    sleep 1
fi
pkill -9 -f "/opt/bin/fptn-client-cli" >/dev/null 2>&1 || true

# Удаляем старый бинарник, чтобы гарантированно избежать ошибки "Text file busy" при записи
rm -f /opt/bin/fptn-client-cli

echo "Скачивание скомпилированного бинарника..."
DOWNLOAD_URL="${GITHUB_RAW_BASE}/../../releases/download/${REMOTE_VER}/fptn-client-cli-${ARCH_SUFFIX}"
BIN_DIRECT_URL="https://github.com/AntikFull/fptn-keenetic/releases/download/${REMOTE_VER}/fptn-client-cli-${ARCH_SUFFIX}"

if ! download_file "$BIN_DIRECT_URL" "/opt/bin/fptn-client-cli" 180; then
    echo "Ошибка: Не удалось скачать бинарный файл с релиза: $BIN_DIRECT_URL"
    echo "Проверьте интернет-соединение или доступность релиза на GitHub."
    exit 1
fi
chmod +x /opt/bin/fptn-client-cli

# 5. Создание и настройка TUN-интерфейса в KeeneticOS
echo ""
echo "[3/7] Регистрация интерфейса $USER_KTUN в KeeneticOS..."
if ! which ndmc >/dev/null 2>&1; then
    echo "Внимание: Утилита ndmc CLI не найдена. Настройка интерфейса пропускается."
    echo "Вам потребуется вручную настроить интерфейс в CLI Keenetic."
else
    # Проверяем, существует ли уже этот интерфейс
    if ndmc -c "show interface $USER_KTUN" >/dev/null 2>&1; then
        echo "Интерфейс $USER_KTUN уже существует в системе."
    else
        echo "Создание интерфейса $USER_KTUN типа OpkgTun..."
        ndmc -c "interface $USER_KTUN type OpkgTun"
    fi
    
    # Настройка параметров интерфейса
    ndmc -c "interface $USER_KTUN description Fptn"
    ndmc -c "interface $USER_KTUN security-level public"
    ndmc -c "interface $USER_KTUN ip address 10.0.0.1 255.255.255.255"
    ndmc -c "interface $USER_KTUN ip global 50000"
    ndmc -c "interface $USER_KTUN ip tcp adjust-mss pmtu"
    ndmc -c "interface $USER_KTUN up"
    ndmc -c "system configuration save"
    echo "Интерфейс $USER_KTUN успешно настроен в KeeneticOS."
fi

# 6. Настройка веб-сервера Lighttpd
echo ""
echo "[4/7] Настройка конфигурации веб-сервера Lighttpd..."
LIGHTTPD_CONF_DIR="/opt/etc/lighttpd/conf.d"
mkdir -p "$LIGHTTPD_CONF_DIR"

# Определяем, является ли порт глобальным в lighttpd.conf
GLOBAL_PORT=80
if [ -f "/opt/etc/lighttpd/lighttpd.conf" ]; then
    CONF_PORT=$(grep -oE "server.port\s*=\s*[0-9]+" /opt/etc/lighttpd/lighttpd.conf | tr -d ' ' | cut -d'=' -f2)
    if [ -n "$CONF_PORT" ]; then
        GLOBAL_PORT=$CONF_PORT
    fi
fi
# Проверяем также nfqws_port
if [ -f "/opt/etc/lighttpd/conf.d/80-nfqws.conf" ]; then
    NFQWS_PORT=$(grep -oE "server.port\s*:=\s*[0-9]+" /opt/etc/lighttpd/conf.d/80-nfqws.conf | tr -d ' ' | cut -d':' -f2 | cut -d'=' -f2)
    if [ -n "$NFQWS_PORT" ]; then
        GLOBAL_PORT=$NFQWS_PORT
    fi
fi

# Если выбранный порт совпадает с текущим глобальным, то привязываем просто по URL
if [ "$USER_PORT" -eq "$GLOBAL_PORT" ]; then
    cat << 'EOF' > "$LIGHTTPD_CONF_DIR/85-fptn.conf"
# Настройки веб-интерфейса FPTN
server.modules += ( "mod_cgi" )
$HTTP["url"] =~ "^/fptn/" {
    cgi.assign = ( ".php" => "/opt/bin/php-cgi" )
    static-file.exclude-extensions += ( ".php" )
}
EOF
else
    # Если порт другой, вешаем на отдельный сокет
    cat << EOF > "$LIGHTTPD_CONF_DIR/85-fptn.conf"
# Настройки веб-интерфейса FPTN на кастомном порту $USER_PORT
server.modules += ( "mod_cgi" )
\$SERVER["socket"] == ":$USER_PORT" {
    \$HTTP["url"] =~ "^/fptn/" {
        cgi.assign = ( ".php" => "/opt/bin/php-cgi" )
        static-file.exclude-extensions += ( ".php" )
    }
}
EOF
fi

echo "Перезапуск веб-сервера Lighttpd..."
/opt/etc/init.d/S80lighttpd restart || echo "Предупреждение: Не удалось перезапустить Lighttpd. Сделайте это вручную."

# 7. Установка файлов веб-панели
echo ""
echo "[5/7] Копирование файлов веб-панели..."
WWW_DIR="/opt/share/www/fptn"
mkdir -p "$WWW_DIR"

SCRIPT_DIR=$(dirname "$0")
if [ -f "$SCRIPT_DIR/index.php" ]; then
    cp "$SCRIPT_DIR/index.php" "$WWW_DIR/index.php"
else
    if ! download_file "${GITHUB_RAW_BASE}/deploy/keenetic/index.php" "$WWW_DIR/index.php" 30; then
        echo "Ошибка: Не удалось скачать index.php"
        exit 1
    fi
fi
chmod 644 "$WWW_DIR/index.php"

# 8. Создание/Обновление файла конфигурации FPTN службы
echo ""
echo "[6/8] Сохранение конфигурации FPTN..."
CONF_PATH="/opt/etc/fptn-client.conf"
CONF_ENABLED="no"
CONF_SERVERS=""
CONF_WATCHDOG="yes"
CONF_PASS=""

if [ -f "$CONF_PATH" ]; then
    CONF_ENABLED=$(grep -E "^ENABLED=" "$CONF_PATH" | cut -d'=' -f2- | tr -d "\"'" || echo "no")
    CONF_SERVERS=$(grep -E "^PREFERRED_SERVER=" "$CONF_PATH" | cut -d'=' -f2- | tr -d "\"'" || echo "")
    CONF_WATCHDOG=$(grep -E "^WATCHDOG=" "$CONF_PATH" | cut -d'=' -f2- | tr -d "\"'" || echo "yes")
    CONF_PASS=$(grep -E "^WEB_PASSWORD=" "$CONF_PATH" | cut -d'=' -f2- | tr -d "\"'" || echo "")
fi

cat << EOF > "$CONF_PATH"
# Конфигурация клиента FPTN (Создано автоматически)
ENABLED="${CONF_ENABLED:-no}"
TOKEN="$USER_TOKEN"
PREFERRED_SERVER="${CONF_SERVERS}"
TUN_INTERFACE="$USER_LTUN"
WATCHDOG="${CONF_WATCHDOG:-yes}"
WEB_PASSWORD="${CONF_PASS}"
EOF
chmod 600 "$CONF_PATH"

# 9. Установка init-скрипта автозапуска службы
echo ""
echo "[7/8] Настройка службы автозапуска..."
if [ -f "$SCRIPT_DIR/S53fptn-client" ]; then
    cp "$SCRIPT_DIR/S53fptn-client" "/opt/etc/init.d/S53fptn-client"
else
    if ! download_file "${GITHUB_RAW_BASE}/deploy/keenetic/S53fptn-client" "/opt/etc/init.d/S53fptn-client" 30; then
        echo "Ошибка: Не удалось скачать init-скрипт"
        exit 1
    fi
fi
chmod 755 /opt/etc/init.d/S53fptn-client

# 10. Настройка автопинг-наблюдателя (Watchdog) и планировщика задач
echo ""
echo "[8/8] Настройка автопинг-наблюдателя (Watchdog)..."
if [ -f "$SCRIPT_DIR/fptn-watchdog.sh" ]; then
    cp "$SCRIPT_DIR/fptn-watchdog.sh" "/opt/bin/fptn-watchdog.sh"
else
    if ! download_file "${GITHUB_RAW_BASE}/deploy/keenetic/fptn-watchdog.sh" "/opt/bin/fptn-watchdog.sh" 30; then
        echo "Ошибка: Не удалось скачать watchdog-скрипт"
chmod 755 /opt/bin/fptn-watchdog.sh

# Прописываем задачу в кронтаб Entware
CRONTAB="/opt/etc/crontab"
CRON_JOB="*/1 * * * * root /opt/bin/fptn-watchdog.sh"
if [ -f "$CRONTAB" ]; then
    if ! grep -q "fptn-watchdog.sh" "$CRONTAB"; then
        echo "$CRON_JOB" >> "$CRONTAB"
    fi
else
    cat << EOF > "$CRONTAB"
SHELL=/bin/sh
PATH=/opt/sbin:/opt/bin:/usr/sbin:/usr/bin:/sbin:/bin
# m h dom mon dow user  command
$CRON_JOB
EOF
fi

# Включаем и запускаем службу планировщика cron
if [ -x "/opt/etc/init.d/S05cron" ]; then
    /opt/etc/init.d/S05cron start >/dev/null 2>&1
elif [ -x "/opt/etc/init.d/S10cron" ]; then
    /opt/etc/init.d/S10cron start >/dev/null 2>&1
fi

echo ""
echo "==========================================================="
echo "             Установка успешно завершена!"
echo "==========================================================="
echo ""
echo "  1. Веб-панель управления доступна по адресу:"
echo "     http://192.168.1.1:$USER_PORT/fptn/"
echo ""
echo "  2. Чтобы запустить туннель:"
echo "     - Откройте веб-панель FPTN."
echo "     - Введите/проверьте токен подписки."
echo "     - Выберите сервер и нажмите кнопку 'Запустить'."
echo ""
echo "  3. Маршрутизация трафика:"
echo "     - В веб-интерфейсе Keenetic в разделе 'Приоритеты подключений'"
echo "       появится новое подключение 'Fptn'."
echo "     - Перетащите его в нужную политику маршрутизации."
echo "     - Также вы можете настраивать DNS-маршруты доменов на интерфейс $USER_KTUN."
echo ""
echo "==========================================================="
