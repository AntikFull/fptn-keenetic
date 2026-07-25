<?php
// Веб-панель управления клиентом FPTN на Keenetic (Entware)
// Автор: Antigravity
// Исправлено: добавлены авторизация, защита от CSRF, маскирование токена и автообновление

session_name('FPTN_SESS');
session_start();
header('Content-Type: text/html; charset=utf-8');

define('CURRENT_VERSION', 'v1.1.15-keenetic');

putenv("PATH=/opt/sbin:/opt/bin:/opt/usr/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin");

$conf_file = "/opt/etc/fptn-client.conf";
$servers_file = "/opt/etc/fptn-servers.json";
$cli_path = "/opt/bin/fptn-client-cli";
$init_script = "/opt/etc/init.d/S53fptn-client";

// Конфигурация Сервера FPTN
$server_conf_file = "/opt/etc/fptn-server.conf";
$server_users_file = "/opt/etc/fptn-server/users.list";
$server_crt_file = "/opt/etc/fptn-server/server.crt";
$server_init_script = "/opt/etc/init.d/S54fptn-server";

// Инициализация дефолтных значений конфигурации клиента
$config = [
    'ENABLED' => 'no',
    'TOKEN' => '',
    'PREFERRED_SERVER' => '',
    'TUN_INTERFACE' => 'opkgtun1',
    'WATCHDOG' => 'yes',
    'WEB_PASSWORD' => ''
];

// Инициализация дефолтных значений конфигурации сервера
$server_config = [
    'SERVER_ENABLED' => 'no',
    'SERVER_PORT' => '8443',
    'SERVER_SNI' => 'rutube.ru',
    'DISABLE_BITTORRENT' => 'no',
    'TUN_INTERFACE' => 'opkgsrv1',
    'SERVER_HOST' => ''
];

function read_server_config() {
    global $server_conf_file, $server_config;
    if (file_exists($server_conf_file)) {
        $lines = file($server_conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            $line_clean = preg_replace('/^export\s+/', '', trim($line));
            $parts = explode('=', $line_clean, 2);
            if (count($parts) == 2) {
                $key = trim($parts[0]);
                $val = trim($parts[1], " \t\n\r\0\x0B\"'");
                if (array_key_exists($key, $server_config)) {
                    $server_config[$key] = $val;
                }
            }
        }
    }
}

function write_server_config() {
    global $server_conf_file, $server_config;
    $content = "# Конфигурация сервера FPTN (Создано автоматически / Auto-generated)\n";
    foreach ($server_config as $k => $v) {
        $content .= "{$k}=\"{$v}\"\n";
    }
    return file_put_contents($server_conf_file, $content, LOCK_EX) !== false;
}

function get_server_md5_fingerprint() {
    global $server_crt_file;
    if (file_exists($server_crt_file)) {
        $cmd = "openssl x509 -in " . escapeshellarg($server_crt_file) . " -noout -fingerprint -md5 2>/dev/null";
        $out = shell_exec($cmd);
        if ($out && preg_match('/MD5 Fingerprint=(.+)/i', trim($out), $m)) {
            return str_replace(':', '', trim($m[1]));
        }
    }
    return '';
}

function get_keendns_domain() {
    $out = shell_exec('ndmc -c "show ip name" 2>/dev/null');
    if ($out && preg_match('/domain:\s*([a-z0-9\.\-]+)/i', $out, $m)) {
        return trim($m[1]);
    }
    return '';
}

function get_server_users() {
    global $server_users_file;
    $users = [];
    if (file_exists($server_users_file)) {
        $lines = file($server_users_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 3) {
                $users[] = [
                    'username' => $parts[0],
                    'hash' => $parts[1],
                    'bandwidth' => (int)$parts[2]
                ];
            }
        }
    }
    return $users;
}

function add_server_user($username, $password, $bandwidth = 100) {
    global $server_users_file;
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) return false;
    $dir = dirname($server_users_file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    
    $users = get_server_users();
    foreach ($users as $u) {
        if ($u['username'] === $username) return false;
    }
    
    $hash = hash('sha256', $password);
    $line = "{$username} {$hash} {$bandwidth}\n";
    return file_put_contents($server_users_file, $line, FILE_APPEND | LOCK_EX) !== false;
}

function delete_server_user($username) {
    global $server_users_file;
    $users = get_server_users();
    $new_lines = [];
    foreach ($users as $u) {
        if ($u['username'] !== $username) {
            $new_lines[] = "{$u['username']} {$u['hash']} {$u['bandwidth']}";
        }
    }
    return file_put_contents($server_users_file, implode("\n", $new_lines) . (empty($new_lines) ? '' : "\n"), LOCK_EX) !== false;
}

function generate_user_token($host, $port, $username, $password, $fingerprint = '') {
    $token_data = [
        'host' => $host,
        'port' => (int)$port,
        'user' => $username,
        'password' => $password,
        'md5_fingerprint' => $fingerprint
    ];
    return 'fptnb:' . base64_encode(json_encode($token_data));
}

// Вспомогательная функция для HTTP запросов с каскадным фоллбэком (cURL -> CLI curl -> stream_context)
function http_get_contents($url, $timeout = 10) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Keenetic FPTN Client)');
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data !== false && $code >= 200 && $code < 400) {
            return ['code' => (int)$code, 'data' => $data];
        }
    }
    
    $cmd = "curl -sL -k --connect-timeout " . (int)$timeout . " --max-time " . (int)$timeout . " " . escapeshellarg($url) . " 2>/dev/null";
    $data = shell_exec($cmd);
    if ($data !== null && $data !== false && strlen($data) > 0) {
        return ['code' => 200, 'data' => $data];
    }
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header' => "User-Agent: Mozilla/5.0 (Keenetic FPTN Client)\r\n",
            'follow_location' => 1
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data !== false) {
        return ['code' => 200, 'data' => $data];
    }
    
    return ['code' => 0, 'data' => ''];
}

$version_file = "/opt/etc/fptn-version";

function get_installed_version() {
    global $version_file;
    if (file_exists($version_file)) {
        $v = trim(@file_get_contents($version_file));
        if (!empty($v)) return $v;
    }
    return CURRENT_VERSION;
}

