<?php
/**
 * api/classes/update.php
 * 학급 정보(학급명 등) 수정 및 구글 드라이브 동기화
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /views/dashboard/main.php");
    exit;
}

$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
       || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

$input = $isJson ? getJsonInput() : $_POST;

$classId = (int)($input['class_id'] ?? 0);
$newName = str_replace(',', '', trim($input['class_name'] ?? ''));

if ($classId <= 0 || $newName === '') {
    if ($isJson) {
        jsonError('학급 ID 또는 새 학급명이 올바르지 않습니다.');
    }
    header("Location: /views/dashboard/main.php");
    exit;
}

$token = getValidAccessToken($pdo, $userId);

$stmt = $pdo->prepare("SELECT drive_folder_id, sheet_id, class_name FROM classes WHERE id = :id AND user_id = :u AND is_active = 1");
$stmt->execute(['id' => $classId, 'u' => $userId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing && $existing['class_name'] !== $newName) {
    if ($token) {
        if (!empty($existing['drive_folder_id'])) {
            renameDriveItem($token, $existing['drive_folder_id'], $newName);
        }
        if (!empty($existing['sheet_id'])) {
            renameDriveItem($token, $existing['sheet_id'], "[쌤글래스] " . $newName);
        }
    }

    $up = $pdo->prepare("UPDATE classes SET class_name = :n WHERE id = :id AND user_id = :u");
    $up->execute(['n' => $newName, 'id' => $classId, 'u' => $userId]);
}

if ($isJson) {
    jsonSuccess(['class_id' => $classId, 'class_name' => $newName], '학급명이 수정되었습니다.');
}

header("Location: /views/dashboard/main.php");
exit;
