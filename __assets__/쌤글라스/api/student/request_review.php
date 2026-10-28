<?php
/**
 * api/student/request_review.php
 * 학생이 본인의 답안 중 AI 오인식 또는 이상 문항에 대해 선생님께 재검토/수정 요청(이의신청)을 전송하는 API
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/response.php';

$studentId = requireStudentAuth();
$input = getJsonInput();

$assignId = (int)($input['assignment_id'] ?? 0);
$itemId   = (string)($input['item_id'] ?? '');
$reason   = trim((string)($input['reason'] ?? '글씨가 다르게 인식되었어요'));

if (!$assignId || $itemId === '') {
    jsonError('필수 파라미터가 누락되었습니다.');
}

try {
    $stmt = $pdo->prepare("SELECT id, raw_results FROM exam_results WHERE assignment_id = :aid AND student_id = :sid");
    $stmt->execute([
        'aid' => $assignId,
        'sid' => $studentId
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonError('제출된 답안 내역을 찾을 수 없습니다.');
    }

    $rawList = json_decode($row['raw_results'] ?? '[]', true) ?: [];
    $isUpdated = false;

    foreach ($rawList as &$item) {
        $curId = (string)($item['item_id'] ?? $item['id'] ?? '');
        if ($curId === $itemId) {
            $item['appeal'] = true;
            $item['appeal_reason'] = $reason;
            $item['appealed_at'] = date('Y-m-d H:i:s');
            $isUpdated = true;
            break;
        }
    }
    unset($item);

    if (!$isUpdated) {
        jsonError('해당 문항을 찾을 수 없습니다.');
    }

    $updateStmt = $pdo->prepare("UPDATE exam_results SET raw_results = :raw WHERE id = :id");
    $updateStmt->execute([
        'raw' => json_encode($rawList, JSON_UNESCAPED_UNICODE),
        'id'  => $row['id']
    ]);

    jsonSuccess([
        'item_id'       => $itemId,
        'appeal'        => true,
        'appeal_reason' => $reason
    ], '선생님께 확인 요청을 보냈습니다. 선생님이 확인 후 채점을 보정해주실 거예요!');

} catch (Exception $e) {
    jsonError('확인 요청 처리 실패: ' . $e->getMessage());
}
