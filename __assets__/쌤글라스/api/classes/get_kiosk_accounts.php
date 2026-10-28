<?php
/**
 * api/classes/get_kiosk_accounts.php
 * 특정 학급의 키오스크 입력기 계정 목록 조회
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();
$classId = (int)($_GET['class_id'] ?? 0);

if (!$classId) {
    jsonError('학급 ID가 누락되었습니다.');
}

try {
    $stmt = $pdo->prepare("
        SELECT k.id, k.class_id, k.kiosk_id
        FROM kiosk_accounts k
        JOIN classes c ON k.class_id = c.id
        WHERE c.id = :class_id AND c.user_id = :user_id
        ORDER BY k.id ASC
    ");
    $stmt->execute([
        'class_id' => $classId,
        'user_id'  => $userId
    ]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess(['accounts' => $accounts]);
} catch (Exception $e) {
    jsonError('키오스크 계정 조회 오류: ' . $e->getMessage());
}
