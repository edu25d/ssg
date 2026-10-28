<?php
/**
 * api/google/restore_sheet.php
 * 구글 시트 및 드라이브 원본 파일로부터 채점 데이터 및 크롭 이미지 복원
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

$assignmentId = (int)($_REQUEST['assign_id'] ?? 0);
if (!$assignmentId) {
    jsonError('과제 ID가 누락되었습니다.');
}

try {
    $accessToken = getValidAccessToken($pdo, $userId);
    if (!$accessToken) {
        throw new Exception("구글 드라이브 연동이 만료되었거나 연결되어 있지 않습니다.");
    }

    $assignStmt = $pdo->prepare("
        SELECT a.class_id, c.class_name, t.title AS exam_name, c.sheet_id, c.drive_folder_id, t.roi_data 
        FROM assignments a 
        JOIN classes c ON a.class_id = c.id
        JOIN templates t ON a.template_id = t.id 
        WHERE a.id = :id AND c.user_id = :user_id
    ");
    $assignStmt->execute(['id' => $assignmentId, 'user_id' => $userId]);
    $assignment = $assignStmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment || empty($assignment['sheet_id'])) {
        throw new Exception("배정 정보나 연결된 학급 시트를 찾을 수 없습니다.");
    }

    $studentRows = readExamScoresFromSheet($accessToken, $assignment['sheet_id'], $assignment['exam_name']);

    $classFolderId = $assignment['drive_folder_id'];
    $examName      = $assignment['exam_name'];
    
    // sheet 폴더 검색
    $sheetFolderQuery = urlencode("name = 'sheet' and '{$classFolderId}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false");
    $sheetUrl = "https://www.googleapis.com/drive/v3/files?q={$sheetFolderQuery}&fields=files(id)";
    
    $chSheet = curl_init($sheetUrl);
    curl_setopt_array($chSheet, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resSheet = json_decode(curl_exec($chSheet), true);
    curl_close($chSheet);
    
    $sheetFolderId = $resSheet['files'][0]['id'] ?? null;
    if (!$sheetFolderId) {
        throw new Exception("드라이브에서 'sheet' 폴더를 찾을 수 없습니다.");
    }

    $driveQuery = urlencode("name = '{$examName}' and '{$sheetFolderId}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false");
    $folderUrl = "https://www.googleapis.com/drive/v3/files?q={$driveQuery}&fields=files(id)";
    
    $ch = curl_init($folderUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    $targetFolderId = $res['files'][0]['id'] ?? null;
    if (!$targetFolderId) {
        throw new Exception("드라이브에서 해당 시험 폴더를 찾을 수 없습니다.");
    }

    $cropDir     = APP_ROOT . "/public/uploads/crops/{$assignmentId}/";
    $originalDir = APP_ROOT . "/public/uploads/originals/{$assignmentId}/";
    if (!is_dir($cropDir))     @mkdir($cropDir, 0777, true);
    if (!is_dir($originalDir)) @mkdir($originalDir, 0777, true);

    $roiList = json_decode($assignment['roi_data'], true) ?: [];
    $pdo->beginTransaction();

    foreach ($studentRows as $student) {
        $studentName = $student['student_name'];
        $fileName    = "{$examName}-{$studentName}.jpg";

        $stuStmt = $pdo->prepare("SELECT id FROM students WHERE class_id = :class_id AND name = :name");
        $stuStmt->execute(['class_id' => $assignment['class_id'], 'name' => $studentName]);
        $studentData = $stuStmt->fetch(PDO::FETCH_ASSOC);
        
        $studentId = $studentData['id'] ?? null;
        if (!$studentId) continue;

        $driveFileId = getDriveFileIdByName($accessToken, $targetFolderId, $fileName);
        if (!$driveFileId) continue;

        $tempOriginalPath = $originalDir . "student_restored_{$studentName}.jpg";
        if (!downloadDriveFile($accessToken, $driveFileId, $tempOriginalPath)) continue;

        $fullImageUrl = "/public/uploads/originals/{$assignmentId}/student_restored_{$studentName}.jpg";

        $img = @imagecreatefromjpeg($tempOriginalPath);
        if (!$img) continue;
        
        $scale = imagesx($img) / 1000;
        $reconstructedResults = json_decode($student['raw_results_json'], true) ?: [];

        foreach ($roiList as $idx => $roi) {
            $itemId = (string)($roi['id'] ?? $roi['item_id'] ?? $roi['num'] ?? ($idx + 1));
            $targetRoi = $roi['ans_roi'] ?? $roi['num_roi'] ?? $roi;
            
            $w = $targetRoi['w'] * $scale * 1.2;
            $h = $targetRoi['h'] * $scale * 1.2;
            $cx = ($targetRoi['x'] * $scale) + (($targetRoi['w'] * $scale) / 2);
            $cy = ($targetRoi['y'] * $scale) + (($targetRoi['h'] * $scale) / 2);
            
            $x = max(0, $cx - ($w / 2));
            $y = max(0, $cy - ($h / 2));
            
            $cropped = @imagecrop($img, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
            if ($cropped !== false) {
                $cropFileName = "student_{$studentName}_item_{$itemId}_" . uniqid() . ".jpg";
                imagejpeg($cropped, $cropDir . $cropFileName, 90);
                $cropUrl = "/public/uploads/crops/{$assignmentId}/" . $cropFileName;
                
                foreach ($reconstructedResults as &$resItem) {
                    if ((string)($resItem['id'] ?? $resItem['item_id']) === $itemId) {
                        $resItem['crop_url'] = $cropUrl;
                        $resItem['image_path'] = $fullImageUrl;
                    }
                }
                unset($resItem);
                imagedestroy($cropped);
            }
        }
        imagedestroy($img);

        // 이전 결과 삭제 후 재삽입
        $delStmt = $pdo->prepare("DELETE FROM exam_results WHERE assignment_id = :assign_id AND student_id = :student_id");
        $delStmt->execute(['assign_id' => $assignmentId, 'student_id' => $studentId]);

        $insStmt = $pdo->prepare("
            INSERT INTO exam_results (assignment_id, student_id, total_score, is_reviewed, raw_results, submitted_at) 
            VALUES (:assign_id, :student_id, :total_score, 0, :raw_results, NOW())
        ");
        $insStmt->execute([
            'assign_id'   => $assignmentId,
            'student_id'  => $studentId,
            'total_score' => $student['total_score'],
            'raw_results' => json_encode($reconstructedResults, JSON_UNESCAPED_UNICODE)
        ]);
    }

    $statusStmt = $pdo->prepare("UPDATE assignments SET status = 'in_progress' WHERE id = :assign_id");
    $statusStmt->execute(['assign_id' => $assignmentId]);

    $pdo->commit();
    jsonSuccess([], '데이터 및 이미지가 성공적으로 복원되었습니다.');

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('복원 실패: ' . $e->getMessage());
}
