#!/bin/sh
# Интерактивный установщик FPTN-клиента для Keenetic (Entware) / Interactive FPTN Client Installer for Keenetic
# Автор / Author: Antigravity
# Поддержка языков / Language: Русский (RU) & English (EN)

set -e

# Устанавливаем UTF-8 локаль для предотвращения крякозябр в любых SSH-клиентах
export LANG="${LANG:-ru_RU.UTF-8}"
export LC_ALL="${LC_ALL:-ru_RU.UTF-8}"

echo "==========================================================="
echo "  Установка FPTN-клиента и веб-панели для Keenetic (Entware)"
echo "  Installing FPTN Client & Web Panel for Keenetic (Entware)"
echo "==========================================================="
echo ""

# 1. Проверка среды Entware / Check Entware environment
if [ ! -d "/opt/etc" ] || [ ! -x "/opt/bin/opkg" ]; then
    echo "Ошибка: Среда Entware не найдена на роутере! / Error: Entware environment not found!"
    echo "Убедитесь, что Entware установлен и работает (/opt доступен) / Ensure Entware is installed and /opt is mounted."
    exit 1
fi

GITHUB_RAW_BASE="https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master"

# Обработка команды удаления / Uninstall command handler
if [ "$1" = "--uninstall" ] || [ "$1" = "-u" ] || [ "$1" = "uninstall" ]; then
    echo "Запуск процесса удаления FPTN... / Starting FPTN uninstallation..."
    
    KTUN_NAME="OpkgTun1"
    if [ -f "/opt/etc/fptn-client.conf" ]; then
        . "/opt/etc/fptn-client.conf" 2>/dev/null || true
        if [ -n "$TUN_INTERFACE" ]; then
            KTUN_NAME=$(echo "$TUN_INTERFACE" | sed -E 's/opkgtun([0-9]+)/OpkgTun\1/i')
        fi
    fi

    echo "[1/5] Остановка служб FPTN / Stopping FPTN services..."
    if [ -f "/opt/etc/init.d/S53fptn-client" ]; then
        /opt/etc/init.d/S53fptn-client stop >/dev/null 2>&1 || true
    fi
    pkill -9 -f "fptn-client-cli" >/dev/null 2>&1 || true
    # Старый watchdog мог остаться от предыдущей версии — удаляем его процесс
    # один раз при миграции, но новая установка такой процесс не запускает.
    pkill -9 -f "fptn-watchdog" >/dev/null 2>&1 || true

    echo "[2/5] Удаление бинарников и файлов веб-панели / Removing binary & web files..."
    rm -f /opt/bin/fptn-client-cli /opt/bin/fptn-watchdog.sh /opt/bin/fptn-ndm-setup.sh /opt/etc/fptn-client.conf /opt/etc/init.d/S53fptn-client /opt/etc/fptn-watchdog.sh /opt/etc/lighttpd/conf.d/85-fptn.conf
    rm -rf /opt/share/www/fptn

    echo "[3/5] Обновление конфигурации Lighttpd / Updating Lighttpd..."
    if [ -f "/opt/etc/init.d/S80lighttpd" ]; then
        /opt/etc/init.d/S80lighttpd restart >/dev/null 2>&1 || true
    fi

    echo "[4/5] Очистка старых записей cron / Cleaning legacy crontab entries..."
    # Задание могло быть добавлено и в /opt/etc/crontab, и в пользовательский
    # spool — разные версии установщика делали по-разному.
    if which crontab >/dev/null 2>&1; then
        (crontab -l 2>/dev/null | grep -v "fptn-watchdog" | crontab - 2>/dev/null) || true
    fi
    for _f in /opt/etc/crontab /opt/var/spool/cron/crontabs/root /var/spool/cron/crontabs/root; do
        if [ -f "$_f" ] && grep -q "fptn-watchdog" "$_f"; then
            sed -i '/fptn-watchdog/d' "$_f"
        fi
    done

    echo "[5/5] Удаление интерфейса $KTUN_NAME в KeeneticOS / Removing $KTUN_NAME interface..."
    if which ndmc >/dev/null 2>&1; then
        ndmc -c "no interface $KTUN_NAME" 2>/dev/null || true
        ndmc -c "system configuration save" 2>/dev/null || true
    fi

    echo ""
    echo "==========================================================="
    echo "  Удаление успешно завершено! / Uninstallation finished!"
    echo "  FPTN полностью удален с роутера. / FPTN successfully removed."
    echo "==========================================================="
    exit 0