// Прямое скачивание бинарников на диск во избежание исчерпания памяти php-cgi (memory_limit)
function download_file_to_disk($url, $dest_path, $timeout = 180) {
    @unlink($dest_path);
    $cmd = "curl -sL -k --connect-timeout 15 --max-time " . (int)$timeout . " -o " . escapeshellarg($dest_path) . " " . escapeshellarg($url) . " 2>/dev/null";
    exec($cmd);
    if (file_exists($dest_path) && filesize($dest_path) > 1000000) {
        return true;
    }
    $mirror_url = "https://ghproxy.net/" . $url;
    $cmd_mirror = "curl -sL -k --connect-timeout 15 --max-time " . (int)$timeout . " -o " . escapeshellarg($dest_path) . " " . escapeshellarg($mirror_url) . " 2>/dev/null";
    exec($cmd_mirror);
    return file_exists($dest_path) && filesize($dest_path) > 1000000;
}

// Функция чтения конфигурации
function read_config() {
    global $conf_file, $config;
    if (file_exists($conf_file)) {
        $lines = file($conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            $line_clean = preg_replace('/^export\s+/', '', trim($line));
            $parts = explode('=', $line_clean, 2);
            if (count($parts) == 2) {
                $key = trim($parts[0]);
                $val = trim($parts[1], " \t\n\r\0\x0B\"'");
                if (array_key_exists($key, $config)) {
                    $config[$key] = $val;
                }
            }
        }
    }
}

read_config();
$password_set = !empty($config['WEB_PASSWORD']);
$authenticated = !$password_set || (isset($_SESSION['auth']) && $_SESSION['auth'] === true);

// Обработка AJAX запросов (проверка обновлений, автообновление статуса и запуск обновления)
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if (!$authenticated) {
        echo json_encode(['success' => false, 'message' => 'Сессия истекла. Пожалуйста, войдите в систему заново.', 'auth_required' => true]);
        exit;
    }
    $ajax_action = $_GET['ajax'];
    
    if ($ajax_action === 'get_status') {
        read_config();
        $service_running = false;
        $pid = null;
        if (file_exists($cli_path)) {
            exec("pgrep -x fptn-client-cli || pgrep -f /opt/bin/fptn-client-cli", $pids);
            $pids = array_filter(array_map('trim', $pids), 'is_numeric');
            if (!empty($pids) && ($config['ENABLED'] ?? 'no') === 'yes') {
                $service_running = true;
                $pid = implode(", ", $pids);
            }
        }
        $interface_status = 'Не активен';
        $interface_ip = '';
        exec("ip addr show " . escapeshellarg($config['TUN_INTERFACE']) . " 2>/dev/null", $ip_output, $ip_status);
        if ($ip_status === 0 && !empty($ip_output)) {
            $interface_status = 'Активен';
            foreach ($ip_output as $line) {
                if (preg_match('/inet\s+([0-9\.]+)/', $line, $matches)) {
                    $interface_ip = $matches[1];
                    break;
                }
            }
        }
        echo json_encode([
            'success' => true,
            'service_running' => $service_running,
            'pid' => $pid ? $pid : '—',
            'interface_name' => $config['TUN_INTERFACE'],
            'interface_status' => $interface_status,
            'interface_ip' => $interface_ip ? $interface_ip : '—'
        ]);
        exit;
    }
    
    if ($ajax_action === 'check_ping') {
        $host = trim($_GET['host'] ?? '');
        $port = (int)($_GET['port'] ?? 443);
        if (empty($host)) {
            echo json_encode(['success' => false, 'message' => 'Пустой хост']);
            exit;
        }
        $pings = [];
        for ($i = 0; $i < 3; $i++) {
            $start = microtime(true);
            $fp = @fsockopen($host, $port, $errno, $errstr, 0.8);
            if ($fp) {
                $end = microtime(true);
                fclose($fp);
                $pings[] = round(($end - $start) * 1000);
            } else {
                $pings[] = -1;
            }
        }
        $valid = array_filter($pings, function($p) { return $p >= 0; });
        $avg_ping = (!empty($valid) && count($valid) === count($pings)) ? round(array_sum($valid) / count($valid)) : -1;
        echo json_encode([
            'success' => true,
            'host' => $host,
            'port' => $port,
            'ping_ms' => $avg_ping,
            'online' => ($avg_ping >= 0)
        ]);
        exit;
    }

    if ($ajax_action === 'check_update') {
        $github_raw_url = 'https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/version.txt';
        $res = http_get_contents($github_raw_url, 4);
        if ($res['code'] !== 200) {
            $github_raw_url = 'https://ghproxy.net/https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/version.txt';
            $res = http_get_contents($github_raw_url, 4);
        }
        
        if ($res['code'] === 200 && !empty($res['data'])) {
            $remote_version = trim($res['data']);
            $current_ver = get_installed_version();
            // Сравниваем версии без префикса 'v' и суффикса '-keenetic'
            $v_remote = preg_replace('/[^0-9\.]/', '', $remote_version);
            $v_current = preg_replace('/[^0-9\.]/', '', $current_ver);
            $has_update = (version_compare($v_remote, $v_current) > 0);
            
            echo json_encode([
                'success' => true,
                'current_version' => $current_ver,
                'remote_version' => $remote_version,
                'has_update' => $has_update
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Не удалось получить данные о версии с GitHub (HTTP ' . $res['code'] . ').'
            ]);
        }
        exit;
    }
    
    if ($ajax_action === 'install_update') {
        // Проверка CSRF
        if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['success' => false, 'message' => 'Ошибка безопасности: неверный CSRF токен.']);
            exit;
        }
        
        // Определение архитектуры роутера
        $arch = trim(shell_exec('uname -m'));
        switch ($arch) {
            case 'aarch64':
                $arch_suffix = 'aarch64';
                break;
            case 'armv7l':
            case 'armv7':
                $arch_suffix = 'armv7';
                break;
            case 'mips':
            case 'mipsel':
                $arch_suffix = 'mipsel';
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Неподдерживаемая архитектура процессора: ' . $arch]);
                exit;
        }
        
        // Получение актуального тега версии с GitHub
        $github_raw_url = 'https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/version.txt';
        $use_mirrors = false;
        
        $res_ver = http_get_contents($github_raw_url, 4);
        if ($res_ver['code'] !== 200) {
            // Переключаемся на зеркало ghproxy.net
            $use_mirrors = true;
            $github_raw_url = 'https://ghproxy.net/https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/version.txt';
            $res_ver = http_get_contents($github_raw_url, 4);
        }
        
        $remote_version = ($res_ver['code'] === 200) ? trim($res_ver['data']) : '';
        if (empty($remote_version)) {
            echo json_encode(['success' => false, 'message' => 'Не удалось определить версию релиза для скачивания бинарников (даже через зеркало).']);
            exit;
        }
        
        $bin_url = "https://github.com/AntikFull/fptn-keenetic/releases/download/{$remote_version}/fptn-client-cli-{$arch_suffix}";
        $php_url = "https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/index.php";
        
        $tmp_bin = "/tmp/fptn-client-cli.tmp";
        $tmp_php = "/tmp/index.php.tmp";
        
        // 1. Прямое скачивание бинарника на диск (без потребления OOM памяти PHP)
        if (!download_file_to_disk($bin_url, $tmp_bin, 180)) {
            echo json_encode(['success' => false, 'message' => "Не удалось скачать бинарный файл версии {$remote_version}."]);
            exit;
        }
        chmod($tmp_bin, 0755);
        
        // 2. Скачивание PHP панели
        $res_php = http_get_contents($php_url, 20);
        if ($res_php['code'] !== 200 || empty($res_php['data'])) {
            @unlink($tmp_bin);
            echo json_encode(['success' => false, 'message' => "Не удалось скачать новую веб-панель index.php: HTTP {$res_php['code']}"]);
            exit;
        }
        file_put_contents($tmp_php, $res_php['data']);

        // 3. Обновление скрипта службы S53fptn-client
        $init_url = "https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/S53fptn-client";
        $res_init = http_get_contents($init_url, 15);
        if ($res_init['code'] === 200 && !empty($res_init['data'])) {
            file_put_contents("/opt/etc/init.d/S53fptn-client", $res_init['data']);
            chmod("/opt/etc/init.d/S53fptn-client", 0755);
        }
        
        // 1. Останавливаем службу
        exec("{$init_script} stop 2>&1");
        
        // 2. Перемещаем бинарник
        if (!rename($tmp_bin, $cli_path)) {
            @unlink($tmp_bin);
            @unlink($tmp_php);
            echo json_encode(['success' => false, 'message' => 'Не удалось перезаписать файл клиента /opt/bin/fptn-client-cli. Проверьте права доступа.']);
            exit;
        }
        chmod($cli_path, 0755);
        
        // 3. Перечитываем конфигурацию и запускаем службу, если она должна быть включена
        // Читаем вручную конфигурационный файл
        $enabled = 'no';
        if (file_exists($conf_file)) {
            $lines = file($conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (preg_match('/^ENABLED="?(yes|no)"?/', trim($line), $m)) {
                    $enabled = $m[1];
                }
            }
        }
        if ($enabled === 'yes') {
            exec("{$init_script} start 2>&1");
        }
        
        // 4. Сохраняем информацию об установленной версии
        @file_put_contents($version_file, $remote_version);
        
        // 5. Заменяем саму себя в последнюю очередь
        rename($tmp_php, "/opt/share/www/fptn/index.php");
        
        echo json_encode(['success' => true, 'message' => 'Обновление успешно установлено!']);
        exit;
    }
}

