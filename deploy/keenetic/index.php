<?php
// Веб-панель управления клиентом FPTN на Keenetic (Entware)
// Автор: Antigravity

putenv("PATH=/opt/sbin:/opt/bin:/opt/usr/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin");

$conf_file = "/opt/etc/fptn-client.conf";
$servers_file = "/opt/etc/fptn-servers.json";
$cli_path = "/opt/bin/fptn-client-cli";
$init_script = "/opt/etc/init.d/S53fptn-client";

// Инициализация дефолтных значений
$config = [
    'ENABLED' => 'no',
    'TOKEN' => '',
    'PREFERRED_SERVER' => '',
    'TUN_INTERFACE' => 'opkgtun1'
];

// Функция чтения конфигурации
function read_config() {
    global $conf_file, $config;
    if (file_exists($conf_file)) {
        $lines = file($conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            $parts = explode('=', $line, 2);
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

// Функция записи конфигурации
function write_config() {
    global $conf_file, $config;
    $content = "# Конфигурация клиента FPTN (Создано автоматически)\n";
    foreach ($config as $k => $v) {
        $content .= "{$k}=\"{$v}\"\n";
    }
    return file_put_contents($conf_file, $content) !== false;
}

read_config();

// Проверка статуса службы (вынесена выше для использования при обработке POST)
$service_running = false;
$pid = null;
if (file_exists($cli_path)) {
    exec("pgrep -f fptn-client-cli", $pids);
    if (!empty($pids)) {
        $service_running = true;
        $pid = implode(", ", $pids);
    }
}

// Обработка действий
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'save_token') {
            $token = trim($_POST['token']);
            if (empty($token)) {
                $error = 'Токен не может быть пустым';
            } else {
                // Проверяем токен с помощью бинарника fptn-client-cli
                if (!file_exists($cli_path)) {
                    $error = 'Клиент fptn-client-cli не найден в /opt/bin/. Сначала соберите и загрузите его.';
                } else {
                    $cmd = $cli_path . " --access-token " . escapeshellarg($token) . " --show-servers 2>&1";
                    exec($cmd, $output, $return_var);
                    
                    if ($return_var === 0) {
                        $json_str = implode("\n", $output);
                        // Отсекаем логи spdlog, которые выводятся до JSON (начинаются с '{')
                        $json_start = strpos($json_str, '{');
                        if ($json_start !== false) {
                            $json_str = substr($json_str, $json_start);
                        }
                        $parsed = json_decode($json_str, true);
                        if ($parsed && isset($parsed['servers'])) {
                            file_put_contents($servers_file, $json_str);
                            $config['TOKEN'] = $token;
                            write_config();
                            $message = 'Токен успешно сохранен и проверен! Служба: ' . htmlspecialchars($parsed['service_name']);
                            
                            // Перезапуск службы для применения нового токена
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
        
        if ($action === 'save_server') {
            $server = trim($_POST['server']);
            $config['PREFERRED_SERVER'] = $server;
            if (write_config()) {
                $message = 'Предпочтительный сервер обновлен на: ' . ($server ? htmlspecialchars($server) : 'Автовыбор');
                
                // Перезапуск службы для применения выбранного сервера
                if ($service_running) {
                    $cmd = $init_script . " restart 2>&1";
                    exec($cmd, $restart_output, $restart_return);
                    $message .= ' Служба автоматически перезапущена.';
                }
            } else {
                $error = 'Не удалось записать конфигурацию.';
            }
        }
        
        if (in_array($action, ['start', 'stop', 'restart'])) {
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
    // Перечитываем конфигурацию после записи
    read_config();
    
    // Переопределяем статус службы после возможных изменений
    $service_running = false;
    $pid = null;
    if (file_exists($cli_path)) {
        exec("pgrep -f fptn-client-cli", $pids);
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
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.3);
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
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 500;
        }
        
        input[type="text"], select {
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
        
        input[type="text"]:focus, select:focus {
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
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>Клиент FPTN</h1>
                <p style="font-size: 14px; color: var(--text-muted); margin-top: 4px;">Маршрутизируемый VPN-клиент на Keenetic/Entware</p>
            </div>
            <div class="status-badge <?php echo $service_running ? 'active' : 'inactive'; ?>">
                <span class="status-dot"></span>
                <span><?php echo $service_running ? 'Служба работает' : 'Служба остановлена'; ?></span>
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
                    <span class="info-value <?php echo $interface_status === 'Активен' ? 'text-success' : ''; ?>" style="color: <?php echo $interface_status === 'Активен' ? 'var(--accent-green)' : 'var(--text-muted)'; ?>">
                        <?php echo $interface_status; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">IP-адрес TUN:</span>
                    <span class="info-value" style="font-family: monospace;"><?php echo $interface_ip ? htmlspecialchars($interface_ip) : '—'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Имя подписки:</span>
                    <span class="info-value"><?php echo $service_name ? htmlspecialchars($service_name) : 'Неизвестно'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">PID процесса:</span>
                    <span class="info-value" style="font-family: monospace;"><?php echo $pid ? htmlspecialchars($pid) : '—'; ?></span>
                </div>

                <div class="btn-group" style="margin-top: 24px;">
                    <?php if ($service_running): ?>
                        <form method="POST" style="flex: 1;">
                            <input type="hidden" name="action" value="stop">
                            <button type="submit" class="btn btn-danger btn-full">Остановить</button>
                        </form>
                        <form method="POST" style="flex: 1;">
                            <input type="hidden" name="action" value="restart">
                            <button type="submit" class="btn btn-secondary btn-full">Перезапустить</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="width: 100%;">
                            <input type="hidden" name="action" value="start">
                            <button type="submit" class="btn btn-success btn-full">Запустить службу</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Карточка выбора сервера -->
            <div class="card">
                <h2>Выбор сервера</h2>
                <?php if (empty($servers_data)): ?>
                    <p style="color: var(--text-muted); font-size: 14px;">Импортируйте токен подписки, чтобы увидеть список доступных серверов.</p>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_server">
                        <div class="form-group">
                            <label for="server-select">Сервер для подключения</label>
                            <select id="server-select" name="server">
                                <option value="" <?php echo empty($config['PREFERRED_SERVER']) ? 'selected' : ''; ?>>Автовыбор (Быстрейший)</option>
                                <?php foreach ($servers_data as $srv): ?>
                                    <option value="<?php echo htmlspecialchars($srv['name']); ?>" <?php echo $config['PREFERRED_SERVER'] === $srv['name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($srv['name']); ?> (<?php echo htmlspecialchars($srv['host']); ?>:<?php echo $srv['port']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-full">Сохранить выбор</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Карточка токена -->
        <div class="card">
            <h2>Токен подписки FPTN</h2>
            <form method="POST">
                <input type="hidden" name="action" value="save_token">
                <div class="form-group">
                    <label for="token-input">Токен подписки (fptnb:...)</label>
                    <input type="text" id="token-input" name="token" placeholder="fptnb:..." value="<?php echo htmlspecialchars($config['TOKEN']); ?>" autocomplete="off">
                </div>
                <button type="submit" class="btn btn-primary">Применить токен</button>
            </form>
        </div>
    </div>
</body>
</html>