fi
# Динамическое определение версии релиза из version.txt
REMOTE_VER=$(curl -sL --connect-timeout 5 "${GITHUB_RAW_BASE}/deploy/keenetic/version.txt" 2>/dev/null | tr -d '\r\n')
if [ -z "$REMOTE_VER" ]; then
    REMOTE_VER="v1.3.0-keenetic"
fi

# Универсальная функция скачивания с каскадом прокси-зеркал / Download helper with mirror fallback
download_file() {
    _url="$1"
    _dest="$2"
    _timeout="${3:-120}"

    if curl -sSL --connect-timeout 10 --max-time "$_timeout" -o "$_dest" "$_url"; then
        if [ -s "$_dest" ]; then return 0; fi
    fi

    # Пробуем каскад зеркал / Try mirrors fallback
    for _prefix in "https://ghproxy.net/" "https://ghfast.top/" "https://cdn.jsdelivr.net/gh/AntikFull/fptn-keenetic@master/"; do
        _mirror_url="${_prefix}${_url}"
        if curl -sSL --connect-timeout 10 --max-time "$_timeout" -o "$_dest" "$_mirror_url"; then
            if [ -s "$_dest" ]; then return 0; fi
        fi
    done
    return 1
}

# Функция проверки занятости порта посторонними сервисами (кроме lighttpd)
is_port_busy() {
    _p="$1"
    _listeners=$(netstat -tulnp 2>/dev/null | grep -E ":${_p}\s" 2>/dev/null || netstat -tuln 2>/dev/null | grep -E ":${_p}\s" 2>/dev/null || true)
    if [ -n "$_listeners" ]; then
        # Если порт занят НЕ lighttpd (например nginx, xray, adguard)
        if echo "$_listeners" | grep -v "lighttpd" >/dev/null 2>&1; then
            return 0
        fi
    fi
    return 1
}

AUTO_ACCEPT="no"
if [ "$1" = "-y" ] || [ "$1" = "--auto" ]; then
    AUTO_ACCEPT="yes"
fi

# Функция считывания ввода пользователя с подстановкой по умолчанию
read_input() {
    _var_name="$1"
    _default_val="$2"
    _res=""
    if [ "$AUTO_ACCEPT" = "yes" ]; then
        _res="$_default_val"
        echo "$_res"
    else
        read -r _res 2>/dev/null || _res=""
        _res=${_res:-$_default_val}
    fi
    eval "$_var_name=\"\$_res\""
}

# 2. Интерактивный опрос параметров / Interactive Configuration Prompt
DEFAULT_PORT=8088
if [ -f "/opt/etc/lighttpd/conf.d/80-nfqws.conf" ]; then
    NFQWS_PORT=$(grep -oE "server.port := [0-9]+" /opt/etc/lighttpd/conf.d/80-nfqws.conf | awk '{print $3}')
    if [ -n "$NFQWS_PORT" ]; then
        DEFAULT_PORT=$NFQWS_PORT
    fi
fi

# Проверяем, свободен ли дефолтный порт
if is_port_busy "$DEFAULT_PORT"; then
    echo "Предупреждение: Порт $DEFAULT_PORT уже занят! / Warning: Port $DEFAULT_PORT is currently busy!"
    CHECK_P=8089
    while is_port_busy "$CHECK_P"; do
        CHECK_P=$((CHECK_P + 1))
    done
    DEFAULT_PORT=$CHECK_P
    echo "Автоматически выбран свободный порт / Auto-selected available port: $DEFAULT_PORT"
fi

printf "Введите порт для веб-панели FPTN / Enter FPTN web panel port (default %s): " "$DEFAULT_PORT"
read_input USER_PORT "$DEFAULT_PORT"

if is_port_busy "$USER_PORT"; then
    echo "ВНИМАНИЕ: Выбранный порт $USER_PORT сейчас занят! / WARNING: Selected port $USER_PORT is busy!"
