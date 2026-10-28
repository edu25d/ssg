<?php
/**
 * api/google/sync_sheet.php
 * 평가 마감 처리: 구글 시트로 최종 점수 동기화 및 서버 이미지 정리
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

$input = getJsonInput();
if (empty($input)) {
    $input = $_REQUEST;
}

$assignId = (int)($input['assign_id'] ?? $input['assignment_id'] ?? 0);

if (!$assignId) {
    jsonError('평가 ID가 누락되었습니다.');
}

try {
    $accessToken = getValidAccessToken($pdo, $userId);
    if (!$accessToken) {
        throw new Exception("구글 연동이 만료되었거나 연결되어 있지 않습니다.");
    }

    $stmt = $pdo->prepare("
        SELECT 
            t.title AS exam_name, 
            c.sheet_id, 
            c.class_name, 
            u.google_root_folder_id
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        JOIN classes c ON a.class_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE a.id = :assign_id AND c.user_id = :user_id
    ");
    $stmt->execute([
        'assign_id' => $assignId,
        'user_id'   => $userId
    ]);
    $assignInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignInfo || empty($assignInfo['sheet_id'])) {
        throw new Exception("연동된 구글 시트 정보를 찾을 수 없습니다.");
    }

    $examName      = $assignInfo['exam_name'];
    $spreadsheetId = $assignInfo['sheet_id'];

    // 채점 결과 전체 조회
    $resStmt = $pdo->prepare("
        SELECT 
            e.id, 
            COALESCE(s.name, '학생') AS student_name, 
            e.total_score, 
            e.raw_results
        FROM exam_results e
        LEFT JOIN students s ON e.student_id = s.id
        WHERE e.assignment_id = :assign_id
        ORDER BY e.id ASC
    ");
    $resStmt->execute(['assign_id' => $assignId]);
    $results = $resStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)) {
        throw new Exception("마감할 채점 결과 데이터가 존재하지 않습니다.");
    }

    // 구글 시트에 기록
    $syncResult = writeExamScoresToSheet($accessToken, $spreadsheetId, $examName, $results);
    if (!$syncResult) {
        throw new Exception("구글 시트 데이터 전송에 실패했습니다.");
    }

    $sheetGid = is_array($syncResult) ? ($syncResult['sheet_gid'] ?? null) : null;

    // 서버 로컬 이미지 정리 (용량 절약)
    $cropsDir = APP_ROOT . '/public/uploads/crops/' . $assignId;
    if (is_dir($cropsDir)) {
        $files = glob($cropsDir . '/*');
        foreach ($files as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($cropsDir);
    }

    // sheet_gid 컬럼 부재 시 자동 추가 (Self-Healing)
    try {
        $pdo->query("SELECT sheet_gid FROM assignments LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN sheet_gid VARCHAR(64) NULL DEFAULT NULL");
    }

    // 평가 상태 'completed' 및 sheet_gid 저장
    $upStmt = $pdo->prepare("UPDATE assignments SET status = 'completed', sheet_gid = :sheet_gid WHERE id = :id");
    $upStmt->execute([
        'sheet_gid' => $sheetGid !== null ? (string)$sheetGid : null,
        'id'        => $assignId
    ]);

    jsonSuccess([], '평가가 성공적으로 마감되었으며 구글 시트에 백업되었습니다.');

} catch (Exception $e) {
    jsonError('마감 오류: ' . $e->getMessage());
}
