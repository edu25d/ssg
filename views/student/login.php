<?php
/**
 * views/student/login.php
 * 학생 학급별 입장 및 PIN(비밀번호) 인증 로그인 뷰
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/utils.php';

$class_id    = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';

if ($class_id === 0) {
    echo "<script>alert('잘못된 접근입니다. 올바른 반 링크로 접속해주세요.'); history.back();</script>";
    exit;
}

$error_message = '';
$show_pin_suggestion = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_name = trim($_POST['student_name'] ?? '');
    $input_pin    = trim($_POST['pin'] ?? '');
    $redirect_to  = $_POST['redirect_to'] ?? '';

    if (!empty($student_name) && !empty($input_pin)) {
        $stmt = $pdo->prepare("SELECT id, name, pin, is_initial_pin FROM students WHERE name = ? AND class_id = ? AND is_active = 1");
        $stmt->execute([$student_name, $class_id]);
        
        if ($student = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($student['pin'] === $input_pin) {
                $_SESSION['student_id']            = $student['id'];
                $_SESSION['student_name']          = $student['name'];
                $_SESSION['class_id']              = $class_id;
                $_SESSION['is_student_logged_in']  = true;
                $_SESSION['role']                  = 'student';

                $next_url = !empty($redirect_to) ? urldecode($redirect_to) : "/views/student/main.php?class_id={$class_id}";

                if ((int)$student['is_initial_pin'] === 1) {
                    $show_pin_suggestion = true;
                } else {
                    echo "<script>location.href='" . addslashes($next_url) . "';</script>";
                    exit;
                }
            } else {
                $error_message = "비밀번호(PIN)가 일치하지 않습니다.";
            }
        } else {
            $error_message = "등록되지 않은 이름이거나 이름을 잘못 입력하셨습니다.";
        }
    } else {
        $error_message = "이름과 비밀번호를 모두 입력해주세요.";
    }
}

$stmt_class = $pdo->prepare("SELECT class_name FROM classes WHERE id = ?");
$stmt_class->execute([$class_id]);
$class_info = $stmt_class->fetch(PDO::FETCH_ASSOC);
$class_name = $class_info ? $class_info['class_name'] : '학급';

$next_url = !empty($redirect_to) ? urldecode($redirect_to) : "/views/student/main.php?class_id={$class_id}";
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <script src="/__preview-bridge.js" data-preview-bridge></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($class_name) ?> - 학생 로그인</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .login-box h2 { text-align: center; margin-bottom: 20px; color: #333; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #666; font-size: 0.95em; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; font-size: 1em; }
        .btn-submit { width: 100%; padding: 12px; background-color: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: bold; }
        .btn-submit:hover { background-color: #45a049; }
        .error { color: #dc3545; text-align: center; margin-bottom: 15px; font-size: 14px; font-weight: bold; }
        
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); display: flex; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 25px; border-radius: 10px; width: 90%; max-width: 350px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .modal-content h3 { margin-top: 0; color: #d9534f; }
        .modal-content p { color: #555; line-height: 1.5; margin-bottom: 20px; font-size: 15px; }
        .btn-group { display: flex; gap: 10px; }
        .btn-change { flex: 1; padding: 10px; background-color: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; font-weight: bold; }
        .btn-later { flex: 1; padding: 10px; background-color: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

<?php if ($show_pin_suggestion): ?>
    <div class="modal-overlay">
        <div class="modal-content">
            <h3>비밀번호 변경 안내</h3>
            <p>현재 초기 임시 비밀번호를 사용 중입니다.<br>안전을 위해 나만의 4자리 비밀번호(PIN)로<br>변경하는 것을 권장합니다.</p>
            <div class="btn-group">
                <a href="/views/student/pin_change.php?redirect_to=<?= urlencode($next_url) ?>" class="btn-change">변경하러 가기</a>
                <a href="<?= e($next_url) ?>" class="btn-later">다음에 변경</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="login-box">
        <h2>🏫 <?= e($class_name) ?> 입장하기</h2>
        
        <?php if (!empty($error_message)): ?>
            <div class="error"><?= e($error_message) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="redirect_to" value="<?= e($redirect_to) ?>">
            
            <div class="form-group">
                <label for="student_name">이름</label>
                <input type="text" name="student_name" id="student_name" class="form-control" placeholder="이름을 입력하세요" required value="<?= e($_POST['student_name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="pin">비밀번호 (PIN 4자리)</label>
                <input type="password" name="pin" id="pin" class="form-control" placeholder="4자리 숫자를 입력하세요" required inputmode="numeric" pattern="[0-9]*" maxlength="4">
            </div>

            <button type="submit" class="btn-submit">입장하기</button>
        </form>
    </div>
<?php endif; ?>

</body>
</html>
