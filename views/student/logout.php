<?php
/**
 * views/student/logout.php
 * 학생 세션 해제 및 학급 로그인 페이지 리다이렉트
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$classId = (int)($_SESSION['class_id'] ?? 0);

unset(
    $_SESSION['student_id'],
    $_SESSION['student_name'],
    $_SESSION['class_id'],
    $_SESSION['is_student_logged_in']
);

if (!empty($_SESSION['kiosk_class_id'])) {
    header("Location: /views/input/kiosk.php");
    exit;
}

$targetUrl = ($classId > 0) ? "/views/student/login.php?class_id={$classId}" : "/index.php";
header("Location: {$targetUrl}");
exit;
