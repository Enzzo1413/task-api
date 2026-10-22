<?php
/**
 * Task API - standalone (no framework, no composer). PHP 7.4+, MySQL.
 * Upload this folder to your host, edit config.php, import schema.sql.
 */

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

function respond($code, $body)
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function valid_date($v)
{
    $d = DateTime::createFromFormat('Y-m-d', (string) $v);
    return $d && $d->format('Y-m-d') === $v;
}

function clean($task)
{
    unset($task['api_key']);
    $task['id'] = (int) $task['id'];
    return $task;
}

/** Returns array of error messages (empty = valid). $partial = true for updates. */
function validate($in, $partial)
{
    $e = [];
    if (!$partial || array_key_exists('title', $in)) {
        $t = isset($in['title']) ? trim((string) $in['title']) : '';
        if ($t === '') $e['title'] = 'Title is required.';
        elseif (mb_strlen($t) > 150) $e['title'] = 'Title must be 150 characters or less.';
    }
    if (!empty($in['due_date']) && !valid_date($in['due_date'])) {
        $e['due_date'] = 'due_date must be in YYYY-MM-DD format.';
    }
    if (isset($in['status']) && !in_array($in['status'], ['pending', 'done'], true)) {
        $e['status'] = "status must be 'pending' or 'done'.";
    }
    return $e;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- Routing -------------------------------------------------------------
$uriPath   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$path      = $uriPath;
if ($scriptDir !== '' && strpos($path, $scriptDir) === 0) {
    $path = substr($path, strlen($scriptDir));
}
$path   = preg_replace('#^/index\.php#', '', $path);
$path   = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

if ($path === '/') {
    respond(200, [
        'name'      => 'Task API',
        'endpoints' => ['GET /tasks', 'GET /tasks/{id}', 'POST /tasks', 'PUT|PATCH /tasks/{id}', 'DELETE /tasks/{id}'],
        'auth'      => 'Send your group secret in the X-API-Key header.',
    ]);
}

$id = null;
if ($path === '/tasks') {
    $route = 'collection';
} elseif (preg_match('#^/tasks/(\d+)$#', $path, $m)) {
    $route = 'item';
    $id = (int) $m[1];
} else {
    respond(404, ['status' => 404, 'message' => 'Not found.']);
}

// ---- Auth (each key gets its own private task list) -------------------------
// If API_KEYS is set (comma-separated list, as an environment variable or in
// config.local.php as 'api_keys'), ONLY those keys are accepted.
// If it is not set, any key of 6-100 characters is accepted.
$apiKey  = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
$allowed = getenv('API_KEYS') ?: ($config['api_keys'] ?? '');
$allowed = array_filter(array_map('trim', explode(',', (string) $allowed)));

if (strlen($apiKey) < 6 || strlen($apiKey) > 100) {
    respond(401, ['status' => 401, 'message' => 'Missing or invalid X-API-Key header.']);
}
if ($allowed) {
    $ok = false;
    foreach ($allowed as $k) {
        if (hash_equals($k, $apiKey)) {
            $ok = true;
        }
    }
    if (!$ok) {
        respond(401, ['status' => 401, 'message' => 'Invalid API key. Ask the API owner for your key.']);
    }
}

// ---- Database -------------------------------------------------------------
$isPg = ($config['driver'] === 'pgsql');
try {
    $port = $config['port'] !== '' ? ';port=' . $config['port'] : '';
    $dsn  = $isPg
        ? "pgsql:host={$config['host']}{$port};dbname={$config['name']};sslmode=prefer"
        : "mysql:host={$config['host']}{$port};dbname={$config['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    respond(500, ['status' => 500, 'message' => 'Database connection failed.']);
}

// Create the table automatically if it doesn't exist yet (no manual SQL import needed)
try {
    $pdo->exec(file_get_contents(__DIR__ . '/schema_' . ($isPg ? 'pgsql' : 'mysql') . '.sql'));
} catch (Throwable $e) {
    // ignore: table probably exists / limited privileges
}

function find_task($pdo, $apiKey, $id)
{
    $s = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND api_key = ?');
    $s->execute([$id, $apiKey]);
    return $s->fetch();
}

// Body: JSON, or form-encoded as fallback
$input = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $input = $json;
    } elseif ($method === 'POST') {
        $input = $_POST;
    } else {
        parse_str($raw, $input);
    }
}

