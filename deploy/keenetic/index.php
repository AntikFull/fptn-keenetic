<?php
// Веб-панель управления клиентом FPTN на Keenetic (Entware)
// Автор: Antigravity
// Исправлено: добавлены авторизация, защита от CSRF, маскирование токена и автообновление

session_name('FPTN_SESS');
session_start();
header('Content-Type: text/html; charset=utf-8');

define('CURRENT_VERSION', 'v1.0.5-keenetic');

putenv("PATH=/opt/sbin:/opt/bin:/opt/usr/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin");

$conf_file = "/opt/etc/fptn-client.conf";
$servers_file = "/opt/etc/fptn-servers.json";
$cli_path = "/opt/bin/fptn-client-cli";
$init_script = "/opt/etc/init.d/S53fptn-client";

// Проверяем состояние авторизации перед обработкой AJAX
$authenticated = isset($_SESSION['auth']) && $_SESSION['auth'] === true;

// Функция для выполнения HTTPS GET запросов без зависимости от расширения php-curl
function http_get_contents($url, $timeout = 15) {
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: FPTN-Keenetic-Client\r\n",
            'timeout' => $timeout,
            'follow_location' => 1,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];
    $context = stream_context_create($options);
    $data = @file_get_contents($url, false, $context);
    
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches)) {
            $code = (int)$matches[1];
        }
    }
    return ['code' => $code, 'data' => $data];
}

// Инициализация дефолтных значений конфигурации
$config = [
    'ENABLED' => 'no',
    'TOKEN' => '',
    'PREFERRED_SERVER' => '',
    'TUN_INTERFACE' => 'opkgtun1',
    'WATCHDOG' => 'yes',
    'WEB_PASSWORD' => ''
];

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

// Обработка AJAX запросов (проверка обновлений, автообновление статуса и запуск обновления)
if (isset($_GET['ajax']) && $authenticated) {
    header('Content-Type: application/json');
    $ajax_action = $_GET['ajax'];
    
    if ($ajax_action === 'get_status') {
        read_config();
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
    
    if ($ajax_action === 'check_update') {
        $github_raw_url = 'https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/version.txt';
        $res = http_get_contents($github_raw_url, 4);
        if ($res['code'] !== 200) {
            $github_raw_url = 'https://ghproxy.net/https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/version.txt';
            $res = http_get_contents($github_raw_url, 4);
        }
        
        if ($res['code'] === 200 && !empty($res['data'])) {
            $remote_version = trim($res['data']);
            // Сравниваем версии без префикса 'v' и суффикса '-keenetic'
            $v_remote = preg_replace('/[^0-9\.]/', '', $remote_version);
            $v_current = preg_replace('/[^0-9\.]/', '', CURRENT_VERSION);
            $has_update = (version_compare($v_remote, $v_current) > 0);
            
            echo json_encode([
                'success' => true,
                'current_version' => CURRENT_VERSION,
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
        
        if ($use_mirrors) {
            $bin_url = "https://ghproxy.net/https://github.com/AntikFull/fptn-keenetic/releases/download/{$remote_version}/fptn-client-cli-{$arch_suffix}";
            $php_url = "https://ghproxy.net/https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/index.php";
        } else {
            $bin_url = "https://github.com/AntikFull/fptn-keenetic/releases/download/{$remote_version}/fptn-client-cli-{$arch_suffix}";
            $php_url = "https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/index.php";
        }
        
        $tmp_bin = "/tmp/fptn-client-cli.tmp";
        $tmp_php = "/tmp/index.php.tmp";
        
        // Скачивание бинарника
        $res_bin = http_get_contents($bin_url, 60);
        if ($res_bin['code'] !== 200 || empty($res_bin['data'])) {
            echo json_encode(['success' => false, 'message' => "Не удалось скачать бинарный файл с GitHub Releases: HTTP {$res_bin['code']}"]);
            exit;
        }
        file_put_contents($tmp_bin, $res_bin['data']);
        chmod($tmp_bin, 0755);
        
        // Скачивание PHP панели
        $res_php = http_get_contents($php_url, 20);
        if ($res_php['code'] !== 200 || empty($res_php['data'])) {
            @unlink($tmp_bin);
            echo json_encode(['success' => false, 'message' => "Не удалось скачать новую веб-панель index.php: HTTP {$res_php['code']}"]);
            exit;
        }
        file_put_contents($tmp_php, $res_php['data']);
        
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
        
        // 4. Заменяем саму себя в последнюю очередь
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

// Флаги состояния авторизации
$password_set = !empty($config['WEB_PASSWORD']);
$authenticated = isset($_SESSION['auth']) && $_SESSION['auth'] === true;

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
                    $config['PREFERRED_SERVER'] = $server;
                    if (write_config()) {
                        $message = 'Список серверов по приоритету обновлен: ' . ($server ? htmlspecialchars($server) : 'Автовыбор');
                        
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
                        }
                        
                        $cmd = $init_script . " " . $action . " 2>&1";
                        exec($cmd, $output, $return_var);
                        $message = 'Выполнено действие: ' . $action . '. ' . htmlspecialchars(implode(" ", $output));
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
        if (!empty($pids)) {
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
$service_name = '';
if (file_exists($servers_file)) {
    $servers_json = json_decode(file_get_contents($servers_file), true);
    if ($servers_json) {
        $servers_data = $servers_json['servers'] ?? [];
        $service_name = $servers_json['service_name'] ?? '';
    }
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
                
                li.innerHTML = `
                    <input type="hidden" name="servers[]" value="${name}">
                    <span style="font-weight: 500;">${badge}${name}</span>
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
                    <h1>Клиент FPTN <span style="font-size: 12px; font-weight: normal; color: var(--text-muted); background: var(--border-color); padding: 2px 8px; border-radius: 12px; margin-left: 8px; vertical-align: middle;"><?php echo CURRENT_VERSION; ?></span></h1>
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
                        <span class="info-value"><?php echo $service_name ? htmlspecialchars($service_name) : 'Неизвестно'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">PID процесса:</span>
                        <span id="service-pid-val" class="info-value" style="font-family: monospace;"><?php echo $pid ? htmlspecialchars($pid) : '—'; ?></span>
                    </div>

                    <div class="info-row" style="margin-top: 16px; border-top: 1px dashed var(--border-color); padding-top: 16px;">
                        <span class="info-label">Версия панели:</span>
                        <span class="info-value">
                            <?php echo CURRENT_VERSION; ?>
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
                            
                            <div class="form-group">
                                <label style="font-size: 13px;">Добавить сервер в список приоритетов:</label>
                                <div style="display: flex; gap: 8px;">
                                    <select id="add-server-select" style="flex: 1;">
                                        <option value="">-- Выберите сервер --</option>
                                        <?php foreach ($servers_data as $srv): ?>
                                            <option value="<?php echo htmlspecialchars($srv['name']); ?>">
                                                <?php echo htmlspecialchars($srv['name']); ?> (<?php echo htmlspecialchars($srv['host']); ?>:<?php echo $srv['port']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-secondary" onclick="addServerToPriority()" style="white-space: nowrap; padding: 6px 12px; font-size: 13px;">➕ Добавить</button>
                                </div>
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
</body>
</html>
