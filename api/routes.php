<?php
/**
 * API сохранённых маршрутов «Фарватера» (для хостинга с ISPmanager + PHP + MySQL).
 *
 * Установка:
 *   1. В ISPmanager создайте базу MySQL и пользователя.
 *   2. Скопируйте db_config.php.sample в db_config.php и впишите доступы.
 *   3. Готово: приложение само увидит этот файл и переключится
 *      с локального хранилища на серверное.
 *
 * Пока db_config.php не создан, эндпоинт отвечает {"mode":"unconfigured"},
 * и приложение спокойно работает на локальном хранилище браузера.
 */
header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/db_config.php';
if (!file_exists($configFile)) {
    echo json_encode(['mode' => 'unconfigured']);
    exit;
}
require $configFile;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $db->set_charset('utf8mb4');
    $db->query(
        'CREATE TABLE IF NOT EXISTS routes (
            id VARCHAR(32) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            form JSON NOT NULL,
            distance DOUBLE NOT NULL,
            saved VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
} catch (Throwable $e) {
    http_response_code(200); // не ломаем консоль приложения
    echo json_encode(['mode' => 'error', 'detail' => 'db unavailable']);
    exit;
}

function allRoutes(mysqli $db): array {
    $rows = $db->query('SELECT * FROM routes ORDER BY saved DESC, id DESC')->fetch_all(MYSQLI_ASSOC);
    return array_map(function ($r) {
        return [
            'id' => $r['id'],
            'name' => $r['name'],
            'form' => json_decode($r['form'], true),
            'distance' => (float) $r['distance'],
            'saved' => $r['saved'],
        ];
    }, $rows);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(allRoutes($db), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $route = json_decode(file_get_contents('php://input'), true);
    if (!is_array($route) || empty($route['id']) || !isset($route['form'])) {
        http_response_code(400);
        echo json_encode(['error' => 'bad payload']);
        exit;
    }
    $stmt = $db->prepare(
        'INSERT INTO routes (id, name, form, distance, saved) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE name=VALUES(name), form=VALUES(form),
                                 distance=VALUES(distance), saved=VALUES(saved)'
    );
    $stmt->bind_param(
        'sssds',
        $route['id'],
        $route['name'],
        json_encode($route['form'], JSON_UNESCAPED_UNICODE),
        $route['distance'],
        $route['saved']
    );
    $stmt->execute();
    echo json_encode(allRoutes($db), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    $stmt = $db->prepare('DELETE FROM routes WHERE id = ?');
    $stmt->bind_param('s', $id);
    $stmt->execute();
    echo json_encode(allRoutes($db), JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
