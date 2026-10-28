<?php
/**
 * api/auth/verify_kiosk_logout.php
 * 키오스크 종료(로그아웃) 시 교사 비밀번호 검증 API
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$kioskClassId = (int)($_SESSION['kiosk_class_id'] ?? 0);
$teacherId    = (int)($_SESSION['teacher_id'] ?? 0);

if (!$kioskClassId && !$teacherId) {
    jsonError('활성화된 키오스크 세션이 없습니다.', 401);
}

$input = getJsonInput();
$password = trim($input['password'] ?? ($_POST['password'] ?? ''));

if ($password === '') {
    jsonError('비밀번호를 입력해 주세요.');
}

try {
    if ($teacherId > 0) {
        $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE id = :id");
        $stmt->execute(['id' => $teacherId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT u.id, u.password_hash 
            FROM users u
            JOIN classes c ON c.user_id = u.id
            WHERE c.id = :cid
        ");
        $stmt->execute(['cid' => $kioskClassId]);
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['password_hash'])) {
        jsonError('교사 계정 정보를 확인할 수 없습니다.');
    }

    if (!password_verify($password, $user['password_hash'])) {
        jsonError('선생님 비밀번호가 일치하지 않습니다.');
    }

    jsonSuccess(['verified' => true], '인증되었습니다. 키오스크를 종료합니다.');

} catch (Exception $e) {
    jsonError('인증 처리 중 오류가 발생했습니다: ' . $e->getMessage());
}
