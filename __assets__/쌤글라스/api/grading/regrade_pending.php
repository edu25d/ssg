<?php
/**
 * api/grading/regrade_pending.php
 * 미판독(AI 채점 대기) 상태인 학생 문항들을 핀포인트로 Gemini AI 즉시 재채점
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';
require_once APP_ROOT . '/includes/grading/objective.php';

$isLoggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['student_id']) || !empty($_SESSION['kiosk_class_id']);
if (!$isLoggedIn) {
    jsonError('로그인이 필요합니다.', 401);
}

$assignmentId = (int)($_POST['assignment_id'] ?? $_GET['assignment_id'] ?? 0);
if (!$assignmentId) {
    jsonError('평가 ID가 유효하지 않습니다.');
}

try {
    // 1. 해당 평가의 템플릿 정보 및 미판독 결과 조회
    $stmt = $pdo->prepare("
        SELECT e.id AS result_id, e.student_id, e.total_score, e.is_reviewed, e.raw_results,
               COALESCE(s.name, '학생') AS student_name
        FROM exam_results e
        LEFT JOIN students s ON e.student_id = s.id
        WHERE e.assignment_id = :aid
    ");
    $stmt->execute(['aid' => $assignmentId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)) {
        jsonSuccess(['regraded_count' => 0], '등록된 학생 시험지가 없습니다.');
    }

    $regradedCount = 0;
    $updatedResults = [];

    foreach ($results as $rIdx => $resRow) {
        $resultId = (int)$resRow['result_id'];
        $rawResults = json_decode($resRow['raw_results'] ?? '[]', true) ?: [];
        
        $pendingObjectiveItems = [];

        foreach ($rawResults as $itemIdx => $item) {
            $type = strtolower($item['type'] ?? 'objective');
            $isObj = ($type === 'objective' || $type === 'composite' || ($type === 'sub_item' && ($item['sub_type'] ?? '') !== 'subjective'));
            $status = (int)($item['status'] ?? -1);
            $rawText = trim((string)($item['rawText'] ?? ''));

            // 미판독(판독대기) 조건 검사
            if ($isObj && ($status === -1 || $rawText === '판독대기' || $rawText === 'AI 채점 중...' || $rawText === '')) {
                // 크롭 이미지 로컬 경로 찾기
                $cropUrl = $item['crop_url'] ?? ($item['crop_image_url'] ?? '');
                $cropPath = '';
                if (!empty($cropUrl)) {
                    $cleanPath = ltrim($cropUrl, '/');
                    if (strpos($cleanPath, 'public/') === 0) {
                        $cropPath = APP_ROOT . '/' . $cleanPath;
                    } else {
                        $cropPath = APP_ROOT . '/public/' . $cleanPath;
                    }
                }

                $gdImg = null;
                if (!empty($cropPath) && file_exists($cropPath)) {
                    $gdImg = @imagecreatefromjpeg($cropPath) ?: (@imagecreatefrompng($cropPath) ?: null);
                }

                if ($gdImg) {
                    $pendingObjectiveItems[] = [
                        'id'             => $item['id'] ?? $item['item_id'],
                        'item_id'        => $item['item_id'] ?? $item['id'],
                        'display_num'    => $item['display_num'] ?? (string)($item['id'] ?? $item['item_id']),
                        'sub_id'         => $item['sub_id'] ?? null,
                        'page'           => (int)($item['page'] ?? 1),
                        'score'          => (float)($item['score'] ?? 0),
                        'answer'         => $item['answer'] ?? '',
                        'type'           => $type,
                        'image_res'      => $gdImg,
                        'crop_url'       => $cropUrl,
                        'crop_image_url' => $cropUrl
                    ];
                }
            }
        }

        if (!empty($pendingObjectiveItems)) {
            if ($regradedCount > 0) {
                // Rate Limit 방지 딜레이
                usleep(500000);
            }

            // Gemini AI 채점 수행
            $gradRes = gradeObjectiveItems($pendingObjectiveItems);
            $newItems = $gradRes['items'] ?? [];

            // GD 리소스 해제
            foreach ($pendingObjectiveItems as $poi) {
                if (!empty($poi['image_res']) && (is_resource($poi['image_res']) || is_object($poi['image_res']))) {
                    @imagedestroy($poi['image_res']);
                }
            }

            if (!empty($newItems)) {
                // 기존 raw_results에 새 채점 결과 병합
                foreach ($newItems as $nItem) {
                    $nId = (string)($nItem['display_num'] ?? $nItem['item_id']);
                    foreach ($rawResults as &$origItem) {
                        $oId = (string)($origItem['display_num'] ?? ($origItem['item_id'] ?? $origItem['id']));
                        if ($oId === $nId) {
                            $origItem['status']       = $nItem['status'];
                            $origItem['currentScore']   = $nItem['currentScore'];
                            $origItem['rawText']        = $nItem['rawText'];
                            break;
                        }
                    }
                    unset($origItem);
                }

                // 총점 재계산
                $newTotalScore = array_reduce($rawResults, function($acc, $it) {
                    return $acc + (float)($it['currentScore'] ?? 0);
                }, 0);

                // 주관식 존재 여부 확인
                $hasSubjective = false;
                foreach ($rawResults as $it) {
                    if (($it['type'] ?? '') === 'subjective') {
                        $hasSubjective = true;
                        break;
                    }
                }
                $isReviewed = $hasSubjective ? 0 : 1;

                $jsonPayload = json_encode($rawResults, JSON_UNESCAPED_UNICODE);
                $upStmt = $pdo->prepare("UPDATE exam_results SET total_score = ?, is_reviewed = ?, raw_results = ?, submitted_at = NOW() WHERE id = ?");
                $upStmt->execute([$newTotalScore, $isReviewed, $jsonPayload, $resultId]);

                $regradedCount++;
                $updatedResults[] = [
                    'result_id' => $resultId,
                    'student_name' => $resRow['student_name'],
                    'regraded_items' => count($newItems)
                ];
            }
        }
    }

    jsonSuccess([
        'regraded_count' => $regradedCount,
        'details' => $updatedResults
    ], $regradedCount > 0 ? "총 {$regradedCount}명 학생의 미판독 문항 채점이 완료되었습니다!" : "모든 객관식 문항이 이미 판독 완료 상태입니다.");

} catch (Exception $e) {
    jsonError('재채점 처리 중 오류: ' . $e->getMessage());
}
