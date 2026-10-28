<?php
/**
 * api/google/connect.php
 * 구글 드라이브 루트 폴더 생성 및 연결
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

$token = getValidAccessToken($pdo, $userId);
if (!$token) {
    echo "<script>alert('구글 연동이 먼저 필요합니다.'); window.location.href='/views/dashboard/main.php';</script>";
    exit;
}

$folderId = createDriveFolder($token, '쌤글라스');

if ($folderId) {
    $updateStmt = $pdo->prepare("UPDATE users SET google_root_folder_id = :fid, google_folder_name = '쌤글라스' WHERE id = :id");
    $updateStmt->execute(['fid' => $folderId, 'id' => $userId]);
    
    echo "<script>alert('구글 드라이브에 [쌤글라스] 폴더가 성공적으로 생성 및 연결되었습니다.'); window.location.href='/views/dashboard/main.php';</script>";
} else {
    echo "<script>alert('폴더 생성 실패. Google Drive API 사용 설정을 확인하세요.'); window.location.href='/views/dashboard/main.php';</script>";
}
exit;
