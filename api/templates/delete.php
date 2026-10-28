<?php
/**
 * api/templates/delete.php
 * 평가지 템플릿 안전 삭제 엔드포인트
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/response.php';

$userId = requireTeacherAuth();

$inputData  = getJsonInput();
$templateId = (int)($inputData['template_id'] ?? ($_POST['template_id'] ?? 0));

if (!$templateId) {
    jsonError('삭제할 템플릿 ID가 누락되었습니다.', 400);
}

try {
    // 1. 소유권 확인
    $stmt = $pdo->prepare("SELECT id, title, image_path FROM templates WHERE id = :id AND user_id = :u");
    $stmt->execute(['id' => $templateId, 'u' => $userId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        jsonError('해당 템플릿을 찾을 수 없거나 삭제 권한이 없습니다.', 404);
    }

    // 2. 이미 배정된 평가가 있는지 확인
    $checkAssign = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE template_id = :tid");
    $checkAssign->execute(['tid' => $templateId]);
    $assignCount = (int)$checkAssign->fetchColumn();

    if ($assignCount > 0) {
        jsonError("해당 템플릿으로 배정된 평가({$assignCount}건)가 존재하여 삭제할 수 없습니다.", 400);
    }

    // 3. 템플릿 삭제
    $delStmt = $pdo->prepare("DELETE FROM templates WHERE id = :id AND user_id = :u");
    $delStmt->execute(['id' => $templateId, 'u' => $userId]);

    // 원본 이미지 파일 정리
    if (!empty($template['image_path'])) {
        $realPath = APP_ROOT . $template['image_path'];
        if (file_exists($realPath)) {
            @unlink($realPath);
        }
    }

    jsonSuccess([], "'{$template['title']}' 템플릿이 삭제되었습니다.");

} catch (Exception $e) {
    jsonError('삭제 실패: ' . $e->getMessage(), 500);
}

