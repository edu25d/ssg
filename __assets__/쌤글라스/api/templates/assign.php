<?php
/**
 * api/templates/assign.php
 * 평가 템플릿의 단일/복수 학급 배정 처리
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /views/dashboard/main.php");
    exit;
}

$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
       || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

$input = $isJson ? getJsonInput() : $_POST;

$templateId = (int)($input['template_id'] ?? 0);
$classIds   = $input['class_ids'] ?? [];

if (!$templateId || empty($classIds) || !is_array($classIds)) {
    if ($isJson) {
        jsonError('템플릿과 1개 이상의 학급을 선택해주세요.');
    }
    echo "<script>alert('템플릿과 1개 이상의 학급을 선택해주세요.'); history.back();</script>";
    exit;
}

try {
    $pdo->beginTransaction();

    $tplStmt = $pdo->prepare("SELECT title FROM templates WHERE id = :tid AND user_id = :u");
    $tplStmt->execute(['tid' => $templateId, 'u' => $userId]);
    $baseTitle = $tplStmt->fetchColumn();

    if (!$baseTitle) {
        throw new Exception("해당 템플릿 정보를 찾을 수 없습니다.");
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM assignments 
        WHERE class_id = :cid AND template_id = :tid AND status != 'deleted'
    ");

    $insertStmt = $pdo->prepare("
        INSERT INTO assignments (template_id, class_id, exam_name, assignment_code, status)
        VALUES (:tid, :cid, :exam_name, :code, 'in_progress')
    ");

    $successCount = 0;
    foreach ($classIds as $cid) {
        $classId = (int)$cid;
        if (!$classId) continue;

        $countStmt->execute(['cid' => $classId, 'tid' => $templateId]);
        $existingCount = (int)$countStmt->fetchColumn();

        $examName = ($existingCount > 0) ? "{$baseTitle} (" . ($existingCount + 1) . ")" : $baseTitle;
        $assignmentCode = bin2hex(random_bytes(16));

        $insertStmt->execute([
            'tid'       => $templateId,
            'cid'       => $classId,
            'exam_name' => $examName,
            'code'      => $assignmentCode
        ]);
        $successCount++;
    }

    $pdo->commit();

    if ($isJson) {
        jsonSuccess(['assigned_count' => $successCount], "총 {$successCount}개 학급에 평가가 배정되었습니다.");
    }

    echo "<script>alert('총 {$successCount}개 학급에 평가가 성공적으로 배정 및 시작되었습니다.'); location.href='/views/dashboard/main.php';</script>";
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($isJson) {
        jsonError('배정 처리 오류: ' . $e->getMessage());
    }
    echo "<script>alert('DB 저장 오류가 발생했습니다: " . addslashes($e->getMessage()) . "'); history.back();</script>";
    exit;
}
