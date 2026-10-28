<?php
/**
 * includes/common/auth.php
 * 세션 검증 가드 함수 모음
 */

require_once __DIR__ . '/response.php';

function isApiRequest(): bool {
    return (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        || (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false);
}

/**
 * 교사 세션 검증 가드
 */
function requireTeacherAuth(): int {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        if (isApiRequest()) {
            jsonError('로그인이 필요합니다.', 401, ['redirect' => '/index.php']);
        }
        header("Location: /index.php");
        exit;
    }
    return (int)$_SESSION['user_id'];
}

/**
 * 학생 세션 검증 가드
 */
function requireStudentAuth(int $classId = 0): int {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['student_id'])) {
        $returnUrl = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        $redirectUrl = "/views/student/login.php?redirect_to={$returnUrl}";
        if ($classId > 0) {
            $redirectUrl .= "&class_id={$classId}";
        }
        if (isApiRequest()) {
            jsonError('학생 로그인이 필요합니다.', 401, ['redirect' => $redirectUrl]);
        }
        header("Location: {$redirectUrl}");
        exit;
    }
    return (int)$_SESSION['student_id'];
}

/**
 * 키오스크(학급 단말) 세션 검증 가드
 */
function requireKioskAuth(): int {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['kiosk_class_id'])) {
        if (isApiRequest()) {
            jsonError('학급 키오스크 로그인이 필요합니다.', 401, ['redirect' => '/index.php']);
        }
        header("Location: /index.php");
        exit;
    }
    return (int)$_SESSION['kiosk_class_id'];
}
