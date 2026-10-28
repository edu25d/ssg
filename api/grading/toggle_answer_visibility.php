<?php
/**
 * api/grading/toggle_answer_visibility.php
 * 교사가 특정 과제의 모범 답안을 학생에게 공개/비공개 토글하는 API
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/response.php';

$userId = requireTeacherAuth();
$input = getJsonInput();

$assignId   = (int)($input['assignment_id'] ?? 0);
$showAnswer = !empty($input['show_answer']) ? 1 : 0;

if (!$assignId) {
    jsonError('과제 ID가 필요합니다.');
}

try {
    // show_answer 컬럼 부재 시 자동 추가 (Self-Healing)
    try {
        $pdo->query("SELECT show_answer FROM assignments LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN show_answer TINYINT(1) NOT NULL DEFAULT 0");
    }

    // 소유권 확인 (교사 본인의 템플릿으로 배정된 과제인지 검증)
    $stmt = $pdo->prepare("
        SELECT a.id 
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        WHERE a.id = :aid AND t.user_id = :uid
    ");
    $stmt->execute(['aid' => $assignId, 'uid' => $userId]);
    if (!$stmt->fetch()) {
        jsonError('해당 과제에 대한 수정 권한이 없습니다.', 403);
    }

    $updateStmt = $pdo->prepare("UPDATE assignments SET show_answer = :show WHERE id = :aid");
    $updateStmt->execute([
        'show' => $showAnswer,
        'aid'  => $assignId
    ]);

    jsonSuccess([
        'assignment_id' => $assignId,
        'show_answer'   => $showAnswer
    ], $showAnswer ? '학생에게 모범 답안이 공개되었습니다.' : '학생 화면에서 모범 답안이 숨김 처리되었습니다.');

} catch (Exception $e) {
    jsonError('설정 저장 실패: ' . $e->getMessage());
}
