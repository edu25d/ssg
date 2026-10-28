<?php
/**
 * api/classes/get_students.php
 * 특정 학급의 등록된 학생 목록 조회 API
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/response.php';

$userId  = requireTeacherAuth();
$classId = (int)($_GET['class_id'] ?? 0);

if (!$classId) {
    jsonError('학급 식별자가 누락되었습니다.');
}

// 교사 소유 학급인지 검증
$classStmt = $pdo->prepare("SELECT id, class_name, sheet_id FROM classes WHERE id = :cid AND user_id = :uid AND is_active = 1");
$classStmt->execute(['cid' => $classId, 'uid' => $userId]);
$class = $classStmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    jsonError('해당 학급을 찾을 수 없거나 접근 권한이 없습니다.', 403);
}

// 활성 학생 명단 조회
$stmt = $pdo->prepare("
    SELECT id, name, pin, is_initial_pin, order_index
    FROM students
    WHERE class_id = :cid AND is_active = 1
    ORDER BY order_index ASC, id ASC
");
$stmt->execute(['cid' => $classId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonSuccess([
    'class_id'     => $class['id'],
    'class_name'   => $class['class_name'],
    'sheet_id'     => $class['sheet_id'],
    'student_count'=> count($students),
    'students'     => $students
]);
