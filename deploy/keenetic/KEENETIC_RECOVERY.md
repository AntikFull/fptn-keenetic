# 🚑 Полное руководство по бэкапу, восстановлению и реанимации FPTN на KeeneticOS

Настоящий документ содержит исчерпывающие инструкции по резервному копированию, аварийному восстановлению настройки и экстренной реанимации сетевого соединения FPTN VPN на роутерах Keenetic (Entware).

---

## 💾 1. Полный бэкап настроек FPTN на роутере

### Что входит в бэкап:
1. Конфигурационный файл клиента: `/opt/etc/fptn-client.conf` (токен, список приоритетов серверов, выбранный TUN интерфейс).
2. Настройки веб-панели Lighttpd: `/opt/etc/lighttpd/conf.d/85-fptn.conf`.
3. Файлы веб-интерфейса: `/opt/share/www/fptn/`.
4. Скрипт автозапуска службы: `/opt/etc/init.d/S53fptn-client`.
5. Встроенный supervisor: повторное подключение выполняет `fptn-client-cli`.

### Команда создания архива бэкапа (выполняется в SSH роутера):
```bash
opkg install tar
/opt/bin/tar -czf /opt/fptn_keenetic_backup.tar.gz \
  /opt/etc/fptn-client.conf \
  /opt/etc/init.d/S53fptn-client \
  /opt/etc/lighttpd/conf.d/85-fptn.conf \
  /opt/share/www/fptn
```
Архив `/opt/fptn_keenetic_backup.tar.gz` можно сохранить на ПК через SCP/SFTP или скопировать на подключенную USB-флешку:
```bash
cp /opt/fptn_keenetic_backup.tar.gz /tmp/mnt/YOUR_USB_NAME/
```

---

## 🛠️ 2. Пошаговое восстановление FPTN после сброса роутера / переустановки Entware

Если роутер был сброшен до заводских настроек или вы переустановили накопитель Entware:

### Шаг 1: Автоматическая установка свежего релиза с GitHub
Подключитесь к роутеру по SSH и выполните команду установки:
```bash
curl -sL https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/install.sh | sh
```
Установщик автоматически:
- Подгрузит зависимости Entware (`lighttpd`, `php8-cgi`, `libxml2`, `curl`).
- Зарегистрирует туннельный интерфейс `OpkgTun1` в KeeneticOS с безопасным приоритетом `ip global 50000`.
- Установит бинарник `fptn-client-cli` под вашу архитектуру роутера (`aarch64`, `armv7` или `mipsel`).
- Развернет веб-панель FPTN на порту `8088`.

### Шаг 2: Восстановление настроек из архива бэкапа (Опционально)
Если у вас сохранен архив `fptn_keenetic_backup.tar.gz`:
```bash
tar -xzf /tmp/fptn_keenetic_backup.tar.gz -C /
/opt/etc/init.d/S80lighttpd restart
/opt/etc/init.d/S53fptn-client restart
```

---

## 🚨 3. Экстренная реанимация сети (Emergency Recovery)

Если при нештатной ситуации (например, сбой электропитания или некорректные настройки) на роутере пропал доступ в интернет:

### Вариант А: Быстрый сброс туннельного интерфейса в KeeneticOS CLI (`ndmc`)
Подключитесь по SSH и отключите интерфейс FPTN в системном CLI Keenetic:
```bash
ndmc -c "no interface OpkgTun1"
ndmc -c "system configuration save"
```
Интернет через основного провайдера восстановится моментально.

### Вариант Б: Полная деинсталляция FPTN «в 1 клик»
Для бессметного и чистого удаления всех компонентов FPTN с роутера выполните:
```bash
curl -sL https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/uninstall.sh | sh
```
Скрипт остановки удалит бинарники и очистит интерфейс KeeneticOS.

---

## 🔍 4. Полезные команды диагностики

- **Проверка статуса службы:**
  ```bash
  /opt/etc/init.d/S53fptn-client status
  ```
- **Просмотр логов работы клиента в реальном времени:**
  ```bash
  tail -f /opt/var/log/fptn/fptn-client-cli.log
  ```
- **Просмотр состояния встроенного supervisor:**
  ```bash
  cat /opt/var/log/fptn/fptn-client-cli.log
  ```
- **Проверка наличия IP адреса на туннеле:**
  ```bash
  ip addr show opkgtun1
  ```