fi

# Проверяем наличие уже существующего конфига для сохранения настроек при обновлении
PREV_TOKEN=""
PREV_LTUN=""
PREV_TUN_IP=""
if [ -f "/opt/etc/fptn-client.conf" ]; then
    . "/opt/etc/fptn-client.conf" 2>/dev/null || true
    PREV_TOKEN="$TOKEN"
    PREV_LTUN="$TUN_INTERFACE"
    PREV_TUN_IP="$TUN_ADDRESS"
fi

# Адрес туннеля. Одно значение уходит и клиенту (--tun-interface-ip), и ndm:
# ndm транслирует в него трафик локальной сети, клиент в него же переписывает
# получателя входящих пакетов. Разойдутся — обратного трафика не будет.
USER_TUN_IP="${PREV_TUN_IP:-10.0.0.1}"

# Автоподбор свободного имени туннельного интерфейса в KeeneticOS
DEFAULT_KTUN="OpkgTun1"
DEFAULT_LTUN="opkgtun1"

if [ -n "$PREV_LTUN" ]; then
    DEFAULT_LTUN="$PREV_LTUN"
    DEFAULT_KTUN=$(echo "$PREV_LTUN" | sed -E 's/opkgtun([0-9]+)/OpkgTun\1/i')
elif which ndmc >/dev/null 2>&1; then
    TUN_IDX=1
    while [ "$TUN_IDX" -le 10 ]; do
        C_KTUN="OpkgTun${TUN_IDX}"
        C_LTUN="opkgtun${TUN_IDX}"
        # Сообщение об ошибке ndmc пишет в stderr и при этом возвращает 0,
        # поэтому судить можно только по тексту вывода — и stderr обязательно
        # надо забрать в stdout, иначе проверка ничего не увидит.
        # Добавляем || true, чтобы при включенном set -e скрипт не падал при ошибке ndmc.
        IF_INFO=$(ndmc -c "show interface $C_KTUN" 2>&1 || true)
        if [ -z "$IF_INFO" ] || echo "$IF_INFO" | grep -qiE "error|no such|not found"; then
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

printf "Введите имя интерфейса в KeeneticOS / Enter KeeneticOS interface name (default %s): " "$DEFAULT_KTUN"
read_input USER_KTUN "$DEFAULT_KTUN"

printf "Введите имя интерфейса в Linux/TUN / Enter Linux/TUN interface name (default %s): " "$DEFAULT_LTUN"
read_input USER_LTUN "$DEFAULT_LTUN"

if [ -n "$PREV_TOKEN" ]; then
    printf "Найден токен [%s...]. Нажмите Enter для сохранения или введите новый / Existing token found [%s...]. Press Enter to keep or type new: " "$(echo "$PREV_TOKEN" | cut -c 1-12)" "$(echo "$PREV_TOKEN" | cut -c 1-12)"
    read_input USER_TOKEN "$PREV_TOKEN"
else
    printf "Введите токен подписки FPTN (опционально) / Enter FPTN subscription token (optional): "
    read_input USER_TOKEN ""
fi

echo ""
echo "Параметры установки / Installation Parameters:"
echo "  Веб-порт / Web Port:               $USER_PORT"
echo "  Интерфейс KeeneticOS / OS Interface: $USER_KTUN"
echo "  Интерфейс Linux TUN / TUN Interface: $USER_LTUN"
if [ -n "$USER_TOKEN" ]; then
    echo "  Токен подписки / Subscription Token: [Указан / Specified]"
else
    echo "  Токен подписки / Subscription Token: [Не указан / Not specified]"
fi
echo ""
printf "Продолжить установку? / Continue installation? (y/n, default y): "
read_input CONFIRM "y"
if [ "$CONFIRM" != "y" ] && [ "$CONFIRM" != "Y" ]; then
    echo "Установка отменена / Installation cancelled."
    exit 0
fi

# 3. Установка необходимых системных пакетов / Install Entware packages
echo ""
echo "[1/7] Обновление пакетов и установка зависимостей / Updating packages & dependencies..."
opkg update || true
opkg install lighttpd lighttpd-mod-cgi php8-cgi php8-mod-openssl php8-mod-session libxml2 procps-ng-pgrep procps-ng-pkill curl ca-bundle ca-certificates

