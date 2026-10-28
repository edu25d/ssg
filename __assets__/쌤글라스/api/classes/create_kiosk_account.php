<?php
/**
 * api/classes/create_kiosk_account.php
 * 학급 키오스크 계정 신규 생성
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

$classId = (int)($_POST['class_id'] ?? 0);
if (!$classId) {
    jsonError('학급 ID가 누락되었습니다.');
}

try {
    // 1. 해당 교사의 학급인지 확인 및 학급 정보 조회
    $cStmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE id = :id AND user_id = :u AND is_active = 1");
    $cStmt->execute(['id' => $classId, 'u' => $userId]);
    $class = $cStmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        jsonError('학급 정보를 찾을 수 없습니다.');
    }

    // 2. 교사 아이디 조회
    $uStmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
    $uStmt->execute(['id' => $userId]);
    $teacherUsername = $uStmt->fetchColumn();

    // 3. 기존 키오스크 계정 수 확인하여 suffix 번호 결정
    $kCountStmt = $pdo->prepare("SELECT COUNT(*) FROM kiosk_accounts WHERE class_id = :cid");
    $kCountStmt->execute(['cid' => $classId]);
    $count = (int)$kCountStmt->fetchColumn();

    $suffix = (string)($count + 1);
    $kioskId = $teacherUsername . '-' . $suffix;

    // 중복 방지 루프
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM kiosk_accounts WHERE kiosk_id = :kid");
    while (true) {
        $checkStmt->execute(['kid' => $kioskId]);
        if ($checkStmt->fetchColumn() == 0) break;
        $count++;
        $suffix = (string)($count + 1);
        $kioskId = $teacherUsername . '-' . $suffix;
    }

    // 4. 삽입
    $ins = $pdo->prepare("INSERT INTO kiosk_accounts (class_id, kiosk_id) VALUES (:cid, :kid)");
    $ins->execute([
        'cid' => $classId,
        'kid' => $kioskId
    ]);

    jsonSuccess(['account_id' => $pdo->lastInsertId(), 'kiosk_id' => $kioskId], '키오스크 계정이 생성되었습니다.');

} catch (Exception $e) {
    jsonError('계정 생성 오류: ' . $e->getMessage());
}
