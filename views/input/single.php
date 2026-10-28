<?php
/**
 * views/input/single.php
 * 학생 개별 시험지 카메라 촬영, 자동 원근 보정 및 제출 화면
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

$assignId = (int)($_GET['assign_id'] ?? 0);
$mode     = $_GET['mode'] ?? 'student'; 

if (!$assignId) {
    echo "<script>alert('잘못된 접근입니다.'); history.back();</script>";
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT a.*, COALESCE(c.class_name, '우리반') AS class_name, t.title AS template_title, t.pages_data
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        LEFT JOIN classes c ON a.class_id = c.id
        WHERE a.id = :id
    ");
    $stmt->execute(['id' => $assignId]);
    $assignRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignRow) {
        echo "<script>alert('존재하지 않는 과제입니다.'); history.back();</script>";
        exit;
    }

    $templatePages = [];
    if (!empty($assignRow['pages_data'])) {
        $templatePages = json_decode($assignRow['pages_data'], true) ?: [];
    }
    $totalPages = max(1, count($templatePages));

    $classId    = (int)$assignRow['class_id'];
    $className  = $assignRow['class_name'] ?? '우리반';
    $examName   = $assignRow['exam_name'] ?? $assignRow['template_title']; 
    $workFolder = $assignRow['work_folder'] ?? '쌤글라스';

    // 학생 로그인 세션 확인 및 미인증 시 로그인 화면으로 리다이렉트
    $studentId   = requireStudentAuth($classId);
    $studentName = $_SESSION['student_name'] ?? '학생';

} catch (Exception $e) {
    die("DB 오류: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <script src="/__preview-bridge.js" data-preview-bridge></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= e($examName) ?> - 시험지 제출</title>
    <style>
        :root {
            --primary: #0d6efd;
            --primary-hover: #0b5ed7;
            --success: #198754;
            --gray-100: #f8fafc;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-600: #64748b;
            --gray-800: #1e293b;
            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif;
            background: #f1f5f9;
            color: var(--gray-800);
            margin: 0;
            padding: 16px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }

        /* 키오스크 전용 일체형 테마 */
        body.kiosk-mode {
            padding-top: 76px;
            background: #f4f6f9;
        }
        .kiosk-top-bar {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 60px;
            background: #2c3e50;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        }
        .kiosk-bar-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-kiosk-back {
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #f1f5f9;
            padding: 7px 16px;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-kiosk-back:hover {
            background: rgba(255, 255, 255, 0.25);
            color: #38bdf8;
            border-color: #38bdf8;
        }

        /* 중앙 영역 학생 이름 타이틀 */
        .kiosk-student-header {
            padding-bottom: 12px;
            margin-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
        }
        .kiosk-student-title {
            margin: 0;
            font-size: 1.45rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* 간결 가이드 미니 카드 */
        .kiosk-col-side {
            margin-top: 24px;
        }
        .guide-mini-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            margin-bottom: 14px;
        }
        .guide-mini-card.guide-camera {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }
        .guide-mini-card.guide-check {
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        .guide-mini-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .guide-camera .guide-mini-title {
            color: #166534;
        }
        .guide-check .guide-mini-title {
            color: #1e40af;
        }
        .guide-mini-desc {
            font-size: 0.88rem;
            color: #334155;
            line-height: 1.5;
            font-weight: 600;
        }

        /* 다쪽 평가지 전용 슬롯 네비게이션 & 플립 넛지 */
        .slot-container {
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
        }
        .slot-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .slot-title { font-size: 0.95rem; font-weight: 800; color: #1e293b; }
        .slot-badge { font-size: 0.8rem; font-weight: 700; background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 10px; }
        .slot-cards {
            display: flex;
            gap: 8px;
            overflow-x: auto;
        }
        .slot-card {
            flex: 1;
            min-width: 90px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .slot-card.active {
            border-color: #0d6efd;
            background: #eff6ff;
            box-shadow: 0 2px 8px rgba(13,110,253,0.15);
        }
        .slot-card.completed {
            border-color: #198754;
            background: #f0fdf4;
        }
        .slot-card-title { font-size: 0.85rem; font-weight: 800; color: #1e293b; margin-bottom: 4px; }
        .slot-card-status { font-size: 0.75rem; font-weight: 700; color: #64748b; }
        .slot-card.completed .slot-card-status { color: #16a34a; }
        .slot-card.active .slot-card-status { color: #0d6efd; }

        .flip-nudge-banner {
            background: #fef3c7;
            border: 1.5px solid #fde68a;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: pulseNudge 1.2s ease-in-out infinite alternate;
        }
        @keyframes pulseNudge {
            from { transform: scale(1); }
            to { transform: scale(1.02); }
        }
        .flip-icon { font-size: 1.8rem; color: #b45309; }
        .flip-main-text { font-size: 0.95rem; font-weight: 800; color: #92400e; }
        .flip-sub-text { font-size: 0.82rem; color: #b45309; margin-top: 2px; }

        .multi-preview-strip {
            display: flex;
            gap: 10px;
            margin-bottom: 14px;
            overflow-x: auto;
            padding: 4px 0;
        }
        .multi-preview-item {
            width: 75px;
            height: 100px;
            border: 2px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            cursor: pointer;
            flex-shrink: 0;
            position: relative;
            background: #f1f5f9;
        }
        .multi-preview-item.active { border-color: #0d6efd; box-shadow: 0 0 0 2px rgba(13,110,253,0.3); }
        .multi-preview-item img { width: 100%; height: 100%; object-fit: cover; }
        .multi-preview-tag {
            position: absolute;
            bottom: 2px;
            left: 2px;
            right: 2px;
            background: rgba(0,0,0,0.65);
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            text-align: center;
            border-radius: 3px;
            padding: 1px 0;
        }

        /* 시험지 이름과 촬영 박스를 직접 연결하는 헤더 */
        .camera-exam-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }
        .camera-exam-tag {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            font-size: 0.82rem;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 6px;
            letter-spacing: -0.01em;
        }
        .camera-exam-title {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: -0.01em;
        }
        .container.kiosk-container {
            max-width: 1060px;
            margin: 15px auto;
            padding: 24px;
        }
        @media (min-width: 860px) {
            .kiosk-grid-layout {
                display: grid;
                grid-template-columns: 1.25fr 1fr;
                gap: 24px;
                align-items: start;
            }
            .kiosk-grid-layout .kiosk-col-side {
                margin-top: 0;
            }
        }
        .kiosk-info-card {
            background: #f8fafc;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            margin-top: 14px;
        }

        .container {
            width: 100%;
            max-width: 580px;
            background: #ffffff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            padding: 24px 20px;
            margin: 10px auto;
        }

        /* 헤더 영역 */
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: 16px;
            margin-bottom: 18px;
        }
        .header-title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .meta-badges {
            display: flex;
            gap: 6px;
            margin-top: 6px;
            flex-wrap: wrap;
        }
        .meta-badge {
            background: #f1f5f9;
            color: var(--gray-600);
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 700;
            background: #fff3cd;
            color: #856404;
            white-space: nowrap;
        }
        .status-pill.ready {
            background: #e8f5e9;
            color: #2e7d32;
        }

        /* 촬영 꿀팁 가이드 박스 */
        .tips-card {
            background: #f0f7ff;
            border: 1px solid #cce3ff;
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            margin-bottom: 20px;
        }
        .tips-title {
            font-weight: 700;
            font-size: 0.88rem;
            color: #0056b3;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .tips-list {
            margin: 0;
            padding-left: 18px;
            font-size: 0.82rem;
            color: #334155;
            line-height: 1.5;
        }

        /* 업로드 영역 (사진 첨부 전) */
        .upload-dropzone {
            border: 2px dashed #94a3b8;
            border-radius: var(--radius-md);
            padding: 36px 16px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .upload-dropzone:hover, .upload-dropzone:active {
            border-color: var(--primary);
            background: #eff6ff;
        }
        .upload-icon {
            font-size: 2.8rem;
            margin-bottom: 10px;
            display: block;
        }
        .upload-text {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }
        .upload-subtext {
            font-size: 0.82rem;
            color: #64748b;
        }

        /* 미리보기 영역 (사진 첨부 후) */
        .preview-wrapper {
            display: none;
            margin-top: 10px;
        }
        .preview-img-container {
            width: 100%;
            min-height: 380px;
            max-height: 480px;
            background: #0f172a;
            border-radius: var(--radius-md);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border: 1px solid var(--gray-300);
            position: relative;
        }
        .preview-img {
            max-width: 100%;
            max-height: 480px;
            object-fit: contain;
            display: block;
        }

        /* 버튼 그룹 */
        .action-row {
            display: flex;
            gap: 10px;
            margin-top: 18px;
            margin-bottom: 6px;
        }
        .btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-sizing: border-box;
            text-decoration: none;
        }
        .btn-primary {
            flex: 1.6;
            background: linear-gradient(135deg, #0d6efd 0%, #0052cc 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(13, 110, 253, 0.4);
        }
        .btn-secondary {
            flex: 1;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid var(--gray-300);
        }
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* 인플레이스 진행 및 접수 완료 박스 (모달 대체) */
        .inplace-status-box {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.90);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 20px;
            box-sizing: border-box;
            color: #ffffff;
            text-align: center;
            z-index: 10;
            overflow-y: auto;
            animation: inplaceFadeIn 0.3s ease;
        }
        @keyframes inplaceFadeIn {
            from { opacity: 0; transform: scale(0.98); }
            to { opacity: 1; transform: scale(1); }
        }

        .inplace-spinner {
            width: 44px;
            height: 44px;
            border: 4px solid rgba(255, 255, 255, 0.2);
            border-top-color: #38bdf8;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 16px auto;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .inplace-title {
            margin: 0 0 6px 0;
            font-size: 1.2rem;
            font-weight: 800;
            color: #ffffff;
        }
        .inplace-desc {
            margin: 0 0 16px 0;
            font-size: 0.88rem;
            color: #94a3b8;
            line-height: 1.4;
        }

        .progress-bar-wrap {
            width: 100%;
            max-width: 290px;
            height: 8px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            overflow: hidden;
            margin: 0 auto 16px auto;
        }
        .progress-bar-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #38bdf8, #818cf8);
            transition: width 0.3s ease;
        }

        .step-list {
            text-align: left;
            margin: 0 auto;
            padding: 0;
            list-style: none;
            width: 100%;
            max-width: 290px;
            font-size: 0.82rem;
        }
        .step-item {
            padding: 5px 0;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .step-item.active {
            color: #38bdf8;
            font-weight: 700;
        }
        .step-item.done {
            color: #4ade80;
        }

        /* 최종 제출 완료 상태 (접수증 카드) */
        .inplace-done-icon {
            font-size: 3.2rem;
            margin-bottom: 8px;
            animation: popBounce 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @keyframes popBounce {
            0% { transform: scale(0.3); opacity: 0; }
            70% { transform: scale(1.15); }
            100% { transform: scale(1); opacity: 1; }
        }
        .inplace-done-title {
            margin: 0 0 4px 0;
            font-size: 1.35rem;
            font-weight: 900;
            color: #ffffff;
        }
        .inplace-done-subtitle {
            margin: 0 0 16px 0;
            font-size: 0.88rem;
            color: #94a3b8;
        }

        .inplace-receipt-card {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 12px 18px;
            width: 100%;
            max-width: 290px;
            margin: 0 auto 16px auto;
            box-sizing: border-box;
            text-align: left;
        }
        .receipt-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            font-size: 0.82rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .receipt-row:last-child {
            border-bottom: none;
        }
        .receipt-label {
            color: #94a3b8;
            font-weight: 600;
        }
        .receipt-value {
            color: #f8fafc;
            font-weight: 700;
        }
        .receipt-value.status-ok {
            color: #4ade80;
        }

        .inplace-done-message {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.25);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.82rem;
            color: #86efac;
            line-height: 1.5;
            font-weight: 600;
            max-width: 290px;
            margin: 0 auto;
        }

        #fileInput { display: none; }
    </style>
    <script async src="https://docs.opencv.org/4.x/opencv.js" onload="onOpenCvReady()"></script>
</head>
<body class="<?= $mode === 'kiosk' ? 'kiosk-mode' : '' ?>">

<?php if ($mode === 'kiosk'): ?>
    <!-- 크롬북/키오스크 전용 일체형 상단 바 (kiosk.php와 100% 동일 위치 유지) -->
    <header class="kiosk-top-bar">
        <div class="kiosk-bar-title">
            <span>🏫</span> <?= e($className) ?> 공용 키오스크
        </div>
        <a href="/views/student/logout.php?class_id=<?= $classId ?>" class="btn-kiosk-back" title="제출을 취소하고 학생 명단으로 돌아가기">
            <span>◀</span> 제출 취소
        </a>
    </header>
<?php endif; ?>

<div class="container <?= $mode === 'kiosk' ? 'kiosk-container' : '' ?>">
    <?php if ($mode !== 'kiosk'): ?>
        <!-- 모바일 학생 모드 전용 헤더 -->
        <header class="card-header">
            <div>
                <h1 class="header-title">🎓 <?= e($studentName) ?> 학생의 시험지 제출</h1>
                <div class="meta-badges">
                    <span class="meta-badge">🏫 <?= e($className) ?></span>
                    <span class="meta-badge">📄 <?= e($examName) ?></span>
                </div>
            </div>
            <div id="cvStatus" class="status-pill">
                <span>⏳</span> 스캐너 로딩 중
            </div>
        </header>
    <?php endif; ?>

    <div class="<?= $mode === 'kiosk' ? 'kiosk-grid-layout' : '' ?>">
        <!-- 1열: 촬영 및 미리보기 영역 -->
        <div class="kiosk-col-camera">
            <?php if ($mode === 'kiosk'): ?>
                <!-- 중앙 영역 학생 이름 타이틀 -->
                <div class="kiosk-student-header">
                    <h1 class="kiosk-student-title">🎓 <?= e($studentName) ?> 학생 답안지 제출</h1>
                </div>

                <!-- 시험지 이름과 촬영 박스를 직접 연결하는 헤더 (이름 표시 밑) -->
                <div class="camera-exam-header">
                    <span class="camera-exam-tag">제출 시험</span>
                    <h2 class="camera-exam-title">📄 <?= e($examName) ?></h2>
                </div>
            <?php endif; ?>

            <?php if ($totalPages >= 2): ?>
                <!-- 다쪽 평가지 전용 슬롯 네비게이션 & 플립 넛지 -->
                <div class="slot-container" id="slotContainer">
                    <div class="slot-header">
                        <span class="slot-title">📑 총 <?= $totalPages ?>쪽 평가지 제출</span>
                        <span class="slot-badge" id="slotBadge">0 / <?= $totalPages ?>쪽 완료</span>
                    </div>
                    <div class="slot-cards" id="slotCards"></div>
                </div>

                <div class="flip-nudge-banner" id="flipNudgeBanner" style="display: none;">
                    <span class="flip-icon">↷</span>
                    <div class="flip-text-wrap">
                        <div class="flip-main-text">1쪽 촬영 완료! 이제 시험지를 뒤집어주세요</div>
                        <div class="flip-sub-text">뒷면(2쪽)의 4개 모서리가 화면에 들어오게 촬영하세요.</div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 파일 선택 및 미리보기 -->
            <input type="file" id="fileInput" accept="image/*" capture="environment">

            <!-- 1. 사진 첨부 전 (드롭존 형태) -->
            <div id="uploadZone" class="upload-dropzone" onclick="document.getElementById('fileInput').click()">
                <span class="upload-icon">📷</span>
                <div class="upload-text">시험지 사진 찍기 / 파일 첨부</div>
                <div class="upload-subtext">여기를 터치하여 카메라를 실행하거나 갤러리에서 선택하세요.</div>
            </div>

            <!-- 2. 사진 첨부 후 (미리보기 및 제출) -->
            <div class="preview-wrapper" id="previewBox">
                <div class="preview-img-container" id="previewContainer">
                    <img id="previewImage" class="preview-img" src="" alt="시험지 미리보기">

                    <!-- 인플레이스 상태 표시 박스 (스캔/진행 & 완료 접수증) -->
                    <div id="inplaceStatusBox" class="inplace-status-box" style="display: none;">
                        <!-- 1. 처리 중 상태 -->
                        <div id="inplaceProgressContent" style="width: 100%; max-width: 300px;">
                            <div class="inplace-spinner" id="modalSpinner"></div>
                            <h3 id="progressTitle" class="inplace-title">시험지 처리 중</h3>
                            <p id="progressDesc" class="inplace-desc">시험지를 분석하고 있습니다...</p>
                            
                            <div class="progress-bar-wrap">
                                <div class="progress-bar-fill" id="progressBar"></div>
                            </div>

                            <ul class="step-list">
                                <li class="step-item" id="step1"><span>①</span> 📐 시험지 영역 자동 감지 및 원근 보정</li>
                                <li class="step-item" id="step2"><span>②</span> 🚀 고화질 답안 최적화 및 서버 전송</li>
                                <li class="step-item" id="step3"><span>③</span> 🤖 Gemini AI 손글씨 자동 판독</li>
                                <li class="step-item" id="step4"><span>④</span> 📊 실시간 채점 집계 및 대시보드 반영</li>
                            </ul>
                        </div>

                        <!-- 2. 최종 제출 완료 상태 (접수증) -->
                        <div id="inplaceDoneContent" style="display: none; width: 100%; max-width: 300px;">
                            <div class="inplace-done-icon">🎉</div>
                            <h3 class="inplace-done-title">제출이 완료되었습니다!</h3>
                            <p class="inplace-done-subtitle">시험지가 안전하게 접수되었습니다.</p>
                            
                            <div class="inplace-receipt-card">
                                <div class="receipt-row">
                                    <span class="receipt-label">학생명</span>
                                    <span class="receipt-value"><?= e($studentName) ?></span>
                                </div>
                                <div class="receipt-row">
                                    <span class="receipt-label">시험명</span>
                                    <span class="receipt-value"><?= e($examName) ?></span>
                                </div>
                                <div class="receipt-row">
                                    <span class="receipt-label">접수 시각</span>
                                    <span class="receipt-value" id="receiptTime">-</span>
                                </div>
                                <div class="receipt-row">
                                    <span class="receipt-label">상태</span>
                                    <span class="receipt-value status-ok">정상 접수 (AI 채점 중)</span>
                                </div>
                            </div>

                            <div class="inplace-done-message">
                                수고하셨습니다! 이제 스마트폰을 내려놓고<br>선생님 안내와 수업에 집중해 주세요. 👏
                            </div>

                            <?php if ($mode === 'kiosk'): ?>
                                <div id="kioskCountdownNotice" style="margin-top:14px; font-size:0.85rem; color:#94a3b8; font-weight:700;">
                                    다음 학생을 위해 잠시 후 학생 목록으로 이동합니다...
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="action-row">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('fileInput').click()">
                        🔄 다시 촬영
                    </button>
                    <button type="button" class="btn btn-primary" id="submitBtn" onclick="processAndSubmit()">
                        🚀 시험지 자동 보정 및 제출하기
                    </button>
                </div>
            </div>
        </div>

        <!-- 2열: 가이드 및 학생 안내 영역 -->
        <div class="kiosk-col-side">
            <!-- 1. 촬영 방법 -->
            <div class="guide-mini-card guide-camera">
                <div class="guide-mini-title">📷 촬영 방법</div>
                <div class="guide-mini-desc">시험지의 4개 모서리가 모두 화면 안에 들어오게 찍으세요.</div>
            </div>

            <!-- 2. 확인하세요 -->
            <div class="guide-mini-card guide-check">
                <div class="guide-mini-title">📌 확인하세요</div>
                <div class="guide-mini-desc">시험지 이름이 맞는지 확인하세요.</div>
<script>
let cvReady = false;
let selectedFile = null;

// N-Slot 상태 머신 및 다쪽 메타데이터 (SSOT Contract C)
const totalPages = <?= $totalPages ?>;
const templatePages = <?= json_encode($templatePages, JSON_UNESCAPED_UNICODE) ?>;
let activeSlotIdx = 0;
let slots = [];

for (let i = 0; i < totalPages; i++) {
    const tPage = templatePages[i] || {};
    slots.push({
        pageNum: i + 1,
        title: tPage.title || (totalPages === 2 ? (i === 0 ? '1쪽 (앞면)' : '2쪽 (뒷면)') : `${i + 1}쪽`),
        status: 'empty',
        blob: null,
        previewUrl: null,
        hashData: null
    });
}

function onOpenCvReady() {
    cvReady = true;
    const badge = document.getElementById('cvStatus');
    if (badge) {
        badge.className = "status-pill ready";
        badge.innerHTML = "<span>✨</span> 스마트 스캐너 준비됨";
    }
}

// 초기화: 다쪽 슬롯 카드 렌더링
if (totalPages >= 2) {
    renderSlotCards();
}

function renderSlotCards() {
    const container = document.getElementById('slotCards');
    const badge = document.getElementById('slotBadge');
    if (!container) return;

    container.innerHTML = "";
    const doneCount = slots.filter(s => s.status === 'completed').length;
    if (badge) {
        badge.innerText = `${doneCount} / ${totalPages}쪽 완료`;
    }

    slots.forEach((s, idx) => {
        const card = document.createElement('div');
        card.className = `slot-card ${idx === activeSlotIdx ? 'active' : ''} ${s.status === 'completed' ? 'completed' : ''}`;
        
        let statusText = s.status === 'completed' ? '✓ 완료' : (idx === activeSlotIdx ? '촬영 대기 ⏳' : '대기');
        card.innerHTML = `
            <div class="slot-card-title">${s.title}</div>
            <div class="slot-card-status">${statusText}</div>
        `;
        card.onclick = () => {
            selectSlot(idx);
        };
        container.appendChild(card);
    });

    // 드롭존 문구 동기화
    const uploadText = document.querySelector('.upload-text');
    if (uploadText && totalPages > 1) {
        uploadText.innerText = `📷 ${slots[activeSlotIdx].title} 사진 찍기`;
    }
}

function selectSlot(idx) {
    if (idx < 0 || idx >= totalPages) return;
    activeSlotIdx = idx;
    renderSlotCards();

    // 만약 이미 완료된 슬롯을 터치했고 미리보기 모드라면 해당 이미지로 전환
    if (slots[idx].status === 'completed' && slots[idx].previewUrl) {
        const pImg = document.getElementById('previewImage');
        if (pImg) pImg.src = slots[idx].previewUrl;
        highlightPreviewThumb(idx);
    }
}

// 파일 선택 핸들러 (단일/다쪽 분기)
document.getElementById('fileInput').addEventListener('change', async function(e) {
    const file = e.target.files[0];
    if (!file) return;

    // 1. 단일 평가지: 기존 로직 100% 동일 유지 (Zero Regression)
    if (totalPages === 1) {
        selectedFile = file;
        const reader = new FileReader();
        reader.onload = function(evt) {
            document.getElementById('previewImage').src = evt.target.result;
            document.getElementById('uploadZone').style.display = 'none';
            document.getElementById('previewBox').style.display = 'block';
        };
        reader.readAsDataURL(file);
        return;
    }

    // 2. 다쪽 평가지: 슬롯별 순차 보정 및 행동 유도 파이프라인
    if (!cvReady) {
        alert("스캐너 엔진이 아직 로딩 중입니다. 1~2초 후 다시 시도해주세요.");
        return;
    }

    try {
        const reader = new FileReader();
        reader.onload = async function(evt) {
            const tempImg = new Image();
            tempImg.onload = async function() {
                // [단계 1] OpenCV 초고속 원근 보정
                const scaledCanvas = getScaledSourceCanvas(tempImg, 1200);
                const warpedCanvas = warpPerspectiveFast(scaledCanvas);

                // [단계 2] 스마트 동일면 중복 촬영 감지 (Smart Image Diff Guard)
                const currentHashData = extractCanvasHash(warpedCanvas);
                if (activeSlotIdx > 0 && slots[0].hashData) {
                    const similarity = calculateSimilarity(slots[0].hashData, currentHashData);
                    if (similarity > 0.94) {
                        const proceed = confirm(
                            "⚠️ 잠깐! 1쪽(앞면)과 거의 똑같은 사진이 촬영되었습니다.\n" +
                            "시험지 뒷면(해당 쪽)을 바르게 촬영하셨는지 확인해주세요.\n\n" +
                            "이 사진으로 계속 진행하시겠습니까?"
                        );
                        if (!proceed) {
                            return;
                        }
                    }
                }

                // 0.80 품질 JPEG 압축
                const blob = await new Promise(resolve => warpedCanvas.toBlob(resolve, 'image/jpeg', 0.80));
                const dataUrl = warpedCanvas.toDataURL('image/jpeg', 0.80);

                slots[activeSlotIdx].blob = blob;
                slots[activeSlotIdx].previewUrl = dataUrl;
                slots[activeSlotIdx].hashData = currentHashData;
                slots[activeSlotIdx].status = 'completed';

                // 다음 미완료 슬롯 탐색
                const allDone = slots.every(s => s.status === 'completed');

                if (allDone) {
                    // 모든 쪽 촬영 완료! 종합 검수 화면으로 전환
                    document.getElementById('uploadZone').style.display = 'none';
                    document.getElementById('previewBox').style.display = 'block';
                    const nudge = document.getElementById('flipNudgeBanner');
                    if (nudge) nudge.style.display = 'none';

                    renderMultiPreviewStrip();
                    document.getElementById('previewImage').src = slots[0].previewUrl;

                    const submitBtn = document.getElementById('submitBtn');
                    if (submitBtn) {
                        submitBtn.innerText = `🚀 총 ${totalPages}장 시험지 자동 보정 및 제출하기`;
                    }
                } else {
                    // 다음 슬롯으로 전진 및 플립 넛지(Flip Nudge) 노출
                    const nextIdx = slots.findIndex((s, i) => i > activeSlotIdx && s.status !== 'completed');
                    activeSlotIdx = (nextIdx !== -1) ? nextIdx : slots.findIndex(s => s.status !== 'completed');

                    const nudge = document.getElementById('flipNudgeBanner');
                    if (nudge) {
                        nudge.style.display = 'flex';
                        const mainTxt = nudge.querySelector('.flip-main-text');
                        if (mainTxt) {
                            mainTxt.innerText = `✓ ${slots[activeSlotIdx - 1]?.title || '1쪽'} 촬영 완료! 이제 시험지를 뒤집어주세요.`;
                        }
                    }
                }

                renderSlotCards();
            };
            tempImg.src = evt.target.result;
        };
        reader.readAsDataURL(file);

    } catch (err) {
        alert("시험지 보정 중 오류가 발생했습니다: " + err.message);
    }
});

// 스마트 이미지 유사도 계산 헬퍼 (32x32 다운샘플링 픽셀 비교)
function extractCanvasHash(canvas) {
    try {
        const off = document.createElement('canvas');
        off.width = 32; off.height = 32;
        const oCtx = off.getContext('2d');
        oCtx.drawImage(canvas, 0, 0, 32, 32);
        return oCtx.getImageData(0, 0, 32, 32).data;
    } catch(e) {
        return null;
    }
}

function calculateSimilarity(d1, d2) {
    if (!d1 || !d2 || d1.length !== d2.length) return 0;
    let diff = 0;
    let total = 0;
    for (let i = 0; i < d1.length; i += 4) {
        diff += Math.abs(d1[i] - d2[i]) + Math.abs(d1[i+1] - d2[i+1]) + Math.abs(d1[i+2] - d2[i+2]);
        total += 255 * 3;
    }
    return 1 - (diff / total);
}

// 다쪽 미리보기 썸네일 스트립
function renderMultiPreviewStrip() {
    let strip = document.getElementById('multiPreviewStrip');
    if (!strip) {
        strip = document.createElement('div');
        strip.id = 'multiPreviewStrip';
        strip.className = 'multi-preview-strip';
        const pContainer = document.getElementById('previewContainer');
        pContainer.insertBefore(strip, document.getElementById('previewImage'));
    }
    strip.style.display = 'flex';
    strip.innerHTML = '';

    slots.forEach((s, idx) => {
        const item = document.createElement('div');
        item.className = `multi-preview-item ${idx === 0 ? 'active' : ''}`;
        item.dataset.index = idx;
        item.innerHTML = `
            <img src="${s.previewUrl}" alt="${s.title}">
            <span class="multi-preview-tag">${s.title}</span>
        `;
        item.onclick = () => {
            highlightPreviewThumb(idx);
            document.getElementById('previewImage').src = s.previewUrl;
        };
        strip.appendChild(item);
    });
}

function highlightPreviewThumb(idx) {
    document.querySelectorAll('.multi-preview-item').forEach(el => {
        el.classList.toggle('active', Number(el.dataset.index) === idx);
    });
}

// 개별 쪽 재촬영 트리거
function retakeCurrentSlot() {
    if (totalPages === 1) {
        document.getElementById('fileInput').click();
    } else {
        document.getElementById('previewBox').style.display = 'none';
        document.getElementById('uploadZone').style.display = 'block';
        slots[activeSlotIdx].status = 'empty';
        renderSlotCards();
        document.getElementById('fileInput').click();
    }
}

// 진행 모달 스텝 업데이트 헬퍼
function setProgress(step, percent, title, desc) {
    const bar = document.getElementById('progressBar');
    const pTitle = document.getElementById('progressTitle');
    const pDesc = document.getElementById('progressDesc');

    if (bar) bar.style.width = percent + '%';
    if (pTitle && title) pTitle.innerText = title;
    if (pDesc && desc) pDesc.innerText = desc;

    for (let i = 1; i <= 4; i++) {
        const el = document.getElementById(`step${i}`);
        if (!el) continue;
        if (i < step) {
            el.className = "step-item done";
            el.querySelector('span').innerText = '✓';
        } else if (i === step) {
            el.className = "step-item active";
            el.querySelector('span').innerText = `●`;
        } else {
            el.className = "step-item";
            el.querySelector('span').innerText = `${i}`;
        }
    }
}

/**
 * [핵심 최적화 1] 원본 이미지를 OpenCV 분석에 적합한 크기(최대 1600px)로 1차 리사이징
 * 연산 속도를 10배 가속하고 브라우저 프리징을 완벽하게 방지합니다.
 */
function getScaledSourceCanvas(imgEl, maxDim = 1600) {
    const origW = imgEl.naturalWidth || imgEl.width;
    const origH = imgEl.naturalHeight || imgEl.height;
    let w = origW;
    let h = origH;

    if (w > maxDim || h > maxDim) {
        if (w > h) {
            h = Math.round((h * maxDim) / w);
            w = maxDim;
        } else {
            w = Math.round((w * maxDim) / h);
            h = maxDim;
        }
    }

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(imgEl, 0, 0, w, h);
    return canvas;
}

/**
 * [핵심 최적화 2] OpenCV 기반 고속 문서 외곽선 검출 및 A4 표준 원근 보정
 */
function warpPerspectiveFast(scaledCanvas) {
    let src = cv.imread(scaledCanvas);
    let dst = new cv.Mat();
    let gray = new cv.Mat();
    let blurred = new cv.Mat();
    let edged = new cv.Mat();

    // A4 표준 해상도 타겟 (가로 1000px, 세로 1414px) - 쌤글라스 1000px 좌표계 1:1 최적화
    const targetW = 1000;
    const targetH = 1414;

    cv.cvtColor(src, gray, cv.COLOR_RGBA2GRAY, 0);
    cv.GaussianBlur(gray, blurred, new cv.Size(5, 5), 0, 0, cv.BORDER_DEFAULT);
    cv.Canny(blurred, edged, 50, 200);

    let contours = new cv.MatVector();
    let hierarchy = new cv.Mat();
    cv.findContours(edged, contours, hierarchy, cv.RETR_EXTERNAL, cv.CHAIN_APPROX_SIMPLE);

    let maxArea = 0;
    let maxContour = null;

    for (let i = 0; i < contours.size(); ++i) {
        let cnt = contours.get(i);
        let area = cv.contourArea(cnt);
        if (area > 30000 && area > maxArea) {
            let peri = cv.arcLength(cnt, true);
            let approx = new cv.Mat();
            cv.approxPolyDP(cnt, approx, 0.02 * peri, true);
            if (approx.rows === 4) {
                if (maxContour) maxContour.delete();
                maxArea = area;
                maxContour = approx;
            } else {
                approx.delete();
            }
        }
    }

    const warpedCanvas = document.createElement('canvas');
    warpedCanvas.width = targetW;
    warpedCanvas.height = targetH;

    if (maxContour) {
        let pts = [];
        for (let i = 0; i < 4; i++) {
            pts.push({ x: maxContour.data32S[i * 2], y: maxContour.data32S[i * 2 + 1] });
        }

        pts.sort((a, b) => a.y - b.y);
        let topTwo = pts.slice(0, 2).sort((a, b) => a.x - b.x);
        let bottomTwo = pts.slice(2, 4).sort((a, b) => a.x - b.x);

        let srcTri = cv.matFromArray(4, 1, cv.CV_32FC2, [
            topTwo[0].x, topTwo[0].y,
            topTwo[1].x, topTwo[1].y,
            bottomTwo[1].x, bottomTwo[1].y,
            bottomTwo[0].x, bottomTwo[0].y
        ]);

        let dstTri = cv.matFromArray(4, 1, cv.CV_32FC2, [
            0, 0,
            targetW - 1, 0,
            targetW - 1, targetH - 1,
            0, targetH - 1
        ]);

        let M = cv.getPerspectiveTransform(srcTri, dstTri);
        let dsize = new cv.Size(targetW, targetH);
        cv.warpPerspective(src, dst, M, dsize, cv.INTER_LINEAR, cv.BORDER_CONSTANT, new cv.Scalar());

        cv.imshow(warpedCanvas, dst);
        srcTri.delete(); dstTri.delete(); M.delete(); maxContour.delete();
    } else {
        // 외곽 사각형 미감지 시 전체 이미지를 A4 비율로 균일 리사이즈
        let dsize = new cv.Size(targetW, targetH);
        cv.resize(src, dst, dsize, 0, 0, cv.INTER_LINEAR);
        cv.imshow(warpedCanvas, dst);
    }

    src.delete(); dst.delete(); gray.delete(); blurred.delete(); edged.delete(); contours.delete(); hierarchy.delete();
    return warpedCanvas;
}

/**
 * [핵심 최적화 3] 초고속 단계별 처리 및 제출 파이프라인
 */
async function processAndSubmit() {
    if (totalPages === 1 && !selectedFile) {
        alert("제출할 시험지 사진을 먼저 선택해주세요.");
        return;
    }
    if (totalPages >= 2 && !slots.every(s => s.status === 'completed')) {
        alert("모든 쪽의 시험지 촬영을 완료해주세요.");
        return;
    }
    if (!cvReady) {
        alert("스캐너 엔진이 아직 로딩 중입니다. 잠시만 기다려주세요.");
        return;
    }

    const inplaceBox = document.getElementById('inplaceStatusBox');
    if (inplaceBox) inplaceBox.style.display = 'flex';
    const pContent = document.getElementById('inplaceProgressContent');
    const dContent = document.getElementById('inplaceDoneContent');
    if (pContent) pContent.style.display = 'block';
    if (dContent) dContent.style.display = 'none';

    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = "⏳ 시험지 분석 및 제출 중...";
    }

    try {
        const formData = new FormData();
        formData.append('assignment_id', '<?= $assignId ?>');
        formData.append('work_folder', '<?= e($workFolder) ?>');
        formData.append('class_name', '<?= e($className) ?>');
        formData.append('exam_name', '<?= e($examName) ?>');
        formData.append('student_id', '<?= $studentId ?>');
        formData.append('student_name', '<?= e($studentName) ?>');
        formData.append('total_pages', totalPages);

        if (totalPages === 1) {
            // [단일 평가지] 보정 및 단일 파일 첨부
            setProgress(1, 25, "시험지 영역 보정 중", "시험지 네 모서리를 감지하여 바르게 펴고 있습니다...");
            await new Promise(r => setTimeout(r, 60));

            const imgEl = document.getElementById('previewImage');
            const scaledSrcCanvas = getScaledSourceCanvas(imgEl, 1200);
            const warpedCanvas = warpPerspectiveFast(scaledSrcCanvas);

            setProgress(2, 50, "최적화 압축 및 전송 준비", "고화질 이미지를 가볍게 압축하여 전송하고 있습니다...");
            const blob = await new Promise(resolve => warpedCanvas.toBlob(resolve, 'image/jpeg', 0.80));
            formData.append('image_0', blob, 'warped_sheet.jpg');
        } else {
            // [다쪽 평가지] 이미 슬롯별로 보정된 Blob 멀티 첨부
            setProgress(2, 50, "다쪽 시험지 전송 준비", `총 ${totalPages}장의 보정된 시험지를 패키징하고 있습니다...`);
            slots.forEach((s, idx) => {
                formData.append(`image_${idx}`, s.blob, `warped_p${idx + 1}.jpg`);
            });
        }

        setProgress(2, 75, "시험지 서버 접수 중", "시험지를 안전하게 서버에 접수하고 있습니다...");

        const response = await fetch('/api/grading/submit_images.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // [3단계] AI 손글씨 판독 시작
            setProgress(3, 90, "AI 손글씨 판독 시작!", "Gemini AI가 실시간으로 손글씨 채점을 시작했습니다...");
            await new Promise(r => setTimeout(r, 300));

            // [4단계] 접수 완료
            setProgress(4, 100, "제출 완료!", "시험지가 안전하게 접수되었습니다.");
            await new Promise(r => setTimeout(r, 150));

            // 인플레이스 접수 완료증으로 화면 전환
            if (pContent) pContent.style.display = 'none';
            if (dContent) dContent.style.display = 'block';

            // 접수 시각 표시
            const now = new Date();
            const timeStr = now.toLocaleTimeString('ko-KR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            const timeEl = document.getElementById('receiptTime');
            if (timeEl) timeEl.innerText = timeStr;

            // 상단 배지 완료 상태로 갱신
            const statusBadge = document.getElementById('cvStatus');
            if (statusBadge) {
                statusBadge.className = "status-pill ready";
                statusBadge.innerHTML = "<span>✅</span> 제출 완료";
            }

            // 하단 액션 버튼 잠금 바(Bar)로 전환
            const actionRow = document.querySelector('.action-row');
            if (actionRow) {
                actionRow.innerHTML = `<div style="width:100%; text-align:center; padding:12px; font-weight:800; color:#16a34a; background:#f0fdf4; border-radius:10px; border:1px solid #bbf7d0; font-size:0.95rem;">✅ 총 ${totalPages}장 시험지가 정상 접수되었습니다</div>`;
            }

            // 하단 촬영 안내 박스 숨김 (완료되었으므로 시선 분산 제거)
            const sideGuide = document.querySelector('.kiosk-col-side');
            if (sideGuide) {
                sideGuide.style.display = 'none';
            }

            const viewMode = '<?= e($mode) ?>';
            if (viewMode === 'kiosk') {
                let count = 2;
                const noticeEl = document.getElementById('kioskCountdownNotice');
                const timer = setInterval(() => {
                    count--;
                    if (noticeEl && count > 0) {
                        noticeEl.innerText = `다음 학생을 위해 ${count}초 후 학생 목록으로 이동합니다...`;
                    }
                    if (count <= 0) {
                        clearInterval(timer);
                        location.href = '/views/student/logout.php?class_id=<?= $classId ?>';
                    }
                }, 1000);
            }
            // student 모드는 리다이렉트나 추가 버튼 없이 이 접수증 화면에서 깔끔하게 완결!

        } else {
            if (inplaceBox) inplaceBox.style.display = 'none';
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = "🚀 시험지 자동 보정 및 제출하기";
            }
            alert("채점 및 저장 오류:\n" + (data.error || data.message || "알 수 없는 오류가 발생했습니다."));
        }

    } catch (err) {
        console.error(err);
        if (inplaceBox) inplaceBox.style.display = 'none';
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = "🚀 시험지 자동 보정 및 제출하기";
        }
        alert("처리 중 오류가 발생했습니다: " + err.message);
    }
}
</script>

</body>
</html>