# 4. Автоопределение архитектуры и скачивание бинарника fptn-client-cli / Detect CPU & Download Binary
echo ""
echo "[2/7] Определение архитектуры процессора / Detecting CPU architecture..."
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
        echo "Ошибка: Неподдерживаемая архитектура / Error: Unsupported CPU architecture: $RAW_ARCH"
        exit 1
        ;;
esac

echo "Архитектура процессора / CPU Architecture: $RAW_ARCH ($ARCH_SUFFIX)"

# Останавливаем запущенную службу перед обновлением
if [ -f "/opt/etc/init.d/S53fptn-client" ]; then
    echo "Остановка запущенной службы / Stopping running VPN service..."
    /opt/etc/init.d/S53fptn-client stop >/dev/null 2>&1 || true
    sleep 1
fi
pkill -9 -f "/opt/bin/fptn-client-cli" >/dev/null 2>&1 || true
rm -f /opt/bin/fptn-client-cli

# Проверка валидности бинарного файла (не менее 1 МБ и отсутствие HTML ошибок)
check_binary_valid() {
    _f="$1"
    if [ ! -s "$_f" ]; then return 1; fi
    _sz=$(wc -c < "$_f" 2>/dev/null || echo 0)
    if [ "$_sz" -lt 1000000 ]; then return 1; fi
    if head -n 1 "$_f" | grep -qi "Not Found\|DOCTYPE\|404\|error"; then return 1; fi
    return 0
}

echo "Скачивание скомпилированного бинарника / Downloading compiled binary..."
BIN_RAW_URL="${GITHUB_RAW_BASE}/deploy/keenetic/bin/fptn-client-cli-${ARCH_SUFFIX}"
BIN_RELEASE_URL="https://github.com/AntikFull/fptn-keenetic/releases/download/${REMOTE_VER}/fptn-client-cli-${ARCH_SUFFIX}"
BIN_LATEST_URL="https://github.com/AntikFull/fptn-keenetic/releases/latest/download/fptn-client-cli-${ARCH_SUFFIX}"

DOWNLOAD_SUCCESS=no
for _src_url in "$BIN_RAW_URL" "$BIN_RELEASE_URL" "$BIN_LATEST_URL"; do
    if download_file "$_src_url" "/opt/bin/fptn-client-cli.tmp" 180; then
        if check_binary_valid "/opt/bin/fptn-client-cli.tmp"; then
            mv "/opt/bin/fptn-client-cli.tmp" "/opt/bin/fptn-client-cli"
            DOWNLOAD_SUCCESS=yes
            break
        else
            rm -f "/opt/bin/fptn-client-cli.tmp"
        fi
    fi
done

if [ "$DOWNLOAD_SUCCESS" != "yes" ] && [ ! -s "/opt/bin/fptn-client-cli" ]; then
    echo "Ошибка: Не удалось скачать бинарный файл / Error: Failed to download valid binary!"
    exit 1
fi
chmod +x /opt/bin/fptn-client-cli
echo "$REMOTE_VER" > /opt/etc/fptn-version

# 5. Создание и настройка TUN-интерфейса в KeeneticOS / Register TUN Interface in KeeneticOS
echo ""
echo "[3/7] Регистрация интерфейса $USER_KTUN в KeeneticOS / Registering $USER_KTUN in KeeneticOS..."
if ! which ndmc >/dev/null 2>&1; then
    echo "Внимание: Утилита ndmc CLI не найдена / Warning: ndmc CLI not found. Skip interface setup."
