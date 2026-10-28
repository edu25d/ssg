<?php
/**
 * api/student/get_my_status.php
 * 학생 대시보드용 초경량 실시간 상태 폴링 엔드포인트
 * - AI 채점 완료, 교사 주관식 채점 반영, 모범답안 공개 여부 등을 실시간 동기화
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/response.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$classId = (int)($_SESSION['class_id'] ?? 0);
$studentId = requireStudentAuth($classId);

if ($classId <= 0 && $studentId > 0) {
    $cStmt = $pdo->prepare("SELECT class_id FROM students WHERE id = :sid");
    $cStmt->execute(['sid' => $studentId]);
    $classId = (int)$cStmt->fetchColumn();
    $_SESSION['class_id'] = $classId;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    // show_answer 컬럼 부재 시 안전 쿼리
    try {
        $stmt = $pdo->prepare("
            SELECT a.id as assignment_id, t.title, a.status as assign_status,
                   COALESCE(a.show_answer, 0) as show_answer,
                   er.id as result_id, er.total_score, er.is_reviewed, er.submitted_at, er.raw_results
            FROM assignments a
            JOIN templates t ON a.template_id = t.id
            LEFT JOIN exam_results er ON a.id = er.assignment_id AND er.student_id = :student_id
            WHERE a.class_id = :class_id AND a.status != 'deleted'
            ORDER BY a.id DESC
        ");
        $stmt->execute([
            'student_id' => $studentId,
            'class_id'   => $classId
        ]);
    } catch (Exception $e) {
        $stmt = $pdo->prepare("
            SELECT a.id as assignment_id, t.title, a.status as assign_status,
                   0 as show_answer,
                   er.id as result_id, er.total_score, er.is_reviewed, er.submitted_at, er.raw_results
            FROM assignments a
            JOIN templates t ON a.template_id = t.id
            LEFT JOIN exam_results er ON a.id = er.assignment_id AND er.student_id = :student_id
            WHERE a.class_id = :class_id AND a.status != 'deleted'
            ORDER BY a.id DESC
        ");
        $stmt->execute([
            'student_id' => $studentId,
            'class_id'   => $classId
        ]);
    }
    
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $rawList = [];
        if (!empty($row['raw_results'])) {
            $rawList = is_string($row['raw_results']) ? json_decode($row['raw_results'], true) : $row['raw_results'];
            if (!is_array($rawList) && isset($rawList['items'])) {
                $rawList = $rawList['items'];
            }
        }
        $rawList = is_array($rawList) ? $rawList : [];

        $objTotal = 0;
        $objCorrect = 0;
        $objScore = 0;
        $subjTotal = 0;
        $pendingCount = 0;
        $hasAiPending = false;
        $hasAppeal = false;

        foreach ($rawList as $item) {
            $type = strtolower($item['type'] ?? 'objective');
            $rawText = trim((string)($item['rawText'] ?? ''));
            $status = (int)($item['status'] ?? -1);

            if ($status === -1) {
                $pendingCount++;
            }

            if ($type === 'subjective' || in_array($type, ['essay', 'short', 'text', '주관식'])) {
                $subjTotal++;
            } else {
                $objTotal++;
                if ($rawText === '판독대기' || $rawText === 'AI 채점 중...' || ($status === -1 && empty($row['is_reviewed']))) {
                    $hasAiPending = true;
                }
                if ($status === 1) {
                    $objCorrect++;
                    $objScore += (float)($item['score'] ?? $item['currentScore'] ?? 0);
                } elseif ($status === 2) {
                    $objScore += (float)($item['currentScore'] ?? 0);
                }
            }

            if (!empty($item['appeal']) && empty($item['appeal_resolved'])) {
                $hasAppeal = true;
            }
        }

        $isGradingComplete = (!empty($row['result_id']) && ($pendingCount === 0 || !empty($row['is_reviewed'])));

        $isGradingComplete = (!empty($row['result_id']) && ($pendingCount === 0 || !empty($row['is_reviewed'])));

        $results[] = [
            'assignment_id'        => (int)$row['assignment_id'],
            'title'                => $row['title'],
            'assign_status'        => $row['assign_status'],
            'show_answer'          => (int)$row['show_answer'],
            'result_id'            => $row['result_id'] ? (int)$row['result_id'] : null,
            'total_score'          => (float)$row['total_score'],
            'is_reviewed'          => (int)$row['is_reviewed'],
            'submitted_at'         => $row['submitted_at'],
            'parsed_items'         => $rawList,
            'obj_total'            => $objTotal,
            'obj_correct'          => $objCorrect,
            'obj_score'            => $objScore,
            'subj_total'           => $subjTotal,
            'pending_count'        => $pendingCount,
            'is_ai_pending'        => $hasAiPending,
            'has_appeal'           => $hasAppeal,
            'is_grading_complete'  => $isGradingComplete,
            'is_missing'           => ($row['assign_status'] === 'completed' && empty($row['result_id']))
        ];
    }

    jsonSuccess(['assignments' => $results, 'timestamp' => time()]);

} catch (Exception $e) {
    jsonError('상태 조회 실패: ' . $e->getMessage());
}
