<?php
/**
 * views/student/main.php
 * 초등학생 눈높이 맞춤 학생 대시보드
 * - 친근하고 직관적인 안내 문구 및 4단계 평가 라이프사이클 반영
 * - 전 문항 채점 완료 시 '채점 완료 (총점 표시)' 승격 및 '가채점/대기중' 불일치 해결
 * - 교사 설정(show_answer)에 따른 모범 답안 조건부 노출 (기본: 숨김으로 오답 재도전 보장)
 * - 미제출 학생 마감 시 0점 표기 방지 (회색 미제출 뱃지 및 안내)
 * - 정답 문항 확인 요청 버튼 제거 & 오답 문항 인라인 아코디언 입력창 (시각적 맥락 100% 보존)
 * - 실시간 비동기 채점 상태 자동 갱신 지원
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

$classId = (int)($_SESSION['class_id'] ?? 0);
$studentId = requireStudentAuth($classId);

if ($classId <= 0 && $studentId > 0) {
    $cStmt = $pdo->prepare("SELECT class_id FROM students WHERE id = :sid");
    $cStmt->execute(['sid' => $studentId]);
    $classId = (int)$cStmt->fetchColumn();
    $_SESSION['class_id'] = $classId;
}

$studentName = $_SESSION['student_name'] ?? '학생';

// 평가 및 채점 결과 목록 조회 (show_answer 및 is_reviewed 포함)
try {
    $stmt = $pdo->prepare("
        SELECT a.id as assignment_id, t.title, a.status as assign_status,
               COALESCE(a.show_answer, 0) as show_answer,
               er.id as result_id, er.total_score, er.is_reviewed, er.submitted_at, er.raw_results
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        LEFT JOIN exam_results er ON a.id = er.assignment_id AND er.student_id = :student_id
        WHERE a.class_id = :class_id AND a.status != 'deleted'
        ORDER BY a.id DESC
    ");
    $stmt->execute([
        'student_id' => $studentId,
        'class_id'   => $classId
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // show_answer 컬럼 부재 시 폴백
    $stmt = $pdo->prepare("
        SELECT a.id as assignment_id, t.title, a.status as assign_status,
               0 as show_answer,
               er.id as result_id, er.total_score, er.is_reviewed, er.submitted_at, er.raw_results
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        LEFT JOIN exam_results er ON a.id = er.assignment_id AND er.student_id = :student_id
        WHERE a.class_id = :class_id AND a.status != 'deleted'
        ORDER BY a.id DESC
    ");
    $stmt->execute([
        'student_id' => $studentId,
        'class_id'   => $classId
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 3단계 분류 및 세부 데이터 분석
$todoList      = []; // 1. 오늘 풀어야 할 시험지 (미제출 / 사진 재촬영 필요)
$reviewList    = []; // 2. 선생님이 채점 중이거나 채점 완료된 시험지 (과제 진행 중)
$completedList = []; // 3. 끝난 시험지 (과제 최종 마감)

foreach ($rows as &$row) {
    $rawList = [];
    if (!empty($row['raw_results'])) {
        $rawList = is_string($row['raw_results']) ? json_decode($row['raw_results'], true) : $row['raw_results'];
        if (!is_array($rawList) && isset($rawList['items'])) {
            $rawList = $rawList['items'];
        }
    }
    $rawList = is_array($rawList) ? $rawList : [];

    // 문항별 통계 분석
    $objTotal = 0;
    $objCorrect = 0;
    $objScore = 0;
    $subjTotal = 0;
    $pendingCount = 0;
    $hasAiPending = false;
    $hasAppeal = false;

    foreach ($rawList as $item) {
        $type = strtolower($item['type'] ?? 'objective');
        $rawText = trim((string)($item['rawText'] ?? ''));
        $status = (int)($item['status'] ?? -1);

        if ($status === -1) {
            $pendingCount++;
        }

        if ($type === 'subjective' || in_array($type, ['essay', 'short', 'text', '주관식'])) {
            $subjTotal++;
        } else {
            $objTotal++;
            if ($rawText === '판독대기' || $rawText === 'AI 채점 중...' || ($status === -1 && empty($row['is_reviewed']))) {
                $hasAiPending = true;
            }
            if ($status === 1) {
                $objCorrect++;
                $objScore += (float)($item['score'] ?? $item['currentScore'] ?? 0);
            } elseif ($status === 2) {
                $objScore += (float)($item['currentScore'] ?? 0);
            }
        }

        if (!empty($item['appeal']) && empty($item['appeal_resolved'])) {
            $hasAppeal = true;
        }
    }

    // [핵심 개념 정립] 해당 학생의 전 문항 채점 완료 여부 판정
    // 미채점 문항(status = -1)이 없거나 교사가 검수를 완료(is_reviewed = 1)한 경우 채점 완료 상태로 인정
    $isGradingComplete = (!empty($row['result_id']) && ($pendingCount === 0 || !empty($row['is_reviewed'])));

    $row['parsed_items']        = $rawList;
    $row['obj_total']           = $objTotal;
    $row['obj_correct']         = $objCorrect;
    $row['obj_score']           = $objScore;
    $row['subj_total']          = $subjTotal;
    $row['pending_count']       = $pendingCount;
    $row['is_ai_pending']       = $hasAiPending;
    $row['has_appeal']          = $hasAppeal;
    $row['is_grading_complete'] = $isGradingComplete;

    if ($row['assign_status'] === 'completed') {
        // [중요 버그 수정] 마감된 평가에서 제출 내역(result_id)이 없으면 0점이 아닌 미제출로 처리
        $row['is_missing'] = empty($row['result_id']);
        $completedList[] = $row;
    } else {
        if (empty($row['result_id'])) {
            $row['state_type'] = 'missing'; // 아직 안 낸 시험지
            $todoList[] = $row;
        } else {
            $rawStr = is_string($row['raw_results']) ? $row['raw_results'] : json_encode($row['raw_results']);
            // 전 문항이 미기재이거나 판독불가인 경우 재촬영 권장
            if ($objTotal > 0 && $objCorrect === 0 && (strpos($rawStr, '판독 불가') !== false || strpos($rawStr, '판독불가') !== false)) {
                $row['state_type'] = 'error'; // 재촬영 필요
                $todoList[] = $row;
            } else {
                $reviewList[] = $row; // 선생님 채점 대기 또는 학생 채점 완료
            }
        }
    }
}
unset($row);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($studentName) ?> 학생의 공부방 - 쌤글라스</title>
    <style>
        :root {
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --primary-light: #eff6ff;
            --success: #10b981;
            --success-light: #ecfdf5;
            --warning: #f59e0b;
            --warning-light: #fffbeb;
            --danger: #ef4444;
            --danger-light: #fef2f2;
            --purple: #8b5cf6;
            --purple-light: #f5f3ff;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-600: #64748b;
            --gray-800: #1e293b;
            --radius-md: 14px;
            --radius-sm: 8px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.08);
        }

        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif;
            background: #f8fafc;
            color: var(--gray-800);
            margin: 0;
            padding: 20px 16px 60px 16px;
            line-height: 1.5;
        }

        .container { max-width: 820px; margin: 0 auto; }

        /* 상단 학생 환영 카드 */
        .welcome-card {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            padding: 24px 28px;
            border-radius: var(--radius-md);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .welcome-title { font-size: 1.35rem; font-weight: 800; margin: 0 0 6px 0; }
        .welcome-sub { font-size: 0.95rem; opacity: 0.9; margin: 0; }
        .user-actions { display: flex; gap: 8px; }

        .btn-top {
            padding: 8px 14px;
            border-radius: var(--radius-sm);
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-top-white { background: white; color: var(--primary); }
        .btn-top-white:hover { background: #f0fdf4; }
        .btn-top-trans { background: rgba(255, 255, 255, 0.2); color: white; }
        .btn-top-trans:hover { background: rgba(255, 255, 255, 0.35); }

        /* 섹션 타이틀 */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 28px 0 14px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--gray-200);
        }
        .section-title {
            font-size: 1.22rem;
            font-weight: 800;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        .section-count {
            font-size: 0.85rem;
            font-weight: 700;
            background: var(--gray-200);
            color: var(--gray-600);
            padding: 3px 10px;
            border-radius: 20px;
        }

        /* 카드 컨테이너 */
        .card-container { display: flex; flex-direction: column; gap: 14px; }
        .exam-card {
            background: white;
            padding: 20px 24px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            border: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .exam-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* 상태별 테두리 및 뱃지 */
        .border-todo { border-left: 6px solid var(--primary); }
        .border-error { border-left: 6px solid var(--warning); }
        .border-review { border-left: 6px solid #f59e0b; }
        .border-done { border-left: 6px solid var(--success); }
        .border-missing { border-left: 6px solid #94a3b8; }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 12px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.82rem;
            margin-bottom: 6px;
        }
        .badge-blue { background: var(--primary-light); color: var(--primary); }
        .badge-amber { background: var(--warning-light); color: #b45309; }
        .badge-green { background: var(--success-light); color: #047857; }
        .badge-gray { background: #f1f5f9; color: #475569; }
        .badge-purple { background: var(--purple-light); color: var(--purple); }
        .badge-pulse {
            background: #eff6ff;
            color: #2563eb;
            animation: pulse 1.5s infinite;
        }
        .pulse-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #2563eb;
            animation: pulse 1s infinite;
        }
        @keyframes pulse {
            0% { opacity: 0.5; }
            50% { opacity: 1; }
            100% { opacity: 0.5; }
        }

        .exam-title {
            font-size: 1.12rem;
            font-weight: 800;
            color: #0f172a;
            margin: 2px 0 4px 0;
        }
        .exam-meta {
            font-size: 0.86rem;
            color: var(--gray-600);
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* 점수 요약 뱃지 */
        .quick-score-tag {
            background: #f0fdf4;
            color: #15803d;
            border: 1px solid #bbf7d0;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-block;
            margin-top: 6px;
        }

        /* 버튼 */
        .btn-action {
            padding: 10px 18px;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-size: 0.92rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            transition: 0.2s;
            white-space: nowrap;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-amber { background: #f59e0b; color: white; }
        .btn-amber:hover { background: #d97706; }
        .btn-outline-blue { background: white; border: 1.5px solid var(--primary); color: var(--primary); }
        .btn-outline-blue:hover { background: var(--primary-light); }

        /* 점수 표기 */
        .score-box { text-align: right; min-width: 100px; }
        .score-number {
            font-size: 1.6rem;
            font-weight: 900;
            color: var(--primary);
            line-height: 1;
        }
        .score-unit { font-size: 0.95rem; font-weight: 700; color: var(--gray-600); margin-left: 2px; }
        .score-missing {
            font-size: 1.15rem;
            font-weight: 800;
            color: #94a3b8;
        }

        /* 빈 상태 메시지 */
        .empty-card {
            background: white;
            border: 2px dashed #cbd5e1;
            border-radius: var(--radius-md);
            padding: 32px 20px;
            text-align: center;
            color: var(--gray-600);
            font-size: 0.96rem;
            font-weight: 600;
        }

        /* 모달 스타일 */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
            padding: 16px;
            backdrop-filter: blur(3px);
        }
        .modal-window {
            background: white;
            width: 100%;
            max-width: 740px;
            max-height: 90vh;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalFadeIn 0.25s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafbfc;
        }
        .modal-header h3 { margin: 0; font-size: 1.2rem; font-weight: 800; color: var(--gray-800); }
        .modal-close-btn {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #94a3b8;
            transition: 0.2s;
            line-height: 1;
        }
        .modal-close-btn:hover { color: #1e293b; }
        .modal-body {
            padding: 20px 24px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-summary-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-item-card {
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 14px;
            background: white;
            transition: 0.2s;
        }
        .modal-item-card.is-correct { border-left: 5px solid #10b981; background: #f0fdf4; }
        .modal-item-card.is-incorrect { border-left: 5px solid #ef4444; background: #fef2f2; }
        .modal-item-card.is-pending { border-left: 5px solid #f59e0b; background: #fffdf0; }

        .modal-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .modal-crop-img {
            max-width: 100%;
            max-height: 130px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            display: block;
            margin-bottom: 10px;
            background: white;
        }
        .modal-answer-compare {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            font-size: 0.92rem;
            margin-bottom: 8px;
        }

        /* 미니멀 인라인 확인 요청 버튼 & 아코디언 패널 */
        .btn-appeal-link {
            background: transparent;
            border: none;
            color: #7c3aed;
            font-size: 0.84rem;
            font-weight: 700;
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: 0.15s;
        }
        .btn-appeal-link:hover {
            background: #f5f3ff;
            text-decoration: underline;
        }

        .appeal-inline-panel {
            margin-top: 10px;
            padding: 12px 14px;
            background: #faf5ff;
            border: 1px solid #ddd6fe;
            border-radius: 8px;
            animation: slideDown 0.2s ease-out;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .appeal-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
        .appeal-chip {
            background: white;
            border: 1px solid #c4b5fd;
            color: #6d28d9;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 12px;
            cursor: pointer;
            transition: 0.15s;
        }
        .appeal-chip:hover { background: #ede9fe; }
        .appeal-input-row { display: flex; gap: 8px; align-items: center; }
        .appeal-text-input {
            flex: 1;
            padding: 7px 10px;
            border: 1.5px solid #c4b5fd;
            border-radius: 6px;
            font-size: 0.88rem;
            outline: none;
            background: white;
        }
        .appeal-text-input:focus { border-color: #8b5cf6; }
        .btn-appeal-submit {
            padding: 7px 14px;
            background: #7c3aed;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.86rem;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-appeal-submit:hover { background: #6d28d9; }
        .btn-appeal-cancel {
            padding: 7px 10px;
            background: #e2e8f0;
            color: #475569;
            border: none;
            border-radius: 6px;
            font-size: 0.86rem;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }
        .appeal-status-tag {
            background: #ede9fe;
            color: #6d28d9;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 700;
            display: inline-block;
        }

        @media (max-width: 640px) {
            .welcome-card { flex-direction: column; align-items: flex-start; gap: 14px; }
            .exam-card { flex-direction: column; align-items: flex-start; }
            .score-box { text-align: left; width: 100%; }
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- 상단 친근한 학생 환영 카드 -->
        <div class="welcome-card">
            <div>
                <div class="welcome-title">🌟 안녕하세요, <?= e($studentName) ?> 학생!</div>
                <div class="welcome-sub">오늘도 즐겁고 힘차게 문제를 풀어볼까요? 응원해요! 👏</div>
            </div>
            <div class="user-actions">
                <a href="/views/student/pin_change.php" class="btn-top btn-top-trans">🔑 비밀번호</a>
                <a href="/views/student/logout.php" class="btn-top btn-top-white">로그아웃</a>
            </div>
        </div>

        <!-- 1. 오늘 풀어야 할 시험지 (할 일) -->
        <div class="section-header">
            <h2 class="section-title">📝 오늘 풀어야 할 시험지</h2>
            <span class="section-count"><?= count($todoList) ?>개</span>
        </div>
        <div class="card-container">
            <?php if (empty($todoList)): ?>
                <div class="empty-card">
                    ✨ 와우! 지금 제출할 시험지가 없어요. 정말 훌륭해요! 👍
                </div>
            <?php else: ?>
                <?php foreach ($todoList as $item): ?>
                    <?php if ($item['state_type'] === 'missing'): ?>
                        <div class="exam-card border-todo">
                            <div>
                                <span class="badge badge-blue">✏️ 제출 대기</span>
                                <div class="exam-title"><?= e($item['title']) ?></div>
                                <div class="exam-meta">
                                    <span>선생님이 내주신 시험지를 풀고 사진을 찍어 제출해주세요!</span>
                                </div>
                            </div>
                            <a href="/views/input/single.php?assign_id=<?= (int)$item['assignment_id'] ?>" class="btn-action btn-primary">
                                📸 시험지 사진 찍기
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="exam-card border-error">
                            <div>
                                <span class="badge badge-amber">⚠️ 사진 다시 찍기 권장</span>
                                <div class="exam-title"><?= e($item['title']) ?></div>
                                <div class="exam-meta">
                                    <span style="color: #b45309;">글씨가 조금 흐려요! 밝은 곳에서 반듯하게 다시 찍어볼까요?</span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <a href="/views/input/single.php?assign_id=<?= (int)$item['assignment_id'] ?>" class="btn-action btn-amber">
                                    🔄 다시 촬영하기
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- 2. 채점 중 또는 채점 완료된 시험지 -->
        <div class="section-header">
            <h2 class="section-title">⏳ 선생님이 채점 중이에요!</h2>
            <span class="section-count"><?= count($reviewList) ?>개</span>
        </div>
        <div class="card-container">
            <?php if (empty($reviewList)): ?>
                <div class="empty-card">
                    현재 확인 대기 중인 시험지가 없습니다.
                </div>
            <?php else: ?>
                <?php foreach ($reviewList as $item): ?>
                    <div class="exam-card <?= $item['is_grading_complete'] ? 'border-done' : 'border-review' ?>" id="reviewCard-<?= (int)$item['assignment_id'] ?>">
                        <div>
                            <?php if ($item['is_ai_pending']): ?>
                                <span class="badge badge-pulse">⚡ AI가 답안을 읽고 있어요...</span>
                            <?php elseif ($item['is_grading_complete']): ?>
                                <span class="badge badge-green">🎉 채점 완료</span>
                            <?php else: ?>
                                <span class="badge badge-amber">⏳ 주관식 채점 중</span>
                            <?php endif; ?>

                            <?php if ($item['has_appeal']): ?>
                                <span class="badge badge-purple">📬 확인 요청 접수됨</span>
                            <?php endif; ?>

                            <div class="exam-title"><?= e($item['title']) ?></div>
                            <div class="exam-meta">
                                <span>제출일시: <?= e($item['submitted_at']) ?></span>
                            </div>

                            <?php if (!$item['is_ai_pending']): ?>
                                <div>
                                    <?php if ($item['is_grading_complete']): ?>
                                        <span class="quick-score-tag" style="background:#ecfdf5; color:#047857; border-color:#a7f3d0;">
                                            총점 <b><?= (float)$item['total_score'] ?>점</b> (선생님 채점 완료 ✨)
                                        </span>
                                    <?php else: ?>
                                        <span class="quick-score-tag">
                                            객관식 1차 채점: <b><?= (float)$item['obj_score'] ?>점</b> (주관식 <?= $item['subj_total'] ?>문제 확인 중)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div>
                                    <span class="quick-score-tag" style="background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe;">
                                        ⚡ AI가 손글씨 답안을 판독하고 있습니다... (약 3~4초 소요)
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div>
                            <button class="btn-action btn-outline-blue" onclick='openAnswerModal(<?= json_encode($item, JSON_UNESCAPED_UNICODE) ?>)'>
                                🔍 내 답안 & 채점 확인
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- 3. 끝난 시험지 (최종 마감 및 미제출 처리) -->
        <div class="section-header">
            <h2 class="section-title">🏆 끝난 시험지</h2>
            <span class="section-count"><?= count($completedList) ?>개</span>
        </div>
        <div class="card-container">
            <?php if (empty($completedList)): ?>
                <div class="empty-card">
                    아직 마감 완료된 시험지 내역이 없습니다.
                </div>
            <?php else: ?>
                <?php foreach ($completedList as $item): ?>
                    <?php if ($item['is_missing']): ?>
                        <!-- [미제출 마감 과제: 0점이 아닌 회색 미제출 명확 분기] -->
                        <div class="exam-card border-missing">
                            <div>
                                <span class="badge badge-gray">⚪ 미제출</span>
                                <div class="exam-title" style="color: #64748b;"><?= e($item['title']) ?></div>
                                <div class="exam-meta">
                                    <span style="color: #94a3b8;">평가 기간이 마감되어 시험지를 제출하지 못했어요.</span>
                                </div>
                            </div>
                            <div class="score-box">
                                <span class="score-missing">-(미제출)</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- [정상 제출 완료 과제] -->
                        <div class="exam-card border-done">
                            <div>
                                <span class="badge badge-green">🎉 마감 완료</span>
                                <div class="exam-title"><?= e($item['title']) ?></div>
                                <div class="exam-meta">
                                    <span>제출일시: <?= e($item['submitted_at']) ?></span>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 16px;">
                                <div class="score-box">
                                    <span class="score-number"><?= (float)$item['total_score'] ?></span>
                                    <span class="score-unit">점</span>
                                </div>
                                <button class="btn-action btn-outline-blue" onclick='openAnswerModal(<?= json_encode($item, JSON_UNESCAPED_UNICODE) ?>, true)'>
                                    📜 결과 보기
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 답안 확인 & 선생님께 확인 요청 모달 -->
    <div class="modal-overlay" id="answerModal">
        <div class="modal-window">
            <div class="modal-header">
                <h3 id="modalExamTitle">📋 답안 확인</h3>
                <button class="modal-close-btn" onclick="closeAnswerModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalExamBody"></div>
        </div>
    </div>

<script>
    let currentModalData = null;

    function openAnswerModal(itemData, isCompleted = false) {
        currentModalData = itemData;
        const isGradingComplete = itemData.is_grading_complete || isCompleted;
        const isAiPending = !!itemData.is_ai_pending;

        let titleSuffix = ' (1차 가채점 현황)';
        if (isGradingComplete) {
            titleSuffix = ' (채점 완료)';
        } else if (isAiPending) {
            titleSuffix = ' (AI 판독 진행 중)';
        }
        document.getElementById('modalExamTitle').innerText = '📋 ' + itemData.title + titleSuffix;

        const body = document.getElementById('modalExamBody');
        const items = itemData.parsed_items || [];
        const showAnswer = parseInt(itemData.show_answer || 0) === 1;

        let summaryHtml = '';
        if (isAiPending) {
            summaryHtml = `
                <div style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1px solid #bfdbfe; border-radius: 12px; padding: 16px; margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                        <div style="font-weight: 800; font-size: 1.05rem; color: #1d4ed8; display: flex; align-items: center; gap: 8px;">
                            <span class="pulse-dot"></span> ⚡ AI가 손글씨 답안을 판독하고 있습니다...
                        </div>
                        <span style="font-size: 0.8rem; background: #3b82f6; color: white; padding: 2px 8px; border-radius: 12px; font-weight: 700;">실시간 자동 갱신</span>
                    </div>
                    <div style="font-size: 0.86rem; color: #334155; margin-bottom: 12px; line-height: 1.4;">
                        시험지가 정상 접수되었습니다! Gemini AI가 답안을 읽고 채점하는 중입니다. (약 3~4초 소요)<br>
                        창을 닫지 않으셔도 채점이 완료되면 이 화면에서 <b>자동으로 정답과 점수가 갱신</b>됩니다.
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <span style="background: #ffffff; border: 1px solid #93c5fd; color: #1e40af; font-size: 0.8rem; padding: 4px 10px; border-radius: 20px; font-weight: 700;">✓ 1. 접수 완료</span>
                        <span style="background: #2563eb; color: #ffffff; font-size: 0.8rem; padding: 4px 10px; border-radius: 20px; font-weight: 700; box-shadow: 0 2px 4px rgba(37,99,235,0.25);">⏳ 2. AI 판독 중...</span>
                        <span style="background: #f1f5f9; color: #94a3b8; font-size: 0.8rem; padding: 4px 10px; border-radius: 20px; font-weight: 600;">📊 3. 채점 완료 대기</span>
                    </div>
                </div>
            `;
        } else {
            summaryHtml = `
                <div class="modal-summary-box">
                    <div>
                        <div style="font-weight: 800; font-size: 1.05rem; color: #1e40af;">
                            ${isGradingComplete ? '🏆 총점: ' + itemData.total_score + '점 (채점 완료)' : '⏳ 객관식 1차 점수: ' + itemData.obj_score + '점'}
                        </div>
                        <div style="font-size: 0.88rem; color: #475569; margin-top: 2px;">
                            객관식 ${itemData.obj_correct}/${itemData.obj_total}문제 정답 ${itemData.subj_total > 0 ? '| 주관식 ' + itemData.subj_total + '문제' + (isGradingComplete ? ' 채점됨' : ' 확인 중') : ''}
                        </div>
                    </div>
                    <div>
                        <span style="font-size: 0.85rem; color: #64748b;">제출: ${itemData.submitted_at || '-'}</span>
                    </div>
                </div>
            `;
        }

        if (items.length === 0) {
            summaryHtml += '<div style="text-align:center; padding:30px; color:#888;">답안 세부 내역을 불러오는 중입니다...</div>';
            body.innerHTML = summaryHtml;
            document.getElementById('answerModal').style.display = 'flex';
            return;
        }

        let itemsHtml = '';
        items.forEach((it, idx) => {
            const itemId = it.item_id || it.id || (idx + 1);
            const isSubj = (it.type === 'subjective' || it.type === '주관식');
            const status = parseInt(it.status !== undefined ? it.status : -1);

            let cardClass = 'is-pending';
            let statusBadge = '';

            if (status === 1) {
                cardClass = 'is-correct';
                statusBadge = '<span class="badge badge-green">✅ 정답 (' + (it.score || it.currentScore || 0) + '점)</span>';
            } else if (status === 0) {
                cardClass = 'is-incorrect';
                statusBadge = '<span class="badge badge-red" style="background:#fee2e2; color:#dc2626;">❌ 오답 (0점)</span>';
            } else if (status === 2) {
                cardClass = 'is-correct';
                statusBadge = '<span class="badge badge-amber">△ 부분점수 (' + (it.currentScore || 0) + '점)</span>';
            } else {
                // status === -1
                if (isSubj) {
                    cardClass = 'is-pending';
                    statusBadge = '<span class="badge badge-amber">⏳ 주관식 채점 대기</span>';
                } else {
                    cardClass = 'is-pending';
                    statusBadge = '<span class="badge badge-pulse" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe;">⚡ AI 판독 진행 중</span>';
                }
            }

            const rawText = (it.rawText || '').trim();
            const cropUrl = it.crop_url || it.crop_image_url || '';

            // [사용자 요구사항 2] 모범 답안 노출 여부: show_answer == 1일 때만 모범 답안 표시
            let modelAnswerHtml = '';
            if (showAnswer && it.answer) {
                modelAnswerHtml = `<div>💡 모범 답안: <b>${it.answer}</b></div>`;
            }

            // 내가 쓴 답 표시
            let myAnswerHtml = '';
            if (!isSubj) {
                if (status === -1 || rawText === '판독대기' || rawText === 'AI 채점 중...') {
                    myAnswerHtml = `<div>🤖 내가 쓴 답: <span style="color:#2563eb; font-weight:600;">AI 판독 중... ⏳</span></div>`;
                } else {
                    myAnswerHtml = `<div>🤖 내가 쓴 답: <b>${rawText === '' ? '미기재' : rawText}</b></div>`;
                }
            }

            // [사용자 요구사항 4 & 5] 
            // 1) 정답(status === 1) 문항에는 확인 요청 버튼을 숨김
            // 2) AI 판독 중(status === -1)에는 채점 완료 후 확인 요청하도록 안내
            // 3) 오답이나 부분점수 문항에만 인라인 아코디언 입력창 제공
            let appealHtml = '';
            if (it.appeal) {
                appealHtml = `<span class="appeal-status-tag">📬 선생님께 확인 요청함: "${it.appeal_reason || '확인 요청'}"</span>`;
            } else if (status !== -1 && status !== 1 && !isCompleted) {
                appealHtml = `
                    <div style="margin-top: 6px;">
                        <button class="btn-appeal-link" onclick="toggleAppealForm(${itemData.assignment_id}, '${itemId}')">
                            💬 글씨 인식 확인 요청
                        </button>
                    </div>
                    <div id="appealForm-${itemId}" class="appeal-inline-panel" style="display:none;">
                        <div class="appeal-chips">
                            <span class="appeal-chip" onclick="setAppealReason('${itemId}', '글씨를 다른 숫자로 읽었어요')">🏷️ 글씨를 다르게 읽었어요</span>
                            <span class="appeal-chip" onclick="setAppealReason('${itemId}', '번호를 맞게 썼어요')">🏷️ 번호를 맞게 썼어요</span>
                            <span class="appeal-chip" onclick="setAppealReason('${itemId}', '사진이 잘렸거나 흐려요')">🏷️ 사진이 흐려요</span>
                        </div>
                        <div class="appeal-input-row">
                            <input type="text" id="appealInput-${itemId}" class="appeal-text-input" placeholder="선생님께 전달할 사유를 적어주세요" value="글씨를 다른 글자로 읽었어요">
                            <button class="btn-appeal-submit" onclick="submitInlineAppeal(${itemData.assignment_id}, '${itemId}')">보내기 🚀</button>
                            <button class="btn-appeal-cancel" onclick="toggleAppealForm(${itemData.assignment_id}, '${itemId}')">취소</button>
                        </div>
                    </div>
                `;
            } else if (status === -1 && !isSubj) {
                appealHtml = `<div style="font-size:0.8rem; color:#64748b; margin-top:4px;">⚡ AI 판독이 완료되면 정답 확인 및 글씨 확인 요청이 가능합니다.</div>`;
            }

            itemsHtml += `
                <div class="modal-item-card ${cardClass}" id="modalItemCard-${itemId}">
                    <div class="modal-item-header">
                        <div>
                            <strong style="font-size: 1.05rem;">${itemId}번 문항</strong>
                            <span style="font-size: 0.85rem; color: #64748b; margin-left: 6px;">[${isSubj ? '주관식' : '객관식'} / 배점 ${it.score || 0}점]</span>
                        </div>
                        <div>${statusBadge}</div>
                    </div>

                    ${cropUrl ? `<img src="${cropUrl}" class="modal-crop-img" alt="내가 쓴 답안">` : ''}

                    <div class="modal-answer-compare">
                        ${myAnswerHtml}
                        ${modelAnswerHtml}
                    </div>

                    <div id="appealArea-${itemId}">${appealHtml}</div>
                </div>
            `;
        });

        body.innerHTML = summaryHtml + itemsHtml;
        document.getElementById('answerModal').style.display = 'flex';
    }

    function closeAnswerModal() {
        document.getElementById('answerModal').style.display = 'none';
    }

    // 인라인 확인 요청 패널 토글 (prompt 대신 화면 맥락 보존)
    function toggleAppealForm(assignId, itemId) {
        const form = document.getElementById(`appealForm-${itemId}`);
        if (!form) return;
        form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
    }

    function setAppealReason(itemId, reason) {
        const input = document.getElementById(`appealInput-${itemId}`);
        if (input) input.value = reason;
    }

    // 인라인 확인 요청 비동기 전송
    function submitInlineAppeal(assignId, itemId) {
        const input = document.getElementById(`appealInput-${itemId}`);
        const reason = input ? input.value.trim() : '글씨가 다르게 인식되었어요';
        if (!reason) {
            alert('확인 요청 사유를 입력해주세요.');
            return;
        }

        fetch('/api/student/request_review.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                assignment_id: assignId,
                item_id: itemId,
                reason: reason
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const area = document.getElementById(`appealArea-${itemId}`);
                if (area) {
                    area.innerHTML = `<span class="appeal-status-tag">📬 선생님께 확인 요청함: "${reason}"</span>`;
                }
            } else {
                alert("확인 요청 실패: " + (data.error || data.message || "알 수 없는 오류"));
            }
        })
        .catch(err => alert("네트워크 통신 오류가 발생했습니다."));
    }

    // 모달 바깥 클릭 시 닫기
    document.getElementById('answerModal').onclick = (e) => {
        if (e.target.id === 'answerModal') closeAnswerModal();
    };

    // [실시간 자동 동기화 고도화]
    // 3초마다 초경량 JSON API(/api/student/get_my_status.php)를 호출하여
    // 1) AI 채점 완료 2) 선생님 주관식 채점 및 부분점수 부여 3) 모범답안 공개 여부 변경을 실시간 자동 감지
    let previousStateHash = '';

    // [초정밀 상태 해시]
    // 개별 문항의 상태(정/오/부분/대기), 개별 배점, 확인요청(appeal) 상태까지 모두 직렬화하여 단 1문항의 채점 변경도 100% 감지
    function calculateStateHash(assignments) {
        return JSON.stringify(assignments.map(a => ({
            id: a.assignment_id,
            stat: a.assign_status,
            score: a.total_score,
            rev: a.is_reviewed,
            ans: a.show_answer,
            comp: a.is_grading_complete,
            ai: a.is_ai_pending,
            app: a.has_appeal,
            pend: a.pending_count,
            items: (a.parsed_items || []).map(it => `${it.item_id || it.id}:${it.status}:${it.currentScore || it.score || 0}:${it.appeal || ''}:${it.appeal_resolved || 0}`)
        })));
    }

    function syncLiveStudentStatus() {
        fetch('/api/student/get_my_status.php?t=' + Date.now())
            .then(res => res.json())
            .then(res => {
                const list = res.assignments || res.data?.assignments || [];
                if (!res.success || !Array.isArray(list)) return;
                const newHash = calculateStateHash(list);

                if (previousStateHash === '') {
                    previousStateHash = newHash;
                    return;
                }

                if (previousStateHash !== newHash) {
                    previousStateHash = newHash;

                    const modalEl = document.getElementById('answerModal');
                    const isModalOpen = (modalEl && modalEl.style.display === 'flex');

                    // 1. 모달이 열려있는 경우: 현재 보고 있는 과제의 최신 데이터로 모달을 실시간 인플레이스 리렌더링 (깜빡임/닫힘 없음)
                    if (isModalOpen && currentModalData) {
                        const updatedItem = list.find(a => a.assignment_id === currentModalData.assignment_id);
                        if (updatedItem) {
                            openAnswerModal(updatedItem, updatedItem.assign_status === 'completed');
                        }
                    }

                    // 2. 대시보드 화면 카드 실시간 동기화
                    let needFullReload = false;
                    list.forEach(item => {
                        const card = document.getElementById(`reviewCard-${item.assignment_id}`);
                        if (card) {
                            // 테두리 클래스 업데이트
                            card.className = `exam-card ${item.is_grading_complete ? 'border-done' : 'border-review'}`;
                            
                            // 뱃지 영역 업데이트
                            const badgeSpan = card.querySelector('.badge');
                            if (badgeSpan) {
                                if (item.is_ai_pending) {
                                    badgeSpan.className = 'badge badge-pulse';
                                    badgeSpan.innerHTML = '⚡ AI가 답안을 읽고 있어요...';
                                } else if (item.is_grading_complete) {
                                    badgeSpan.className = 'badge badge-green';
                                    badgeSpan.innerHTML = '🎉 채점 완료';
                                } else {
                                    badgeSpan.className = 'badge badge-amber';
                                    badgeSpan.innerHTML = '⏳ 주관식 채점 중';
                                }
                            }

                            // 점수 요약 뱃지 업데이트
                            const scoreTag = card.querySelector('.quick-score-tag');
                            if (scoreTag) {
                                if (item.is_ai_pending) {
                                    scoreTag.style.background = '#eff6ff';
                                    scoreTag.style.color = '#1d4ed8';
                                    scoreTag.style.borderColor = '#bfdbfe';
                                    scoreTag.innerHTML = `⚡ AI가 손글씨 답안을 판독하고 있습니다... (약 3~4초 소요)`;
                                } else if (item.is_grading_complete) {
                                    scoreTag.style.background = '#ecfdf5';
                                    scoreTag.style.color = '#047857';
                                    scoreTag.style.borderColor = '#a7f3d0';
                                    scoreTag.innerHTML = `총점 <b>${item.total_score}점</b> (선생님 채점 완료 ✨)`;
                                } else {
                                    scoreTag.style.background = '#f0fdf4';
                                    scoreTag.style.color = '#15803d';
                                    scoreTag.style.borderColor = '#bbf7d0';
                                    scoreTag.innerHTML = `객관식 1차 채점: <b>${item.obj_score}점</b> (주관식 ${item.subj_total}문제 확인 중)`;
                                }
                            }

                            // 버튼에 바인딩된 최신 데이터 갱신
                            const actionBtn = card.querySelector('.btn-action');
                            if (actionBtn) {
                                actionBtn.onclick = () => openAnswerModal(item, item.assign_status === 'completed');
                            }
                        } else {
                            // 카드가 현재 섹션에 없는 경우 (구역 이동 등 구조적 변화가 발생한 경우)
                            if (!isModalOpen) {
                                needFullReload = true;
                            }
                        }
                    });

                    if (needFullReload && !isModalOpen) {
                        location.reload();
                    }
                }
            })
            .catch(err => {
                console.warn('실시간 상태 동기화 재시도 중...', err);
            });
    }

    // 3초마다 지속적으로 실시간 상태 감지
    setInterval(syncLiveStudentStatus, 3000);
    syncLiveStudentStatus(); // 초기 1회 즉시 실행
</script>

</body>
</html>
