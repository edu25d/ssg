<?php
/**
 * api/classes/delete_kiosk_account.php
 * 키오스크 입력기 계정 삭제
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

$accountId = (int)($_POST['account_id'] ?? 0);
if (!$accountId) {
    jsonError('계정 ID가 필요합니다.');
}

try {
    $stmt = $pdo->prepare("
        DELETE k FROM kiosk_accounts k
        JOIN classes c ON k.class_id = c.id
        WHERE k.id = :account_id AND c.user_id = :user_id
    ");
    $stmt->execute([
        'account_id' => $accountId,
        'user_id'    => $userId
    ]);

    jsonSuccess([], '입력기 계정이 삭제되었습니다.');
} catch (Exception $e) {
    jsonError('입력기 계정 삭제 오류: ' . $e->getMessage());
}