try {
    // ---- GET /tasks ---------------------------------------------------------
    if ($route === 'collection' && $method === 'GET') {
        $where  = ['api_key = ?'];
        $params = [$apiKey];

        if (!empty($_GET['status']) && in_array($_GET['status'], ['pending', 'done'], true)) {
            $where[] = 'status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['due'])) {
            $today = date('Y-m-d');
            switch ($_GET['due']) {
                case 'today':    $where[] = 'due_date = ?'; $params[] = $today; break;
                case 'overdue':  $where[] = "due_date < ? AND status = 'pending'"; $params[] = $today; break;
                case 'upcoming': $where[] = 'due_date > ?'; $params[] = $today; break;
                case 'none':     $where[] = 'due_date IS NULL'; break;
            }
        }
        if (!empty($_GET['from']) && valid_date($_GET['from'])) { $where[] = 'due_date >= ?'; $params[] = $_GET['from']; }
        if (!empty($_GET['to'])   && valid_date($_GET['to']))   { $where[] = 'due_date <= ?'; $params[] = $_GET['to']; }
        if (!empty($_GET['q'])) {
            $op = $isPg ? 'ILIKE' : 'LIKE';
            $where[] = "(title $op ? OR description $op ?)";
            $like = '%' . $_GET['q'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sortBy = in_array($_GET['sort_by'] ?? '', ['due_date', 'created_at', 'title', 'status'], true) ? $_GET['sort_by'] : 'created_at';
        $order  = strtolower($_GET['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $perPage = min(max((int) ($_GET['per_page'] ?? 20), 1), 100);
        $page    = max((int) ($_GET['page'] ?? 1), 1);
        $offset  = ($page - 1) * $perPage;
        $w = implode(' AND ', $where);

        $c = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE $w");
        $c->execute($params);
        $total = (int) $c->fetchColumn();

        $s = $pdo->prepare("SELECT * FROM tasks WHERE $w ORDER BY $sortBy $order, id DESC LIMIT $perPage OFFSET $offset");
        $s->execute($params);

        respond(200, [
            'status' => 200,
            'data'   => array_map('clean', $s->fetchAll()),
            'meta'   => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / $perPage)],
        ]);
    }

    // ---- POST /tasks --------------------------------------------------------
    if ($route === 'collection' && $method === 'POST') {
        $errors = validate($input, false);
        if ($errors) respond(422, ['status' => 422, 'errors' => $errors]);

        $s = $pdo->prepare('INSERT INTO tasks (api_key, title, description, due_date, status) VALUES (?, ?, ?, ?, ?)' . ($isPg ? ' RETURNING id' : ''));
        $s->execute([
            $apiKey,
            trim($input['title']),
            isset($input['description']) && $input['description'] !== '' ? $input['description'] : null,
            !empty($input['due_date']) ? $input['due_date'] : null,
            $input['status'] ?? 'pending',
        ]);
        $newId = $isPg ? (int) $s->fetchColumn() : (int) $pdo->lastInsertId();
        respond(201, ['status' => 201, 'message' => 'Task created.', 'data' => clean(find_task($pdo, $apiKey, $newId))]);
    }

    // ---- /tasks/{id} --------------------------------------------------------
    if ($route === 'item') {
        $task = find_task($pdo, $apiKey, $id);
        if (!$task) respond(404, ['status' => 404, 'message' => 'Task not found.']);

        if ($method === 'GET') {
            respond(200, ['status' => 200, 'data' => clean($task)]);
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            $errors = validate($input, true);
            if ($errors) respond(422, ['status' => 422, 'errors' => $errors]);

            $sets = [];
            $vals = [];
            if (array_key_exists('title', $input))       { $sets[] = 'title = ?';       $vals[] = trim($input['title']); }
            if (array_key_exists('description', $input)) { $sets[] = 'description = ?'; $vals[] = $input['description'] !== '' ? $input['description'] : null; }
            if (array_key_exists('due_date', $input))    { $sets[] = 'due_date = ?';    $vals[] = !empty($input['due_date']) ? $input['due_date'] : null; }
            if (array_key_exists('status', $input))      { $sets[] = 'status = ?';      $vals[] = $input['status']; }
            if (!$sets) respond(422, ['status' => 422, 'message' => 'Nothing to update.']);
            $sets[] = 'updated_at = CURRENT_TIMESTAMP';

            $vals[] = $id;
            $vals[] = $apiKey;
            $pdo->prepare('UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = ? AND api_key = ?')->execute($vals);
            respond(200, ['status' => 200, 'message' => 'Task updated.', 'data' => clean(find_task($pdo, $apiKey, $id))]);
        }

        if ($method === 'DELETE') {
            $pdo->prepare('DELETE FROM tasks WHERE id = ? AND api_key = ?')->execute([$id, $apiKey]);
            respond(200, ['status' => 200, 'message' => 'Task deleted.']);
        }
    }

    respond(405, ['status' => 405, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    respond(500, ['status' => 500, 'message' => 'Server error.']);
}
