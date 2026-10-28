<?php
/**
 * api/classes/get_status.php
 * 교사 대시보드 실시간 현황(제출수, 검토대기수) 초경량 폴링 엔드포인트
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

try {
    $stmt = $pdo->prepare("
        SELECT a.id AS assignment_id,
               (SELECT COUNT(*) FROM exam_results er WHERE er.assignment_id = a.id) AS submitted_count,
               (SELECT COUNT(*) FROM exam_results er WHERE er.assignment_id = a.id AND er.is_reviewed = 0) AS pending_review_count
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        WHERE t.user_id = :u
    ");
    $stmt->execute(['u' => $userId]);
    $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess(['assignments' => $statuses]);
} catch (Exception $e) {
    jsonError('상태 조회 오류: ' . $e->getMessage());
}
