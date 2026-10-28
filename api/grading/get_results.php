<?php
/**
 * api/grading/get_results.php
 * 실시간 채점 결과 폴링 엔드포인트
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

$assignmentId = (int)($_GET['assign_id'] ?? 0);
$lastId       = (int)($_GET['last_id'] ?? 0);

if (!$assignmentId) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT e.id, e.assignment_id, e.student_id, 
               COALESCE(s.name, '학생') AS student_name, 
               e.total_score, e.is_reviewed, e.raw_results, e.submitted_at 
        FROM exam_results e
        LEFT JOIN students s ON e.student_id = s.id
        WHERE e.assignment_id = :assign_id 
        ORDER BY e.id ASC
    ");

    $stmt->execute([
        'assign_id' => $assignmentId
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['raw_results'] = json_decode($row['raw_results'] ?? '[]', true);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    jsonError('채점 결과 조회 실패: ' . $e->getMessage());
}
