<?php
/**
 * views/grading/review.php
 * 문항별/학생별 실시간 채점 결과 점검 및 첨삭/부분점수 부여 뷰
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

$userId   = requireTeacherAuth();
$assignId = (int)($_GET['assign_id'] ?? 0);
if (!$assignId) {
    die("잘못된 접근입니다. 과제 ID가 필요합니다.");
}

// 과제 및 템플릿 정보 조회 (show_answer 안전 조회)
$showAnswer = 0;
try {
    $stmt = $pdo->prepare("
        SELECT t.title AS template_name, c.class_name, COALESCE(a.show_answer, 0) AS show_answer 
        FROM assignments a 
        JOIN templates t ON a.template_id = t.id 
        LEFT JOIN classes c ON a.class_id = c.id
        WHERE a.id = :id
    ");
    $stmt->execute(['id' => $assignId]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    $showAnswer = (int)($info['show_answer'] ?? 0);
} catch (Exception $e) {
    $stmt = $pdo->prepare("
        SELECT t.title AS template_name, c.class_name 
        FROM assignments a 
        JOIN templates t ON a.template_id = t.id 
        LEFT JOIN classes c ON a.class_id = c.id
        WHERE a.id = :id
    ");
    $stmt->execute(['id' => $assignId]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
}
$examName = $info ? ($info['template_name'] . ($info['class_name'] ? ' - ' . $info['class_name'] : '')) : '평가 점검';

$stmtResult = $pdo->prepare("
    SELECT e.id, e.assignment_id, e.student_id, COALESCE(s.name, '학생') AS student_name, e.total_score, e.is_reviewed, e.raw_results, e.submitted_at 
    FROM exam_results e
    LEFT JOIN students s ON e.student_id = s.id
    WHERE e.assignment_id = :assign_id 
    ORDER BY e.id ASC
");
$stmtResult->execute(['assign_id' => $assignId]);
$initialRows = $stmtResult->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title><?= e($examName) ?> - 채점 현황 점검</title>
    <link rel="stylesheet" href="/public/assets/css/dashboard.css">
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif; 
            background: #f4f6f9; 
            padding: 20px; 
            margin: 0; 
            padding-bottom: 75px; 
        }
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background: white; 
            padding: 16px 22px; 
            border-radius: var(--radius-md, 10px); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.05); 
            border: 1px solid var(--gray-300, #dee2e6);
        }
        .header-left { display: flex; align-items: center; gap: 15px; }
        .header-left h2 { font-size: 1.35rem; font-weight: 800; color: #1e293b; }
        .btn-back { 
            padding: 8px 16px; 
            background: #64748b; 
            color: white; 
            border: none; 
            border-radius: 6px; 
            font-size: 0.92rem; 
            font-weight: 700; 
            cursor: pointer; 
            transition: 0.2s; 
        }
        .btn-back:hover { background: #475569; }
        
        .controls-wrapper { display: flex; justify-content: flex-start; align-items: center; margin: 18px 0; gap: 14px; }
        .view-toggle-container { display: flex; gap: 6px; background: #e2e8f0; padding: 5px; border-radius: 10px; }
        .toggle-btn { 
            padding: 9px 20px; 
            border: none; 
            border-radius: 7px; 
            font-weight: 700; 
            font-size: 1rem; 
            cursor: pointer; 
            background: transparent; 
            color: #475569; 
            transition: 0.2s; 
        }
        .toggle-btn.active { 
            background: #0d6efd; 
            color: white; 
            box-shadow: 0 2px 6px rgba(13,110,253,0.3); 
        }
        .filter-label { 
            background: white; 
            padding: 9px 16px; 
            border-radius: 8px; 
            border: 1px solid #cbd5e1; 
            font-weight: 700; 
            cursor: pointer; 
            user-select: none; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.04); 
            font-size: 0.92rem; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
        }
        
        .sub-tabs { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 8px; margin-bottom: 16px; }
        .sub-tab-item { 
            padding: 7px 15px; 
            background: white; 
            border: 1px solid #cbd5e1; 
            border-radius: 20px; 
            font-size: 0.88rem; 
            font-weight: 700; 
            cursor: pointer; 
            white-space: nowrap; 
            transition: 0.2s;
        }
        .sub-tab-item:hover { border-color: #94a3b8; }
        .sub-tab-item.active { background: #1e293b; color: white; border-color: #1e293b; }
        .sub-tab-item .type-badge { font-size: 0.75em; padding: 2px 6px; border-radius: 4px; margin-left: 4px; background: #e2e8f0; color: #475569; }
        .sub-tab-item.active .type-badge { background: #334155; color: #f8fafc; }
        
        table { width: 100%; border-collapse: separate; border-spacing: 0 12px; }
        th, td { 
            padding: 16px; 
            background: white; 
            vertical-align: middle; 
            border-top: 2px solid #cbd5e1; 
            border-bottom: 2px solid #cbd5e1; 
            cursor: pointer; 
            transition: background 0.15s, border-color 0.15s;
        }
        td:first-child { 
            border-left: 2px solid #cbd5e1; 
            border-radius: 10px 0 0 10px; 
            width: 16%; 
            text-align: center; 
            line-height: 1.4; 
        }
        td:nth-child(2) { text-align: left; padding-left: 32px; } 
        td:last-child { 
            border-right: 2px solid #cbd5e1; 
            border-radius: 0 10px 10px 0; 
            width: 32%; 
            text-align: center; 
        }
        
        /* 3색 상태 시스템 (명확한 시각 구분) */
        tr.pending td { border-color: #f59e0b; background: #fffdf0; }
        tr.correct td { border-color: #10b981; background: #f0fdf4; }
        tr.incorrect td { border-color: #ef4444; background: #fef2f2; }
        tr.partial td { border-color: #f59e0b; background: #fffbeb; }

        /* 상태 보존형 포커스 아웃라인 (본래 상태색을 지우지 않고 확실하게 포커스 표시) */
        tr.focused td {
            box-shadow: 0 4px 16px rgba(13, 110, 253, 0.22) !important;
        }
        tr.focused td:first-child {
            border-left: 6px solid #0d6efd !important;
        }

        /* 3색 상태 뱃지 */
        .badge-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .badge-pending { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-correct { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; }
        .badge-incorrect { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-partial { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }

        .badge-appeal {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 800;
            background: #ede9fe;
            color: #6d28d9;
            border: 1px solid #ddd6fe;
        }
        
        .crop-container { position: relative; display: inline-block; padding: 5px; }
        .crop-img { max-width: 100%; max-height: 160px; border: 1px solid #ccc; border-radius: 6px; display: block; }
        .expand-btn { position: absolute; background: rgba(13, 202, 240, 0.25); cursor: pointer; transition: 0.2s; }
        .expand-btn:hover { background: rgba(13, 202, 240, 0.7); }
        .expand-top { top: -5px; left: 0; right: 0; height: 10px; }
        .expand-bottom { bottom: -5px; left: 0; right: 0; height: 10px; }
        .expand-left { top: 0; bottom: 0; left: -5px; width: 10px; }
        .expand-right { top: 0; bottom: 0; right: -5px; width: 10px; }

        .sub-roi-selector {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
            padding: 4px 10px;
            background: #f1f5f9;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .sub-roi-badge {
            padding: 3px 9px;
            font-size: 0.78rem;
            font-weight: 700;
            border-radius: 6px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .sub-roi-badge:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }
        .sub-roi-badge.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.35);
        }
        
        .answer-info-block { display: flex; flex-direction: row; justify-content: flex-start; align-items: center; gap: 15px; margin-top: 10px; padding-left: 5px; }
        .model-answer-badge { font-size: 0.92em; color: #856404; background: #fff3cd; padding: 6px 14px; border-radius: 6px; border: 1px solid #ffeeba; }
        .extracted-answer { font-size: 0.92em; color: #495057; background: #f8f9fa; padding: 6px 14px; border-radius: 6px; border: 1px solid #e9ecef; }
        .extracted-answer b { color: #0d6efd; }
        .badge-warning { background: #dc3545; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em; font-weight: bold; margin-right: 4px; }
        
        .score-btn { padding: 9px 15px; font-size: 0.92em; font-weight: 700; border: 1.5px solid #cbd5e1; border-radius: 6px; margin: 0 3px; cursor: pointer; background: white; transition: 0.2s; }
        .btn-o { color: #10b981; border-color: #a7f3d0; } 
        .btn-x { color: #ef4444; border-color: #fecaca; } 
        .btn-p { color: #b45309; border-color: #fde68a; }
        tr.correct .btn-o { background: #10b981; color: white; border-color: #10b981; }
        tr.incorrect .btn-x { background: #ef4444; color: white; border-color: #ef4444; }
        tr.partial .btn-p { background: #f59e0b; color: white; border-color: #f59e0b; }
        
        .partial-input-wrapper { display: none; margin-top: 8px; }
        tr.partial-mode .partial-input-wrapper { display: block; }
        .partial-input { width: 70px; padding: 6px; font-size: 1.1em; text-align: center; border: 2px solid #ffc107; border-radius: 6px; font-weight: bold; outline: none; }
        .empty-msg { text-align: center; padding: 50px; font-size: 1.2em; color: #6c757d; }
    </style>
</head>
<body>

    <div class="header">
        <div class="header-left">
            <h2 style="margin:0;">📋 <?= e($examName) ?> - 채점 현황 점검</h2>
            <button class="btn-back" onclick="goBackAndReset()">⬅ 돌아가기</button>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="/views/grading/print_slips.php?assign_id=<?= $assignId ?>" class="btn-back" style="background:#475569; text-decoration:none;" target="_blank">🖨️ 학생 배부용 정오표</a>
            <a href="/views/grading/diagnose.php?assign_id=<?= $assignId ?>" class="btn-back" style="background:#2563eb; text-decoration:none;" target="_blank">🔬 AI 채점 정밀 진단</a>
            <span id="syncStatus" style="color: #198754; font-weight: bold;">🟢 실시간 자동 동기화 중</span>
        </div>
    </div>

    <div class="controls-wrapper">
        <div class="view-toggle-container">
            <button class="toggle-btn active" id="btnModeQ" onclick="switchViewMode('question')">📝 문항별 모아보기</button>
            <button class="toggle-btn" id="btnModeS" onclick="switchViewMode('student')">👤 학생별 모아보기</button>
        </div>
        <label class="filter-label">
            <input type="checkbox" id="chkSubjectiveOnly" onchange="toggleFilter()"> 주관식만 보기
        </label>
        <label class="filter-label" style="margin-left: auto; border-color: #8b5cf6; background: #faf5ff;">
            <input type="checkbox" id="chkShowAnswer" onchange="toggleAnswerVisibility()" <?= $showAnswer ? 'checked' : '' ?>>
            <span style="color: #6d28d9; font-weight: 800;">💡 학생에게 모범 답안 공개</span>
        </label>
        <button type="button" id="btnRegradePending" class="btn-regrade" onclick="regradePending()" style="background: linear-gradient(135deg, #0d6efd, #6366f1); color: white; border: none; padding: 7px 14px; border-radius: 8px; font-weight: 800; font-size: 0.88rem; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(13,110,253,0.25);">
            <span>⚡</span> <span>미판독 문항 AI 즉시 채점</span>
        </button>
    </div>

    <div class="sub-tabs" id="subTabsContainer"></div>

    <table id="reviewTable">
        <tbody id="studentList"></tbody>
    </table>

    <div style="position: fixed; bottom: 0; left: 0; right: 0; padding: 12px 20px; background: #212529; color: white; border-top: 3px solid #0d6efd; font-size: 14px; z-index: 1000;">
        ⌨️ <b>단축키:</b> 
        <span style="color:#28a745">[1] 정답(만점)</span> | 
        <span style="color:#dc3545">[2] 오답(0점)</span> | 
        <span style="color:#ffc107">[3] 부분점수</span> | 
        <span>[↑/↓] 위아래 이동</span> | 
        <span>[Backspace] 채점 취소</span> | 
        <span style="color:#0dcaf0">[Shift + 방향키] 크롭 이미지 확장</span>
    </div>

<script>
    const assignId = <?= $assignId ?>;
    const initialDbRows = <?= json_encode($initialRows, JSON_UNESCAPED_UNICODE) ?>;
    
    const storageKey = `gradingState_${assignId}`;
    const savedState = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
    
    let rawRowsData = [];         
    let filteredData = [];        
    let displayRows = [];         
    
    let viewMode = savedState.viewMode || 'question';    
    let showSubjectiveOnly = false; // 진입 시 항상 전체 문항(객관식+주관식) 기본 표시
    let selectedFilterKey = savedState.selectedFilterKey || null; 
    
    let currentRowIndex = -1;
    let isPartialMode = false;
    let maxLastId = 0; 

    function goBackAndReset() {
        const dbIds = new Set();
        if (Array.isArray(rawRowsData)) {
            rawRowsData.forEach(r => { if (r.db_id) dbIds.add(r.db_id); });
        }
        if (Array.isArray(initialDbRows)) {
            initialDbRows.forEach(r => { if (r.id) dbIds.add(r.id); });
        }

        dbIds.forEach(dbId => {
            const formData = new FormData();
            formData.append('db_id', dbId);
            navigator.sendBeacon('/api/grading/delete_temp_image.php', formData);
        });

        sessionStorage.removeItem(storageKey);
        location.href = '/views/dashboard/main.php';
    }

    document.getElementById('chkSubjectiveOnly').checked = showSubjectiveOnly;
    document.getElementById('btnModeQ').classList.toggle('active', viewMode === 'question');
    document.getElementById('btnModeS').classList.toggle('active', viewMode === 'student');

    function saveState() {
        sessionStorage.setItem(storageKey, JSON.stringify({
            viewMode: viewMode,
            showSubjectiveOnly: showSubjectiveOnly,
            selectedFilterKey: selectedFilterKey
        }));
    }

    function unpackDbRow(dbRow) {
        const resultsMap = new Map(); 
        let rawList = [];
        try {
            rawList = typeof dbRow.raw_results === 'string' ? JSON.parse(dbRow.raw_results) : (dbRow.raw_results || []);
        } catch(e) { rawList = []; }

        // 레거시 객체 포맷 { status: 'processed', items: [...] } 대응
        if (!Array.isArray(rawList) && rawList && Array.isArray(rawList.items)) {
            rawList = rawList.items;
        } else if (!Array.isArray(rawList)) {
            rawList = [];
        }

        const studentLabel = dbRow.student_name; 

        rawList.forEach(item => {
            const rawId = item.item_id || item.id || 0;
            const subId = item.sub_id || null;
            const displayNum = (item.display_num || (subId ? `${rawId}-${subId}` : `${rawId}`)).toString();
            const itemId = displayNum;
            const maxScore = parseFloat(item.score || 0);
            
            let statusCode = -1;
            if (item.status !== undefined && item.status !== null) {
                if (typeof item.status === 'number') statusCode = item.status;
                else if (item.status === 'correct') statusCode = 1;
                else if (item.status === 'incorrect') statusCode = 0;
                else if (item.status === 'partial') statusCode = 2;
                else if (item.status === 'pending') statusCode = -1;
            }

            const statusClassMap = { 1: 'correct', 0: 'incorrect', 2: 'partial', '-1': 'pending' };
            const statusStr = statusClassMap[statusCode] || 'pending';

            let currentScore = 0;
            if (item.currentScore !== undefined && item.currentScore !== null) {
                currentScore = parseFloat(item.currentScore);
            } else if (statusCode === 1) {
                currentScore = maxScore;
            } else if (statusCode === 2) {
                currentScore = parseFloat(item.score_awarded || 0);
            }

            const recognizedText = (item.rawText || item.recognized_text || '').trim();
            const isAiPending = (item.type !== 'subjective') && (recognizedText === '판독대기' || recognizedText === 'AI 채점 중...' || (statusCode === -1 && !recognizedText));
            const isUnread = (item.type !== 'subjective') && !isAiPending && (!recognizedText || recognizedText === '미기재' || recognizedText === '판독불가');

            resultsMap.set(itemId, {
                db_id: dbRow.id,
                student_id: dbRow.student_id,
                studentName: studentLabel,
                item_id: itemId,
                type: item.type || 'objective',
                score: maxScore,
                currentScore: currentScore,
                statusCode: statusCode,
                status: statusStr,
                answer: item.answer || item.model_answer || '미지정',
                rawText: recognizedText,
                isAiPending: isAiPending,
                isUnread: isUnread,
                crop_image_url: item.crop_url || item.crop_image_url || '',
                appeal: !item.appeal_resolved && !!item.appeal,
                appeal_reason: item.appeal_reason || '확인 요청'
            });
        });
        
        return Array.from(resultsMap.values()); 
    }

    if (Array.isArray(initialDbRows)) {
        initialDbRows.forEach(row => {
            const rowId = parseInt(row.id || 0);
            if (rowId > maxLastId) maxLastId = rowId;

            const items = unpackDbRow(row);
            items.forEach(item => {
                const idx = rawRowsData.findIndex(r => r.db_id === item.db_id && r.item_id === item.item_id);
                if (idx >= 0) rawRowsData[idx] = item;
                else rawRowsData.push(item);
            });
        });
        applyFiltersAndRefresh(true);
    }

    function fetchLatestResults() {
        fetch(`/api/grading/get_results.php?assign_id=${assignId}`)
            .then(res => res.json())
            .then(dataList => {
                const statusElem = document.getElementById('syncStatus');
                if (statusElem) statusElem.innerHTML = "<span style='color:#198754;'>🟢 실시간 자동 동기화 중</span>";

                if (!Array.isArray(dataList) || dataList.length === 0) return;

                let hasNewData = false;
                dataList.forEach(data => {
                    const items = unpackDbRow(data);
                    items.forEach(item => {
                        const idx = rawRowsData.findIndex(r => r.db_id === item.db_id && r.item_id === item.item_id);
                        if (idx >= 0) {
                            // 상태나 점수 또는 인식 텍스트가 바뀐 경우만 업데이트
                            const prev = rawRowsData[idx];
                            if (prev.statusCode !== item.statusCode || prev.currentScore !== item.currentScore || prev.rawText !== item.rawText) {
                                rawRowsData[idx] = item;
                                hasNewData = true;
                            }
                        } else {
                            rawRowsData.push(item);
                            hasNewData = true;
                        }
                    });
                });

                if (hasNewData) {
                    const focusedTarget = displayRows[currentRowIndex];
                    applyFiltersAndRefresh(false);
                    if (focusedTarget) {
                        const restoredIdx = displayRows.findIndex(r => r.db_id === focusedTarget.db_id && r.item_id === focusedTarget.item_id);
                        if (restoredIdx >= 0) currentRowIndex = restoredIdx;
                    }
                    renderTable();
                }
            })
            .catch(() => {
                const statusElem = document.getElementById('syncStatus');
                if (statusElem) statusElem.innerHTML = "<span style='color:#ffc107;'>🟡 동기화 연결 확인 중...</span>";
            });
    }

    // 미판독 문항 즉시 재채점 호출 함수
    function regradePending() {
        const btn = document.getElementById('btnRegradePending');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = "<span>⏳</span> <span>AI 채점 진행 중...</span>";
        }

        fetch('/api/grading/regrade_pending.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assignment_id: assignId })
        })
        .then(res => res.json())
        .then(res => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = "<span>⚡</span> <span>미판독 문항 AI 즉시 채점</span>";
            }
            if (res.success) {
                alert(res.message || '미판독 문항 채점이 완료되었습니다.');
                fetchLatestResults();
            } else {
                alert('재채점 실패: ' + (res.error || '오류가 발생했습니다.'));
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = "<span>⚡</span> <span>미판독 문항 AI 즉시 채점</span>";
            }
            alert('통신 오류: ' + err.message);
        });
    }

    setInterval(fetchLatestResults, 3000);

    function toggleFilter() {
        showSubjectiveOnly = document.getElementById('chkSubjectiveOnly').checked;
        saveState();
        applyFiltersAndRefresh(true);
    }

    function applyFiltersAndRefresh(resetFocus = false) {
        filteredData = rawRowsData.filter(r => {
            if (showSubjectiveOnly && r.type !== 'subjective') return false;
            return true;
        });
        refreshTabsAndView(resetFocus);
    }

    function switchViewMode(mode) {
        viewMode = mode;
        selectedFilterKey = null; 
        saveState();
        document.getElementById('btnModeQ').classList.toggle('active', mode === 'question');
        document.getElementById('btnModeS').classList.toggle('active', mode === 'student');
        refreshTabsAndView(true);
    }

    function refreshTabsAndView(resetFocus = false) {
        const container = document.getElementById('subTabsContainer');
        container.innerHTML = '';
        
        if (filteredData.length === 0) {
            displayRows = [];
            renderTable();
            return;
        }

        if (viewMode === 'question') {
            const questions = [];
            filteredData.forEach(r => {
                if (!questions.some(q => q.item_id === r.item_id)) {
                    questions.push({ item_id: r.item_id, type: r.type });
                }
            });
            questions.sort((a,b) => String(a.item_id).localeCompare(String(b.item_id), undefined, { numeric: true }));

            if (selectedFilterKey && !questions.some(q => q.item_id === selectedFilterKey)) {
                selectedFilterKey = null;
            }

            questions.forEach(q => {
                const btn = document.createElement('button');
                btn.className = `sub-tab-item ${q.item_id === selectedFilterKey ? 'active' : ''}`;
                const badgeLabel = q.type === 'subjective' ? '주관식' : (q.type === 'sub_item' ? '소문항' : '객관식');
                btn.innerHTML = `${q.item_id}번 <span class="type-badge">${badgeLabel}</span>`;
                btn.onclick = () => { selectedFilterKey = q.item_id; saveState(); refreshTabsAndView(true); };
                container.appendChild(btn);
            });

            displayRows = selectedFilterKey ? filteredData.filter(r => r.item_id === selectedFilterKey) : [];

        } else {
            const students = [];
            filteredData.forEach(r => {
                if (!students.some(s => s.db_id === r.db_id)) {
                    students.push({ db_id: r.db_id, studentName: r.studentName });
                }
            });

            if (selectedFilterKey && !students.some(s => s.db_id === selectedFilterKey)) {
                selectedFilterKey = null;
            }

            students.forEach(s => {
                const btn = document.createElement('button');
                btn.className = `sub-tab-item ${s.db_id === selectedFilterKey ? 'active' : ''}`;
                btn.innerText = s.studentName;
                btn.onclick = () => { selectedFilterKey = s.db_id; saveState(); refreshTabsAndView(true); };
                container.appendChild(btn);
            });

            displayRows = selectedFilterKey ? filteredData.filter(r => r.db_id === selectedFilterKey) : [];
            displayRows.sort((a,b) => String(a.item_id).localeCompare(String(b.item_id), undefined, { numeric: true }));
        }

        renderTable();
        
        if (resetFocus && displayRows.length > 0) setFocus(0);
        else if (displayRows.length === 0) setFocus(-1);
    }

    function renderTable() {
        const tbody = document.getElementById('studentList');
        tbody.innerHTML = '';

        if (displayRows.length === 0) {
            const msg = selectedFilterKey ? '조건에 맞는 데이터가 없습니다.' : '👆 상단의 탭(문항 또는 학생)을 먼저 선택해주세요.';
            tbody.innerHTML = `<tr id="emptyRow"><td colspan="3" class="empty-msg">${msg}</td></tr>`;
            return;
        }

        displayRows.forEach((row, index) => {
            const tr = document.createElement('tr');
            tr.id = `row-${index}`;
            tr.className = row.status; 
            if (index === currentRowIndex) tr.classList.add('focused');
            if (isPartialMode && index === currentRowIndex) tr.classList.add('partial-mode');

            const firstColText = (viewMode === 'question') ? row.studentName : `${row.item_id}번`;
            const typeLabel = row.type === 'composite'
                ? '<span style="color:#c2410c; font-weight:700;">[복합다답]</span>'
                : (row.type === 'subjective'
                    ? '<span style="color:#b45309; font-weight:700;">[주관식]</span>'
                    : (row.type === 'sub_item'
                        ? '<span style="color:#7c3aed; font-weight:700;">[소문항]</span>'
                        : '<span style="color:#0d6efd; font-weight:700;">[객관식]</span>'));

            let statusBadge = '';
            if (row.status === 'correct') {
                statusBadge = `<span class="badge-status badge-correct">✅ 정답 (${row.currentScore}점)</span>`;
            } else if (row.status === 'incorrect') {
                statusBadge = `<span class="badge-status badge-incorrect">❌ 오답 (0점)</span>`;
            } else if (row.status === 'partial') {
                statusBadge = `<span class="badge-status badge-partial">△ 부분점수 (${row.currentScore}점)</span>`;
            } else {
                if (row.isAiPending) {
                    statusBadge = `<span class="badge-status badge-pending" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe;">⚡ AI 판독 진행 중</span>`;
                } else {
                    statusBadge = `<span class="badge-status badge-pending">⏳ 검수 대기</span>`;
                }
            }

            let appealHtml = '';
            if (row.appeal) {
                appealHtml = `<div style="margin-top:6px;"><span class="badge-appeal">🙋 확인 요청: "${row.appeal_reason}"</span></div>`;
            }

            let extractedAnswerHtml = '';
            if (row.type !== 'subjective') {
                if (row.isAiPending) {
                    extractedAnswerHtml = `<div class="extracted-answer"><span style="color:#2563eb; font-weight:700;">🤖 AI 판독 진행 중... ⏳</span></div>`;
                } else {
                    const warningBadge = row.isUnread ? '<span class="badge-warning">⚠️ 미기재/검수필요</span> ' : '';
                    extractedAnswerHtml = `<div class="extracted-answer">${warningBadge}인식된 답안: <b>${row.rawText === '' ? '미기재' : row.rawText}</b></div>`;
                }
            }

            tr.onclick = (e) => {
                if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'INPUT' && !e.target.classList.contains('expand-btn')) {
                    setFocus(index);
                }
            };

            let compositeSelectorHtml = '';
            if (row.type === 'composite') {
                const subAnsParts = (row.answer || '').split(/[,/]/).map(s => s.trim()).filter(Boolean);
                const subCount = subAnsParts.length > 1 ? subAnsParts.length : 2;
                row.subCount = subCount;
                if (row.activeSubIndex === undefined) row.activeSubIndex = 0;

                let btnHtml = '';
                for (let si = 0; si < subCount; si++) {
                    const isActive = ((row.activeSubIndex || 0) === si);
                    btnHtml += `<button type="button" class="sub-roi-badge ${isActive ? 'active' : ''}" onclick="selectSubRoi(${index}, ${si}, event)" title="클릭 또는 Tab키로 선택">답안 [${si+1}]</button>`;
                }
                compositeSelectorHtml = `
                    <div class="sub-roi-selector">
                        <span style="font-size:0.78rem; font-weight:700; color:#475569;">확장 답안:</span>
                        ${btnHtml}
                        <span style="font-size:0.72rem; color:#94a3b8;">(Tab키 전환)</span>
                    </div>
                `;
            }
            
            tr.innerHTML = `
                <td>
                    <div style="font-size:1.15em; font-weight:800; color:#1e293b;">${firstColText}</div>
                    <div style="margin-top:2px;">${typeLabel}</div>
                    <div style="font-size: 0.85em; color: #64748b; font-weight: bold; margin-top: 4px;">배점: ${row.score}점</div>
                    <div style="margin-top: 6px;">${statusBadge}</div>
                    ${appealHtml}
                </td>
                <td>
                    ${compositeSelectorHtml}
                    <div class="crop-container">
                        <div class="expand-btn expand-top" onclick="expandImage(${index}, 'top')" title="Shift+↑ 확장"></div>
                        <div class="expand-btn expand-bottom" onclick="expandImage(${index}, 'bottom')" title="Shift+↓ 확장"></div>
                        <div class="expand-btn expand-left" onclick="expandImage(${index}, 'left')" title="Shift+← 확장"></div>
                        <div class="expand-btn expand-right" onclick="expandImage(${index}, 'right')" title="Shift+→ 확장"></div>
                        <img src="${row.crop_image_url}" class="crop-img" id="cropImg-${index}" alt="답안 크롭">
                    </div>
                    <div class="answer-info-block">
                        <div class="model-answer-badge">💡 모범 답안: <b>${row.answer}</b></div>
                        ${extractedAnswerHtml}
                    </div>
                </td>
                <td>
                    <button class="score-btn btn-o" onclick="markScore(${index}, ${row.score})"><b>[1]</b> O 정답 (${row.score})</button>
                    <button class="score-btn btn-x" onclick="markScore(${index}, 0)"><b>[2]</b> X 오답 (0)</button>
                    <button class="score-btn btn-p" onclick="enterPartialMode(${index})"><b>[3]</b> △ 부분점수</button>
                    
                    <div class="partial-input-wrapper">
                        <input type="number" class="partial-input" id="partialInput-${index}" 
                               min="0" max="${row.score}" step="0.5" 
                               value="${row.currentScore !== null ? row.currentScore : Math.floor(row.score/2)}">
                        <span style="font-weight:bold;"> / ${row.score}점</span>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    function selectSubRoi(index, subIdx, e) {
        if (e) e.stopPropagation();
        if (displayRows[index]) {
            displayRows[index].activeSubIndex = subIdx;
            renderTable();
        }
    }

    function setFocus(index) {
        if (index < 0 || index >= displayRows.length) {
            currentRowIndex = -1;
            renderTable();
            return;
        }
        currentRowIndex = index;
        isPartialMode = false;
        renderTable();
        const targetRow = document.getElementById(`row-${index}`);
        if (targetRow) targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    document.addEventListener('keydown', (e) => {
        if (currentRowIndex < 0 || displayRows.length === 0) return;
        const row = displayRows[currentRowIndex];

        if (e.key === 'Tab' && row && row.type === 'composite') {
            e.preventDefault();
            const count = row.subCount || 2;
            row.activeSubIndex = ((row.activeSubIndex || 0) + 1) % count;
            renderTable();
            return;
        }

        if (e.shiftKey) {
            if (e.key === 'ArrowUp') { e.preventDefault(); expandImage(currentRowIndex, 'top'); }
            if (e.key === 'ArrowDown') { e.preventDefault(); expandImage(currentRowIndex, 'bottom'); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); expandImage(currentRowIndex, 'left'); }
            if (e.key === 'ArrowRight') { e.preventDefault(); expandImage(currentRowIndex, 'right'); }
            return;
        }

        if (isPartialMode) {
            const inputEl = document.getElementById(`partialInput-${currentRowIndex}`);
            if (e.key === 'Enter') {
                e.preventDefault();
                savePartialScore(currentRowIndex, parseFloat(inputEl.value));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                inputEl.value = Math.min(row.score, (parseFloat(inputEl.value) || 0) + 0.5);
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                inputEl.value = Math.max(0, (parseFloat(inputEl.value) || 0) - 0.5);
            } else if (e.key === 'Escape') {
                isPartialMode = false;
                renderTable();
            }
            return;
        }

        switch (e.key) {
            case '1':
                e.preventDefault(); markScore(currentRowIndex, row.score, 'correct'); break;
            case '2':
                e.preventDefault(); markScore(currentRowIndex, 0, 'incorrect'); break;
            case '3':
                e.preventDefault(); enterPartialMode(currentRowIndex); break;
            case 'ArrowDown':
                e.preventDefault(); setFocus(currentRowIndex + 1); break;
            case 'ArrowUp':
                e.preventDefault(); setFocus(currentRowIndex - 1); break;
            case 'Backspace':
                e.preventDefault();
                if (currentRowIndex > 0) {
                    displayRows[currentRowIndex - 1].status = 'pending';
                    displayRows[currentRowIndex - 1].statusCode = -1;
                    displayRows[currentRowIndex - 1].currentScore = 0;
                    setFocus(currentRowIndex - 1);
                }
                break;
        }
    });

    function markScore(index, score, forceStatus = null) {
        const row = displayRows[index];
        row.currentScore = score;
        row.appeal = false;
        
        if (forceStatus) {
            row.status = forceStatus;
            row.statusCode = (forceStatus === 'correct') ? 1 : ((forceStatus === 'incorrect') ? 0 : 2);
        } else {
            if (score === row.score) { row.status = 'correct'; row.statusCode = 1; }
            else if (score === 0) { row.status = 'incorrect'; row.statusCode = 0; }
            else { row.status = 'partial'; row.statusCode = 2; }
        }

        if (index + 1 < displayRows.length) setFocus(index + 1);
        else { setFocus(index); renderTable(); }

        fetch('/api/grading/save_score.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                db_id: row.db_id,
                item_id: row.item_id,
                score: score,
                status: row.statusCode
            })
        })
        .then(res => res.json())
        .then(res => { if (!res.success) console.error('점수 저장 실패:', res.error); });
    }

    function enterPartialMode(index) {
        if (currentRowIndex !== index) setFocus(index);
        isPartialMode = true;
        renderTable();
        setTimeout(() => {
            const inputEl = document.getElementById(`partialInput-${index}`);
            if (inputEl) { inputEl.focus(); inputEl.select(); }
        }, 50);
    }

    function savePartialScore(index, score) {
        if (isNaN(score) || score < 0) score = 0;
        markScore(index, score, 'partial');
    }

    function expandImage(index, direction) {
        const row = displayRows[index];
        const imgEl = document.getElementById(`cropImg-${index}`);
        imgEl.style.opacity = '0.5';
        const subIdx = row.activeSubIndex || 0;

        fetch(`/api/grading/expand_crop.php?db_id=${row.db_id}&item_id=${encodeURIComponent(row.item_id)}&direction=${direction}&sub_index=${subIdx}`)
            .then(res => res.json())
            .then(data => {
                imgEl.style.opacity = '1';
                if (data.success && data.new_image_base64) {
                    imgEl.src = data.new_image_base64;
                    row.crop_image_url = data.new_image_base64;
                } else alert('이미지를 확장할 수 없습니다: ' + (data.error || '원본 파일 없음'));
            })
            .catch(() => imgEl.style.opacity = '1');
    }

    function toggleAnswerVisibility() {
        const isChecked = document.getElementById('chkShowAnswer').checked;
        fetch('/api/grading/toggle_answer_visibility.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                assignment_id: assignId,
                show_answer: isChecked ? 1 : 0
            })
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert('설정 변경 실패: ' + (data.error || '오류 발생'));
                document.getElementById('chkShowAnswer').checked = !isChecked;
            } else {
                const statusElem = document.getElementById('syncStatus');
                if (statusElem) {
                    statusElem.innerHTML = `<span style="color:#8b5cf6; font-weight:800;">💡 ${data.message}</span>`;
                    setTimeout(() => {
                        statusElem.innerHTML = "<span style='color:#198754; font-weight:bold;'>🟢 실시간 자동 동기화 중</span>";
                    }, 3000);
                }
            }
        })
        .catch(() => {
            alert('네트워크 통신 오류가 발생했습니다.');
            document.getElementById('chkShowAnswer').checked = !isChecked;
        });
    }
</script>
</body>
</html>
