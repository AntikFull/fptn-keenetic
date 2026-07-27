#!/bin/sh
# Донастройка туннельного интерфейса в KeeneticOS после старта клиента.
#
# Почему это отдельный файл, а не POSTCMD в S53fptn-client:
# rc.func из Entware подставляет POSTCMD без eval — просто `$POSTCMD`. При этом
# кавычки, `;` и перенаправления теряют синтаксический смысл и попадают в
# аргументы как обычные слова. Прежний POSTCMD с четырьмя вызовами `ndmc -c '...'`
# сворачивался в один вызов ndmc, которому в -c прилетал литерал `'interface`.
# В логе роутера это видно как:
#     [E] ndm: Command::Base: no such command: 'interface.
# Ни приоритет интерфейса, ни access-list при этом не применялись: интерфейс
# оставался с security-level public без разрешающего списка, то есть весь
# трафик через него резался файрволом ndm.
#
# POSTCMD должен быть ОДНИМ словом без кавычек и аргументов — тогда подстановка
# безопасна. Отсюда этот файл.

CONF="${FPTN_CONF:-/opt/etc/fptn-client.conf}"
LOG="/opt/var/log/fptn/fptn-ndm-setup.log"

[ -f "$CONF" ] || exit 0
# shellcheck disable=SC1090
. "$CONF"

[ "$ENABLED" = "yes" ] || exit 0

IFNAME="${TUN_INTERFACE:-opkgtun1}"
KTUN=$(echo "$IFNAME" | sed -E 's/opkgtun([0-9]+)/OpkgTun\1/i')

# Сколько ждать появления устройства. Клиент до создания TUN успевает сходить за
# списком серверов, выполнить TLS-рукопожатие и авторизоваться, а rc.func зовёт
# POSTCMD примерно через секунду после запуска — без ожидания настраивать нечего.
WAIT_SECONDS="${FPTN_NDM_WAIT:-90}"

configure() {
    _i=0
    while [ "$_i" -lt "$WAIT_SECONDS" ]; do
        if ip link show "$IFNAME" >/dev/null 2>&1; then
            break
        fi
        _i=$((_i + 1))
        sleep 1
    done

    if ! ip link show "$IFNAME" >/dev/null 2>&1; then
        echo "$(date): интерфейс $IFNAME не появился за ${WAIT_SECONDS} с" >> "$LOG"
        return 1
    fi

    # Интерфейсу нужен приоритет, иначе ndm не покажет его в политиках
    # маршрутизации, и разрешающий access-list: KeeneticOS удаляет его
    # автоматически, когда интерфейс какое-то время отсутствует.
    ndmc -c "interface ${KTUN} ip global 50000" >/dev/null 2>&1
    ndmc -c "access-list _WEBADMIN_${KTUN} permit ip 0.0.0.0 0.0.0.0 0.0.0.0 0.0.0.0" >/dev/null 2>&1
    ndmc -c "interface ${KTUN} ip access-group _WEBADMIN_${KTUN} in" >/dev/null 2>&1
    ndmc -c "access-list _WEBADMIN_${KTUN} auto-delete" >/dev/null 2>&1
    ndmc -c "interface ${KTUN} up" >/dev/null 2>&1

    echo "$(date): $KTUN настроен" >> "$LOG"
    return 0
}

mkdir -p "$(dirname "$LOG")" 2>/dev/null || true

# Уходим в фон: init-скрипты KeeneticOS выполняются последовательно, и ожидание
# здесь задержало бы запуск всех последующих служб.
if [ "$1" = "--foreground" ]; then
    configure
else
    configure >/dev/null 2>&1 &
fi

exit 0
