<?php
/**
 * api/classes/update_kiosk_account.php
 * 키오스크 입력기 ID 식별자(suffix) 수정
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

$accountId = (int)($_POST['account_id'] ?? 0);
$suffix    = trim($_POST['suffix'] ?? '');

if (!$accountId || $suffix === '') {
    jsonError('계정 ID와 새 식별자가 필요합니다.');
}

try {
    // 교사의 username 조회
    $uStmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
    $uStmt->execute(['id' => $userId]);
    $teacherUsername = $uStmt->fetchColumn();

    $newKioskId = $teacherUsername . '-' . $suffix;

    // 해당 계정이 이 교사의 학급에 속해 있는지 검증 후 수정
    $stmt = $pdo->prepare("
        UPDATE kiosk_accounts k
        JOIN classes c ON k.class_id = c.id
        SET k.kiosk_id = :kiosk_id
        WHERE k.id = :account_id AND c.user_id = :user_id
    ");
    $stmt->execute([
        'kiosk_id'   => $newKioskId,
        'account_id' => $accountId,
        'user_id'    => $userId
    ]);

    jsonSuccess(['new_kiosk_id' => $newKioskId], '입력기 ID가 변경되었습니다.');
} catch (Exception $e) {
    jsonError('입력기 ID 수정 오류: ' . $e->getMessage());
}
