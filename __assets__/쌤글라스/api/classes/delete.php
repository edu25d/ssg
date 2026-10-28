<?php
/**
 * api/classes/delete.php
 * 학급 비활성화(삭제) 처리
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
       || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
       || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

$input = $isJson ? getJsonInput() : $_REQUEST;
$classId = (int)($input['class_id'] ?? 0);

if (!$classId) {
    if ($isJson) {
        jsonError('삭제할 학급 ID가 전달되지 않았습니다.');
    }
    echo "<script>alert('삭제할 학급 ID가 전달되지 않았습니다.'); history.back();</script>";
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE classes SET is_active = 0 WHERE id = :id AND user_id = :u");
    $stmt->execute(['id' => $classId, 'u' => $userId]);

    // 연관된 키오스크 계정도 비활성화
    $kStmt = $pdo->prepare("UPDATE kiosk_accounts SET is_active = 0 WHERE class_id = :cid");
    $kStmt->execute(['cid' => $classId]);

    if ($isJson) {
        jsonSuccess(['class_id' => $classId], '학급이 삭제되었습니다.');
    }

    header("Location: /views/dashboard/main.php");
    exit;
} catch (Exception $e) {
    if ($isJson) {
        jsonError('삭제 중 오류 발생: ' . $e->getMessage());
    }
    echo "<script>alert('삭제 중 오류: " . addslashes($e->getMessage()) . "'); history.back();</script>";
    exit;
}
