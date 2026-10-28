<?php
/**
 * views/student/pin_change.php
 * 학생 비밀번호(PIN 4자리) 변경 뷰 및 처리 (Fatal Error 버그 수정본)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

$classId = (int)($_SESSION['class_id'] ?? 0);
$studentId = requireStudentAuth($classId);
$studentName = $_SESSION['student_name'] ?? '학생';

$redirectTo = $_GET['redirect_to'] ?? $_POST['redirect_to'] ?? "/views/student/main.php?class_id={$classId}";

$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPin     = trim($_POST['new_pin'] ?? '');
    $confirmPin = trim($_POST['confirm_pin'] ?? '');

    if (empty($newPin) || empty($confirmPin)) {
        $errorMessage = "새 비밀번호를 모두 입력해주세요.";
    } elseif ($newPin !== $confirmPin) {
        $errorMessage = "입력한 두 비밀번호가 일치하지 않습니다.";
    } elseif (strlen($newPin) !== 4 || !ctype_digit($newPin)) {
        $errorMessage = "비밀번호는 4자리 숫자로 입력해주세요.";
    } else {
        try {
            // [버그 수정]: mysqli($conn) 대신 전역 PDO($pdo) 사용
            $stmt = $pdo->prepare("UPDATE students SET pin = :pin, is_initial_pin = 0 WHERE id = :id");
            $stmt->execute([
                'pin' => $newPin,
                'id'  => $studentId
            ]);

            echo "<script>
                alert('비밀번호가 성공적으로 변경되었습니다!');
                location.href = '" . addslashes($redirectTo) . "';
            </script>";
            exit;
        } catch (Exception $e) {
            $errorMessage = "비밀번호 변경 중 오류가 발생했습니다: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>비밀번호 변경 - <?= e($studentName) ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .change-box { background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .change-box h2 { text-align: center; margin-bottom: 10px; color: #333; }
        .welcome-text { text-align: center; color: #666; margin-bottom: 25px; font-size: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #666; font-size: 14px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; font-size: 16px; text-align: center; letter-spacing: 5px; }
        .btn-submit { width: 100%; padding: 12px; background-color: #0d6efd; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: bold; margin-top: 10px; }
        .btn-submit:hover { background-color: #0b5ed7; }
        .btn-cancel { width: 100%; padding: 10px; background-color: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: bold; margin-top: 8px; text-decoration: none; display: block; text-align: center; box-sizing: border-box; }
        .error { color: #dc3545; text-align: center; margin-bottom: 15px; font-size: 14px; font-weight: bold; }
    </style>
</head>
<body>

    <div class="change-box">
        <h2>🔑 비밀번호(PIN) 변경</h2>
        <div class="welcome-text"><b><?= e($studentName) ?></b> 학생, 새로운 4자리 숫자를 입력하세요.</div>

        <?php if (!empty($errorMessage)): ?>
            <div class="error"><?= e($errorMessage) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">

            <div class="form-group">
                <label for="new_pin">새 비밀번호 (숫자 4자리)</label>
                <input type="password" name="new_pin" id="new_pin" class="form-control" maxlength="4" pattern="[0-9]*" inputmode="numeric" required placeholder="••••">
            </div>

            <div class="form-group">
                <label for="confirm_pin">새 비밀번호 확인</label>
                <input type="password" name="confirm_pin" id="confirm_pin" class="form-control" maxlength="4" pattern="[0-9]*" inputmode="numeric" required placeholder="••••">
            </div>

            <button type="submit" class="btn-submit">변경 완료</button>
            <a href="<?= e($redirectTo) ?>" class="btn-cancel">다음에 변경하기</a>
        </form>
    </div>

</body>
</html>