// Функция записи конфигурации (используем одинарные кавычки для предотвращения раскрытия $ в shell)
function write_config() {
    global $conf_file, $config;
    $content = "# Конфигурация клиента FPTN (Создано автоматически)\n";
    foreach ($config as $k => $v) {
        $escaped_v = str_replace("'", "'\\''", $v);
        $content .= "{$k}='{$escaped_v}'\n";
    }
    return file_put_contents($conf_file, $content) !== false;
}

read_config();

// Проверка статуса службы
$service_running = false;
$pid = null;
if (file_exists($cli_path)) {
    exec("pgrep -x fptn-client-cli || pgrep -f /opt/bin/fptn-client-cli", $pids);
    $pids = array_filter(array_map('trim', $pids), 'is_numeric');
    if (!empty($pids)) {
        $service_running = true;
        $pid = implode(", ", $pids);
    }
}

$message = '';
$error = '';

// Генерация CSRF-токена, если он не задан
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Флаги состояния авторизации уже вычислены выше для корректной работы AJAX

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        // 1. Установка нового пароля (если он еще не задан)
        if ($action === 'setup_password' && !$password_set) {
            $new_pass = $_POST['password'] ?? '';
            $confirm_pass = $_POST['confirm_password'] ?? '';
            if (strlen($new_pass) < 6) {
                $error = 'Пароль должен быть не менее 6 символов';
            } elseif ($new_pass !== $confirm_pass) {
                $error = 'Пароли не совпадают';
            } else {
                $config['WEB_PASSWORD'] = password_hash($new_pass, PASSWORD_BCRYPT);
                if (write_config()) {
                    $_SESSION['auth'] = true;
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = 'Не удалось записать пароль в конфигурацию.';
                }
            }
        }

        // 2. Вход (если пароль задан)
        elseif ($action === 'login' && $password_set) {
            $pass = $_POST['password'] ?? '';
            if (password_verify($pass, $config['WEB_PASSWORD'])) {
                $_SESSION['auth'] = true;
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $error = 'Неверный пароль';
            }
        }

        // 3. Выход из панели
        elseif ($action === 'logout') {
            unset($_SESSION['auth']);
            session_destroy();
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        // 4. Действия, требующие авторизации и CSRF-валидации
        elseif ($authenticated) {
            // Валидация CSRF-токена
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                $error = 'Ошибка безопасности: неверный CSRF токен.';
            } else {
                if ($action === 'save_token') {
                    $token = trim($_POST['token']);
                    if (empty($token)) {
                        $error = 'Токен не может быть пустым';
                    } else {
                        if (!file_exists($cli_path)) {
                            $error = 'Клиент fptn-client-cli не найден в /opt/bin/. Сначала соберите и загрузите его.';
                        } else {
                            $cmd = $cli_path . " --access-token " . escapeshellarg($token) . " --show-servers 2>&1";
                            exec($cmd, $output, $return_var);
                            
                            if ($return_var === 0) {
                                $json_str = implode("\n", $output);
                                $json_start = strpos($json_str, '{');
                                $json_end = strrpos($json_str, '}');
                                if ($json_start !== false && $json_end !== false && $json_end > $json_start) {
                                    $json_str = substr($json_str, $json_start, $json_end - $json_start + 1);
                                }
                                $parsed = json_decode($json_str, true);
                                if ($parsed && isset($parsed['servers'])) {
                                    file_put_contents($servers_file, $json_str);
                                    $config['TOKEN'] = $token;
                                    write_config();
                                    $message = 'Токен успешно сохранен и проверен! Служба: ' . htmlspecialchars($parsed['service_name']);
                                    
                                    if ($service_running) {
                                        $cmd = $init_script . " restart 2>&1";
                                        exec($cmd, $restart_output, $restart_return);
                                        $message .= ' Служба автоматически перезапущена.';
                                    }
                                } else {
                                    $error = 'Не удалось распарсить список серверов из ответа клиента.';
                                }
                            } else {
                                $error = 'Ошибка проверки токена: ' . htmlspecialchars(implode(" ", $output));
                            }
                        }
                    }
                }
                
                elseif ($action === 'save_server') {
                    $servers_input = $_POST['servers'] ?? [];
                    if (is_array($servers_input)) {
                        $clean_servers = array_filter(array_map('trim', $servers_input));
                        $server = implode(',', $clean_servers);
                    } else {
                        $server = trim($_POST['server'] ?? '');
                    }
                    $fallback = isset($_POST['fallback_to_auto']) && $_POST['fallback_to_auto'] === '1' ? 'yes' : 'no';
                    $config['PREFERRED_SERVER'] = $server;
                    $config['FALLBACK_TO_AUTO'] = $fallback;
                    if (write_config()) {
                        $message = 'Список серверов по приоритету обновлен: ' . ($server ? htmlspecialchars($server) : 'Автовыбор') . ' (Автовозврат: ' . ($fallback === 'yes' ? 'Включен' : 'Выключен') . ')';
                        
                        if ($service_running) {
                            $cmd = $init_script . " restart 2>&1";
                            exec($cmd, $restart_output, $restart_return);
                            $message .= ' Служба автоматически перезапущена.';
                        }
                    } else {
                        $error = 'Не удалось записать конфигурацию.';
                    }
                }
                
                elseif ($action === 'save_watchdog') {
                    $watchdog = isset($_POST['watchdog']) && $_POST['watchdog'] === '1' ? 'yes' : 'no';
                    $config['WATCHDOG'] = $watchdog;
                    if (write_config()) {
                        $message = 'Настройка автопинга (Watchdog) обновлена. Статус: ' . ($watchdog === 'yes' ? 'Включен' : 'Выключен');
                    } else {
                        $error = 'Не удалось записать конфигурацию.';
                    }
                }
                
                elseif (in_array($action, ['start', 'stop', 'restart'])) {
                    if (!file_exists($init_script)) {
                        $error = 'Скрипт управления службой /opt/etc/init.d/S53fptn-client не найден.';
                    } else {
                        if ($action === 'start') {
                            $config['ENABLED'] = 'yes';
                            write_config();
                        } elseif ($action === 'stop') {
                            $config['ENABLED'] = 'no';
                            write_config();
                            exec("killall -9 fptn-client-cli 2>/dev/null; pkill -9 -f /opt/bin/fptn-client-cli 2>/dev/null");
                        }
                        
                        $cmd = $init_script . " " . $action . " 2>&1";
                        exec($cmd, $output, $return_var);
                        $raw_out = implode(" ", $output);
                        $clean_out = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $raw_out);
                        $message = 'Выполнено действие: ' . htmlspecialchars($action) . '. ' . htmlspecialchars(trim($clean_out));
                    }
                }
            }
        }
    }
    // Перечитываем конфигурацию и статус
    read_config();
    $service_running = false;
    $pid = null;
    if (file_exists($cli_path)) {
        exec("pgrep -x fptn-client-cli || pgrep -f /opt/bin/fptn-client-cli", $pids);
        $pids = array_filter(array_map('trim', $pids), 'is_numeric');
        if (!empty($pids) && ($config['ENABLED'] ?? 'no') === 'yes') {
            $service_running = true;
            $pid = implode(", ", $pids);
        }
    }
}