else
    # ndmc возвращает 0 и для несуществующего интерфейса, а текст ошибки пишет
    # в stderr. Прежняя проверка по коду возврата всегда уходила в ветку «уже
    # существует», и интерфейс не создавался.
    # Добавляем || true, чтобы при включенном set -e скрипт не падал при ошибке ndmc.
    EXISTING_IF=$(ndmc -c "show interface $USER_KTUN" 2>&1 || true)
    if [ -n "$EXISTING_IF" ] && ! echo "$EXISTING_IF" | grep -qiE "error|no such|not found"; then
        echo "Интерфейс $USER_KTUN уже существует / Interface $USER_KTUN already exists."
    else
        echo "Создание интерфейса $USER_KTUN типа OpkgTun / Creating OpkgTun interface $USER_KTUN..."
        ndmc -c "interface $USER_KTUN" 2>/dev/null || ndmc -c "interface $USER_KTUN type OpkgTun" 2>/dev/null || true
    fi

    ndmc -c "interface $USER_KTUN description Fptn" 2>/dev/null || true
    ndmc -c "interface $USER_KTUN security-level public" 2>/dev/null || true
    # Адрес и маска должны в точности совпадать с тем, что назначает сам клиент
    # (TUN_ADDRESS в fptn-client.conf передаётся ему как --tun-interface-ip,
    # маска /32). При расхождении ndm уходит в цикл переконфигурации интерфейса,
    # а conntrack перестаёт сопоставлять обратный трафик.
    ndmc -c "interface $USER_KTUN ip address $USER_TUN_IP 255.255.255.255" 2>/dev/null || true
    ndmc -c "interface $USER_KTUN ip mtu 1360" 2>/dev/null || true
    ndmc -c "interface $USER_KTUN ip global 50000" 2>/dev/null || true
    # Подгонку MSS выполняет ndm; правила iptables TCPMSS для этого не нужны
    ndmc -c "interface $USER_KTUN ip tcp adjust-mss pmtu" 2>/dev/null || true
    ndmc -c "access-list _WEBADMIN_$USER_KTUN permit ip 0.0.0.0 0.0.0.0 0.0.0.0 0.0.0.0" >/dev/null 2>&1 || true
    ndmc -c "interface $USER_KTUN ip access-group _WEBADMIN_$USER_KTUN in" >/dev/null 2>&1 || true
    ndmc -c "access-list _WEBADMIN_$USER_KTUN auto-delete" >/dev/null 2>&1 || true
    ndmc -c "interface $USER_KTUN up" 2>/dev/null || true
    ndmc -c "system configuration save" >/dev/null 2>&1 || true
    echo "Интерфейс $USER_KTUN успешно настроен / Interface $USER_KTUN successfully configured."
fi

# 6. Настройка веб-сервера Lighttpd / Configure Lighttpd
echo ""
echo "[4/7] Настройка конфигурации Lighttpd / Configuring Lighttpd web server..."
LIGHTTPD_CONF_DIR="/opt/etc/lighttpd/conf.d"
mkdir -p "$LIGHTTPD_CONF_DIR"

IS_LIGHTTPD_LISTENING=no
if netstat -tulnp 2>/dev/null | grep "lighttpd" | grep -qE ":${USER_PORT}\s"; then
    IS_LIGHTTPD_LISTENING=yes
elif netstat -tuln 2>/dev/null | grep "lighttpd" | grep -qE ":${USER_PORT}\s"; then
    IS_LIGHTTPD_LISTENING=yes
fi

