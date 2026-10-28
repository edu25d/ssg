<?php
/**
 * api/auth/login.php
 * 사용자(교사) 및 학급 입력기(키오스크) 로그인 처리
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /index.php");
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
       || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

if (empty($username) || empty($password)) {
    if ($isJson) {
        jsonError('아이디와 비밀번호를 모두 입력해주세요.');
    }
    echo "<script>alert('아이디와 비밀번호를 모두 입력해주세요.'); history.back();</script>";
    exit;
}

// -------------------------------------------------------------
// [A] 학급 입력기(키오스크) 계정 처리 ('-' 문자가 포함된 경우: 예 star-1)
// -------------------------------------------------------------
if (strpos($username, '-') !== false) {
    // 1. kiosk_accounts 테이블에 등록된 계정 우선 조회 (담당 학급 및 담당 교사 정보 JOIN)
    $kioskStmt = $pdo->prepare("
        SELECT k.id AS kiosk_account_id, k.kiosk_id, k.class_id,
               c.class_name, c.user_id,
               u.id AS teacher_id, u.username AS teacher_username, u.name AS teacher_name, u.password_hash
        FROM kiosk_accounts k
        JOIN classes c ON k.class_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE k.kiosk_id = :kid AND c.is_active = 1
    ");
    $kioskStmt->execute(['kid' => $username]);
    $kioskData = $kioskStmt->fetch(PDO::FETCH_ASSOC);

    // 2. kiosk_accounts에 등록되지 않은 경우: [교사ID-학급식별자] 형태 폴백 조회
    if (!$kioskData) {
        list($teacherUsername, $classSuffix) = explode('-', $username, 2);

        $userStmt = $pdo->prepare("SELECT id, name, username, password_hash FROM users WHERE username = :u");
        $userStmt->execute(['u' => $teacherUsername]);
        $teacher = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($teacher) {
            $classStmt = $pdo->prepare("
                SELECT id, class_name 
                FROM classes 
                WHERE user_id = :teacher_id 
                  AND (class_name = :s1 OR class_name = :s2 OR class_name = :s3)
                  AND is_active = 1
            ");
            $classStmt->execute([
                'teacher_id' => $teacher['id'],
                's1' => $classSuffix,
                's2' => $classSuffix . '반',
                's3' => $teacherUsername . '-' . $classSuffix
            ]);
            $classData = $classStmt->fetch(PDO::FETCH_ASSOC);

            if ($classData) {
                $kioskData = [
                    'class_id'         => $classData['id'],
                    'class_name'       => $classData['class_name'],
                    'teacher_id'       => $teacher['id'],
                    'teacher_name'     => $teacher['name'],
                    'password_hash'    => $teacher['password_hash']
                ];
            }
        }
    }

    // 3. 해당 학급을 생성한 교사의 비밀번호와 일치하는지 검증
    if ($kioskData && !empty($kioskData['password_hash'])) {
        if (password_verify($password, $kioskData['password_hash'])) {
            unset($_SESSION['user_id']); // 교사 관리자 세션 초기화
            
            $_SESSION['kiosk_class_id']   = $kioskData['class_id'];
            $_SESSION['kiosk_class_name'] = $kioskData['class_name'];
            $_SESSION['teacher_id']       = $kioskData['teacher_id'];
            $_SESSION['teacher_name']     = $kioskData['teacher_name'];
            $_SESSION['role']             = 'kiosk';

            $kioskUrl = "/views/input/kiosk.php";
            if ($isJson) {
                jsonSuccess(['redirect' => $kioskUrl]);
            }
            header("Location: {$kioskUrl}");
            exit;
        }
    }

    if ($isJson) {
        jsonError('학급 입력기 계정 정보 또는 비밀번호가 올바르지 않습니다.');
    }
    echo "<script>alert('학급 입력기 계정 정보 또는 비밀번호가 올바르지 않습니다.'); history.back();</script>";
    exit;
}

// -------------------------------------------------------------
// [B] 일반 교사 계정 처리 ('-'가 없는 경우)
// -------------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u");
$stmt->execute(['u' => $username]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password_hash'])) {
    unset($_SESSION['kiosk_class_id']); // 학급 세션 초기화
    
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['name']     = $user['name'];
    $_SESSION['role']     = 'teacher';

    $dashUrl = "/views/dashboard/main.php";
    if ($isJson) {
        jsonSuccess(['redirect' => $dashUrl]);
    }
    header("Location: {$dashUrl}");
    exit;
}

if ($isJson) {
    jsonError('아이디 또는 비밀번호가 올바르지 않습니다.');
}
echo "<script>alert('아이디 또는 비밀번호가 올바르지 않습니다.'); history.back();</script>";
exit;
