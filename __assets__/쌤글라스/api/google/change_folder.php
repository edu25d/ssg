<?php
/**
 * api/google/change_folder.php
 * 교사의 구글 드라이브 작업 폴더 변경 및 기존 하위 파일 이전
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /views/dashboard/main.php");
    exit;
}

$newFolderId   = trim($_POST['new_folder_id'] ?? '');
$newFolderName = trim($_POST['new_folder_name'] ?? '선택된 폴더');

if (empty($newFolderId)) {
    echo "<script>alert('이동할 폴더를 선택해주세요.'); history.back();</script>";
    exit;
}

$token = getValidAccessToken($pdo, $userId);
if (!$token) {
    echo "<script>alert('구글 연동 상태를 확인해주세요.'); history.back();</script>";
    exit;
}

$stmt = $pdo->prepare("SELECT google_root_folder_id FROM users WHERE id = :id");
$stmt->execute(['id' => $userId]);
$oldFolderId = $stmt->fetchColumn();

// 기존 폴더 항목이 있다면 새 폴더 위치로 이전
if ($oldFolderId && $oldFolderId !== $newFolderId) {
    $query = urlencode("'{$oldFolderId}' in parents and trashed=false");
    $url = "https://www.googleapis.com/drive/v3/files?q={$query}&fields=files(id)";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($res['files'])) {
        foreach ($res['files'] as $file) {
            $fileId = $file['id'];
            $moveUrl = "https://www.googleapis.com/drive/v3/files/{$fileId}?addParents={$newFolderId}&removeParents={$oldFolderId}";
            $mCh = curl_init($moveUrl);
            curl_setopt_array($mCh, [
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            curl_exec($mCh);
            curl_close($mCh);
        }
    }
}

// DB 업데이트
$updateStmt = $pdo->prepare("UPDATE users SET google_root_folder_id = :fid, google_folder_name = :fname WHERE id = :id");
$updateStmt->execute(['fid' => $newFolderId, 'fname' => $newFolderName, 'id' => $userId]);

echo "<script>alert('선택하신 폴더로 변경 및 이동이 완료되었습니다!'); window.location.href='/views/dashboard/main.php';</script>";
exit;
