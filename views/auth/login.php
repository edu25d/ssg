<?php
/**
 * views/auth/login.php
 * 교사 및 학급 키오스크 통합 로그인 화면
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/utils.php';

// 기인증 세션 자동 리다이렉트
if (!empty($_SESSION['user_id'])) {
    header("Location: /views/dashboard/main.php");
    exit;
}
if (!empty($_SESSION['kiosk_class_id'])) {
    header("Location: /views/input/kiosk.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <script src="/__preview-bridge.js" data-preview-bridge></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>로그인 - 쌤글라스</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .login-card { background: #fff; padding: 40px 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); width: 100%; max-width: 400px; box-sizing: border-box; }
        .login-card h1 { text-align: center; margin-top: 0; margin-bottom: 8px; color: #2c3e50; font-size: 1.8rem; font-weight: 800; }
        .login-card .subtitle { text-align: center; color: #6c757d; font-size: 0.9rem; margin-bottom: 25px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; color: #495057; font-size: 0.95rem; font-weight: bold; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #ced4da; border-radius: 6px; box-sizing: border-box; font-size: 1rem; }
        .form-control:focus { outline: none; border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13,110,253,0.15); }
        .helper-text { font-size: 0.8rem; color: #6c757d; margin-top: 4px; display: block; }
        .btn-submit { width: 100%; padding: 14px; background-color: #0d6efd; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 1rem; font-weight: bold; margin-top: 10px; transition: 0.2s; }
        .btn-submit:hover { background-color: #0b5ed7; }
        .footer-links { text-align: center; margin-top: 25px; font-size: 0.9rem; color: #6c757d; border-top: 1px solid #eee; padding-top: 15px; }
        .footer-links a { color: #0d6efd; text-decoration: none; font-weight: bold; }
        .footer-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>

    <div class="login-card">
        <h1>👓 쌤글라스</h1>
        <div class="subtitle">스마트 AI 채점 및 학급 평가 관리 플랫폼</div>

        <form action="/api/auth/login.php" method="POST">
            <div class="form-group">
                <label for="username">아이디</label>
                <input type="text" name="username" id="username" class="form-control" placeholder="교사 ID 또는 학급 ID(star-1)" required autofocus>
                <span class="helper-text">💡 학급 키오스크: 발급된 키오스크 ID (비밀번호는 생성한 교사 비밀번호와 동일)</span>
            </div>

            <div class="form-group">
                <label for="password">비밀번호</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="비밀번호" required>
            </div>

            <button type="submit" class="btn-submit">로그인</button>
            
            <div class="footer-links">
                아직 계정이 없으신가요? <a href="/views/auth/register.php">교사 회원가입</a>
            </div>
        </form>
    </div>

</body>
</html>
