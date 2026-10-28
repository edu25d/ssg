<?php
/**
 * api/grading/delete_temp_image.php
 * 캐시된 임시 이미지 삭제 API
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

$resultId    = (int)($_REQUEST['db_id'] ?? 0);
$driveFileId = trim($_REQUEST['drive_file_id'] ?? '');

if (!$resultId && !$driveFileId) {
    jsonError('필수 파라미터가 누락되었습니다.');
}

if (empty($driveFileId) && $resultId > 0) {
    $stmt = $pdo->prepare("SELECT raw_results FROM exam_results WHERE id = :id");
    $stmt->execute(['id' => $resultId]);
    $data = $stmt->fetchColumn();
    
    if ($data) {
        $rawResults  = json_decode($data, true) ?: [];
        $imgPath     = $rawResults[0]['image_path'] ?? '';
        $driveFileId = $rawResults[0]['drive_file_id'] ?? '';
        
        if (!$driveFileId && strpos($imgPath, 'drive:') === 0) {
            $driveFileId = str_replace('drive:', '', $imgPath);
        }
    }
}

if (empty($driveFileId)) {
    jsonSuccess([], '삭제할 캐시 파일이 없거나 구글 드라이브 이미지가 아닙니다.');
}

$cacheDir     = APP_ROOT . '/public/uploads/temp_cache';
$physicalPath = $cacheDir . '/' . $driveFileId . '.jpg';

if (file_exists($physicalPath)) {
    if (@unlink($physicalPath)) {
        jsonSuccess([], '임시 파일이 성공적으로 삭제되었습니다.');
    } else {
        jsonError('파일 삭제 권한이 없습니다.');
    }
} else {
    jsonSuccess([], '이미 삭제되었거나 존재하지 않는 파일입니다.');
}
