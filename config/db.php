<?php
/**
 * config/db.php
 * 전역 데이터베이스 연결 객체 (PDO) 및 세션 초기화
 */

require_once __DIR__ . '/env.php';
require_once APP_ROOT . '/includes/common/utils.php';

// 세션 초기화 (CLI 환경 제외)
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_lifetime', 0);
    ini_set('session.gc_maxlifetime', 1800);
    session_start();

    // 30분 무활동 자동 로그아웃 (단, 순수 JSON API 요청 시에는 401 JSON 응답 또는 갱신)
    $timeout = 1800;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        session_unset();
        session_destroy();
        
        $isApi = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
              || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
              || (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false);

        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => '세션이 만료되었습니다. 다시 로그인해주세요.', 'session_expired' => true], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            header("Location: /index.php?timeout=1");
            exit;
        }
    }
    $_SESSION['last_activity'] = time();
}

$db_driver = env('DB_DRIVER', 'mysql');

try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    if ($db_driver === 'sqlite') {
        $sqlitePath = env('DB_SQLITE_PATH', APP_ROOT . '/database/dev.sqlite');
        if ($sqlitePath[0] !== '/') {
            $sqlitePath = APP_ROOT . '/' . $sqlitePath;
        }
        $sqliteDir = dirname($sqlitePath);
        if (!is_dir($sqliteDir)) {
            mkdir($sqliteDir, 0775, true);
        }

        if (class_exists('Pdo\\Sqlite')) {
            $pdo = new \Pdo\Sqlite('sqlite:' . $sqlitePath, null, null, $options);
            $pdo->createFunction('NOW', static fn() => date('Y-m-d H:i:s'));
        } else {
            $pdo = new PDO('sqlite:' . $sqlitePath, null, null, $options);
            $pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
        }
        $pdo->exec('PRAGMA foreign_keys = ON');

        $hasUsersTable = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
        if (!$hasUsersTable) {
            $schema = file_get_contents(APP_ROOT . '/database/schema.sqlite.sql');
            $pdo->exec($schema);
        }
    } else {
        $db_host = env('DB_HOST', 'localhost');
        $db_port = (int)env('DB_PORT', 3306);
        $db_user = env('DB_USER', 'jinsu2');
        $db_pass = env('DB_PASS', '');
        $db_name = env('DB_NAME', 'jinsu2');
        $options[PDO::ATTR_EMULATE_PREPARES] = false;

        $pdo = new PDO("mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, $options);
    }

    // 무중단 다쪽 평가지 자가 치유 스키마 마이그레이션 (Gate 1)
    require_once APP_ROOT . '/includes/common/db_migration.php';
    ensureMultiPageSchema($pdo);
} catch (PDOException $e) {
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => '데이터베이스 연결 실패: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    die("DB 연결 실패: " . $e->getMessage());
}