if [ "$IS_LIGHTTPD_LISTENING" != "yes" ]; then
    for _cf in /opt/etc/lighttpd/lighttpd.conf /opt/etc/lighttpd/conf.d/*.conf; do
        if [ -f "$_cf" ]; then
            if grep -oE "server.port\s*[:=]+\s*${USER_PORT}\b" "$_cf" >/dev/null 2>&1; then
                IS_LIGHTTPD_LISTENING=yes
                break
            fi
        fi
    done
fi

if [ "$IS_LIGHTTPD_LISTENING" = "yes" ]; then
    cat << 'EOF' > "$LIGHTTPD_CONF_DIR/85-fptn.conf"
# Настройки веб-интерфейса FPTN
server.modules += ( "mod_cgi" )
$HTTP["url"] =~ "^/fptn/" {
    cgi.assign = ( ".php" => "/opt/bin/php-cgi" )
    static-file.exclude-extensions += ( ".php" )
}
EOF
else
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

echo "Перезапуск Lighttpd / Restarting Lighttpd..."
/opt/etc/init.d/S80lighttpd restart || echo "Предупреждение: Не удалось перезапустить Lighttpd / Warning: Could not restart Lighttpd."

# 7. Установка файлов веб-панели / Install Web Panel
echo ""
echo "[5/7] Копирование файлов веб-панели / Copying web panel files..."
WWW_DIR="/opt/share/www/fptn"
mkdir -p "$WWW_DIR"

SCRIPT_DIR=$(dirname "$0")
if [ -f "$SCRIPT_DIR/index.php" ]; then
    cp "$SCRIPT_DIR/index.php" "$WWW_DIR/index.php"
else
    if ! download_file "${GITHUB_RAW_BASE}/deploy/keenetic/index.php" "$WWW_DIR/index.php" 30; then
        echo "Ошибка: Не удалось скачать index.php / Error: Failed to download index.php"
        exit 1
    fi
fi
chmod 644 "$WWW_DIR/index.php"

# 8. Создание/Обновление файла конфигурации FPTN службы / Save Configuration
echo ""
echo "[6/8] Сохранение конфигурации FPTN / Saving FPTN configuration..."
CONF_PATH="/opt/etc/fptn-client.conf"
CONF_ENABLED="no"
CONF_SERVERS=""
CONF_PASS=""
CONF_TUN_IPV6=""
CONF_TUN_MTU="1360"
CONF_GATEWAY_IP=""
CONF_GATEWAY_IPV6=""
CONF_OUT_IFACE=""
CONF_SNI="rutube.ru"
CONF_BYPASS="sni-spoofing"
CONF_BLACKLIST=""
CONF_EXCLUDE="10.0.0.0/8,172.16.0.0/12,192.168.0.0/16"
CONF_INCLUDE=""
CONF_SPLIT="no"
CONF_SPLIT_MODE="exclude"
CONF_SPLIT_DOMAINS=""
CONF_DISABLE_FALLBACK="no"
CONF_MAX_RESTARTS="15"
CONF_STARTUP_DELAY="5"

if [ -f "$CONF_PATH" ]; then
    . "$CONF_PATH" 2>/dev/null || true
    CONF_ENABLED=$(grep -E "^ENABLED=" "$CONF_PATH" | cut -d'=' -f2- | tr -d "\"'" || echo "no")
    CONF_SERVERS=$(grep -E "^PREFERRED_SERVER=" "$CONF_PATH" | cut -d'=' -f2- | tr -d "\"'" || echo "")
    CONF_PASS=$(grep -E "^WEB_PASSWORD=" "$CONF_PATH" | cut -d'=' -f2- | tr -d "\"'" || echo "")
    CONF_TUN_IPV6="${TUN_IPV6_ADDRESS:-}"
    CONF_TUN_MTU="${TUN_MTU:-1360}"
    CONF_GATEWAY_IP="${GATEWAY_IP:-}"
    CONF_GATEWAY_IPV6="${GATEWAY_IPV6:-}"
    CONF_OUT_IFACE="${OUT_NETWORK_INTERFACE:-}"
    CONF_SNI="${SERVER_SNI:-rutube.ru}"
    CONF_BYPASS="${BYPASS_METHOD:-sni-spoofing}"
    CONF_BLACKLIST="${BLACKLIST_DOMAINS:-}"
    CONF_EXCLUDE="${EXCLUDE_TUNNEL_NETWORKS:-10.0.0.0/8,172.16.0.0/12,192.168.0.0/16}"
    CONF_INCLUDE="${INCLUDE_TUNNEL_NETWORKS:-}"
    CONF_SPLIT="${SPLIT_TUNNEL:-no}"
    CONF_SPLIT_MODE="${SPLIT_TUNNEL_MODE:-exclude}"
    CONF_SPLIT_DOMAINS="${SPLIT_TUNNEL_DOMAINS:-}"
    CONF_DISABLE_FALLBACK="${DISABLE_AUTO_FALLBACK:-no}"
    CONF_MAX_RESTARTS="${MAX_FULL_RESTARTS:-15}"
    CONF_STARTUP_DELAY="${STARTUP_RETRY_DELAY:-5}"
fi

cat << EOF > "$CONF_PATH"
# Конфигурация клиента FPTN (Создано автоматически / Auto-generated)
ENABLED="${CONF_ENABLED:-no}"
TOKEN="$USER_TOKEN"
PREFERRED_SERVER="${CONF_SERVERS}"
TUN_INTERFACE="$USER_LTUN"
# Должно совпадать с адресом, назначенным интерфейсу в KeeneticOS (шаг [3/7]).
TUN_ADDRESS="$USER_TUN_IP"
TUN_IPV6_ADDRESS="${CONF_TUN_IPV6}"
TUN_MTU="${CONF_TUN_MTU}"
GATEWAY_IP="${CONF_GATEWAY_IP}"
GATEWAY_IPV6="${CONF_GATEWAY_IPV6}"
OUT_NETWORK_INTERFACE="${CONF_OUT_IFACE}"
SERVER_SNI="${CONF_SNI}"
BYPASS_METHOD="${CONF_BYPASS}"
BLACKLIST_DOMAINS="${CONF_BLACKLIST}"
EXCLUDE_TUNNEL_NETWORKS="${CONF_EXCLUDE}"
INCLUDE_TUNNEL_NETWORKS="${CONF_INCLUDE}"
SPLIT_TUNNEL="${CONF_SPLIT}"
SPLIT_TUNNEL_MODE="${CONF_SPLIT_MODE}"
SPLIT_TUNNEL_DOMAINS="${CONF_SPLIT_DOMAINS}"
DISABLE_AUTO_FALLBACK="${CONF_DISABLE_FALLBACK}"
MAX_FULL_RESTARTS="${CONF_MAX_RESTARTS}"
STARTUP_RETRY_DELAY="${CONF_STARTUP_DELAY}"
WEB_PASSWORD="${CONF_PASS}"
EOF
chmod 600 "$CONF_PATH"

# Повторная настройка интерфейса здесь удалена намеренно.
# Ранее этот блок назначал 172.20.0.2/255.255.0.0 и ip defaultgateway 172.20.0.1
# поверх уже настроенного выше 10.0.0.1/255.255.255.255. Три разных адреса с
# разными масками на одном интерфейсе (и шлюз, которого не существует)
# заставляли ndm непрерывно сверять свою конфигурацию с ядром — отсюда
# постоянная загрузка процессора, флапающие маршруты и зависание веб-морды.
# Интерфейс настраивается ровно один раз, в шаге [3/7].

# 9. Установка init-скрипта автозапуска службы / Install Init Script
echo ""
echo "[7/8] Настройка службы автозапуска / Setting up auto-start service..."

if [ -f "$SCRIPT_DIR/S53fptn-client" ]; then
    cp "$SCRIPT_DIR/S53fptn-client" "/opt/etc/init.d/S53fptn-client"
else
    if ! download_file "${GITHUB_RAW_BASE}/deploy/keenetic/S53fptn-client" "/opt/etc/init.d/S53fptn-client" 30; then
        echo "Ошибка: Не удалось скачать init-скрипт / Error: Failed to download init script"
        exit 1
    fi
fi
chmod 755 /opt/etc/init.d/S53fptn-client

# 8. Восстановление выполняется встроенным supervisor в fptn-client-cli.
echo ""
echo "[8/8] Внешний watchdog и cron не устанавливаются: supervisor встроен в клиент."

echo ""
echo "==========================================================="
echo "     Установка успешно завершена / Installation Finished!"
echo "==========================================================="
echo ""
echo "  1. Веб-панель доступна по адресу / Web panel URL:"
echo "     http://192.168.1.1:$USER_PORT/fptn/"
echo ""
echo "  2. Запуск туннеля / How to start VPN:"
echo "     - Откройте веб-панель / Open FPTN Web Panel."
echo "     - Введите токен подписки / Enter subscription token."
echo "     - Выберите сервер и нажмите 'Запустить' / Click 'Start'."
echo ""
echo "  3. Маршрутизация трафика / Traffic Routing:"
echo "     - В Keenetic Web UI в разделе 'Приоритеты подключений'"
echo "       появится подключение '$USER_KTUN'."
echo "     - Перетащите '$USER_KTUN' в нужную политику маршрутизации."
echo "     - Drag connection '$USER_KTUN' into target routing policy."
echo "==========================================================="
