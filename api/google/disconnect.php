<?php
/**
 * api/google/disconnect.php
 * 구글 드라이브 연동 해제 (토큰 및 폴더 ID 초기화)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

try {
    $stmt = $pdo->prepare("
        UPDATE users 
        SET google_access_token = NULL, 
            google_refresh_token = NULL, 
            google_root_folder_id = NULL 
        WHERE id = :id
    ");
    $stmt->execute(['id' => $userId]);

    echo "<script>alert('구글 드라이브 연동이 해제되었습니다.'); window.location.href='/views/dashboard/main.php';</script>";
} catch (PDOException $e) {
    echo "<script>alert('해제 중 오류: " . addslashes($e->getMessage()) . "'); window.location.href='/views/dashboard/main.php';</script>";
}
exit;
