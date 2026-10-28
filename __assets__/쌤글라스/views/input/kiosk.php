<?php
/**
 * views/input/kiosk.php
 * 전체화면 기반 학급 공용 키오스크 입력기 뷰 (크롬북 최적화 & 교사 관리자 잠금 탑재)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

$classId     = requireKioskAuth();
$className   = $_SESSION['kiosk_class_name'] ?? '학급';
$teacherName = $_SESSION['teacher_name'] ?? '선생님';

// 1. 진행 중(in_progress)인 평가 목록 조회
$stmt = $pdo->prepare("
    SELECT a.id AS assignment_id, t.id AS template_id, t.title 
    FROM assignments a
    JOIN templates t ON a.template_id = t.id
    WHERE a.class_id = :cid AND a.status = 'in_progress'
    ORDER BY a.created_at DESC
");
$stmt->execute(['cid' => $classId]);
$activeAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. 활성 평가별 이미 제출 완료한 학생 ID 목록 맵 구성
$submittedByAssign = [];
if (!empty($activeAssignments)) {
    $assignIds = array_column($activeAssignments, 'assignment_id');
    $inClause = implode(',', array_map('intval', $assignIds));
    $subStmt = $pdo->query("SELECT assignment_id, student_id FROM exam_results WHERE assignment_id IN ($inClause)");
    while ($row = $subStmt->fetch(PDO::FETCH_ASSOC)) {
        $submittedByAssign[(int)$row['assignment_id']][] = (int)$row['student_id'];
    }
}

// 3. DB에서 실제 학급 학생 명단 불러오기
$stmt = $pdo->prepare("
    SELECT id, name, order_index
    FROM students 
    WHERE class_id = :cid AND is_active = 1
    ORDER BY order_index ASC, name ASC
");
$stmt->execute(['cid' => $classId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?= e($className) ?> 키오스크 입력기 - 쌤글라스</title>
    <style>
        :root {
            --primary: #0d6efd;
            --success: #10b981;
            --gray-100: #f8fafc;
            --gray-200: #e2e8f0;
            --gray-600: #64748b;
            --gray-800: #1e293b;
            --radius-md: 14px;
        }
        * { box-sizing: border-box; user-select: none; }
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif;
            background-color: #f4f6f9;
            overflow: hidden;
            height: 100vh;
        }

        /* 상단 키오스크 헤더 */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #2c3e50;
            color: white;
            padding: 14px 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .header-title-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-title {
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.01em;
        }
        .header-badge {
            font-size: 0.8rem;
            background: rgba(255, 255, 255, 0.15);
            padding: 3px 10px;
            border-radius: 20px;
            color: #e2e8f0;
        }

        /* 은은한 키오스크 종료 버튼 (학생 임의 조작 방지) */
        .btn-kiosk-exit {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #cbd5e1;
            padding: 6px 14px;
            font-size: 0.84rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-kiosk-exit:hover {
            background: #dc2626;
            color: #ffffff;
            border-color: #dc2626;
        }

        /* 본문 컨테이너 */
        .container {
            padding: 20px 24px;
            height: calc(100vh - 65px);
            overflow-y: auto;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* 평가 선택 바 */
        .assignment-select-bar {
            background: #ffffff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 12px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 4px rgba(0,0,0,0.03);
        }
        .assignment-select-bar label {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .assignment-dropdown {
            font-size: 1rem;
            font-weight: 700;
            padding: 8px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #f8fafc;
            color: #0f172a;
            outline: none;
            cursor: pointer;
        }

        .section-guide-text {
            font-size: 1rem;
            font-weight: 700;
            color: #475569;
            margin: 0 0 12px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* 학생 카드 그리드 */
        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 14px;
        }
        .student-card {
            background: #ffffff;
            border: 2px solid #e2e8f0;
            border-radius: var(--radius-md);
            padding: 18px 12px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s, background-color 0.15s;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 110px;
        }
        .student-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border-color: #94a3b8;
        }
        .student-card:active {
            transform: scale(0.97);
        }

        /* 제출 완료 카드 스타일 */
        .student-card.submitted {
            border-color: #86efac;
            background: #f0fdf4;
        }
        .student-card.submitted:hover {
            border-color: #22c55e;
        }

        .student-name {
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
        }

        /* 상태 배지 */
        .badge-box {
            display: flex;
            justify-content: center;
        }
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .badge-unsubmitted {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }
        .badge-submitted {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        /* 공통 모달 스타일 */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(3px);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal-content {
            background: white;
            padding: 28px 24px;
            border-radius: 16px;
            width: 90%;
            max-width: 440px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            animation: popModal 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes popModal {
            from { transform: scale(0.92); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .modal-title {
            margin: 0 0 8px 0;
            font-size: 1.2rem;
            font-weight: 800;
            color: #0f172a;
        }
        .modal-desc {
            margin: 0 0 18px 0;
            font-size: 0.88rem;
            color: #64748b;
            line-height: 1.5;
        }
        .modal-input {
            width: 100%;
            padding: 12px;
            font-size: 1.4rem;
            text-align: center;
            letter-spacing: 8px;
            margin-bottom: 16px;
            border: 2px solid #cbd5e1;
            border-radius: 10px;
            outline: none;
            box-sizing: border-box;
        }
        .modal-input:focus {
            border-color: var(--primary);
        }
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .btn {
            padding: 11px 20px;
            font-size: 0.95rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            transition: all 0.15s;
        }
        .btn-primary { background: var(--primary); color: white; flex: 1; }
        .btn-primary:hover { background: #0b5ed7; }
        .btn-secondary { background: #e2e8f0; color: #475569; flex: 1; }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: #dc2626; color: white; flex: 1; }
        .btn-danger:hover { background: #b91c1c; }
    </style>
</head>
<body onclick="openFullscreen()">

<header>
    <div class="header-title-box">
        <span class="header-title">🏫 <?= e($className) ?> 공용 키오스크</span>
        <span class="header-badge">담당: <?= e($teacherName) ?> 선생님</span>
    </div>
    <button type="button" class="btn-kiosk-exit" onclick="promptLogout(event)" title="키오스크 종료 (선생님 전용)">
        🔒 키오스크 종료
    </button>
</header>

<div class="container">
    <?php if (count($activeAssignments) === 0): ?>
        <div style="text-align:center; padding: 60px 20px; background: white; border-radius: 16px; border: 1px dashed #cbd5e1; margin-top: 30px;">
            <h3 style="color: #64748b; font-size: 1.2rem; margin: 0 0 10px 0;">현재 진행 중인 평가가 없습니다.</h3>
            <p style="color: #94a3b8; font-size: 0.9rem; margin: 0;">교사 대시보드에서 새로운 평가를 배정해 주세요.</p>
        </div>
    <?php else: ?>
        <!-- 평가 선택 드롭다운 (평가가 2개 이상일 때 노출) -->
        <div class="assignment-select-bar" style="<?= count($activeAssignments) === 1 ? 'display:none;' : '' ?>">
            <label for="assignmentSelect">
                <span>🎯</span> 진행 중인 평가 선택:
            </label>
            <select id="assignmentSelect" class="assignment-dropdown" onchange="onAssignmentChange(this.value)">
                <?php foreach ($activeAssignments as $assign): ?>
                    <option value="<?= (int)$assign['assignment_id'] ?>"><?= e($assign['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="section-guide-text">
            <span>🎓</span> 학생 명단 (본인 이름을 터치하세요)
        </div>

        <div class="student-grid" id="studentGrid">
            <?php foreach ($students as $st): 
                $stId = (int)$st['id'];
            ?>
                <div class="student-card" id="student-card-<?= $stId ?>" onclick="handleStudentClick(<?= $stId ?>, '<?= e($st['name']) ?>')">
                    <div class="student-name"><?= e($st['name']) ?></div>
                    <div class="badge-box">
                        <span class="badge badge-unsubmitted" id="badge-<?= $stId ?>">미제출</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- 1. PIN 인증 모달 -->
<div class="modal" id="pinModal">
    <div class="modal-content">
        <h3 class="modal-title" id="pinModalTitle">학생 인증</h3>
        <p class="modal-desc">본인의 비밀번호(PIN 4자리)를 입력하세요.</p>
        <input type="password" id="pinInput" class="modal-input" maxlength="4" inputmode="numeric" onkeydown="if(event.key==='Enter') verifyPin()">
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="closeModal('pinModal')">취소</button>
            <button type="button" class="btn btn-primary" onclick="verifyPin()">확인</button>
        </div>
    </div>
</div>

<!-- 2. 재제출 확인 가드 모달 -->
<div class="modal" id="retakeModal">
    <div class="modal-content">
        <div style="font-size: 2.2rem; margin-bottom: 8px;">⚠️</div>
        <h3 class="modal-title" id="retakeModalTitle">이미 제출 완료된 시험지입니다</h3>
        <p class="modal-desc">
            <b id="retakeStudentName"></b> 학생은 이미 답안을 제출하였습니다.<br>
            시험지를 다시 촬영하여 <b>재제출</b>하시겠습니까?
        </p>
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="closeModal('retakeModal')">취소</button>
            <button type="button" class="btn btn-primary" onclick="confirmRetake()">네, 다시 제출합니다</button>
        </div>
    </div>
</div>

<!-- 3. 키오스크 종료(교사 비밀번호 인증) 모달 -->
<div class="modal" id="logoutModal">
    <div class="modal-content" style="text-align: left;">
        <h3 class="modal-title" style="display:flex; align-items:center; gap:8px;">
            <span>🔒</span> 키오스크 종료 (관리자 인증)
        </h3>
        <p class="modal-desc" style="margin-bottom:14px;">
            학생들의 임의 조작 방지를 위해 <b>담당 선생님(<?= e($teacherName) ?>)의 비밀번호</b>를 입력해야 종료할 수 있습니다.
        </p>
        <input type="password" id="logoutPasswordInput" class="modal-input" placeholder="선생님 비밀번호 입력" style="letter-spacing: normal; font-size:1.05rem; text-align:left; margin-bottom:10px;" onkeydown="if(event.key==='Enter') execLogout()">
        <div id="logoutErrorMsg" style="display:none; color:#dc2626; font-size:0.84rem; font-weight:700; margin-bottom:12px;"></div>
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="closeModal('logoutModal')">취소</button>
            <button type="button" class="btn btn-danger" id="btnLogoutConfirm" onclick="execLogout()">종료 및 로그아웃</button>
        </div>
    </div>
</div>

<script>
let currentStudent = null;
const submittedMap = <?= json_encode($submittedByAssign) ?>;

// 1. 크롬북 전체화면 및 Keyboard Lock API (Esc 등 시스템 키 잠금)
function openFullscreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(() => {});
    }
    if (navigator.keyboard && typeof navigator.keyboard.lock === 'function') {
        navigator.keyboard.lock(['Escape']).catch(() => {});
    }
}

// 2. 단축키 방어 (F12, 새로고침 등)
document.addEventListener('keydown', (e) => {
    if (e.key === 'F12' || (e.ctrlKey && ['r', 'w', 'u', 't', 'n'].includes(e.key.toLowerCase()))) {
        e.preventDefault();
    }
});

// 3. 현재 평가에 맞춰 학생 카드 제출 상태 배지 갱신
function updateBadges() {
    const selectEl = document.getElementById('assignmentSelect');
    const currentAssignId = selectEl ? parseInt(selectEl.value) : (<?= !empty($activeAssignments) ? (int)$activeAssignments[0]['assignment_id'] : 0 ?>);
    const submittedList = submittedMap[currentAssignId] || [];

    document.querySelectorAll('[id^="student-card-"]').forEach(card => {
        const studentId = parseInt(card.id.replace('student-card-', ''));
        const badge = document.getElementById(`badge-${studentId}`);
        if (!badge) return;

        if (submittedList.includes(studentId)) {
            card.classList.add('submitted');
            badge.className = 'badge badge-submitted';
            badge.textContent = '✅ 제출완료';
        } else {
            card.classList.remove('submitted');
            badge.className = 'badge badge-unsubmitted';
            badge.textContent = '미제출';
        }
    });
}

function onAssignmentChange(newAssignId) {
    updateBadges();
}

// 초기 로딩 시 배지 갱신
document.addEventListener('DOMContentLoaded', updateBadges);
updateBadges();

// 4. 학생 카드 클릭 핸들러
function handleStudentClick(studentId, name) {
    currentStudent = { id: studentId, name: name };
    const selectEl = document.getElementById('assignmentSelect');
    const currentAssignId = selectEl ? parseInt(selectEl.value) : (<?= !empty($activeAssignments) ? (int)$activeAssignments[0]['assignment_id'] : 0 ?>);
    const submittedList = submittedMap[currentAssignId] || [];

    // 이미 제출 완료된 학생인 경우 재제출 확인 모달
    if (submittedList.includes(studentId)) {
        document.getElementById('retakeStudentName').textContent = name;
        document.getElementById('retakeModal').style.display = 'flex';
        return;
    }

    openPinModal();
}

function confirmRetake() {
    closeModal('retakeModal');
    openPinModal();
}

function openPinModal() {
    if (!currentStudent) return;
    document.getElementById('pinModalTitle').textContent = `${currentStudent.name} - 인증`;
    document.getElementById('pinInput').value = '';
    document.getElementById('pinModal').style.display = 'flex';
    setTimeout(() => document.getElementById('pinInput').focus(), 100);
}

// 5. PIN 인증 및 제출 화면 이동 (이탈 경고창 없이 매끄럽게 전환)
async function verifyPin() {
    const pin = document.getElementById('pinInput').value;
    const assignSelect = document.getElementById('assignmentSelect');
    const assignId = assignSelect ? assignSelect.value : (<?= !empty($activeAssignments) ? (int)$activeAssignments[0]['assignment_id'] : 0 ?>);

    if (!assignId) {
        alert('진행 중인 평가가 없습니다.');
        return;
    }

    try {
        const res = await fetch('/api/auth/verify_student_pin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ student_id: currentStudent.id, pin: pin })
        });
        const data = await res.json();

        if (data.success) {
            closeModal('pinModal');
            // 'beforeunload' 경고 없이 안전하게 이동
            location.href = `/views/input/single.php?assign_id=${assignId}&mode=kiosk`;
        } else {
            alert(data.error || data.message || '비밀번호(PIN)가 일치하지 않습니다.');
            document.getElementById('pinInput').value = '';
            document.getElementById('pinInput').focus();
        }
    } catch (err) {
        alert('통신 오류가 발생했습니다.');
    }
}

// 6. 키오스크 종료 (관리자 비밀번호 검증)
function promptLogout(e) {
    e.stopPropagation();
    const pwInput = document.getElementById('logoutPasswordInput');
    pwInput.value = '';
    document.getElementById('logoutErrorMsg').style.display = 'none';
    document.getElementById('logoutModal').style.display = 'flex';
    setTimeout(() => pwInput.focus(), 100);
}

async function execLogout() {
    const pwInput = document.getElementById('logoutPasswordInput');
    const errorMsg = document.getElementById('logoutErrorMsg');
    const pw = pwInput.value.trim();

    if (!pw) {
        errorMsg.textContent = '선생님 비밀번호를 입력해 주세요.';
        errorMsg.style.display = 'block';
        pwInput.focus();
        return;
    }

    errorMsg.style.display = 'none';
    const btn = document.getElementById('btnLogoutConfirm');
    btn.disabled = true;
    btn.textContent = '확인 중...';

    try {
        const res = await fetch('/api/auth/verify_kiosk_logout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: pw })
        });
        const data = await res.json();

        if (data.success) {
            location.href = '/api/auth/logout.php';
        } else {
            errorMsg.textContent = data.message || '비밀번호가 일치하지 않습니다.';
            errorMsg.style.display = 'block';
            pwInput.value = '';
            pwInput.focus();
            btn.disabled = false;
            btn.textContent = '종료 및 로그아웃';
        }
    } catch (e) {
        errorMsg.textContent = '통신 오류가 발생했습니다.';
        errorMsg.style.display = 'block';
        btn.disabled = false;
        btn.textContent = '종료 및 로그아웃';
    }
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
</script>
</body>
</html>
