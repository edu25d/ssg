<?php
/**
 * views/dashboard/main.php
 * 교사 메인 대시보드 뷰 (모던 & 반응형 시스템 탑재)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

$userId = requireTeacherAuth();

// 1. 교사 기본 정보 조회
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$teacherUsername = $user['username'] ?? ('user' . $user['id']);
$isGoogleConnected = !empty($user['google_access_token']);

// 2. 학급 목록 및 학급별 등록 학생 수 카운트 조회
$classStmt = $pdo->prepare("
    SELECT c.*, 
           (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.is_active = 1) AS student_count
    FROM classes c 
    WHERE c.user_id = :u AND c.is_active = 1 
    ORDER BY c.id ASC
");
$classStmt->execute(['u' => $userId]);
$classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. 학급 입력기(키오스크) 계정 목록 조회 및 학급별 그룹화
$kioskStmt = $pdo->prepare("
    SELECT k.* 
    FROM kiosk_accounts k
    JOIN classes c ON k.class_id = c.id
    WHERE c.user_id = :u
    ORDER BY k.id ASC
");
$kioskStmt->execute(['u' => $userId]);
$allKiosks = $kioskStmt->fetchAll(PDO::FETCH_ASSOC);

$kiosksByClass = [];
foreach ($allKiosks as $k) {
    $kiosksByClass[$k['class_id']][] = $k;
}

// 4. 템플릿 목록 조회
$tplStmt = $pdo->prepare("SELECT * FROM templates WHERE user_id = :u ORDER BY id DESC");
$tplStmt->execute(['u' => $userId]);
$templates = $tplStmt->fetchAll(PDO::FETCH_ASSOC);

// sheet_gid 컬럼 부재 시 자동 추가 (Self-Healing)
try {
    $pdo->query("SELECT sheet_gid FROM assignments LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE assignments ADD COLUMN sheet_gid VARCHAR(64) NULL DEFAULT NULL");
}

// 5. 배정된 평가 현황 및 실시간 통계 조회
$assignStmt = $pdo->prepare("
    SELECT a.*, t.title AS template_title, c.class_name, c.sheet_id,
           (SELECT COUNT(*) FROM exam_results er WHERE er.assignment_id = a.id) AS submitted_count,
           (SELECT COUNT(*) FROM exam_results er WHERE er.assignment_id = a.id AND er.is_reviewed = 0) AS pending_review_count,
           (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.is_active = 1) AS total_students
    FROM assignments a
    JOIN templates t ON a.template_id = t.id
    JOIN classes c ON a.class_id = c.id
    WHERE t.user_id = :u
    ORDER BY a.created_at DESC
");
$assignStmt->execute(['u' => $userId]);
$assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

// [신규] 활성(진행 중) 평가 중 가장 최근 1건 추출 (모바일 퀵 스캔 히어로 배너용)
$activeHeroAssign = null;
foreach ($assignments as $a) {
    if ($a['status'] !== 'completed') {
        $activeHeroAssign = $a;
        break;
    }
}

// 6. 구글 드라이브 정보 및 OAuth URL 구성
$folderName = $user['google_folder_name'] ?? '쌤글라스';
$fullPath   = '/' . $folderName;
$folderId   = $user['google_root_folder_id'] ?? '';

$driveUrl = !empty($folderId) 
    ? "https://drive.google.com/drive/folders/" . urlencode($folderId) 
    : "https://drive.google.com/";

$clientId    = env('GOOGLE_CLIENT_ID', '');
$redirectUri = env('GOOGLE_REDIRECT_URI', getBaseUrl() . "/google_callback.php");

$googleParams = [
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets',
    'access_type'   => 'offline',
    'prompt'        => 'consent'
];
$googleAuthUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($googleParams);

$baseUrl = getBaseUrl();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>교사 대시보드 - 쌤글라스</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <link rel="stylesheet" href="/public/assets/css/dashboard.css?v=<?= file_exists(APP_ROOT . '/public/assets/css/dashboard.css') ? filemtime(APP_ROOT . '/public/assets/css/dashboard.css') : time() ?>">
</head>
<body>
    <div id="toast">알림</div>

    <div class="dashboard-container">
        <!-- 상단 헤더 바 (슬림 & 미니멀) -->
        <header class="header-bar">
            <h1 class="header-title">
                👩‍🏫 <?= e($user['name'] ?? '선생님') ?> 선생님의 대시보드
            </h1>
            <div class="header-actions">
                <?php if ($isGoogleConnected): ?>
                    <button type="button" class="header-drive-btn" onclick="openModal('driveSettingsModal')" title="구글 드라이브 작업 폴더 설정">
                        <span>📁</span> 드라이브 설정
                    </button>
                <?php else: ?>
                    <a href="<?= $googleAuthUrl ?>" class="header-drive-btn not-connected" title="구글 계정 연동하기">
                        <span>📁</span> 드라이브 연동
                    </a>
                <?php endif; ?>
                <span class="header-sep">·</span>
                <a href="/api/auth/logout.php" class="header-logout-btn">로그아웃</a>
            </div>
        </header>

        <!-- [신규] 모바일 세그먼트 탭 컨트롤러 (2단 집중 탭) -->
        <div class="mobile-segment-tabs">
            <button type="button" class="segment-tab-btn active" onclick="switchMobileTab('assignments', this)">🎯 평가 & 시험지 촬영</button>
            <button type="button" class="segment-tab-btn" onclick="switchMobileTab('classes', this)">🏫 학급 & 학생 관리</button>
        </div>

        <!-- 1. 평가 학급 관리 카드 그리드 (상단 배치) -->
        <section id="tabSectionClasses" class="dashboard-card tab-section">
            <div class="section-header">
                <h2 class="section-title">🏫 평가 학급 관리</h2>
                <button type="button" class="btn btn-sm btn-primary" onclick="openAddModal()">+ 새 학급 추가</button>
            </div>

            <?php if (empty($classes)): ?>
                <div style="background: #f8fafc; padding: 30px; border-radius: 8px; text-align: center; border: 1px dashed #cbd5e1;">
                    <p style="color: #64748b; font-size: 1.05rem; margin-bottom: 15px;">등록된 평가 학급이 없습니다.<br>학급을 생성하고 학생 명단을 간편하게 등록해 보세요.</p>
                    <button type="button" class="btn btn-primary" onclick="openAddModal()">지금 학급 만들기</button>
                </div>
            <?php else: ?>
                <div class="class-grid">
                    <?php foreach ($classes as $c): 
                        $cId = (int)$c['id'];
                        $classKiosks = $kiosksByClass[$cId] ?? [];
                        $classPortalUrl = "{$baseUrl}/views/student/login.php?class_id={$cId}";
                        $studentCount = (int)($c['student_count'] ?? 0);
                    ?>
                        <div class="class-card">
                            <div>
                                <!-- 1단: 학급명 + 학생 수 + 설정 버튼 -->
                                <div class="class-card-header">
                                    <div class="class-card-title">
                                        <span class="class-name-text"><?= e($c['class_name']) ?></span>
                                        <span class="student-badge"><?= $studentCount ?>명</span>
                                    </div>
                                    <button type="button" class="edit-card-btn" onclick="openSingleEditModal(<?= $cId ?>, '<?= e($c['class_name']) ?>')" title="학급명 수정/삭제">⚙️</button>
                                </div>

                                <div class="class-card-body">
                                    <!-- 2단: 학생 접속 전용 내 공부방 버튼 (QR / 링크) -->
                                    <button type="button" class="btn-class-portal" onclick="openClassShareSheet(<?= $cId ?>, '<?= htmlspecialchars(addslashes($c['class_name']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($classPortalUrl, ENT_QUOTES, 'UTF-8') ?>')">
                                        <span>🌐</span> 내 공부방 (접속 QR / 링크)
                                    </button>

                                    <!-- 3단: 핵심 데이터 관리 (학생 명단 / 구글 시트) -->
                                    <div class="class-card-actions">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="openStudentRosterModal(<?= $cId ?>, '<?= e($c['class_name']) ?>')">
                                            학생 명단
                                        </button>
                                        <?php if (!empty($c['sheet_id'])): ?>
                                            <a href="https://docs.google.com/spreadsheets/d/<?= e($c['sheet_id']) ?>/edit" target="_blank" class="btn btn-sm btn-outline" style="text-decoration:none;">
                                                📊 구글 시트
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-secondary" disabled title="구글 드라이브 미연동">시트 미연동</button>
                                        <?php endif; ?>
                                    </div>

                                    <!-- 4단: 학급 입력기 ID 콤팩트 행 -->
                                    <div class="kiosk-footer-row">
                                        <div class="kiosk-footer-left">
                                            <span class="kiosk-footer-label">입력기:</span>
                                            <?php if (empty($classKiosks)): ?>
                                                <span class="kiosk-empty-text">미등록</span>
                                            <?php else: ?>
                                                <?php foreach ($classKiosks as $k): ?>
                                                    <span class="kiosk-badge"><?= e($k['kiosk_id']) ?></span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="kiosk-footer-right">
                                            <?php if (empty($classKiosks)): ?>
                                                <button type="button" class="btn-kiosk-manage" onclick="createKioskAccount(<?= $cId ?>)">ID 등록</button>
                                            <?php else: ?>
                                                <button type="button" class="btn-kiosk-manage" onclick="openKioskEditModal(<?= $cId ?>, '<?= e($c['class_name']) ?>', '<?= e($teacherUsername) ?>')">ID 관리</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="add-class-card" onclick="openAddModal()">
                        <div style="font-size: 1.8em; font-weight: bold; line-height: 1;">+</div>
                        <div style="font-weight: 700; margin-top: 4px; font-size: 0.85rem;">새 학급 추가</div>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <!-- 2. 평가 배정 및 진행 현황 (하단 배치) -->
        <section id="tabSectionAssignments" class="dashboard-card tab-section active">
            <div class="section-header">
                <h2 class="section-title">🎯 평가 진행 현황 & 배정 관리</h2>
                <div style="display:flex; gap:8px;" class="desktop-only">
                    <a href="/views/templates/list.php" class="btn btn-sm btn-outline-primary" style="text-decoration:none;">📋 템플릿 보관함</a>
                    <a href="/views/templates/upload.php" class="btn btn-sm btn-primary" style="text-decoration:none;">+ 새 템플릿 등록</a>
                </div>
            </div>

            <!-- 새 평가 배정 폼 (콤팩트 인라인 바: 약 550px) -->
            <form action="/api/templates/assign.php" method="POST" class="assign-form-compact-bar">
                <div class="assign-inline-row">
                    <span class="assign-inline-label">새 평가 배정:</span>
                    
                    <div class="assign-inline-select-box">
                        <select name="template_id" id="assignTemplateSelect" required class="assign-select" onchange="onTemplateSelectChange(this.value)">
                            <option value="">📋 평가지 템플릿 선택</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= e($t['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="assign-inline-multi-box multi-select-container">
                        <button type="button" class="multi-select-btn" onclick="toggleClassDropdown()">
                            <span id="selectLabel">대상 학급 선택</span>
                            <small>▼</small>
                        </button>
                        <div class="multi-select-dropdown" id="classDropdown">
                            <label class="dropdown-item">
                                <input type="checkbox" id="selectAllClasses" onchange="toggleAllClasses(this)">
                                <b>전체 선택</b>
                            </label>
                            <hr style="border:0; border-top:1px solid #eee; margin:4px 0;">
                            <?php foreach ($classes as $c): ?>
                                <label class="dropdown-item">
                                    <input type="checkbox" name="class_ids[]" value="<?= (int)$c['id'] ?>" class="class-checkbox" onchange="updateSelectLabel()">
                                    <?= e($c['class_name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn-assign-inline">🚀 평가 시작</button>
                </div>

                <!-- 평가지 선택 시 나타나는 ROI/정답 점검 링크 -->
                <div id="selectedTemplateEditWrapper" style="display:none; text-align:left; margin-top:4px; padding-left:90px;">
                    <a id="btnEditSelectedTemplate" href="#" target="_blank" style="font-size:0.8rem; color:#2563eb; text-decoration:none; font-weight:700;" title="선택한 평가지의 ROI 및 정답을 점검/수정합니다">
                        ✏️ 선택된 평가지 ROI/정답 수정 ▶
                    </a>
                </div>
            </form>

            <!-- 데스크톱 전용 모던 정제 테이블 (산만함 제거 & 구글 시트 탭 직행 연동) -->
            <div class="table-responsive desktop-table-container">
                <table class="dashboard-table-v3">
                    <thead>
                        <tr>
                            <th style="width: 28%;">평가 정보 & 정책</th>
                            <th style="width: 25%;">실시간 접수 및 검수</th>
                            <th style="width: 32%; text-align:center;">수집 채널 및 결과 확인</th>
                            <th style="width: 15%; text-align:right;">마감 관리</th>
                        </tr>
                    </thead>
                    <tbody id="statusTableBody">
                        <?php if (empty($assignments)): ?>
                            <tr><td colspan="4" style="padding:40px; color:#64748b; text-align:center;">현재 진행 중이거나 배정된 평가가 없습니다.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assignments as $a): 
                                $aId = (int)$a['id'];
                                $singleInputUrl = "{$baseUrl}/views/input/single.php?assign_id={$aId}";
                                $isClosed = ($a['status'] === 'completed');
                                $sheetTabUrl = !empty($a['sheet_id']) && !empty($a['sheet_gid'])
                                    ? "https://docs.google.com/spreadsheets/d/" . rawurlencode($a['sheet_id']) . "/edit#gid=" . rawurlencode($a['sheet_gid'])
                                    : "/api/google/open_sheet.php?assign_id={$aId}";
                                $totalStudents = (int)($a['total_students'] ?? 0);
                                $submittedCount = (int)($a['submitted_count'] ?? 0);
                                $percent = $totalStudents > 0 ? min(100, round(($submittedCount / $totalStudents) * 100)) : 0;
                            ?>
                                <tr id="assign-row-<?= $aId ?>" class="<?= $isClosed ? 'row-closed' : '' ?>">
                                    <!-- 1. 평가 정보 & 정책 -->
                                    <td>
                                        <div class="exam-title-row">
                                            <span class="class-pill-badge"><?= e($a['class_name']) ?></span>
                                            <strong class="exam-name-text"><?= e($a['template_title']) ?></strong>
                                            <?php if ($isClosed): ?>
                                                <span class="status-pill status-closed">🔒 마감됨</span>
                                            <?php elseif ($a['status'] === 'waiting'): ?>
                                                <span class="status-pill status-waiting">⚪ 대기 중</span>
                                            <?php else: ?>
                                                <span class="status-pill status-active">🟢 진행 중</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!$isClosed): ?>
                                            <label class="toggle-answer-subtle" title="체크 시 학생 대시보드에 모범 답안이 노출되고, 해제 시 숨겨져 오답 재도전이 가능합니다.">
                                                <input type="checkbox" id="chkShowAnswer_<?= $aId ?>" onchange="toggleDashboardAnswer(<?= $aId ?>, this)" <?= !empty($a['show_answer']) ? 'checked' : '' ?>>
                                                <span>💡 학생에게 모범답안 공개 중</span>
                                            </label>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 2. 실시간 접수 및 검수 -->
                                    <td>
                                        <?php if ($isClosed): ?>
                                            <div class="progress-status-box">
                                                <div class="progress-text-row">
                                                    <b><?= $submittedCount ?>명 완료</b>
                                                    <span class="sub-note">구글 시트 보관됨</span>
                                                </div>
                                            </div>
                                        <?php elseif ($a['status'] === 'waiting'): ?>
                                            <div class="progress-status-box">
                                                <div class="progress-text-row">
                                                    <span style="color:#64748b;">제출 대기 중</span>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="progress-status-box">
                                                <div class="progress-text-row">
                                                    <b><span id="submitted-count-<?= $aId ?>"><?= $submittedCount ?></span> / <?= $totalStudents ?>명 (<?= $percent ?>%)</b>
                                                    <span style="color:<?= $a['pending_review_count'] > 0 ? '#dc2626' : '#64748b' ?>; font-size:0.75rem;">
                                                        주관식 대기: <b id="pending-count-<?= $aId ?>"><?= (int)$a['pending_review_count'] ?></b>명
                                                    </span>
                                                </div>
                                                <div class="progress-bar-track">
                                                    <div class="progress-bar-fill" id="progress-fill-<?= $aId ?>" style="width: <?= $percent ?>%;"></div>
                                                </div>
                                                <a href="/views/grading/review.php?assign_id=<?= $aId ?>" class="review-board-link">
                                                    📝 채점 검수판 확인하기 ▶
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 3. 수집 채널 및 결과 확인 -->
                                    <td style="text-align: center;">
                                        <?php if ($isClosed): ?>
                                            <div style="display:inline-flex; gap:6px; justify-content:center; align-items:center;">
                                                <a href="<?= e($sheetTabUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn-sheet-tab-link" title="해당 학급 구글 스프레드시트의 이 평가 탭으로 바로 이동합니다">
                                                    <span>📊</span> 결과 시트 ↗
                                                </a>
                                                <a href="/views/grading/print_slips.php?assign_id=<?= $aId ?>" target="_blank" class="btn-sheet-tab-link" style="background:#f8fafc; color:#0f172a; border-color:#cbd5e1;" title="학생 배부용 정오표 일괄 출력">
                                                    <span>🖨️</span> 정오표 출력
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="collection-btn-group">
                                                <a href="/views/input/bulk.php?assign_id=<?= $aId ?>" class="btn-collect-scan">
                                                    <span>📷</span> 교사 일괄 촬영
                                                </a>
                                                <button type="button" class="btn-collect-share" onclick="openShareSheet(<?= $aId ?>, '<?= htmlspecialchars(addslashes($a['template_title']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($a['class_name']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($singleInputUrl, ENT_QUOTES, 'UTF-8') ?>')">
                                                    <span>📱</span> 학생 제출 (QR)
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 4. 마감 관리 -->
                                    <td style="text-align: right;">
                                        <?php if ($isClosed): ?>
                                            <button type="button" class="btn-action-restore" onclick="restoreData(<?= $aId ?>)">
                                                ✏️ 재수정
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn-action-close" onclick="closeAssignment(<?= $aId ?>)">
                                                결과 마감
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- [신규] 모바일 전용 3-in-1 통합 시험 상태 카드 뷰 -->
            <div class="mobile-exam-cards">
                <?php if (empty($assignments)): ?>
                    <div style="background:#f8fafc; padding:30px; border-radius:12px; text-align:center; border:1px dashed #cbd5e1; color:#64748b; font-size:0.95rem;">
                        현재 진행 중이거나 배정된 평가가 없습니다.
                    </div>
                <?php else: ?>
                    <?php foreach ($assignments as $a): 
                        $aId = (int)$a['id'];
                        $singleInputUrl = "{$baseUrl}/views/input/single.php?assign_id={$aId}";
                        $isClosed = ($a['status'] === 'completed');
                        $isWaiting = ($a['status'] === 'waiting');
                        $sheetTabUrl = !empty($a['sheet_id']) && !empty($a['sheet_gid'])
                            ? "https://docs.google.com/spreadsheets/d/" . rawurlencode($a['sheet_id']) . "/edit#gid=" . rawurlencode($a['sheet_gid'])
                            : "/api/google/open_sheet.php?assign_id={$aId}";
                        $totalStudents = (int)($a['total_students'] ?? 0);
                        $submittedCount = (int)$a['submitted_count'];
                        $pendingCount = (int)$a['pending_review_count'];
                        $progressPct = ($totalStudents > 0) ? min(100, round(($submittedCount / $totalStudents) * 100)) : ($submittedCount > 0 ? 100 : 0);
                    ?>
                        <div class="exam-status-card <?= $isClosed ? 'is-closed' : '' ?>" id="mobile-assign-card-<?= $aId ?>">
                            <!-- 1. 상단 통합 헤더 (학급 + 진행상태 + 템플릿명 + 우측 정책설정) -->
                            <div class="card-top-row">
                                <div class="card-meta-left">
                                    <div class="card-badges">
                                        <span class="badge-class"><?= e($a['class_name']) ?></span>
                                        <?php if ($isClosed): ?>
                                            <span class="badge-status status-closed">🔒 마감 완료</span>
                                        <?php elseif ($isWaiting): ?>
                                            <span class="badge-status status-waiting">⚪ 대기 중</span>
                                        <?php else: ?>
                                            <span class="badge-status status-active">
                                                <span class="pulse-dot"></span> 진행 중
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="card-exam-title"><?= e($a['template_title']) ?></h3>
                                </div>

                                <!-- 우측 시험 설정 (정답공개 토글 & 마감/재수정) -->
                                <div class="card-settings-right">
                                    <?php if ($isClosed): ?>
                                        <button type="button" class="btn-setting-restore" onclick="restoreData(<?= $aId ?>)" title="마감 해제 및 성적 재수정 모드로 전환">✏️ 재수정</button>
                                    <?php else: ?>
                                        <label class="toggle-answer-pill" title="체크 시 학생 대시보드에 모범 답안이 노출되고, 해제 시 숨겨집니다.">
                                            <input type="checkbox" id="chkShowAnswerMobile_<?= $aId ?>" onchange="toggleDashboardAnswer(<?= $aId ?>, this)" <?= !empty($a['show_answer']) ? 'checked' : '' ?> style="accent-color:#7c3aed; cursor:pointer;">
                                            <span>💡 정답공개</span>
                                        </label>
                                        <button type="button" class="btn-setting-close" onclick="closeAssignment(<?= $aId ?>)" title="시험 결과를 확정하고 구글 시트로 백업합니다">마감</button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- 2. 중단 실시간 진행 현황 게이지 박스 -->
                            <div class="card-progress-box">
                                <?php if ($isClosed): ?>
                                    <div class="closed-desc">
                                        <span><?= $submittedCount ?>명 전원 제출 완료</span>
                                        <span>구글 시트 성적부 백업 완료 ✅</span>
                                    </div>
                                    <div style="margin-top: 10px; display: flex; gap: 8px;">
                                        <a href="<?= e($sheetTabUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn-sheet-tab-link" style="display:flex; justify-content:center; flex:1; text-decoration:none; padding:8px 10px; border-radius:8px; font-size:0.85rem;">
                                            <span>📊</span> 결과 시트 ↗
                                        </a>
                                        <a href="/views/grading/print_slips.php?assign_id=<?= $aId ?>" target="_blank" class="btn-sheet-tab-link" style="display:flex; justify-content:center; flex:1; text-decoration:none; padding:8px 10px; border-radius:8px; font-size:0.85rem; background:#f8fafc; color:#0f172a; border-color:#cbd5e1;">
                                            <span>🖨️</span> 정오표 출력
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="progress-info-row">
                                        <span>접수 현황: <b class="count-bold submitted-count-<?= $aId ?>"><?= $submittedCount ?>명 완료</b><?= $totalStudents > 0 ? " / {$totalStudents}명" : "" ?></span>
                                        <?php if ($totalStudents > 0): ?>
                                            <span style="color:var(--success); font-weight:800;"><?= $progressPct ?>%</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mini-progress-bar">
                                        <div class="mini-progress-fill" style="width: <?= max(4, $progressPct) ?>%;"></div>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; color:#64748b; margin-top:5px;">
                                        <span class="pending-tag <?= $pendingCount > 0 ? 'has-pending' : '' ?>">
                                            주관식 채점 대기: <b class="pending-count-<?= $aId ?>"><?= $pendingCount ?>명</b>
                                        </span>
                                        <span style="font-size:0.72rem; color:#94a3b8;">실시간 자동 갱신</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- 3. 하단 액션 존 (투 트랙 수집 채널: 교사 일괄 촬영 vs 학생 개별 제출) -->
                            <?php if (!$isClosed): ?>
                                <div class="card-actions-zone">
                                    <div class="track-grid">
                                        <!-- Track 1: 교사 일괄 연속 촬영 -->
                                        <a href="/views/input/bulk.php?assign_id=<?= $aId ?>" class="track-btn track-teacher">
                                            <span class="track-icon">📷</span>
                                            <span class="track-title">교사 일괄 촬영</span>
                                            <span class="track-sub">선생님이 연속 스캔</span>
                                        </a>

                                        <!-- Track 2: 학생 개별 제출 (URL 노출 대신 원터치 바텀시트 안내) -->
                                        <button type="button" class="track-btn track-student" onclick="openShareSheet(<?= $aId ?>, '<?= htmlspecialchars(addslashes($a['template_title']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($a['class_name']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($singleInputUrl, ENT_QUOTES, 'UTF-8') ?>')">
                                            <span class="track-icon">📱</span>
                                            <span class="track-title">학생 개별 제출</span>
                                            <span class="track-sub">공유 / QR 안내</span>
                                        </button>
                                    </div>

                                    <!-- 검수 및 채점 관리 풀 너비 버튼 -->
                                    <a href="/views/grading/review.php?assign_id=<?= $aId ?>" class="btn-card-review">
                                        <span>📝</span> 실시간 채점 검수판 확인하기 (<span class="submitted-count-<?= $aId ?>"><?= $submittedCount ?>명</span>)
                                        <span style="margin-left:auto; font-size:0.75rem; color:#a16207;">▶</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- ========================================================================= -->
    <!-- 모달 목록 -->
    <!-- ========================================================================= -->

    <!-- [신규] 스마트 학생 명단 간편 관리 모달 (2단 탭: 명단/PIN 관리 + 텍스트 일괄 등록) -->
    <div id="studentRosterModal" class="modal">
        <div class="modal-content modal-lg">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 id="rosterModalTitle" style="margin:0;">👥 학생 명단 간편 관리</h3>
                <button type="button" onclick="closeModal('studentRosterModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
            </div>

            <!-- 2단 탭 메뉴 -->
            <div class="roster-tabs">
                <button type="button" class="roster-tab-btn active" id="tabBtnList" onclick="switchRosterTab('list')">
                    📋 등록 학생 및 PIN 관리 (<span id="tabStudentCount">0</span>명)
                </button>
                <button type="button" class="roster-tab-btn" id="tabBtnEdit" onclick="switchRosterTab('edit')">
                    ✍️ 텍스트 일괄 등록 / 수정
                </button>
            </div>

            <!-- [탭 1] 등록된 학생 목록 및 개별 PIN 초기화 -->
            <div id="rosterTabList" class="roster-tab-content active">
                <p style="font-size: 0.88rem; color: #64748b; margin-bottom: 12px;">
                    학급에 등록된 학생 목록입니다. 학생이 비밀번호를 잊어버린 경우 우측의 <b>[🔑 PIN 초기화]</b>를 눌러 새 비밀번호를 지정해 줄 수 있습니다.
                </p>
                <div style="max-height: 320px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <table class="student-manage-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">순번</th>
                                <th>학생 이름</th>
                                <th style="width: 130px;">PIN 상태</th>
                                <th style="width: 110px; text-align: center;">비밀번호 관리</th>
                            </tr>
                        </thead>
                        <tbody id="studentManageTbody">
                            <tr><td colspan="4" style="text-align:center; padding:30px; color:#94a3b8;">학생 목록을 불러오는 중...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px;">
                    <button type="button" class="btn btn-outline-primary" onclick="switchRosterTab('edit')">
                        ✍️ 텍스트로 명단 수정/추가하기
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('studentRosterModal')">닫기</button>
                </div>
            </div>

            <!-- [탭 2] 나이스/엑셀 텍스트 일괄 등록/수정 -->
            <div id="rosterTabEdit" class="roster-tab-content">
                <p style="font-size: 0.88rem; color: #64748b; margin-bottom: 12px;">
                    나이스(NEIS)나 엑셀에서 복사한 학생 명단을 아래에 그대로 붙여넣으세요 (Ctrl+V).<br>
                    <small style="color:#0d6efd;">💡 기본 초기 PIN을 변경하면 이름만 있는 학생에게 일괄 적용되며, 동명이인은 번호나 기호를 붙여 구분해주세요.</small>
                </p>

                <div class="roster-editor-box">
                    <div class="roster-meta-row">
                        <div>
                            <label for="defaultPinInput" style="font-weight:bold; margin-right:6px;">기본 초기 PIN:</label>
                            <input type="text" id="defaultPinInput" value="0000" maxlength="4" style="width:70px; padding:4px 6px; text-align:center; font-weight:bold; border:1px solid #ccc; border-radius:4px; font-size:1rem;" oninput="parseRosterText()">
                            <span style="font-size:0.8rem; color:#64748b; margin-left:4px;">(원하는 4자리 숫자 지정 가능)</span>
                        </div>
                        <span id="rosterCountBadge" class="student-badge">0명 인식됨</span>
                    </div>

                    <textarea id="rosterTextarea" class="roster-textarea" placeholder="여기에 명단을 붙여넣으세요&#10;&#10;예시 1 (이름만):&#10;김철수&#10;이영희&#10;&#10;예시 2 (이름과 PIN):&#10;김철수&#9;1234&#10;이영희&#9;5678" oninput="parseRosterText()"></textarea>

                    <div id="rosterWarning" class="roster-warning-msg"></div>

                    <div>
                        <strong style="font-size:0.88rem; color:#475569;">실시간 인식 미리보기:</strong>
                        <div class="roster-preview-container">
                            <table class="roster-preview-table">
                                <thead>
                                    <tr>
                                        <th style="width:50px;">순번</th>
                                        <th>이름</th>
                                        <th style="width:120px;">설정될 PIN</th>
                                    </tr>
                                </thead>
                                <tbody id="rosterPreviewTbody">
                                    <tr><td colspan="3" style="text-align:center; color:#94a3b8;">입력된 학생이 없습니다.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
                    <button type="button" class="btn btn-secondary" onclick="switchRosterTab('list')">이전 목록 보기</button>
                    <button type="button" id="rosterSaveBtn" class="btn btn-primary" onclick="saveStudentRoster()" disabled>학생 명단 일괄 저장</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 학급 추가 모달 -->
    <div id="addClassModal" class="modal">
        <div class="modal-content">
            <h3>새 학급 추가</h3>
            <form action="/api/classes/create.php" method="POST">
                <input type="text" name="class_names" placeholder="학급명 입력 (여러 개는 쉼표로 구분, 예: 1반, 2반)" required style="width:100%; padding:10px; margin:14px 0; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:10px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addClassModal')">취소</button>
                    <button type="submit" class="btn btn-primary">생성하기</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 학급 정보 수정 모달 -->
    <div id="editClassModal" class="modal">
        <div class="modal-content">
            <h3>학급 정보 수정</h3>
            <form action="/api/classes/update.php" method="POST">
                <input type="hidden" name="class_id" id="editClassId">
                <div style="text-align:left; margin-bottom:15px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">학급명</label>
                    <input type="text" name="class_name" id="editClassName" required oninput="this.value = this.value.replace(/,/g, '');" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="수정할 학급명 입력">
                    <small style="color:#888;">※ 쉼표(,)는 입력할 수 없습니다.</small>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px;">
                    <button type="button" onclick="deleteClass()" style="color:#dc3545; background:none; border:none; cursor:pointer; text-decoration:underline; font-weight:bold;">이 학급 삭제</button>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('editClassModal')">취소</button>
                        <button type="submit" class="btn btn-success">저장</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- 키오스크 계정 관리 모달 -->
    <div id="kioskEditModal" class="modal">
        <div class="modal-content">
            <h3 id="kioskModalTitle">학급 입력기 ID 관리</h3>
            <p style="font-size: 0.85rem; color: #666;">선생님 계정 접두사 뒤의 식별자를 변경할 수 있습니다.<br>(영문, 숫자만 입력 가능)</p>
            <div id="kioskListContainer" style="max-height: 250px; overflow-y: auto; margin: 15px 0; text-align: left;"></div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px;">
                <button type="button" class="btn btn-outline-primary" id="kioskAddBtn" style="font-size:0.85rem; font-weight:700;">+ 새 ID 추가</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('kioskEditModal')">닫기</button>
            </div>
        </div>
    </div>

    <!-- 구글 드라이브 폴더 선택 모달 -->
    <div class="modal" id="folderModal">
        <div class="modal-content">
            <h3 id="folderModalTitle">작업 폴더 선택 및 자료 이동</h3>
            <p style="font-size: 0.9rem; color: #666; word-break: keep-all;">구글 드라이브 내의 폴더를 선택하면, 기존 자료들이 <b>새 폴더로 자동 이동</b>합니다.</p>
            <form action="/api/google/change_folder.php" method="POST">
                <input type="hidden" name="new_folder_name" id="newFolderName" value="">
                <select name="new_folder_id" id="folderSelect" required style="width: 100%; padding: 10px; margin: 15px 0; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem;">
                    <option value="">폴더 목록 대기 중...</option>
                </select>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top: 15px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('folderModal')">취소</button>
                    <button type="submit" class="btn btn-primary">선택한 폴더로 이동</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 구글 드라이브 & 작업 폴더 설정 모달 -->
    <div class="modal" id="driveSettingsModal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                    <span>📁</span> 구글 드라이브 & 폴더 설정
                </h3>
                <button type="button" onclick="closeModal('driveSettingsModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
            </div>

            <?php if ($isGoogleConnected): ?>
                <div style="background:#f8fafc; padding:15px; border-radius:10px; border:1px solid #e2e8f0; font-size:0.9rem; line-height:1.8;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="color:#64748b; font-weight:600;">연동 상태:</span>
                        <span style="color:#16a34a; font-weight:700;">✅ 정상 연동됨</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px;">
                        <span style="color:#64748b; font-weight:600;">현재 작업 폴더:</span>
                        <a href="<?= e($driveUrl) ?>" target="_blank" class="google-path-link" style="font-weight:700; color:#2563eb; text-decoration:underline;">
                            <?= e($fullPath) ?> ↗
                        </a>
                    </div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px;">
                    <a href="/api/google/disconnect.php" class="btn btn-sm btn-outline-danger" onclick="return confirm('구글 드라이브 연결을 해제하시겠습니까?');">🔌 연동 해제</a>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('driveSettingsModal'); openFolderModal();">⚙️ 작업 폴더 변경</button>
                        <button type="button" class="btn btn-sm btn-primary" onclick="closeModal('driveSettingsModal')">닫기</button>
                    </div>
                </div>
            <?php else: ?>
                <div style="background:#f8fafc; padding:25px; border-radius:10px; text-align:center; border:1px dashed #cbd5e1;">
                    <p style="color:#64748b; margin-bottom:15px; line-height:1.5;">현재 구글 드라이브 계정이 연동되어 있지 않습니다.<br>연동 시 채점 결과가 학급별 스프레드시트로 자동 백업됩니다.</p>
                    <a href="<?= $googleAuthUrl ?>" class="btn btn-primary">구글 계정 연동하기</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- QR 표시 모달 -->
    <div class="modal" id="qrModal">
        <div class="modal-content">
            <h3 id="qrModalTitle">접속 QR 코드</h3>
            <p style="font-size: 0.9rem; color: #666;">교실 TV에 띄우거나 출력하여 활용하세요.</p>
            <div id="qrcode-container"></div>
            <div style="display:flex; justify-content:center; margin-top:15px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('qrModal')">닫기</button>
            </div>
        </div>
    </div>

    <!-- [신규] 학생 접속 및 개별 제출 전용 공유 모달 (화면 중앙 정렬) -->
    <div class="modal" id="studentShareModal" style="align-items: center;" onclick="if(event.target === this) closeModal('studentShareModal')">
        <div class="modal-content share-modal-content">
            <div class="share-sheet-header">
                <div>
                    <h3 id="shareModalExamTitle" style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a;">📱 학생 개별 제출 안내</h3>
                    <p id="shareModalClassTitle" style="margin:4px 0 0 0; font-size:0.83rem; color:#64748b;">학생이 각자의 기기로 직접 접속하여 답안지를 제출합니다.</p>
                </div>
                <button type="button" onclick="closeModal('studentShareModal')" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#94a3b8; line-height:1;">&times;</button>
            </div>

            <!-- QR 코드 렌더링 영역 -->
            <div class="share-qr-box">
                <div id="shareSheetQrcodeContainer" style="display:flex; justify-content:center; align-items:center; min-height:170px;"></div>
                <p style="margin:10px 0 0 0; font-size:0.82rem; font-weight:700; color:#475569;">스마트폰/태블릿 카메라로 QR을 스캔하세요</p>
            </div>

            <!-- 액션 버튼 그리드 -->
            <div class="share-actions-grid">
                <button type="button" class="btn btn-secondary" onclick="copyCurrentShareLink()" style="font-weight:700; padding:12px; font-size:0.9rem;">
                    📋 링크 주소 복사
                </button>
                <button type="button" class="btn btn-primary" onclick="openTvQrFromShare()" style="font-weight:700; padding:12px; font-size:0.9rem;">
                    📺 TV/새 창으로 띄우기
                </button>
            </div>
        </div>
    </div>

    <script src="/public/assets/js/dashboard.js?v=<?= file_exists(APP_ROOT . '/public/assets/js/dashboard.js') ? filemtime(APP_ROOT . '/public/assets/js/dashboard.js') : time() ?>"></script>
</body>
</html>
