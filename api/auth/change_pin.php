<?php
/**
 * api/auth/change_pin.php
 * 학생 비밀번호(PIN 4자리) 변경 처리
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$studentId = $_SESSION['student_id'] ?? null;
if (!$studentId) {
    jsonError('학생 로그인이 필요합니다.', 401);
}

$input = getJsonInput();
if (empty($input)) {
    $input = $_POST;
}

$newPin = trim($input['pin'] ?? $input['new_pin'] ?? '');

if (strlen($newPin) !== 4 || !ctype_digit($newPin)) {
    jsonError('PIN은 숫자 4자리여야 합니다.');
}

try {
    $stmt = $pdo->prepare("UPDATE students SET pin = :pin, is_initial_pin = 0 WHERE id = :id");
    $stmt->execute([
        'pin' => $newPin,
        'id'  => $studentId
    ]);

    jsonSuccess([], '비밀번호가 성공적으로 변경되었습니다.');
} catch (Exception $e) {
    jsonError('비밀번호 변경 실패: ' . $e->getMessage());
}
