# 🌐 Клиент FPTN для роутеров Keenetic (Entware) / FPTN Client for Keenetic

[🇷🇺 Русский](#-клиент-fptn-для-роутеров-keenetic-entware) | [🇬🇧 English](#-fptn-client-for-keenetic-routers-entware)

---

# 🇷🇺 Клиент FPTN для роутеров Keenetic (Entware)

Интеграция маршрутизируемого VPN-клиента FPTN на роутеры Keenetic с установленной средой Entware. Включает в себя веб-панель управления и автоматическое заведение туннеля в систему KeeneticOS.

---

## 1. Быстрая установка (Рекомендуемая)

Запустите интерактивный установщик на роутере по SSH.

### 🚀 Стандартная установка:
Скачайте скрипт установщика во временный файл и запустите его:
```bash
curl -fsSL -o /tmp/install.sh https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/install.sh && sh /tmp/install.sh
```

### ⚡ Зеркало для РФ (в случае блокировок GitHub):
Если ваш провайдер или ТСПУ блокирует GitHub (зависает на raw.githubusercontent.com), используйте быстрое CDN-зеркало:
```bash
curl -fsSL -o /tmp/install.sh https://cdn.jsdelivr.net/gh/AntikFull/fptn-keenetic@master/deploy/keenetic/install.sh && sh /tmp/install.sh
```
*(Или через `wget`, если на роутере нет curl:)*
```bash
wget -O /tmp/install.sh https://cdn.jsdelivr.net/gh/AntikFull/fptn-keenetic@master/deploy/keenetic/install.sh && sh /tmp/install.sh
```

Скрипт автоматически установит все пакеты, скачает бинарный файл под архитектуру вашего процессора (`aarch64`, `armv7`, `mipsel`), создаст туннельный интерфейс в KeeneticOS и настроит веб-панель.

---

## 2. Ручная установка

Если вы хотите развернуть систему вручную, выполните следующие шаги:

### Шаг 1. Установка пакетов Entware
```bash
opkg update
opkg install lighttpd php8-cgi php8-mod-openssl php8-mod-session procps-ng-pgrep procps-ng-pkill curl ca-bundle ca-certificates cron
```

### Шаг 2. Развертывание бинарника
Скачайте бинарник для вашей архитектуры процессора (посмотрите через `uname -m`) из [Релизов GitHub](https://github.com/AntikFull/fptn-keenetic/releases) и положите его по пути `/opt/bin/fptn-client-cli`:
```bash
# Пример для aarch64
curl -L -o /opt/bin/fptn-client-cli https://github.com/AntikFull/fptn-keenetic/releases/download/v1.0.5-keenetic/fptn-client-cli-aarch64
chmod +x /opt/bin/fptn-client-cli
```

### Шаг 3. Регистрация TUN-интерфейса в KeeneticOS
Выполните команды в CLI Keenetic (`ndmc`):
```bash
# Создаем интерфейс OpkgTun1 (или другое имя, например OpkgTun2)
interface OpkgTun1 type OpkgTun
interface OpkgTun1 description Fptn
interface OpkgTun1 security-level public
interface OpkgTun1 ip address 10.0.0.1 255.255.255.255
interface OpkgTun1 ip global 50000
interface OpkgTun1 ip tcp adjust-mss pmtu
interface OpkgTun1 up
system configuration save
```

### Шаг 4. Настройка веб-сервера Lighttpd
Создайте файл `/opt/etc/lighttpd/conf.d/85-fptn.conf`:
```lighttpd
# Настройки веб-интерфейса FPTN
server.modules += ( "mod_cgi" )
$HTTP["url"] =~ "^/fptn/" {
    cgi.assign = ( ".php" => "/opt/bin/php-cgi" )
    static-file.exclude-extensions += ( ".php" )
}
```
Перезапустите Lighttpd: `/opt/etc/init.d/S80lighttpd restart`.

### Шаг 5. Файлы панели и службы
1. Создайте папку `/opt/share/www/fptn/` и скопируйте туда файл [index.php](index.php).
2. Создайте файл конфигурации службы `/opt/etc/fptn-client.conf`:
   ```ini
   ENABLED="no"
   TOKEN="ВАШ_ТОКЕН_ПОДПИСКИ"
   PREFERRED_SERVER=""
   TUN_INTERFACE="opkgtun1"
   ```
3. Скопируйте скрипт инициализации [S53fptn-client](S53fptn-client) в `/opt/etc/init.d/S53fptn-client` и сделайте исполняемым: `chmod +x /opt/etc/init.d/S53fptn-client`.

---

## 3. Настройка маршрутизации в KeeneticOS

Служба FPTN запускается с флагом `--disable-routing`, предоставляя KeeneticOS полное управление политиками маршрутизации.

### Вариант 1. Через Web-интерфейс Keenetic (Политики маршрутизации)
1. Перейдите в раздел **«Сетевые правила» -> «Приоритеты подключений»**.
2. Вы увидите новое подключение **Fptn**.
3. Перетащите подключение **Fptn** в нужную политику (например, в «Основную» или «VPN»).
4. Все устройства в этой политике будут направлять свой трафик через туннель FPTN.

### Вариант 2. Выборочная маршрутизация доменов (DNS-маршруты)
1. Перейдите в раздел **«Сетевые правила» -> «Маршрутизация»** (или «DNS-маршруты»).
2. Создайте новый DNS-маршрут для нужного доменного списка и выберите интерфейс **Fptn** в качестве шлюза.

---

## 4. Сброс пароля веб-панели

Если вы забыли пароль доступа к веб-панели `/fptn/`:
1. Подключитесь к роутеру по SSH.
2. Откройте `/opt/etc/fptn-client.conf` и удалите строку `WEB_PASSWORD`:
   ```bash
   sed -i '/WEB_PASSWORD/d' /opt/etc/fptn-client.conf
   ```
3. Обновите страницу панели в браузере.

---
---

# 🇬🇧 FPTN Client for Keenetic Routers (Entware)

Integration of the routed FPTN VPN client into Keenetic routers running the Entware environment. Features an interactive web management panel and automatic tunnel registration in KeeneticOS.

---

## 1. Quick Installation (Recommended)

Run the interactive installer on your router via SSH.

### 🚀 Standard Installation:
Download the installer script to a temporary file and run it:
```bash
curl -fsSL -o /tmp/install.sh https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/install.sh && sh /tmp/install.sh
```

### ⚡ Mirror for Restricted Networks / CDN:
If your connection blocks GitHub raw content, use the fast CDN mirror:
```bash
curl -fsSL -o /tmp/install.sh https://cdn.jsdelivr.net/gh/AntikFull/fptn-keenetic@master/deploy/keenetic/install.sh && sh /tmp/install.sh
```
*(Or via `wget` if curl is missing:)*
```bash
wget -O /tmp/install.sh https://cdn.jsdelivr.net/gh/AntikFull/fptn-keenetic@master/deploy/keenetic/install.sh && sh /tmp/install.sh
```

The installer automatically installs required packages, downloads the binary matching your CPU architecture (`aarch64`, `armv7`, `mipsel`), configures the TUN interface in KeeneticOS, and sets up the web panel.

---

## 2. Manual Installation

If you prefer a manual setup, follow these steps:

### Step 1. Install Entware Packages
```bash
opkg update
opkg install lighttpd php8-cgi php8-mod-openssl php8-mod-session procps-ng-pgrep procps-ng-pkill curl ca-bundle ca-certificates cron
```

### Step 2. Download Binary
Fetch the binary corresponding to your router's architecture (`uname -m`) from [GitHub Releases](https://github.com/AntikFull/fptn-keenetic/releases) and place it at `/opt/bin/fptn-client-cli`:
```bash
# Example for aarch64
curl -L -o /opt/bin/fptn-client-cli https://github.com/AntikFull/fptn-keenetic/releases/download/v1.0.5-keenetic/fptn-client-cli-aarch64
chmod +x /opt/bin/fptn-client-cli
```

### Step 3. Register TUN Interface in KeeneticOS
Run the following commands in Keenetic CLI (`ndmc`):
```bash
interface OpkgTun1 type OpkgTun
interface OpkgTun1 description Fptn
interface OpkgTun1 security-level public
interface OpkgTun1 ip address 10.0.0.1 255.255.255.255
interface OpkgTun1 ip global 50000
interface OpkgTun1 ip tcp adjust-mss pmtu
interface OpkgTun1 up
system configuration save
```

### Step 4. Configure Lighttpd Web Server
Create `/opt/etc/lighttpd/conf.d/85-fptn.conf`:
```lighttpd
server.modules += ( "mod_cgi" )
$HTTP["url"] =~ "^/fptn/" {
    cgi.assign = ( ".php" => "/opt/bin/php-cgi" )
    static-file.exclude-extensions += ( ".php" )
}
```
Restart Lighttpd: `/opt/etc/init.d/S80lighttpd restart`.

### Step 5. Web Panel and Service Configuration
1. Create directory `/opt/share/www/fptn/` and copy [index.php](index.php) into it.
2. Create `/opt/etc/fptn-client.conf`:
   ```ini
   ENABLED="no"
   TOKEN="YOUR_SUBSCRIPTION_TOKEN"
   PREFERRED_SERVER=""
   TUN_INTERFACE="opkgtun1"
   ```
3. Copy init script [S53fptn-client](S53fptn-client) to `/opt/etc/init.d/S53fptn-client` and make it executable: `chmod +x /opt/etc/init.d/S53fptn-client`.

---

## 3. Routing Configuration in KeeneticOS

The FPTN client runs with `--disable-routing`, delegating full routing policy control to KeeneticOS.

### Option 1. Keenetic Web Interface (Connection Priorities)
1. Navigate to **Network Rules -> Connection Priorities** in KeeneticOS Web UI.
2. Locate the new **Fptn** connection interface.
3. Drag **Fptn** into your desired policy (e.g. Main policy or a custom VPN policy).
4. All client devices assigned to this policy will route traffic through FPTN.

### Option 2. Selective Domain Routing (DNS Routes)
1. Go to **Network Rules -> Routing** (or **DNS Routes**).
2. Create a new DNS route for target domain lists and select **Fptn** as the gateway.

---

## 4. Resetting Web Panel Password

If you forget your `/fptn/` web panel password:
1. Connect to the router via SSH.
2. Remove the `WEB_PASSWORD` line in `/opt/etc/fptn-client.conf`:
   ```bash
   sed -i '/WEB_PASSWORD/d' /opt/etc/fptn-client.conf
   ```
3. Refresh the web panel page in your browser to set a new password.
