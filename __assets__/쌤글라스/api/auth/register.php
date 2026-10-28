<?php
/**
 * api/auth/register.php
 * 교사 회원가입 처리 및 아이디 중복 확인
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

// 1. GET 요청: 아이디 중복 검증
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $username = trim($_GET['username'] ?? '');

    if (!$username) {
        jsonSuccess(['exists' => false]);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $count = (int)$stmt->fetchColumn();

    jsonSuccess(['exists' => $count > 0]);
}

// 2. POST 요청: 회원가입 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isJson = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
           || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

    $input = $isJson ? getJsonInput() : $_POST;

    $name        = trim($input['name'] ?? '');
    $username    = trim($input['username'] ?? '');
    $school_name = trim($input['school_name'] ?? '');
    $password    = $input['password'] ?? '';

    if (!$name || !$username || !$school_name || !$password) {
        if ($isJson) {
            jsonError('필수 항목을 모두 입력해 주세요.');
        }
        echo "<script>alert('필수 항목을 모두 입력해 주세요.'); history.back();</script>";
        exit;
    }

    // 서버 측 중복 체크
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    if ($stmt->fetch()) {
        if ($isJson) {
            jsonError('이미 존재하는 아이디입니다.');
        }
        echo "<script>alert('이미 존재하는 아이디입니다.'); history.back();</script>";
        exit;
    }

    // 비밀번호 해싱 및 DB 삽입
    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("INSERT INTO users (name, username, school_name, password_hash) VALUES (:name, :username, :school_name, :hash)");
    $stmt->execute([
        'name'        => $name,
        'username'    => $username,
        'school_name' => $school_name,
        'hash'        => $password_hash
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    // 세션 설정
    $_SESSION['user_id']  = $newUserId;
    $_SESSION['username'] = $username;
    $_SESSION['name']     = $name;
    $_SESSION['role']     = 'teacher';

    $dashUrl = "/views/dashboard/main.php";
    if ($isJson) {
        jsonSuccess(['redirect' => $dashUrl]);
    }
    header("Location: {$dashUrl}");
    exit;
}
