<?php
/**
 * api/classes/reset_student_pin.php
 * 학생 비밀번호 초기화 (웹 대시보드 교사 관리 및 Google Apps Script 연동 통합)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

$data = getJsonInput();
$studentId   = (int)($data['student_id'] ?? 0);
$sheetId     = trim($data['sheet_id'] ?? '');
$studentName = trim($data['student_name'] ?? '');
$newPin      = trim($data['new_pin'] ?? '');

if (strlen($newPin) !== 4 || !ctype_digit($newPin)) {
    jsonError('새 PIN 번호는 4자리 숫자여야 합니다.');
}

try {
    // 1. 웹 대시보드(교사)에서 student_id로 요청한 경우
    if ($studentId > 0) {
        $teacherId = (int)($_SESSION['user_id'] ?? ($_SESSION['teacher_id'] ?? 0));
        if ($teacherId <= 0) {
            jsonError('교사 권한 로그인이 필요합니다.', 401);
        }

        // 해당 교사 소유 학급의 학생인지 확인
        $checkStmt = $pdo->prepare("
            SELECT s.id, s.name, c.sheet_id 
            FROM students s 
            JOIN classes c ON s.class_id = c.id 
            WHERE s.id = :sid AND c.user_id = :uid AND s.is_active = 1
        ");
        $checkStmt->execute(['sid' => $studentId, 'uid' => $teacherId]);
        $target = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            jsonError('해당 학생을 찾을 수 없거나 권한이 없습니다.', 404);
        }

        $updateStmt = $pdo->prepare("
            UPDATE students 
            SET pin = :pin, is_initial_pin = 1 
            WHERE id = :sid
        ");
        $updateStmt->execute(['pin' => $newPin, 'sid' => $studentId]);

        jsonSuccess([
            'student_id'   => $studentId,
            'student_name' => $target['name'],
            'new_pin'      => $newPin
        ], "'{$target['name']}' 학생의 PIN이 '{$newPin}'(으)로 초기화되었습니다.");
    }

    // 2. 구글 앱스 스크립트 연동 (sheet_id + student_name)
    if (!empty($sheetId) && !empty($studentName)) {
        $stmt = $pdo->prepare("
            UPDATE students s
            JOIN classes c ON s.class_id = c.id
            SET s.pin = :pin, s.is_initial_pin = 1
            WHERE s.name = :name AND c.sheet_id = :sheet_id AND s.is_active = 1
        ");
        $stmt->execute([
            'pin'      => $newPin,
            'name'     => $studentName,
            'sheet_id' => $sheetId
        ]);

        if ($stmt->rowCount() > 0) {
            jsonSuccess([], "'{$studentName}' 학생의 비밀번호가 초기화되었습니다.");
        } else {
            jsonError('해당 학급에서 일치하는 학생을 찾을 수 없습니다.');
        }
    }

    jsonError('요청 파라미터가 올바르지 않습니다.');

} catch (Exception $e) {
    jsonError('DB 업데이트 실패: ' . $e->getMessage());
}