// Проверка существования интерфейса
$interface_status = 'Не активен';
$interface_ip = '';
exec("ip addr show " . escapeshellarg($config['TUN_INTERFACE']) . " 2>/dev/null", $ip_output, $ip_status);
if ($ip_status === 0 && !empty($ip_output)) {
    $interface_status = 'Активен';
    foreach ($ip_output as $line) {
        if (preg_match('/inet\s+([0-9\.]+)/', $line, $matches)) {
            $interface_ip = $matches[1];
            break;
        }
    }
}

// Чтение серверов
$servers_data = [];
$service_title = '—';
if (!empty($config['TOKEN'])) {
    if (file_exists($servers_file)) {
        $json_data = json_decode(file_get_contents($servers_file), true);
        if ($json_data) {
            $servers_data = $json_data['servers'] ?? [];
            if (isset($json_data['service_name'])) {
                $service_title = htmlspecialchars($json_data['service_name']);
            }
        }
    }
} else {
    $service_title = '<span style="color: #ef4444;">Токен не задан</span>';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление клиентом FPTN</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: #161f30;
            --border-color: #24354f;
            --text-color: #f3f4f6;
            --text-muted: #9ca3af;
            --accent-blue: #3b82f6;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --accent-yellow: #f59e0b;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
            padding: 40px 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 20px;
        }
        
        h1 {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 14px;
            gap: 8px;
        }
        
        .status-badge.active {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--accent-green);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .status-badge.inactive {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--accent-red);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: currentColor;
            display: inline-block;
        }
        
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 30px;
            font-size: 15px;
            animation: fadeIn 0.3s ease;
        }
        
        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--accent-green);
            color: #d1fae5;
        }
        
        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--accent-red);
            color: #fee2e2;
        }
        
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 640px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
        
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .card h2 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(36, 53, 79, 0.5);
            padding-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 15px;
        }
        
        .info-label {
            color: var(--text-muted);
        }
        
        .info-value {
            font-weight: 500;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 500;
        }
        
        input[type="text"], input[type="password"], select {
            width: 100%;
            background-color: var(--bg-color);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px 16px;
            color: var(--text-color);
            font-family: inherit;
            font-size: 14px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        
        input[type="text"]:focus, input[type="password"]:focus, select:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary {
            background-color: var(--accent-blue);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2563eb;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background-color: var(--accent-green);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #059669;
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background-color: var(--accent-red);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #dc2626;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background-color: #374151;
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background-color: #4b5563;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .btn-full {
            width: 100%;
        }

        .auth-container {
            max-width: 420px;
            margin: 100px auto 0;
        }

        .toggle-btn {
            position: absolute;
            right: 12px;
            top: 38px;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 14px;
        }

        .toggle-btn:hover {
            color: var(--text-color);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        /* Стили для тумблера Watchdog */
        .switch input:checked + .slider {
            background-color: var(--accent-blue);
        }
        .switch input:checked + .slider:before {
            transform: translateX(20px);
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
    </style>
    <script>
        function toggleVisibility(inputId, btnId) {
            const input = document.getElementById(inputId);
            const btn = document.getElementById(btnId);
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = '🔒 Скрыть';
            } else {
                input.type = 'password';
                btn.textContent = '👁️ Показать';
            }
        }

        async function checkUpdates() {
            const btn = document.getElementById('check-update-btn');
            const statusBlock = document.getElementById('update-status-block');
            btn.disabled = true;
            btn.textContent = '⌛ Проверка...';
            statusBlock.style.display = 'none';
            
            try {
                const response = await fetch('?ajax=check_update');
                const data = await response.json();
                if (data.success) {
                    if (data.has_update) {
                        statusBlock.innerHTML = `
                            <div style="color: var(--accent-yellow); font-weight: 600; margin-bottom: 6px;">Доступно обновление: ${data.remote_version}</div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 10px;">Текущая версия: ${data.current_version}</div>
                            <button type="button" class="btn btn-success btn-sm btn-full" onclick="installUpdate()">🚀 Установить обновление</button>
                        `;
                    } else {
                        statusBlock.innerHTML = `
                            <div style="color: var(--accent-green); font-weight: 500;">У вас последняя версия!</div>
                        `;
                    }
                    statusBlock.style.display = 'block';
                } else {
                    alert(data.message || 'Ошибка проверки обновлений');
                }
            } catch (e) {
                alert('Сбой сети при проверке обновлений');
            } finally {
                btn.disabled = false;
                btn.textContent = '🔄 Проверить';
            }
        }

        async function installUpdate() {
            if (!confirm('Вы уверены, что хотите обновить клиент и панель? Служба VPN будет временно перезапущена.')) {
                return;
            }
            const statusBlock = document.getElementById('update-status-block');
            statusBlock.innerHTML = `
                <div style="color: var(--accent-blue); font-weight: 600; text-align: center;">
                    <span style="display: inline-block; animation: spin 1s linear infinite; margin-right: 8px;">⏳</span>
                    Установка обновления... Пожалуйста, не закрывайте вкладку.
                </div>
            `;
            
            try {
                const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
                const response = await fetch('?ajax=install_update&csrf_token=' + encodeURIComponent(csrfToken));
                const data = await response.json();
                if (data.success) {
                    statusBlock.innerHTML = `
                        <div style="color: var(--accent-green); font-weight: 600; text-align: center; margin-bottom: 8px;">🎉 ${data.message}</div>
                    `;
                    setTimeout(() => {
                        window.location.reload();
                    }, 2500);
                } else {
                    statusBlock.innerHTML = `
                        <div style="color: var(--accent-red); font-weight: 600; margin-bottom: 8px;">❌ Ошибка:</div>
                        <div style="font-size: 12px;">${data.message}</div>
                        <button type="button" class="btn btn-secondary btn-sm btn-full" style="margin-top: 10px;" onclick="checkUpdates()">Попробовать снова</button>
                    `;
                }
            } catch (e) {
                statusBlock.innerHTML = `
                    <div style="color: var(--accent-red); font-weight: 600; margin-bottom: 8px;">❌ Ошибка соединения:</div>
                    <div style="font-size: 12px;">Связь с роутером была разорвана во время обновления. Подождите 10-15 секунд и перезагрузите страницу вручную.</div>
                `;
            }
        }

        const serversData = <?php echo json_encode($servers_data ?? []); ?>;
        let priorityServers = <?php echo json_encode(array_values(array_filter(array_map('trim', explode(',', $config['PREFERRED_SERVER'] ?? ''))))); ?>;
        const SERVER_HOST_MAP = <?php 
            $map = [];
            foreach ($servers_data as $s) { $map[$s['name']] = $s['host']; }
            echo json_encode($map);
        ?>;

        function renderPriorityList() {
            const listEl = document.getElementById('priority-server-list');
            const hintEl = document.getElementById('auto-select-hint');
            if (!listEl) return;
            listEl.innerHTML = '';
            
            if (priorityServers.length === 0) {
                if (hintEl) hintEl.style.display = 'block';
                return;
            }
            if (hintEl) hintEl.style.display = 'none';

            priorityServers.forEach((name, idx) => {
                const li = document.createElement('li');
                li.style.cssText = 'display: flex; align-items: center; justify-content: space-between; background: #1f293d; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); font-size: 13px;';
                
                const badge = idx === 0 ? '🥇 1 (Основной): ' : (idx === 1 ? '🥈 2 (Резерв 1): ' : `🥉 ${idx + 1} (Резерв ${idx}): `);
                const host = SERVER_HOST_MAP[name] || '';
                
                li.innerHTML = `
                    <input type="hidden" name="servers[]" value="${name}">
                    <div style="display: flex; align-items: center;">
                        <span style="font-weight: 500;">${badge}${name}</span>
                        ${host ? `<span class="ping-badge" data-host="${host}" style="margin-left: 8px; font-size: 11px; font-weight: 600;"></span>` : ''}
                    </div>
                    <div style="display: flex; gap: 4px;">
                        ${idx > 0 ? `<button type="button" class="btn btn-secondary" style="padding: 2px 6px; font-size: 11px;" onclick="movePriority(${idx}, -1)">⬆</button>` : ''}
                        ${idx < priorityServers.length - 1 ? `<button type="button" class="btn btn-secondary" style="padding: 2px 6px; font-size: 11px;" onclick="movePriority(${idx}, 1)">⬇</button>` : ''}
                        <button type="button" class="btn btn-danger" style="padding: 2px 6px; font-size: 11px;" onclick="removePriority(${idx})">❌</button>
                    </div>
                `;
                listEl.appendChild(li);
            });
        }

        function addServerToPriority() {
            const select = document.getElementById('add-server-select');
            if (!select || !select.value) return;
            const val = select.value;
            if (!priorityServers.includes(val)) {
                priorityServers.push(val);
                renderPriorityList();
            }
            select.value = '';
        }

        function removePriority(idx) {
            priorityServers.splice(idx, 1);
            renderPriorityList();
        }

        function movePriority(idx, dir) {
            const targetIdx = idx + dir;
            if (targetIdx < 0 || targetIdx >= priorityServers.length) return;
            const temp = priorityServers[idx];
            priorityServers[idx] = priorityServers[targetIdx];
            priorityServers[targetIdx] = temp;
            renderPriorityList();
        }

        function clearPriorityList() {
            priorityServers = [];
            renderPriorityList();
        }

        async function fetchRealtimeStatus() {
            try {
                const res = await fetch('?ajax=get_status');
                if (!res.ok) return;
                const data = await res.json();
                if (data.success) {
                    const badge = document.getElementById('service-status-badge');
                    const badgeText = document.getElementById('service-status-text');
                    const ifaceStatus = document.getElementById('iface-status-val');
                    const ifaceIp = document.getElementById('iface-ip-val');
                    const pidVal = document.getElementById('service-pid-val');

                    if (badge && badgeText) {
                        if (data.service_running) {
                            badge.className = 'status-badge active';
                            badgeText.innerText = 'Служба работает';
                        } else {
                            badge.className = 'status-badge inactive';
                            badgeText.innerText = 'Служба остановлена';
                        }
                    }
                    if (ifaceStatus) {
                        ifaceStatus.innerText = data.interface_status;
                        ifaceStatus.style.color = data.interface_status === 'Активен' ? 'var(--accent-green)' : 'var(--text-muted)';
                    }
                    if (ifaceIp) {
                        ifaceIp.innerText = data.interface_ip;
                    }
                    if (pidVal) {
                        pidVal.innerText = data.pid;
                    }
                }
            } catch (e) {}
        }
        setInterval(fetchRealtimeStatus, 3000);
        document.addEventListener('DOMContentLoaded', () => {
            renderPriorityList();
            setTimeout(checkServerPings, 300);
        });
    </script>
</head>
<body>
    <div class="container">
        
        <?php if (!$password_set): ?>
            <!-- Шаблон установки пароля администратора -->
            <div class="card auth-container">
                <h2 style="border-bottom: none; text-align: center; margin-bottom: 24px;">Установка пароля веб-панели</h2>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="setup_password">
                    <div class="form-group">
                        <label for="new_pass">Новый пароль (минимум 6 символов)</label>
                        <input type="password" id="new_pass" name="password" required autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label for="confirm_pass">Подтверждение пароля</label>
                        <input type="password" id="confirm_pass" name="confirm_password" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary btn-full">Сохранить пароль</button>
                </form>
            </div>
            
        <?php elseif (!$authenticated): ?>
            <!-- Шаблон входа в веб-панель -->
            <div class="card auth-container">
                <h2 style="border-bottom: none; text-align: center; margin-bottom: 24px;">Вход в панель управления FPTN</h2>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label for="login_pass">Пароль администратора</label>
                        <input type="password" id="login_pass" name="password" required autofocus autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-primary btn-full">Войти</button>
                </form>
            </div>
            
        <?php else: ?>
            <!-- Основной интерфейс управления (доступен только после авторизации) -->
            <header>
                <div>
                    <h1>Клиент FPTN <span style="font-size: 12px; font-weight: normal; color: var(--text-muted); background: var(--border-color); padding: 2px 8px; border-radius: 12px; margin-left: 8px; vertical-align: middle;"><?php echo htmlspecialchars(get_installed_version()); ?></span></h1>
                    <p style="font-size: 14px; color: var(--text-muted); margin-top: 4px;">Маршрутизируемый VPN-клиент на Keenetic/Entware</p>
                </div>
                <div style="display: flex; align-items: center; gap: 16px;">
                    <div id="service-status-badge" class="status-badge <?php echo $service_running ? 'active' : 'inactive'; ?>">
                        <span class="status-dot"></span>
                        <span id="service-status-text"><?php echo $service_running ? 'Служба работает' : 'Служба остановлена'; ?></span>
                    </div>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn btn-secondary btn-sm" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;">Выйти</button>
                    </form>
                </div>
            </header>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>

            <div class="grid">
                <!-- Карточка статуса -->
                <div class="card">
                    <h2>Параметры соединения</h2>
                    <div class="info-row">
                        <span class="info-label">Интерфейс:</span>
                        <span class="info-value" style="font-family: monospace;"><?php echo htmlspecialchars($config['TUN_INTERFACE']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Статус интерфейса:</span>
                        <span id="iface-status-val" class="info-value" style="color: <?php echo $interface_status === 'Активен' ? 'var(--accent-green)' : 'var(--text-muted)'; ?>">
                            <?php echo $interface_status; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">IP-адрес TUN:</span>
                        <span id="iface-ip-val" class="info-value" style="font-family: monospace;"><?php echo $interface_ip ? htmlspecialchars($interface_ip) : '—'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Имя подписки:</span>
                        <span class="info-value"><?php echo !empty($service_title) ? $service_title : 'Неизвестно'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">PID процесса:</span>
                        <span id="service-pid-val" class="info-value" style="font-family: monospace;"><?php echo $pid ? htmlspecialchars($pid) : '—'; ?></span>
                    </div>

                    <div class="info-row" style="margin-top: 16px; border-top: 1px dashed var(--border-color); padding-top: 16px;">
                        <span class="info-label">Версия панели:</span>
                        <span class="info-value">
                            <?php echo htmlspecialchars(get_installed_version()); ?>
                            <button type="button" id="check-update-btn" class="btn btn-secondary" style="padding: 2px 8px; font-size: 11px; margin-left: 8px; border-radius: 6px; display: inline-flex;" onclick="checkUpdates()">🔄 Проверить</button>
                        </span>
                    </div>
                    <div id="update-status-block" style="margin-top: 12px; display: none; font-size: 13px; padding: 12px; border-radius: 10px; background: rgba(59, 130, 246, 0.05); border: 1px dashed var(--border-color);">
                        <!-- Сюда вставляется статус обновления -->
                    </div>

                    <div class="btn-group" style="margin-top: 24px;">
                        <?php if ($service_running): ?>
                            <form method="POST" style="flex: 1;">
                                <input type="hidden" name="action" value="stop">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" class="btn btn-danger btn-full">Остановить</button>
                            </form>
                            <form method="POST" style="flex: 1;">
                                <input type="hidden" name="action" value="restart">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" class="btn btn-secondary btn-full">Перезапустить</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="width: 100%;">
                                <input type="hidden" name="action" value="start">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" class="btn btn-success btn-full">Запустить службу</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Карточка выбора серверов (Приоритет) -->
                <div class="card">
                    <h2>Приоритет серверов (Failover)</h2>
                    <?php if (empty($servers_data)): ?>
                        <p style="color: var(--text-muted); font-size: 14px;">Импортируйте токен подписки, чтобы увидеть список доступных серверов.</p>
                    <?php else: ?>
                        <form method="POST" id="server-priority-form" style="margin-bottom: 20px;">
                            <input type="hidden" name="action" value="save_server">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            
                            <div class="form-group" style="margin-bottom: 12px;">
                                <label style="font-size: 13px;">Добавить сервер в список приоритетов:</label>
                                <div style="display: flex; gap: 8px; margin-bottom: 8px;">
                                    <select id="add-server-select" style="flex: 1; min-width: 0; text-overflow: ellipsis;">
                                        <option value="">-- Выберите сервер --</option>
                                        <?php foreach ($servers_data as $srv): ?>
                                            <option value="<?php echo htmlspecialchars($srv['name']); ?>" data-host="<?php echo htmlspecialchars($srv['host']); ?>">
                                                <?php echo htmlspecialchars($srv['name']); ?> (<?php echo htmlspecialchars($srv['host']); ?>:<?php echo $srv['port']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-secondary" onclick="addServerToPriority()" style="white-space: nowrap; padding: 6px 14px; font-size: 13px;">➕ Добавить</button>
                                </div>
                                <button type="button" id="ping-servers-btn" class="btn btn-secondary" onclick="checkServerPings(event); return false;" style="width: 100%; font-size: 13px; padding: 7px 12px; display: flex; align-items: center; justify-content: center; gap: 6px; background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.2); color: #60a5fa;">⚡ Замерить пинг всех серверов</button>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="margin-bottom: 8px; font-size: 13px;">Порядок приоритета подключений:</label>
                                <ul id="priority-server-list" style="list-style: none; padding: 0; display: flex; flex-direction: column; gap: 8px;">
                                    <!-- Динамический список серверов -->
                                </ul>
                                <p id="auto-select-hint" style="font-size: 12px; color: var(--text-muted); margin-top: 6px;">
                                    💡 Список пуст: используется <b>Автовыбор (Автоматический выбор наибыстрейшего сервера)</b>.
                                </p>
                            </div>

                            <div style="margin-bottom: 16px; background: rgba(59, 130, 246, 0.03); padding: 10px 12px; border-radius: 8px; border: 1px dashed var(--border-color); display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                <div>
                                    <span style="font-size: 12px; font-weight: 600; display: block; color: var(--text-color);">Переходить в Автовыбор при отвале серверов</span>
                                    <span style="font-size: 11px; color: var(--text-muted);">Если все выбранные сервера недоступны — временно уйти на наибыстрейший доступный сервер</span>
                                </div>
                                <label class="switch" style="position: relative; display: inline-block; width: 40px; height: 20px; flex-shrink: 0;">
                                    <input type="checkbox" name="fallback_to_auto" value="1" <?php echo ($config['FALLBACK_TO_AUTO'] ?? 'yes') === 'yes' ? 'checked' : ''; ?> style="opacity: 0; width: 0; height: 0;">
                                    <span class="slider round" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 20px;"></span>
                                </label>
                            </div>

                            <div style="display: flex; gap: 8px;">
                                <button type="submit" class="btn btn-primary" style="flex: 1;">Сохранить приоритеты</button>
                                <button type="button" class="btn btn-secondary" onclick="clearPriorityList()" style="font-size: 11px; padding: 6px 10px;">Сбросить в авто</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <div style="border-top: 1px dashed var(--border-color); padding-top: 20px; margin-top: 20px;">
                        <form method="POST">
                            <input type="hidden" name="action" value="save_watchdog">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: rgba(59, 130, 246, 0.03); padding: 12px; border-radius: 10px; border: 1px solid var(--border-color);">
                                <div>
                                    <span style="font-weight: 600; font-size: 14px; display: block; color: var(--text-color);">Автопинг и переключение</span>
                                    <span style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 2px;">Перезапустит VPN на новый сервер, если текущий завис или упал</span>
                                </div>
                                <label class="switch" style="position: relative; display: inline-block; width: 44px; height: 22px; flex-shrink: 0;">
                                    <input type="checkbox" name="watchdog" value="1" <?php echo $config['WATCHDOG'] === 'yes' ? 'checked' : ''; ?> onchange="this.form.submit()" style="opacity: 0; width: 0; height: 0;">
                                    <span class="slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #374151; transition: .3s; border-radius: 22px;"></span>
                                </label>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Карточка токена -->
            <div class="card" style="margin-bottom: 40px;">
                <h2>Токен подписки FPTN</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="save_token">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="form-group">
                        <label for="token-input">Токен подписки (fptnb:...)</label>
                        <input type="password" id="token-input" name="token" placeholder="fptnb:..." value="<?php echo htmlspecialchars($config['TOKEN']); ?>" autocomplete="off" style="padding-right: 110px;">
                        <button type="button" id="toggle-token-btn" class="toggle-btn" onclick="toggleVisibility('token-input', 'toggle-token-btn')">👁️ Показать</button>
                    </div>
                    <button type="submit" class="btn btn-primary">Применить токен</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const CSRF_TOKEN = "<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>";

        function toggleVisibility(inputId, btnId) {
            const input = document.getElementById(inputId);
            const btn = document.getElementById(btnId);
            if (!input || !btn) return;
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = '🔒 Скрыть';
            } else {
                input.type = 'password';
                btn.textContent = '👁️ Показать';
            }
        }

        function checkServerPings(e) {
            if (e && e.preventDefault) {
                e.preventDefault();
            }
            const btn = document.getElementById('ping-servers-btn');
            if (btn) {
                btn.disabled = true;
                btn.textContent = '⏳ Измерение задержек всех серверов...';
            }

            if (!Array.isArray(serversData) || serversData.length === 0) {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '⚡ Замерить пинг всех серверов';
                }
                return;
            }

            // Предварительная индикация "Пинг..."
            serversData.forEach(srv => {
                const option = document.querySelector(`#add-server-select option[data-host="${srv.host}"]`);
                if (option) {
                    option.textContent = `${srv.name} (${srv.host}:${srv.port || 443}) — ⏳ Пинг...`;
                }
                const badges = document.querySelectorAll(`.ping-badge[data-host="${srv.host}"]`);
                badges.forEach(b => {
                    b.style.color = 'var(--text-muted)';
                    b.textContent = '[⏳ Пинг...]';
                });
            });

            const promises = serversData.map(srv => {
                return fetch(`?ajax=check_ping&host=${encodeURIComponent(srv.host)}&port=${encodeURIComponent(srv.port || 443)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.auth_required) {
                            window.location.reload();
                            return;
                        }
                        if (data.success) {
                            let pingStr = '';
                            let color = '#ef4444';
                            if (data.online && data.ping_ms >= 0) {
                                color = data.ping_ms < 60 ? '#10b981' : (data.ping_ms < 120 ? '#f59e0b' : '#ef4444');
                                const icon = data.ping_ms < 60 ? '🟢' : (data.ping_ms < 120 ? '🟡' : '🔴');
                                pingStr = `${icon} ${data.ping_ms} ms`;
                            } else {
                                pingStr = '🔴 Offline';
                            }

                            // 1. Обновляем опции в выпадающем списке
                            const option = document.querySelector(`#add-server-select option[data-host="${srv.host}"]`);
                            if (option) {
                                option.textContent = `${srv.name} (${srv.host}:${srv.port || 443}) — ${pingStr}`;
                            }

                            // 2. Обновляем плашки в списке приоритетов
                            const badges = document.querySelectorAll(`.ping-badge[data-host="${srv.host}"]`);
                            badges.forEach(b => {
                                b.style.color = color;
                                b.textContent = `[${pingStr}]`;
                            });
                        }
                    })
                    .catch(() => {});
            });

            Promise.all(promises).then(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '⚡ Замерить пинг всех серверов';
                }
            });
        }

        function checkUpdates() {
            const btn = document.getElementById('check-update-btn');
            const block = document.getElementById('update-status-block');
            if (btn) btn.disabled = true;
            if (block) {
                block.style.display = 'block';
                block.innerHTML = '<span style="color: var(--text-muted);">⏳ Проверка доступных обновлений на GitHub...</span>';
            }

            fetch('?ajax=check_update')
                .then(r => r.json())
                .then(data => {
                    if (btn) btn.disabled = false;
                    if (data.success) {
                        if (data.has_update) {
                            block.className = 'alert alert-info';
                            block.style.background = 'rgba(59, 130, 246, 0.08)';
                            block.style.borderColor = 'rgba(59, 130, 246, 0.3)';
                            block.innerHTML = `
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                                    <div>
                                        <b style="color: #60a5fa;">🚀 Доступно новое обновление: ${data.remote_version}</b>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">Текущая версия: ${data.current_version}</div>
                                    </div>
                                    <button type="button" class="btn btn-success btn-sm" onclick="installUpdate()" style="padding: 6px 12px; font-size: 12px; white-space: nowrap; cursor: pointer;">⚡ Установить обновление</button>
                                </div>
                            `;
                        } else {
                            block.className = '';
                            block.style.background = 'rgba(16, 185, 129, 0.05)';
                            block.style.borderColor = 'rgba(16, 185, 129, 0.2)';
                            block.innerHTML = `✅ <b>У вас установлена актуальная версия (${data.current_version}).</b> Обновление не требуется.`;
                        }
                    } else {
                        block.className = '';
                        block.style.background = 'rgba(239, 68, 68, 0.05)';
                        block.style.borderColor = 'rgba(239, 68, 68, 0.2)';
                        block.innerHTML = `❌ <b>Сбой проверки обновлений:</b> ${data.message || 'Неизвестная ошибка'}`;
                    }
                })
                .catch(err => {
                    if (btn) btn.disabled = false;
                    if (block) {
                        block.style.display = 'block';
                        block.style.background = 'rgba(239, 68, 68, 0.05)';
                        block.style.borderColor = 'rgba(239, 68, 68, 0.2)';
                        block.innerHTML = `❌ <b>Сбой сети при проверке обновлений:</b> ${err.message || 'Ошибка соединения'}`;
                    }
                });
        }

        function installUpdate() {
            const block = document.getElementById('update-status-block');
            if (block) {
                block.innerHTML = `
                    <div style="text-align: center; padding: 8px;">
                        <div style="font-weight: 600; color: #60a5fa;">📥 Идет установка обновления...</div>
                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Загрузка клиента fptn-client-cli и интерфейса index.php. Пожалуйста, не закрывайте вкладку...</div>
                    </div>
                `;
            }

            fetch(`?ajax=install_update&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.auth_required) {
                        window.location.reload();
                        return;
                    }
                    if (data.success) {
                        if (block) {
                            block.innerHTML = `✅ <b>${data.message}</b><br><span style="font-size: 11px;">Перезагрузка страницы через 3 секунды...</span>`;
                        }
                        setTimeout(() => { window.location.reload(); }, 3000);
                    } else {
                        if (block) {
                            block.innerHTML = `❌ <b>Ошибка установки:</b> ${data.message}`;
                        }
                    }
                })
                .catch(err => {
                    if (block) {
                        block.innerHTML = `❌ <b>Сбой установки обновления:</b> ${err.message}`;
                    }
                });
        }

        // Автоматический мониторинг статуса службы каждые 5 секунд
        setInterval(() => {
            fetch('?ajax=get_status')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const badge = document.getElementById('service-status-badge');
                        const text = document.getElementById('service-status-text');
                        const pidVal = document.getElementById('service-pid-val');
                        const ifaceStatus = document.getElementById('iface-status-val');
                        const ifaceIp = document.getElementById('iface-ip-val');

                        if (badge && text) {
                            if (data.service_running) {
                                badge.className = 'status-badge active';
                                text.textContent = 'Служба работает';
                            } else {
                                badge.className = 'status-badge inactive';
                                text.textContent = 'Служба остановлена';
                            }
                        }
                        if (pidVal) pidVal.textContent = data.pid;
                        if (ifaceStatus) {
                            ifaceStatus.textContent = data.interface_status;
                            ifaceStatus.style.color = data.interface_status === 'Активен' ? 'var(--accent-green)' : 'var(--text-muted)';
                        }
                        if (ifaceIp) ifaceIp.textContent = data.interface_ip;
                    }
                })
                .catch(() => {});
        }, 5000);
    </script>
</body>
</html>
