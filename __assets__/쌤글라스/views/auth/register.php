<?php
/**
 * views/auth/register.php
 * 교사 신규 회원가입 뷰
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/utils.php';

// 이미 로그인된 경우 대시보드로 이동
if (isset($_SESSION['user_id'])) {
    header("Location: /views/dashboard/main.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>회원가입 - 쌤글라스</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px 0; }
        .register-card { background: #fff; padding: 35px 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); width: 100%; max-width: 420px; box-sizing: border-box; }
        .register-card h2 { text-align: center; margin-top: 0; margin-bottom: 25px; color: #2c3e50; font-size: 1.6rem; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; color: #495057; font-size: 0.95rem; font-weight: bold; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #ced4da; border-radius: 6px; box-sizing: border-box; font-size: 1rem; }
        .form-control:focus { outline: none; border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13,110,253,0.15); }
        .input-group { display: flex; gap: 8px; }
        .btn-check { padding: 12px 14px; background: #6c757d; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-weight: bold; white-space: nowrap; }
        .btn-check:hover { background: #5a6268; }
        .btn-submit { width: 100%; padding: 14px; background-color: #0d6efd; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 1rem; font-weight: bold; margin-top: 10px; transition: 0.2s; }
        .btn-submit:disabled { background-color: #adb5bd; cursor: not-allowed; }
        .btn-submit:not(:disabled):hover { background-color: #0b5ed7; }
        .feedback { font-size: 0.85rem; margin-top: 6px; font-weight: bold; display: block; min-height: 1.2em; }
        .login-link { text-align: center; margin-top: 20px; font-size: 0.9rem; color: #6c757d; }
        .login-link a { color: #0d6efd; text-decoration: none; font-weight: bold; }
        .login-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>

    <div class="register-card">
        <h2>👨‍🏫 교사 회원가입</h2>
        <form action="/api/auth/register.php" method="POST" onsubmit="return validateForm()">
            <div class="form-group">
                <label for="name">성명</label>
                <input type="text" name="name" id="name" class="form-control" required placeholder="홍길동">
            </div>

            <div class="form-group">
                <label for="username">아이디 (영문/숫자)</label>
                <div class="input-group">
                    <input type="text" name="username" id="username" class="form-control" required placeholder="아이디 입력" oninput="onUsernameChanged()">
                    <button type="button" class="btn-check" onclick="checkId()">중복 검증</button>
                </div>
                <span id="idResult" class="feedback"></span>
            </div>

            <div class="form-group">
                <label for="school_name">소속 학교명</label>
                <input type="text" name="school_name" id="school_name" class="form-control" required placeholder="○○초등학교 또는 ○○중학교">
            </div>

            <div class="form-group">
                <label for="password">비밀번호</label>
                <input type="password" name="password" id="password" class="form-control" required placeholder="비밀번호를 입력하세요">
            </div>

            <button type="submit" id="subBtn" class="btn-submit" disabled>가입 완료</button>
            
            <div class="login-link">
                이미 계정이 있으신가요? <a href="/index.php">로그인하기</a>
            </div>
        </form>
    </div>

    <script>
    let isIdChecked = false;

    function onUsernameChanged() {
        isIdChecked = false;
        const el = document.getElementById('idResult');
        el.textContent = '';
        document.getElementById('subBtn').disabled = true;
    }

    async function checkId() {
        const u = document.getElementById('username').value.trim();
        if(!u) return alert('아이디를 먼저 입력하세요.');
        
        try {
            const res = await fetch(`/api/auth/register.php?username=${encodeURIComponent(u)}`);
            if (!res.ok) throw new Error(`서버 통신 에러: ${res.status}`);
            
            const data = await res.json();
            const el = document.getElementById('idResult');
            
            if(data.exists) {
                el.textContent = '❌ 이미 사용 중인 아이디입니다.';
                el.style.color = '#dc3545';
                document.getElementById('subBtn').disabled = true;
                isIdChecked = false;
            } else {
                el.textContent = '🟢 사용 가능한 아이디입니다.';
                el.style.color = '#198754';
                document.getElementById('subBtn').disabled = false;
                isIdChecked = true;
            }
        } catch (error) {
            console.error("검증 오류:", error);
            alert("서버와 통신 중 오류가 발생했습니다.");
        }
    }

    function validateForm() {
        if (!isIdChecked) {
            alert('아이디 중복 검증을 완료해주세요.');
            return false;
        }
        return true;
    }
    </script>
</body>
</html>
