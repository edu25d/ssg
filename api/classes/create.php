<?php
/**
 * api/classes/create.php
 * 학급 생성: 구글 드라이브 폴더 생성 및 마스터 시트 복사 연동
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
$rawNames = trim($input['class_names'] ?? '');
$classNames = empty($rawNames) ? ['우리반'] : array_filter(array_map('trim', explode(',', $rawNames)));

$token = getValidAccessToken($pdo, $userId);
$stmt = $pdo->prepare("SELECT google_root_folder_id FROM users WHERE id = :id");
$stmt->execute(['id' => $userId]);
$rootFolderId = $stmt->fetchColumn();

if (!$token || !$rootFolderId) {
    if ($isJson) {
        jsonError('구글 드라이브 연결 상태를 먼저 확인하세요.');
    }
    echo "<script>alert('구글 드라이브 연결 상태를 먼저 확인하세요.'); history.back();</script>";
    exit;
}

$masterSheetId = env('GOOGLE_MASTER_SHEET_ID', '1lxwqbkb3aRl1-wTL1kl1UKXBKPdn-jx0lfrKK9eIpCI');

$createdClasses = [];
foreach ($classNames as $name) {
    // 1. 학급 전용 폴더 생성
    $folderId = createDriveFolder($token, $name, $rootFolderId);
    if (!$folderId) {
        if ($isJson) {
            jsonError("학급 [{$name}]의 드라이브 폴더 생성에 실패했습니다.");
        }
        echo "<script>alert('학급 폴더 생성에 실패했습니다.'); history.back();</script>";
        exit;
    }

    // 2. 마스터 시트 복사
    $copyResult = copyMasterSheetToFolder($token, $masterSheetId, $folderId, $name);
    $sheetId = $copyResult['id'] ?? null;

    if ($sheetId) {
        $ins = $pdo->prepare("INSERT INTO classes (user_id, class_name, drive_folder_id, sheet_id) VALUES (:u, :c, :f, :s)");
        $ins->execute([
            'u' => $userId,
            'c' => $name,
            'f' => $folderId,
            's' => $sheetId
        ]);
        $createdClasses[] = ['name' => $name, 'class_id' => $pdo->lastInsertId()];
    } else {
        $errMsg = "마스터 시트 복사 오류: " . ($copyResult['error']['message'] ?? '권한 또는 시트 ID 확인 필요');
        if ($isJson) {
            jsonError($errMsg);
        }
        echo "<script>alert('" . addslashes($errMsg) . "'); history.back();</script>";
        exit;
    }
}

if ($isJson) {
    jsonSuccess(['created' => $createdClasses], '학급이 성공적으로 생성되었습니다.');
}

header("Location: /views/dashboard/main.php");
exit;
