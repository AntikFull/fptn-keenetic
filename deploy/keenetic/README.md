# Клиент FPTN для роутеров Keenetic (Entware)

Интеграция маршрутизируемого VPN-клиента FPTN на роутеры Keenetic с установленной средой Entware. Включает в себя веб-панель управления и автоматическое заведение туннеля в систему KeeneticOS.

---

## 1. Быстрая установка (Рекомендуемая)

Запустите интерактивный установщик на роутере по SSH.

### 🚀 Стандартная установка:
Скачайте скрипт установщика во временный файл и запустите его (это необходимо для корректной работы ввода в терминале):
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
opkg install lighttpd php8-cgi php8-mod-openssl curl ca-bundle ca-certificates
```

### Шаг 2. Развертывание бинарника
Скачайте бинарник для вашей архитектуры процессора (посмотрите через `uname -m`) из [Релизов GitHub](https://github.com/AntikFull/fptn-keenetic/releases/tag/v1.0.1-keenetic) и положите его по пути `/opt/bin/fptn-client-cli`:
```bash
# Пример для aarch64
curl -L -o /opt/bin/fptn-client-cli https://github.com/AntikFull/fptn-keenetic/releases/download/v1.0.1-keenetic/fptn-client-cli-aarch64
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
# Перенаправляем путь /fptn/ на php-cgi
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

Поскольку служба FPTN запускается с флагом `--disable-routing`, она не вмешивается в системные маршруты Linux напрямую, позволяя операционной системе роутера KeeneticOS управлять маршрутизацией через стандартные политики.

### Вариант 1. Через Web-интерфейс Keenetic (Политики маршрутизации)
1. Перейдите в раздел **«Сетевые правила» -> «Приоритеты подключений»** (или «Политики маршрутизации» в зависимости от версии KeeneticOS).
2. Вы увидите новое подключение **Fptn** в списке «Приоритеты подключений».
3. Перетащите подключение **Fptn** в нужную политику (например, в «Основную» или создайте отдельную политику «VPN»).
4. Все устройства, добавленные в эту политику, будут направлять свой трафик через туннель FPTN.

### Вариант 2. Выборочная маршрутизация доменов (DNS-маршруты)
Вы можете направлять только определенные домены (например, заблокированные ресурсы) через FPTN.

**Через Web-панель Keenetic:**
1. Перейдите в раздел **«Сетевые правила» -> «Маршрутизация»** (или «Интернет-фильтр» -> «Настройка DNS-маршрутов» в KeeneticOS 4.x/5.x).
2. Создайте новый DNS-маршрут для нужного доменного списка и выберите интерфейс **Fptn** в качестве шлюза.

**Через CLI роутера по SSH:**
```bash
# Шаг 1. Посмотреть существующие доменные группы (object-groups)
# ndmc -c 'show running-config'

# Шаг 2. Направить группу доменов (например, domain-list8 / 4pda) через интерфейс OpkgTun1
ndmc -c 'ip dns-route object-group domain-list8 OpkgTun1'

# Шаг 3. Сохранить конфигурацию
ndmc -c 'system configuration save'
```
