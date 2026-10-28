<?php
/**
 * views/templates/editor.php
 * 고급 ROI 지정 및 배점/정답 설정 에디터 뷰 (N쪽 다면 평가지 탭 & page_uid 무결성 보존)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

$userId = requireTeacherAuth();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { 
    header("Location: /views/dashboard/main.php"); 
    exit; 
}

$stmt = $pdo->prepare("SELECT * FROM templates WHERE id = :id AND user_id = :u");
$stmt->execute(['id' => $id, 'u' => $userId]);
$template = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$template) { 
    die("존재하지 않거나 권한이 없는 템플릿입니다."); 
}

// SSOT Contract A: pages_data 로드 및 레거시 1쪽 폴백
$pagesData = [];
if (!empty($template['pages_data'])) {
    $pagesData = json_decode($template['pages_data'], true) ?: [];
}
if (empty($pagesData)) {
    $pagesData = [[
        'page_uid'   => 'p_1_default',
        'page_num'   => 1,
        'title'      => '1쪽',
        'image_path' => $template['image_path'],
        'width'      => 1200,
        'height'     => 1700
    ]];
}

// SSOT Contract B: roi_data 로드 및 안전 디코딩
$roiData = [];
if (!empty($template['roi_data'])) {
    $roiData = json_decode($template['roi_data'], true) ?: [];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title><?= e($template['title']) ?> - ROI 지정 및 정답 설정</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif; background: #1e1e1e; color: #fff; margin: 0; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; background: #2d2d2d; padding: 15px 20px; border-radius: 8px; }
        .guide-box { background: #333; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 13.5px; line-height: 1.6; border-left: 4px solid #007bff; }
        .wrapper { display: flex; gap: 20px; height: calc(100vh - 180px); }
        
        .canvas-panel { flex: 1.2; display: flex; flex-direction: column; min-width: 0; }
        
        /* 상단 페이지 탭 바 */
        .page-nav-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #25282c;
            padding: 8px 12px;
            border-radius: 8px 8px 0 0;
            border: 1px solid #444;
            border-bottom: none;
            gap: 10px;
        }
        .page-tabs {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            align-items: center;
        }
        .page-tab-btn {
            background: #34373c;
            color: #bbb;
            border: 1px solid #555;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .page-tab-btn:hover { background: #42464c; color: #fff; }
        .page-tab-btn.active {
            background: #007bff;
            color: #fff;
            border-color: #007bff;
            box-shadow: 0 2px 8px rgba(0,123,255,0.3);
        }
        .tab-badge {
            background: rgba(0,0,0,0.25);
            font-size: 11px;
            padding: 1px 6px;
            border-radius: 10px;
            color: #ddd;
        }
        .page-tab-btn.active .tab-badge {
            background: rgba(255,255,255,0.25);
            color: #fff;
        }
        .btn-manage-pages {
            background: #3a3d42;
            color: #e2e8f0;
            border: 1px solid #555;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: 0.15s;
        }
        .btn-manage-pages:hover { background: #4f535a; }

        .btn-ai-reextract {
            background: linear-gradient(135deg, #0d6efd 0%, #4f46e5 100%);
            color: #fff;
            border: 1px solid #6366f1;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            white-space: nowrap;
            transition: 0.2s;
            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.3);
        }
        .btn-ai-reextract:hover { filter: brightness(1.1); }
        .btn-reset-rois {
            background: #2b2d31;
            color: #f87171;
            border: 1px solid #ef4444;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: 0.15s;
        }
        .btn-reset-rois:hover { background: #dc2626; color: #fff; }

        /* 알림 토스트 */
        .toast-banner {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            border: 1.5px solid #3b82f6;
            color: #f8fafc;
            padding: 12px 24px;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
            z-index: 10001;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 14px;
            animation: fadeInDown 0.3s ease;
        }
        @keyframes fadeInDown {
            from { opacity: 0; transform: translate(-50%, -20px); }
            to { opacity: 1; transform: translate(-50%, 0); }
        }

        /* 캔버스 줌 & 뷰포트 맞춤 툴바 */
        .zoom-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #1e2024;
            padding: 6px 12px;
            border: 1px solid #444;
            border-top: none;
            border-bottom: 1px solid #383a40;
            font-size: 12px;
        }
        .btn-zoom {
            background: #2b2e33;
            color: #cbd5e1;
            border: 1px solid #475569;
            padding: 4px 9px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-zoom:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; }
        .zoom-badge {
            background: #0f172a;
            color: #38bdf8;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 800;
            border: 1px solid #1e293b;
        }

        .canvas-container { flex: 1; background: #111; overflow: auto; position: relative; border-radius: 0 0 8px 8px; border: 1px solid #444; border-top: none; text-align: center; }
        canvas { display: block; margin: 0 auto; transition: width 0.1s ease, height 0.1s ease; }
        
        .sidebar { flex: 0.9; background: #2d2d2d; padding: 20px; border-radius: 8px; display: flex; flex-direction: column; min-width: 580px; }
        
        .score-control-panel { background: #202225; border: 1px solid #3f4248; border-radius: 8px; padding: 12px 15px; margin-bottom: 15px; }
        .score-summary { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 13.5px; font-weight: bold; border-bottom: 1px solid #333; padding-bottom: 10px; }
        .score-summary input { width: 55px; padding: 3px 5px; text-align: center; border-radius: 4px; border: 1px solid #555; background: #111; color: #fff; font-weight: bold; }
        .base-score { color: #28a745; }
        .base-score.exceeded { color: #dc3545; }

        .score-batch-actions { display: flex; align-items: center; gap: 12px; font-size: 13px; background: #2b2d31; padding: 8px 12px; border-radius: 6px; }
        .action-group { display: flex; align-items: center; gap: 6px; }
        .action-group input { padding: 4px 6px; border-radius: 4px; border: 1px solid #555; background: #111; color: #fff; width: 50px; text-align: center; }
        .action-divider { color: #555; font-weight: bold; }
        
        .btn-action { padding: 4px 8px; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; }
        .btn-action:hover { background: #0056b3; }

        .roi-list-box { flex: 1; overflow-y: auto; margin-bottom: 15px; padding-right: 5px; }
        
        .roi-item { 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            background: #3d3d3d; 
            padding: 8px 12px; 
            margin-bottom: 8px; 
            border-radius: 6px; 
            border-left: 4px solid #007bff; 
            cursor: pointer;
            transition: background 0.15s;
        }
        .roi-item:hover { background: #484848; }
        .roi-item.subjective { border-left-color: #28a745; }
        .roi-item.selected-focus {
            background: #334155 !important;
            border-left-width: 6px !important;
            box-shadow: 0 0 0 2px #38bdf8, 0 4px 12px rgba(56, 189, 248, 0.25) !important;
        }
        .roi-item.other-page { opacity: 0.65; background: #2e3034; }
        .roi-page-tag { font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #222; color: #38bdf8; font-weight: 700; white-space: nowrap; }
        .roi-title { font-weight: bold; font-size: 14px; min-width: 25px; color: #ffc107; }
        
        .type-toggle { display: inline-flex; background: #1e1f22; border-radius: 4px; border: 1px solid #555; overflow: hidden; flex-shrink: 0; }
        .type-btn { padding: 4px 8px; font-size: 12px; border: none; background: transparent; color: #888; cursor: pointer; }
        .type-btn.active.obj { background: #007bff; color: #fff; font-weight: bold; }
        .type-btn.active.subj { background: #28a745; color: #fff; font-weight: bold; }
        .type-btn.active.sub { background: #8b5cf6; color: #fff; font-weight: bold; }
        .type-btn.active.comp { background: #f97316; color: #fff; font-weight: bold; }
        .btn-merge-composite { font-size: 11px; padding: 3px 7px; border-radius: 4px; border: 1px solid #f97316; background: transparent; color: #f97316; cursor: pointer; white-space: nowrap; }
        .btn-merge-composite:hover { background: #f97316; color: #fff; }
        .btn-split-sub { font-size: 11px; padding: 3px 7px; border-radius: 4px; border: 1px solid #8b5cf6; background: transparent; color: #8b5cf6; cursor: pointer; white-space: nowrap; }
        .btn-split-sub:hover { background: #8b5cf6; color: #fff; }
        .btn-add-sub { font-size: 11px; padding: 3px 7px; border-radius: 4px; border: 1px solid #8b5cf6; background: rgba(139, 92, 246, 0.2); color: #c4b5fd; cursor: pointer; white-space: nowrap; font-weight: 700; transition: all 0.15s; }
        .btn-add-sub:hover { background: #8b5cf6; color: #fff; }
        .btn-add-sub.comp { border-color: #f97316; background: rgba(249, 115, 22, 0.2); color: #fdba74; }
        .btn-add-sub.comp:hover { background: #f97316; color: #fff; }
        .composite-ans-list { font-size: 11px; color: #f97316; margin-top: 3px; }
        .adding-sub-guide { font-size: 11.5px; color: #c4b5fd; font-weight: 700; margin-top: 4px; display: flex; align-items: center; gap: 4px; }
        .adding-sub-guide.comp { color: #fdba74; }
        .roi-item.adding-sub-mode {
            border-left: 5px solid #8b5cf6 !important;
            box-shadow: 0 0 0 2px #8b5cf6, 0 4px 12px rgba(139, 92, 246, 0.3) !important;
            animation: pulseSubBorder 1.2s infinite alternate;
        }
        .roi-item.adding-sub-mode.composite {
            border-left-color: #f97316 !important;
            box-shadow: 0 0 0 2px #f97316, 0 4px 12px rgba(249, 115, 22, 0.3) !important;
            animation: pulseCompBorder 1.2s infinite alternate;
        }
        @keyframes pulseSubBorder {
            0% { background: #3d3d3d; }
            100% { background: #382c52; }
        }
        @keyframes pulseCompBorder {
            0% { background: #3d3d3d; }
            100% { background: #4a2f1c; }
        }

        .input-answer { flex: 1; padding: 6px 10px; border-radius: 4px; border: 1px solid #555; background: #222; color: #fff; font-size: 13px; }
        .score-wrap { display: flex; align-items: center; gap: 4px; font-size: 13px; white-space: nowrap; }
        .input-score { width: 45px; padding: 6px 4px; text-align: center; border-radius: 4px; border: 1px solid #555; background: #222; color: #fff; font-size: 13px; }
        
        .btn-delete { background: transparent; color: #dc3545; border: 1px solid #dc3545; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .btn-delete:hover { background: #dc3545; color: white; }
        
        .btn-save { width: 100%; padding: 15px; font-size: 16px; font-weight: bold; background: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer; }
        .btn-save:hover { background: #0056b3; }

        /* 페이지 관리 모달 */
        .modal-backdrop {
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            display: none;
        }
        .modal-card {
            background: #2a2d32;
            width: 90%;
            max-width: 600px;
            border-radius: 10px;
            border: 1px solid #444;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        .modal-title { font-size: 1.2rem; font-weight: 800; margin-top: 0; margin-bottom: 16px; color: #fff; }
        .modal-page-list { max-height: 350px; overflow-y: auto; margin-bottom: 16px; }
        .modal-page-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            background: #1e2024;
            border-radius: 6px;
            margin-bottom: 8px;
            border: 1px solid #3f4248;
        }
        .modal-page-info { display: flex; align-items: center; gap: 10px; }
        .modal-page-thumb { width: 45px; height: 60px; object-fit: cover; border-radius: 4px; border: 1px solid #555; }
        .modal-page-actions { display: flex; gap: 6px; }
        .btn-modal-action {
            background: #34373c;
            color: #ddd;
            border: 1px solid #555;
            padding: 5px 9px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .btn-modal-action:hover { background: #4a4e54; color: #fff; }
        .btn-modal-action.btn-del:hover { background: #dc3545; border-color: #dc3545; color: #fff; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; }
        .btn-modal-close { background: #007bff; color: white; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>

    <div class="header">
        <h2>🎯 ROI 지정 및 정답 설정: <?= e($template['title']) ?></h2>
        <a href="/views/dashboard/main.php" style="color: #ccc; text-decoration: none;">← 메인으로 돌아가기</a>
    </div>

    <div class="guide-box">
        💡 <b>사용 방법 & 팁:</b><br>
        1. <b>AI 자동 완성:</b> 상단의 <code>[✨ AI 자동 완성 다시 받기]</code>를 누르면 시험지 문항과 정답 영역을 언제든지 다시 추출합니다.<br>
        2. <b>자유로운 미세 조정:</b> 사각형 모서리를 드래그해 크기를 조절하고, 사각형을 클릭한 뒤 [Delete] 키를 누르면 개별 삭제됩니다.<br>
        3. <b>직접 추가:</b> 빈 캔버스를 드래그하여 문항 번호(노란색)와 정답란(파란색)을 손쉽게 직접 추가할 수 있습니다.<br>
        4. <b>페이지 이동:</b> 상단 페이지 탭을 클릭하거나 오른쪽 문항 카드를 클릭하면 해당 페이지로 즉시 전환됩니다.
    </div>

    <div class="wrapper">
        <div class="canvas-panel">
            <div class="page-nav-bar">
                <div class="page-tabs" id="pageTabs"></div>
                <div class="page-actions-bar" style="display: flex; gap: 8px; align-items: center;">
                    <button type="button" class="btn-ai-reextract" onclick="reExtractAi()">✨ AI 자동 완성 다시 받기</button>
                    <button type="button" class="btn-reset-rois" onclick="resetAllRois()">🗑️ 전체 초기화</button>
                    <button type="button" class="btn-manage-pages" onclick="openPageManager()">⚙️ 페이지 관리</button>
                </div>
            </div>
            <div class="zoom-toolbar">
                <div style="display: flex; gap: 6px; align-items: center;">
                    <span style="color: #94a3b8; font-weight: 700; margin-right: 4px;">🔍 화면 맞춤:</span>
                    <button type="button" class="btn-zoom" onclick="fitWidth()" title="캔버스 너비를 화면에 100% 맞춤">↔ 가로폭 맞춤</button>
                    <button type="button" class="btn-zoom" onclick="fitPage()" title="시험지 1쪽 전체가 화면에 들어오도록 맞춤">↕ 세로 맞춤</button>
                    <button type="button" class="btn-zoom" onclick="zoomReset()">100%</button>
                    <button type="button" class="btn-zoom" onclick="zoomIn()">＋ 확대</button>
                    <button type="button" class="btn-zoom" onclick="zoomOut()">－ 축소</button>
                    <span class="zoom-badge" id="zoomBadge">100%</span>
                </div>
                <div style="font-size: 11.5px; color: #94a3b8;">
                    <span>💡 번호 지정 중: <b style="color:#f59e0b">[Backspace]/[ESC] 취소</b> | <b style="color:#10b981">[Ctrl+Z] Undo</b> | 답안란 클릭: <b style="color:#38bdf8">객/주 전환</b></span>
                </div>
            </div>
            <div class="canvas-container">
                <canvas id="editorCanvas"></canvas>
            </div>
        </div>

        <div class="sidebar">
            <div class="score-control-panel">
                <div class="score-summary">
                    <span>총 문항: <span id="summaryCount">0</span>개</span>
                    <span>설정 배점: <span id="summaryTotalScore">0</span>점</span>
                    <span>기본점수: <span id="summaryBaseScore" class="base-score">100점</span></span>
                    <div class="action-group">
                        <span>만점:</span>
                        <input type="number" id="targetTotalScore" value="100" min="0"> 점
                        <button type="button" class="btn-action" onclick="applyTargetScore()">적용</button>
                    </div>
                </div>
                <div class="score-batch-actions">
                    <div class="action-group">
                        <span>일괄:</span>
                        <input type="number" id="batchAllVal" placeholder="점수" min="0"> 점
                        <button type="button" class="btn-action" onclick="applyBatchAll()">적용</button>
                    </div>
                    <span class="action-divider">|</span>
                    <div class="action-group">
                        <span>객관식:</span>
                        <input type="number" id="batchObjVal" placeholder="점수" min="0"> 점
                        <span>/ 주관식:</span>
                        <input type="number" id="batchSubjVal" placeholder="점수" min="0"> 점
                        <button type="button" class="btn-action" onclick="applyBatchType()">적용</button>
                    </div>
                </div>
            </div>

            <div class="roi-list-box" id="roiList"></div>
            <button type="button" class="btn-save" onclick="saveTemplate()">💾 템플릿 설정 저장하기</button>
        </div>
    </div>

    <!-- 페이지 관리 모달 -->
    <div class="modal-backdrop" id="pageModal">
        <div class="modal-card">
            <h3 class="modal-title">⚙️ 페이지 순서 변경 및 관리</h3>
            <div class="modal-page-list" id="modalPageList"></div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-close" onclick="closePageManager()">확인 및 닫기</button>
            </div>
        </div>
    </div>

    <script>
        const templateId = <?= $id ?>;
        const canvas = document.getElementById('editorCanvas');
        const ctx = canvas.getContext('2d');
        const roiListContainer = document.getElementById('roiList');
        const pageTabsContainer = document.getElementById('pageTabs');

        // SSOT Contract A: 페이지 목록
        let pages = <?= json_encode($pagesData, JSON_UNESCAPED_UNICODE) ?>;
        let activePageIndex = 0;
        let pageImages = {}; // 캐시: { [pageIndex]: HTMLImageElement }

        // SSOT Contract B: 문항 목록 (page_uid 영구 바인딩)
        let rawRois = <?= json_encode($roiData, JSON_UNESCAPED_UNICODE) ?>;
        let rois = (Array.isArray(rawRois) ? rawRois : []).map((r, i) => {
            const pageNum = parseInt(r.page) || 1;
            const matchedPage = pages.find(p => p.page_uid === r.page_uid) || pages[pageNum - 1] || pages[0];
            const rawId = r.id || (i + 1);
            const subId = r.sub_id || null;
            const displayNum = r.display_num || (subId ? `${rawId}-${subId}` : `${rawId}`);
            return {
                id: rawId,
                sub_id: subId,
                display_num: displayNum,
                page_uid: r.page_uid || (matchedPage ? matchedPage.page_uid : 'p_1_default'),
                page: matchedPage ? matchedPage.page_num : pageNum,
                num_roi: r.num_roi || { x: r.x || 50, y: r.y || 50, w: 50, h: 30 },
                ans_roi: r.ans_roi || { x: (r.x ? r.x + 60 : 110), y: r.y || 50, w: r.w || 60, h: r.h || 35 },
                ans_rois: Array.isArray(r.ans_rois) ? r.ans_rois : null,
                type: r.type || 'objective',
                sub_type: r.sub_type || (r.type === 'subjective' ? 'subjective' : 'objective'),
                answer: r.answer || '',
                score: typeof r.score === 'number' ? r.score : 0
            };
        });

        let targetScore = 100;
        let currentStage = 'NUM';
        let tempNumRoi = null;

        let dragMode = null;
        let activeTarget = null;
        let dragStartPos = { x: 0, y: 0 };
        let initialRect = null;
        let selectedRoiIndex = null;
        let addAnsTargetIndex = null; // 추가 소문항/다답형 답안 드로잉 대기 대상 인덱스

        const HANDLE_SIZE = 8;

        // 실행 취소(Undo) 히스토리 스택
        const undoStack = [];
        const MAX_UNDO = 30;
        let roiSnapshotBeforeDrag = null;

        function pushUndoState(snapshot = null) {
            const stateToPush = snapshot ? snapshot : JSON.parse(JSON.stringify(rois));
            undoStack.push(stateToPush);
            if (undoStack.length > MAX_UNDO) {
                undoStack.shift();
            }
        }

        function undo() {
            if (undoStack.length === 0) {
                showToast("더 이상 실행 취소할 작업이 없습니다.");
                return;
            }
            rois = undoStack.pop();
            selectedRoiIndex = null;
            tempNumRoi = null;
            currentStage = 'NUM';
            draw();
            renderSidebar();
            renderPageTabs();
            showToast("실행 취소(Undo)되었습니다.");
        }

        // 현재 활성 페이지 기준으로 신규 문항 생성 시 부여될 스마트 번호 계산
        function getNextQuestionNumberForActivePage() {
            const currentPg = pages[activePageIndex];
            if (!currentPg) return rois.length + 1;
            let insertIdx = -1;
            for (let i = rois.length - 1; i >= 0; i--) {
                if (rois[i].page_uid === currentPg.page_uid || rois[i].page === currentPg.page_num) {
                    insertIdx = i + 1;
                    break;
                }
            }
            if (insertIdx === -1) {
                for (let i = 0; i < rois.length; i++) {
                    const rPageIdx = pages.findIndex(p => p.page_uid === rois[i].page_uid || p.page_num === rois[i].page);
                    if (rPageIdx > activePageIndex) {
                        insertIdx = i;
                        break;
                    }
                }
                if (insertIdx === -1) insertIdx = rois.length;
            }
            return insertIdx + 1;
        }

        // 키보드 단축키 (문항 생성 취소, 문항 삭제, Undo 등)
        window.addEventListener('keydown', (e) => {
            if (['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;

            // 1. Ctrl + Z (또는 Cmd + Z) 실행 취소
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
                e.preventDefault();
                if (tempNumRoi !== null || currentStage === 'ANS') {
                    // 번호 상자만 그린 상태에서 Ctrl+Z: 번호 지정 취소
                    tempNumRoi = null;
                    currentStage = 'NUM';
                    draw();
                    showToast("문항 번호 지정이 취소되었습니다.");
                } else {
                    // 일반 Undo 실행
                    undo();
                }
                return;
            }

            // 2. ESC (Escape): 번호 상자 취소, 추가 답안 모드 종료 또는 선택 해제
            if (e.key === 'Escape') {
                e.preventDefault();
                if (addAnsTargetIndex !== null) {
                    addAnsTargetIndex = null;
                    currentStage = 'NUM';
                    reindexRois();
                    draw();
                    renderSidebar();
                } else if (tempNumRoi !== null || currentStage === 'ANS') {
                    tempNumRoi = null;
                    currentStage = 'NUM';
                    draw();
                    showToast("문항 번호 지정이 취소되었습니다.");
                } else if (selectedRoiIndex !== null) {
                    selectedRoiIndex = null;
                    draw();
                    renderSidebar();
                }
                return;
            }

            // 3. Delete / Backspace: 번호 상자 취소 또는 선택 문항 삭제
            if (e.key === 'Delete' || e.key === 'Backspace') {
                if (tempNumRoi !== null || currentStage === 'ANS') {
                    e.preventDefault();
                    tempNumRoi = null;
                    currentStage = 'NUM';
                    draw();
                    showToast("문항 번호 지정이 취소되었습니다.");
                    return;
                }
                if (selectedRoiIndex !== null) {
                    e.preventDefault();
                    deleteRoi(selectedRoiIndex);
                    selectedRoiIndex = null;
                    showToast("선택된 문항 영역이 삭제되었습니다.");
                    return;
                }
            }
        });

        // 페이지 네비게이션 렌더링 및 첫 페이지 로드
        renderPageTabs();
        loadPage(0);

        function renderPageTabs() {
            pageTabsContainer.innerHTML = "";
            pages.forEach((page, idx) => {
                const count = rois.filter(r => r.page_uid === page.page_uid).length;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `page-tab-btn ${idx === activePageIndex ? 'active' : ''}`;
                btn.innerHTML = `📄 ${page.title || (page.page_num + '쪽')} <span class="tab-badge">${count}문항</span>`;
                btn.onclick = () => switchPage(idx);
                pageTabsContainer.appendChild(btn);
            });
        }

        function switchPage(idx) {
            if (idx < 0 || idx >= pages.length) return;
            activePageIndex = idx;
            currentStage = 'NUM';
            tempNumRoi = null;
            selectedRoiIndex = null;
            renderPageTabs();
            loadPage(idx);
        }

        function loadPage(idx) {
            const page = pages[idx];
            if (!page) return;

            if (pageImages[idx] && pageImages[idx].complete) {
                applyCanvasImage(pageImages[idx]);
            } else {
                const img = new Image();
                img.src = page.image_path;
                img.onload = () => {
                    pageImages[idx] = img;
                    applyCanvasImage(img);
                };
                img.onerror = () => {
                    console.error("이미지 로드 실패:", page.image_path);
                    showToast(`⚠️ ${page.page_num || (idx + 1)}쪽 이미지를 불러오지 못했습니다. 경로를 확인해주세요.`, 5000);
                };
            }
        }

        let currentZoom = 1.0;

        function setZoom(scale) {
            currentZoom = Math.min(Math.max(scale, 0.3), 3.0);
            applyZoom();
        }

        function applyZoom() {
            if (!canvas.width) return;
            const displayW = Math.round(canvas.width * currentZoom);
            const displayH = Math.round(canvas.height * currentZoom);
            canvas.style.width = displayW + 'px';
            canvas.style.height = displayH + 'px';
            const badge = document.getElementById('zoomBadge');
            if (badge) badge.innerText = Math.round(currentZoom * 100) + '%';
        }

        function zoomIn() {
            setZoom(currentZoom + 0.1);
        }

        function zoomOut() {
            setZoom(currentZoom - 0.1);
        }

        function zoomReset() {
            setZoom(1.0);
        }

        function fitWidth() {
            const container = document.querySelector('.canvas-container');
            if (!container || !canvas.width) return;
            const availableW = container.clientWidth - 16;
            if (availableW > 0) {
                const scale = availableW / canvas.width;
                setZoom(scale);
            }
        }

        function fitPage() {
            const container = document.querySelector('.canvas-container');
            if (!container || !canvas.width || !canvas.height) return;
            const availableW = container.clientWidth - 20;
            const availableH = container.clientHeight - 20;
            if (availableW > 0 && availableH > 0) {
                const scale = Math.min(availableW / canvas.width, availableH / canvas.height);
                setZoom(scale);
            }
        }

        function applyCanvasImage(img) {
            canvas.width = img.naturalWidth || 1200;
            canvas.height = img.naturalHeight || 1700;
            fitWidth();
            draw();
            renderSidebar();
        }

        canvas.addEventListener('mousedown', onMouseDown);
        canvas.addEventListener('mousemove', onMouseMove);
        canvas.addEventListener('mouseup', onMouseUp);
        canvas.addEventListener('mouseleave', () => { 
            if (!dragMode) {
                canvas.style.cursor = 'default';
                draw(); 
            }
        });
        canvas.addEventListener('wheel', onWheel, { passive: false });

        function onMouseDown(e) {
            const pos = getCanvasPos(e);
            dragStartPos = { x: pos.x, y: pos.y };

            const target = findRoiAtPos(pos.x, pos.y);

            if (target) {
                roiSnapshotBeforeDrag = JSON.parse(JSON.stringify(rois));
                activeTarget = target;
                const rect = getTargetRect(target);
                initialRect = { ...rect };

                if (target.handle) {
                    selectedRoiIndex = target.itemIndex;
                    dragMode = 'RESIZE';
                    draw();
                    renderSidebar();
                } else {
                    dragMode = 'MOVE';
                }
                return;
            }

            selectedRoiIndex = null;
            renderSidebar();
            dragMode = 'DRAW';
        }

        function onMouseMove(e) {
            const pos = getCanvasPos(e);

            if (dragMode === 'RESIZE' && activeTarget) {
                const rect = getTargetRect(activeTarget);
                const dx = pos.x - dragStartPos.x;
                const dy = pos.y - dragStartPos.y;
                const minSize = 15;

                switch (activeTarget.handle) {
                    case 'br':
                        rect.w = Math.max(minSize, initialRect.w + dx);
                        rect.h = Math.max(minSize, initialRect.h + dy);
                        break;
                    case 'bl':
                        const newW_bl = Math.max(minSize, initialRect.w - dx);
                        rect.x = initialRect.x + (initialRect.w - newW_bl);
                        rect.w = newW_bl;
                        rect.h = Math.max(minSize, initialRect.h + dy);
                        break;
                    case 'tr':
                        rect.w = Math.max(minSize, initialRect.w + dx);
                        const newH_tr = Math.max(minSize, initialRect.h - dy);
                        rect.y = initialRect.y + (initialRect.h - newH_tr);
                        rect.h = newH_tr;
                        break;
                    case 'tl':
                        const newW_tl = Math.max(minSize, initialRect.w - dx);
                        rect.x = initialRect.x + (initialRect.w - newW_tl);
                        rect.w = newW_tl;
                        const newH_tl = Math.max(minSize, initialRect.h - dy);
                        rect.y = initialRect.y + (initialRect.h - newH_tl);
                        rect.h = newH_tl;
                        break;
                }
                draw();
                return;
            }

            if (dragMode === 'MOVE' && activeTarget) {
                const rect = getTargetRect(activeTarget);
                rect.x = initialRect.x + (pos.x - dragStartPos.x);
                rect.y = initialRect.y + (pos.y - dragStartPos.y);
                draw();
                return;
            }

            const isNumStage = (currentStage === 'NUM');
            const defaultW = isNumStage ? 50 : 60;
            const defaultH = isNumStage ? 30 : 35;

            if (dragMode === 'DRAW') {
                draw();
                const rawW = pos.x - dragStartPos.x;
                const rawH = pos.y - dragStartPos.y;
                const w = Math.abs(rawW);
                const h = Math.abs(rawH);

                ctx.lineWidth = 2;
                ctx.strokeStyle = isNumStage ? '#ffc107' : '#007bff';

                const rectX = (w < 10 && h < 10) ? (pos.x - defaultW / 2) : Math.min(dragStartPos.x, pos.x);
                const rectY = (w < 10 && h < 10) ? (pos.y - defaultH / 2) : Math.min(dragStartPos.y, pos.y);
                const rectW = (w < 10 && h < 10) ? defaultW : w;
                const rectH = (w < 10 && h < 10) ? defaultH : h;

                ctx.strokeRect(rectX, rectY, rectW, rectH);

                // 드래그 중인 상자 상단에 프리뷰 번호 렌더링
                const nextQNum = getNextQuestionNumberForActivePage();
                ctx.fillStyle = isNumStage ? '#ffc107' : '#007bff';
                ctx.font = isNumStage ? 'bold 16px sans-serif' : 'bold 14px sans-serif';
                ctx.fillText(isNumStage ? `문항 ${nextQNum}` : `문항 ${nextQNum} 답안`, rectX, rectY - 5);
                return;
            }

            if (addAnsTargetIndex !== null && rois[addAnsTargetIndex]) {
                const targetItem = rois[addAnsTargetIndex];
                canvas.style.cursor = 'crosshair';
                draw();

                const isComp = (targetItem.type === 'composite');
                const themeColor = isComp ? '#f97316' : '#8b5cf6';
                const fillColor = isComp ? 'rgba(249, 115, 22, 0.2)' : 'rgba(139, 92, 246, 0.2)';

                const boxW = 60, boxH = 35;
                const boxX = pos.x - boxW / 2;
                const boxY = pos.y - boxH / 2;

                ctx.save();
                ctx.lineWidth = 2;
                ctx.strokeStyle = themeColor;
                ctx.setLineDash([4, 4]);
                ctx.fillStyle = fillColor;
                ctx.fillRect(boxX, boxY, boxW, boxH);
                ctx.strokeRect(boxX, boxY, boxW, boxH);
                ctx.setLineDash([]);

                let label = '';
                if (isComp) {
                    const ansCount = Array.isArray(targetItem.ans_rois) ? targetItem.ans_rois.length : 1;
                    label = `[Q${targetItem.id} 답-${ansCount + 1} 드래그]`;
                } else {
                    const pk = targetItem.num_roi ? `${targetItem.page_uid}_${Math.round(targetItem.num_roi.x)}_${Math.round(targetItem.num_roi.y)}` : null;
                    const subCount = pk ? rois.filter(rx => rx.num_roi && `${rx.page_uid}_${Math.round(rx.num_roi.x)}_${Math.round(rx.num_roi.y)}` === pk).length : 1;
                    label = `[Q${targetItem.id}-${subCount + 1} 소문항 드래그]`;
                }

                ctx.font = 'bold 12px sans-serif';
                const txtW = ctx.measureText(label).width;
                const pillX = boxX;
                const pillY = boxY - 22;
                const pillW = txtW + 12;
                const pillH = 18;

                ctx.fillStyle = 'rgba(20, 20, 24, 0.88)';
                if (ctx.roundRect) {
                    ctx.beginPath();
                    ctx.roundRect(pillX, pillY, pillW, pillH, 4);
                    ctx.fill();
                    ctx.strokeStyle = themeColor;
                    ctx.lineWidth = 1;
                    ctx.stroke();
                } else {
                    ctx.fillRect(pillX, pillY, pillW, pillH);
                    ctx.strokeStyle = themeColor;
                    ctx.strokeRect(pillX, pillY, pillW, pillH);
                }

                ctx.fillStyle = themeColor;
                ctx.fillText(label, pillX + 6, pillY + 13);
                ctx.restore();
                return;
            }

            const hoveredTarget = findRoiAtPos(pos.x, pos.y);
            if (hoveredTarget) {
                if (hoveredTarget.handle) {
                    canvas.style.cursor = (hoveredTarget.handle === 'tl' || hoveredTarget.handle === 'br') ? 'nwse-resize' : 'nesw-resize';
                } else {
                    canvas.style.cursor = 'pointer';
                }
                draw();
            } else {
                canvas.style.cursor = 'crosshair';
                draw();
                ctx.lineWidth = 2;
                ctx.strokeStyle = isNumStage ? '#ffc107' : '#007bff';
                ctx.setLineDash([4, 4]);
                ctx.strokeRect(pos.x - defaultW / 2, pos.y - defaultH / 2, defaultW, defaultH);
                ctx.setLineDash([]);
            }
        }

        function onMouseUp(e) {
            const pos = getCanvasPos(e);
            const distMoved = Math.hypot(pos.x - dragStartPos.x, pos.y - dragStartPos.y);

            if (dragMode === 'MOVE' && activeTarget) {
                if (distMoved < 5) {
                    // Click without drag!
                    if (activeTarget.roiType === 'num_roi') {
                        // Toggle set-focus (문항 번호 상자 클릭 시 세트 포커싱 토글)
                        selectedRoiIndex = (selectedRoiIndex === activeTarget.itemIndex) ? null : activeTarget.itemIndex;
                    } else if (activeTarget.roiType === 'ans_roi' || activeTarget.roiType === 'ans_rois') {
                        // 답안 영역 클릭 시: 단일이면 4단계 순환, 2개 이상이면 소문항 ⇄ 복합 순환
                        cycleRoiType(activeTarget.itemIndex);
                    }
                } else {
                    // 드래그 이동 완료
                    if (roiSnapshotBeforeDrag) {
                        pushUndoState(roiSnapshotBeforeDrag);
                        roiSnapshotBeforeDrag = null;
                    }
                    selectedRoiIndex = activeTarget.itemIndex;
                }
            }

            if (dragMode === 'RESIZE' && activeTarget) {
                if (roiSnapshotBeforeDrag) {
                    pushUndoState(roiSnapshotBeforeDrag);
                    roiSnapshotBeforeDrag = null;
                }
            }

            if (dragMode === 'DRAW') {
                const w = Math.abs(pos.x - dragStartPos.x);
                const h = Math.abs(pos.y - dragStartPos.y);

                let x, y, finalW, finalH;
                const isNumStage = (currentStage === 'NUM' && addAnsTargetIndex === null);
                const defaultW = isNumStage ? 50 : 60;
                const defaultH = isNumStage ? 30 : 35;

                if (w < 10 && h < 10) {
                    finalW = defaultW;
                    finalH = defaultH;
                    x = pos.x - defaultW / 2;
                    y = pos.y - defaultH / 2;
                } else {
                    finalW = w;
                    finalH = h;
                    x = Math.min(dragStartPos.x, pos.x);
                    y = Math.min(dragStartPos.y, pos.y);
                }

                if (addAnsTargetIndex !== null && rois[addAnsTargetIndex]) {
                    // 기존 문항에 추가 답안 영역 또는 소문항 등록
                    pushUndoState();
                    const targetItem = rois[addAnsTargetIndex];
                    const targetId = targetItem.id;
                    const newRect = { x, y, w: finalW, h: finalH };

                    if (targetItem.type === 'composite') {
                        if (!Array.isArray(targetItem.ans_rois)) {
                            targetItem.ans_rois = targetItem.ans_roi ? [targetItem.ans_roi] : [];
                        }
                        targetItem.ans_rois.push(newRect);
                    } else {
                        // 소문항(sub_item) 추가
                        targetItem.type = 'sub_item';
                        targetItem.sub_type = targetItem.sub_type || 'objective';

                        let insertPos = addAnsTargetIndex + 1;
                        while (insertPos < rois.length && rois[insertPos].type === 'sub_item' && rois[insertPos].id === targetId) {
                            insertPos++;
                        }
                        const newSub = {
                            id: targetId,
                            page_uid: targetItem.page_uid,
                            page: targetItem.page,
                            num_roi: targetItem.num_roi,
                            ans_roi: newRect,
                            ans_rois: null,
                            type: 'sub_item',
                            sub_type: targetItem.sub_type || 'objective',
                            answer: '',
                            score: 0
                        };
                        rois.splice(insertPos, 0, newSub);
                    }
                    // ponytail: 연속 추가 모드 유지 (휠 또는 ESC로 종료 시까지 대기)
                    reindexRois();
                    const refreshedIdx = rois.findIndex(rx => rx.page_uid === targetItem.page_uid && rx.id === targetId);
                    if (refreshedIdx !== -1) {
                        addAnsTargetIndex = refreshedIdx;
                        selectedRoiIndex = refreshedIdx;
                    }
                } else if (currentStage === 'NUM') {
                    tempNumRoi = { x, y, w: finalW, h: finalH };
                    currentStage = 'ANS';
                } else {
                    pushUndoState(); // 신규 문항 추가 직전 상태 저장
                    const currentPg = pages[activePageIndex];
                    // 스마트 번호 매김: 현재 페이지의 마지막 문항 바로 뒤에 삽입
                    let insertIdx = -1;
                    for (let i = rois.length - 1; i >= 0; i--) {
                        if (rois[i].page_uid === currentPg.page_uid || rois[i].page === currentPg.page_num) {
                            insertIdx = i + 1;
                            break;
                        }
                    }
                    if (insertIdx === -1) {
                        for (let i = 0; i < rois.length; i++) {
                            const rPageIdx = pages.findIndex(p => p.page_uid === rois[i].page_uid || p.page_num === rois[i].page);
                            if (rPageIdx > activePageIndex) {
                                insertIdx = i;
                                break;
                            }
                        }
                        if (insertIdx === -1) insertIdx = rois.length;
                    }

                    const newRoi = {
                        id: insertIdx + 1,
                        page_uid: currentPg.page_uid,
                        page: currentPg.page_num,
                        num_roi: tempNumRoi,
                        ans_roi: { x, y, w: finalW, h: finalH },
                        type: 'objective',
                        answer: '',
                        score: 0
                    };
                    rois.splice(insertIdx, 0, newRoi);
                    reindexRois();
                    selectedRoiIndex = insertIdx;
                    tempNumRoi = null;
                    currentStage = 'NUM';
                }
            }

            dragMode = null;
            activeTarget = null;
            initialRect = null;
            draw();
            renderSidebar();
            renderPageTabs();
        }

        function onWheel(e) {
            // ponytail: 추가 소문항/답안 모드 중 마우스 휠을 굴리면 모드 종료 -> 새 문항 번호(NUM) 모드로 복귀
            if (addAnsTargetIndex !== null) {
                e.preventDefault();
                addAnsTargetIndex = null;
                currentStage = 'NUM';
                reindexRois();
                draw();
                renderSidebar();
                return;
            }

            const pos = getCanvasPos(e);
            const target = findRoiAtPos(pos.x, pos.y);
            if (target && (target.roiType === 'ans_roi' || target.roiType === 'ans_rois')) {
                e.preventDefault();
                cycleRoiType(target.itemIndex);
            }
        }

        // ponytail: 답안 영역 클릭/휠 시 유형 순환 (1개일 때 4단계, 2개 이상일 때 소문항 ⇄ 복합 제한)
        function cycleRoiType(idx) {
            const item = rois[idx];
            if (!item) return;

            const parentKey = item.num_roi ? `${item.page_uid}_${Math.round(item.num_roi.x)}_${Math.round(item.num_roi.y)}` : '';
            const subCount = parentKey ? rois.filter(x => x.num_roi && `${x.page_uid}_${Math.round(x.num_roi.x)}_${Math.round(x.num_roi.y)}` === parentKey).length : 1;
            const hasMultipleAns = (item.type === 'composite' && Array.isArray(item.ans_rois) && item.ans_rois.length > 1) || subCount > 1;

            if (hasMultipleAns) {
                // 답안 영역이 이미 2개 이상: 소문항 ⇄ 복합 다답형 안에서만 안전 순환
                if (item.type === 'composite') {
                    splitCompositeToSubItems(idx);
                } else {
                    mergeSubItemsToComposite(item.id);
                }
            } else {
                // 답안 영역이 1개: 객관식 ⇄ 주관식 ⇄ 소문항 ⇄ 복합 4단계 순환
                const order = ['objective', 'subjective', 'sub_item', 'composite'];
                const curIdx = order.indexOf(item.type);
                const nextType = order[(curIdx + 1) % order.length];
                setType(idx, nextType, false);
            }
            selectedRoiIndex = idx;
        }

        function startAddAnsRoiMode(idx) {
            addAnsTargetIndex = idx;
            selectedRoiIndex = idx;
            const item = rois[idx];
            if (item) {
                const targetPageIdx = pages.findIndex(p => p.page_uid === item.page_uid || p.page_num === item.page);
                if (targetPageIdx >= 0 && targetPageIdx !== activePageIndex) {
                    switchPage(targetPageIdx);
                }
            }
            draw();
            renderSidebar();
        }

        function getTargetRect(target) {
            if (!target) return null;
            const item = rois[target.itemIndex];
            if (!item) return null;
            if (target.roiType === 'ans_rois' && Array.isArray(item.ans_rois) && typeof target.subAnsIndex === 'number') {
                return item.ans_rois[target.subAnsIndex];
            }
            return item[target.roiType];
        }

        // 현재 활성 페이지에 속한 ROI만 타겟팅
        function findRoiAtPos(x, y) {
            const currentPg = pages[activePageIndex];
            if (!currentPg) return null;

            for (let i = rois.length - 1; i >= 0; i--) {
                const item = rois[i];
                if (!item) continue;
                if (item.page_uid !== currentPg.page_uid && item.page !== currentPg.page_num) continue;

                // 1. composite 타입인 경우 복수 ans_rois 검사
                if (item.type === 'composite' && Array.isArray(item.ans_rois) && item.ans_rois.length > 0) {
                    for (let k = item.ans_rois.length - 1; k >= 0; k--) {
                        const rect = item.ans_rois[k];
                        if (!rect) continue;
                        const handles = getHandles(rect);
                        for (let hKey in handles) {
                            const h = handles[hKey];
                            if (x >= h.x && x <= h.x + h.w && y >= h.y && y <= h.y + h.h) {
                                return { itemIndex: i, roiType: 'ans_rois', subAnsIndex: k, handle: hKey };
                            }
                        }
                        if (x >= rect.x && x <= rect.x + rect.w && y >= rect.y && y <= rect.y + rect.h) {
                            return { itemIndex: i, roiType: 'ans_rois', subAnsIndex: k, handle: null };
                        }
                    }
                }

                // 2. 일반 ans_roi 및 num_roi 검사
                const roiTypes = (item.type === 'composite' && Array.isArray(item.ans_rois)) ? ['num_roi'] : ['ans_roi', 'num_roi'];

                for (let type of roiTypes) {
                    const rect = item[type];
                    if (!rect) continue;

                    const handles = getHandles(rect);
                    for (let hKey in handles) {
                        const h = handles[hKey];
                        if (x >= h.x && x <= h.x + h.w && y >= h.y && y <= h.y + h.h) {
                            return { itemIndex: i, roiType: type, handle: hKey };
                        }
                    }

                    if (x >= rect.x && x <= rect.x + rect.w && y >= rect.y && y <= rect.y + rect.h) {
                        return { itemIndex: i, roiType: type, handle: null };
                    }
                }
            }
            return null;
        }

        function getHandles(rect) {
            const s = HANDLE_SIZE;
            return {
                tl: { x: rect.x - s / 2, y: rect.y - s / 2, w: s, h: s },
                tr: { x: rect.x + rect.w - s / 2, y: rect.y - s / 2, w: s, h: s },
                bl: { x: rect.x - s / 2, y: rect.y + rect.h - s / 2, w: s, h: s },
                br: { x: rect.x + rect.w - s / 2, y: rect.y + rect.h - s / 2, w: s, h: s }
            };
        }

        // ponytail: 문항 유형 변경 (다중 답안 시 확인 알림, 소문항/복합 시 추가 답안 모드 활성화)
        function setType(idx, type, startDrawMode = true) {
            const r = rois[idx];
            if (!r) return;

            // 해당 문항의 페이지로 이동 및 포커스 설정
            const targetPageIdx = pages.findIndex(p => p.page_uid === r.page_uid || p.page_num === r.page);
            if (targetPageIdx >= 0 && targetPageIdx !== activePageIndex) {
                switchPage(targetPageIdx);
            }
            selectedRoiIndex = idx;

            // 이미 동일한 타입인 경우: 소문항 또는 복합이면 추가 답안 모드로 진입 (토스트 없이)
            if (r.type === type) {
                if (type === 'sub_item' || type === 'composite') {
                    startAddAnsRoiMode(idx);
                }
                return;
            }

            // 이미 다중 답안을 가진 문항을 객관식/주관식으로 변경하려는 경우
            const isCompMulti = (r.type === 'composite' && Array.isArray(r.ans_rois) && r.ans_rois.length > 1);
            const pk = r.num_roi ? `${r.page_uid}_${Math.round(r.num_roi.x)}_${Math.round(r.num_roi.y)}` : null;
            const sameGroupSubs = (r.type === 'sub_item' && pk)
                ? rois.filter(rx => rx.num_roi && `${rx.page_uid}_${Math.round(rx.num_roi.x)}_${Math.round(rx.num_roi.y)}` === pk)
                : [];
            const isSubMulti = sameGroupSubs.length > 1;

            if ((type === 'objective' || type === 'subjective') && (isCompMulti || isSubMulti)) {
                const targetName = type === 'objective' ? '객관식' : '주관식';
                if (!confirm(`이 문항에는 여러 개의 답안 영역이 설정되어 있습니다.\n단일 ${targetName}(으)로 변경하면 첫 번째 답안 영역만 남고 나머지는 삭제됩니다. 계속하시겠습니까?`)) {
                    renderSidebar();
                    return;
                }
                pushUndoState();
                if (isCompMulti) {
                    r.ans_roi = r.ans_rois[0];
                    r.ans_rois = null;
                }
                if (isSubMulti) {
                    const keepRoi = sameGroupSubs[0];
                    const removeIndices = [];
                    rois.forEach((item, i) => {
                        if (sameGroupSubs.includes(item) && item !== keepRoi) {
                            removeIndices.push(i);
                        }
                    });
                    for (let i = removeIndices.length - 1; i >= 0; i--) {
                        rois.splice(removeIndices[i], 1);
                    }
                    selectedRoiIndex = rois.indexOf(keepRoi);
                }
            } else {
                pushUndoState();
            }

            const targetRoi = rois[selectedRoiIndex];
            if (!targetRoi) return;

            targetRoi.type = type;
            if (type === 'sub_item') {
                targetRoi.sub_type = targetRoi.sub_type || 'objective';
                targetRoi.ans_rois = null;
            } else if (type === 'composite') {
                targetRoi.sub_id = null;
                if (!Array.isArray(targetRoi.ans_rois) || targetRoi.ans_rois.length === 0) {
                    targetRoi.ans_rois = targetRoi.ans_roi ? [targetRoi.ans_roi] : [];
                }
            } else {
                targetRoi.ans_rois = null;
                targetRoi.sub_id = null;
            }

            // 소문항 또는 복합 버튼 클릭 시 reindexRois 전에 추가 모드 타깃을 미리 등록하여 단일 객관식 강등 방지
            if (startDrawMode && (type === 'composite' || type === 'sub_item')) {
                addAnsTargetIndex = selectedRoiIndex;
            } else {
                addAnsTargetIndex = null;
            }

            reindexRois();
            draw();
            renderSidebar();

            if (startDrawMode && (type === 'composite' || type === 'sub_item')) {
                startAddAnsRoiMode(selectedRoiIndex);
            }
        }

        // 소문항 그룹 → 복합 다답형으로 병합
        function mergeSubItemsToComposite(mainId) {
            pushUndoState();
            // 같은 mainId를 가진 sub_item 들 수집
            const groupItems = rois.filter(r => r.type === 'sub_item' && r.id === mainId);
            if (groupItems.length < 2) {
                showToast('소문항이 2개 이상이어야 복합 다답형으로 묶을 수 있습니다.');
                return;
            }
            const firstItem = groupItems[0];
            const totalScore = groupItems.reduce((s, r) => s + (r.score || 0), 0);
            const allAnswers = groupItems.map(r => r.answer || '').filter(a => a).join(', ');
            const ansRoisArr = groupItems.map(r => r.ans_roi);

            // 기존 sub_items 모두 제거
            rois = rois.filter(r => !(r.type === 'sub_item' && r.id === mainId));

            // composite 문항 하나 삽입 (원래 첫 소문항 위치 기준)
            const insertIdx = rois.findIndex(r => r.id > mainId);
            const newRoi = {
                id: mainId,
                sub_id: null,
                display_num: String(mainId),
                page_uid: firstItem.page_uid,
                page: firstItem.page,
                num_roi: firstItem.num_roi,
                ans_roi: ansRoisArr[0],
                ans_rois: ansRoisArr,
                type: 'composite',
                sub_type: 'composite',
                answer: allAnswers,
                score: totalScore
            };
            if (insertIdx === -1) {
                rois.push(newRoi);
            } else {
                rois.splice(insertIdx, 0, newRoi);
            }
            reindexRois();
            draw();
            renderSidebar();
            showToast(`Q${mainId} 소문항들을 복합 다답형으로 병합했습니다.`);
        }

        // 복합 다답형 → 소문항 분할
        function splitCompositeToSubItems(idx) {
            pushUndoState();
            const r = rois[idx];
            if (r.type !== 'composite' || !Array.isArray(r.ans_rois) || r.ans_rois.length < 2) {
                showToast('복합 다답형 문항(ans_rois 2개 이상)에만 분할 가능합니다.');
                return;
            }
            const mainId = r.id;
            const perScore = Math.floor((r.score || 0) / r.ans_rois.length);
            const allAnswers = (r.answer || '').split(',').map(a => a.trim());
            const newItems = r.ans_rois.map((ar, i) => ({
                id: mainId,
                sub_id: i + 1,
                display_num: `${mainId}-${i + 1}`,
                page_uid: r.page_uid,
                page: r.page,
                num_roi: r.num_roi,
                ans_roi: ar,
                ans_rois: null,
                type: 'sub_item',
                sub_type: 'objective',
                answer: allAnswers[i] || '',
                score: perScore
            }));
            rois.splice(idx, 1, ...newItems);
            reindexRois();
            draw();
            renderSidebar();
            showToast(`Q${mainId} 복합 다답형을 소문항으로 분할했습니다.`);
        }

        function deleteRoi(idx) {
            pushUndoState();
            rois.splice(idx, 1);
            reindexRois();
            draw();
            renderSidebar();
            renderPageTabs();
        }

        // ponytail: 소문항/복합 답안이 1개만 남으면 단일 문항(객관식)으로 자동 승격 및 재색인
        function reindexRois() {
            // 1. 각 num_roi 기준 그룹 개수 집계
            const groupCounts = {};
            rois.forEach(r => {
                if (r.num_roi) {
                    const pk = `${r.page_uid}_${Math.round(r.num_roi.x)}_${Math.round(r.num_roi.y)}`;
                    groupCounts[pk] = (groupCounts[pk] || 0) + 1;
                }
            });

            let currentMainNum = 0;
            let lastParentKey = null;
            let currentSubNum = 0;

            for (let i = 0; i < rois.length; i++) {
                const r = rois[i];
                const parentKey = r.num_roi ? `${r.page_uid}_${Math.round(r.num_roi.x)}_${Math.round(r.num_roi.y)}` : null;
                const countInGroup = parentKey ? (groupCounts[parentKey] || 1) : 1;

                // ponytail: 현재 사용자가 추가 작업 중인 문항인지 확인 (추가 모드 중에는 1개여도 강등 방지)
                const targetRoi = (addAnsTargetIndex !== null) ? rois[addAnsTargetIndex] : null;
                const isUnderAddition = targetRoi && (r.id === targetRoi.id || i === addAnsTargetIndex || (r.num_roi && targetRoi.num_roi && r.page_uid === targetRoi.page_uid && Math.round(r.num_roi.x) === Math.round(targetRoi.num_roi.x) && Math.round(r.num_roi.y) === Math.round(targetRoi.num_roi.y)));

                // 복합 다답형인데 답안 영역이 1개 이하로 남은 경우 (단, 추가 모드 중이면 유지)
                if (r.type === 'composite' && Array.isArray(r.ans_rois) && r.ans_rois.length <= 1 && !isUnderAddition) {
                    r.ans_roi = (r.ans_rois && r.ans_rois[0]) || r.ans_roi;
                    r.ans_rois = null;
                    r.type = 'objective';
                    r.sub_id = null;
                }

                // 소문항이었으나 그룹 내 1개만 남은 경우 (단, 추가 모드 중이면 유지)
                const isActualSubItem = (r.type === 'sub_item' || r.sub_id) && (countInGroup > 1 || isUnderAddition);

                if (isActualSubItem) {
                    if (parentKey !== lastParentKey) {
                        currentMainNum++;
                        lastParentKey = parentKey;
                        currentSubNum = 1;
                    } else {
                        currentSubNum++;
                    }
                    r.id = currentMainNum;
                    r.sub_id = currentSubNum;
                    r.display_num = `${currentMainNum}-${currentSubNum}`;
                    r.type = 'sub_item';
                } else {
                    // 단일 문항으로 승격 또는 유지
                    if (r.type === 'sub_item') {
                        r.type = (r.sub_type === 'subjective') ? 'subjective' : 'objective';
                    }
                    currentMainNum++;
                    lastParentKey = null;
                    currentSubNum = 0;
                    r.id = currentMainNum;
                    r.sub_id = null;
                    r.display_num = `${currentMainNum}`;
                }
            }
        }

        function drawHandles(rect) {
            const handles = getHandles(rect);
            ctx.fillStyle = "#ffffff";
            ctx.strokeStyle = "#000000";
            ctx.lineWidth = 1;
            for (let hKey in handles) {
                const h = handles[hKey];
                ctx.fillRect(h.x, h.y, h.w, h.h);
                ctx.strokeRect(h.x, h.y, h.w, h.h);
            }
        }

        function getCanvasPos(e) {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            return {
                x: (e.clientX - rect.left) * scaleX,
                y: (e.clientY - rect.top) * scaleY
            };
        }

        function draw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            const currentImg = pageImages[activePageIndex];
            if (currentImg && currentImg.complete && currentImg.naturalWidth > 0) {
                ctx.drawImage(currentImg, 0, 0);
            }

            const currentPg = pages[activePageIndex];
            if (!currentPg) return;

            // 현재 페이지의 문항만 캔버스에 렌더링
            rois.forEach((r, rIdx) => {
                if (!r || !r.num_roi || !r.ans_roi) return;
                if (r.page_uid !== currentPg.page_uid && r.page !== currentPg.page_num) return;

                const isSelected = (selectedRoiIndex === rIdx);

                const isGroupLeader = (!r.sub_id || r.sub_id === 1);
                const displayLabel = r.display_num ? `Q${r.display_num}` : `Q${r.id}`;
                const isComposite = (r.type === 'composite');

                // 1. 문항 번호 상자 (num_roi) - 소문항 그룹의 첫 번째 문항이거나 단일 문항일 때만 상자 렌더링
                if (isGroupLeader) {
                    ctx.save();
                    if (isSelected) {
                        ctx.shadowColor = "#ffc107";
                        ctx.shadowBlur = 12;
                        ctx.lineWidth = 3.5;
                    } else {
                        ctx.lineWidth = 2;
                    }
                    ctx.strokeStyle = "#ffc107";
                    ctx.strokeRect(r.num_roi.x, r.num_roi.y, r.num_roi.w, r.num_roi.h);
                    ctx.restore();

                    ctx.fillStyle = "#ffc107";
                    ctx.font = "bold 14px sans-serif";
                    ctx.fillText(`Q${r.id} 번호`, r.num_roi.x, r.num_roi.y - 5);
                    drawHandles(r.num_roi);
                }

                // 2. 답안 작성란 상자
                if (isComposite && Array.isArray(r.ans_rois) && r.ans_rois.length > 0) {
                    // 복합 다답형: 오렌지색으로 개별 ans_rois 렌더링
                    const compColor = "#f97316";
                    r.ans_rois.forEach((ar, ai) => {
                        if (!ar) return;
                        ctx.save();
                        if (isSelected) {
                            ctx.shadowColor = "#fb923c";
                            ctx.shadowBlur = 14;
                            ctx.lineWidth = 3.5;
                        } else {
                            ctx.lineWidth = 2;
                        }
                        ctx.strokeStyle = compColor;
                        ctx.fillStyle = isSelected ? "rgba(249, 115, 22, 0.35)" : "rgba(249, 115, 22, 0.18)";
                        ctx.fillRect(ar.x, ar.y, ar.w, ar.h);
                        ctx.strokeRect(ar.x, ar.y, ar.w, ar.h);
                        ctx.restore();

                        ctx.fillStyle = compColor;
                        ctx.font = "bold 13px sans-serif";
                        ctx.fillText(`${displayLabel} 답-${ai + 1} [복합]`, ar.x, ar.y - 5);
                        drawHandles(ar);
                    });
                } else {
                    // 일반/소문항/주관식: 기존 렌더링
                    const isSubItem = (r.type === 'sub_item');
                    const isObj = r.type === 'objective' || (isSubItem && r.sub_type !== 'subjective');
                    const ansColor = isSubItem ? "#8b5cf6" : (isObj ? "#007bff" : "#28a745");
                    const typeTag = isSubItem ? '[소문항]' : (isObj ? '[객]' : '[주]');

                    ctx.save();
                    if (isSelected) {
                        ctx.shadowColor = isSubItem ? "#a78bfa" : (isObj ? "#38bdf8" : "#4ade80");
                        ctx.shadowBlur = 14;
                        ctx.lineWidth = 3.5;
                    } else {
                        ctx.lineWidth = 2;
                    }
                    ctx.strokeStyle = ansColor;
                    ctx.fillStyle = isSelected
                        ? (isSubItem ? "rgba(139, 92, 246, 0.35)" : (isObj ? "rgba(0, 123, 255, 0.35)" : "rgba(40, 167, 69, 0.35)"))
                        : (isSubItem ? "rgba(139, 92, 246, 0.18)" : (isObj ? "rgba(0, 123, 255, 0.18)" : "rgba(40, 167, 69, 0.18)"));
                    ctx.fillRect(r.ans_roi.x, r.ans_roi.y, r.ans_roi.w, r.ans_roi.h);
                    ctx.strokeRect(r.ans_roi.x, r.ans_roi.y, r.ans_roi.w, r.ans_roi.h);
                    ctx.restore();

                    ctx.fillStyle = ansColor;
                    ctx.font = "bold 13px sans-serif";
                    ctx.fillText(`${displayLabel} 답안 ${typeTag}`, r.ans_roi.x, r.ans_roi.y - 5);
                    drawHandles(r.ans_roi);
                }
            });

            if (tempNumRoi) {
                ctx.strokeStyle = "#ffc107";
                ctx.lineWidth = 2;
                ctx.strokeRect(tempNumRoi.x, tempNumRoi.y, tempNumRoi.w, tempNumRoi.h);
                ctx.fillStyle = "#ffc107";
                ctx.font = "bold 16px sans-serif";
                ctx.fillText(`문항 ${getNextQuestionNumberForActivePage()}`, tempNumRoi.x, tempNumRoi.y - 5);
                drawHandles(tempNumRoi);
            }
        }

        function renderSidebar() {
            roiListContainer.innerHTML = "";
            let currentTotal = 0;
            const currentPg = pages[activePageIndex];

            rois.forEach((r, idx) => {
                currentTotal += (r.score || 0);
                const isSubItem = (r.type === 'sub_item');
                const isComposite = (r.type === 'composite');
                const isObj = r.type === 'objective' || (isSubItem && r.sub_type !== 'subjective');
                const displayLabel = r.display_num ? `Q${r.display_num}` : `Q${r.id}`;
                const isCurrentPage = currentPg && (r.page_uid === currentPg.page_uid || r.page === currentPg.page_num);
                const isFocused = (selectedRoiIndex === idx);

                // 현재 추가 모드 진행 중인 문항인지 확인
                const targetAddRoi = (addAnsTargetIndex !== null) ? rois[addAnsTargetIndex] : null;
                const isAddingThisItem = targetAddRoi && (
                    targetAddRoi.id === r.id ||
                    (targetAddRoi.num_roi && r.num_roi && targetAddRoi.page_uid === r.page_uid && Math.round(targetAddRoi.num_roi.x) === Math.round(r.num_roi.x) && Math.round(targetAddRoi.num_roi.y) === Math.round(r.num_roi.y))
                );

                const div = document.createElement('div');
                div.className = `roi-item ${r.type} ${isCurrentPage ? '' : 'other-page'} ${isFocused ? 'selected-focus' : ''} ${isAddingThisItem ? 'adding-sub-mode' : ''}`;
                div.draggable = true;
                div.dataset.index = idx;
                if (isFocused || isAddingThisItem) {
                    setTimeout(() => div.scrollIntoView({ block: 'nearest', behavior: 'smooth' }), 50);
                }

                // 소문항 그룹의 마지막 소문항인지 확인 (병합 버튼 및 추가 버튼 표시 여부)
                const isLastSubInGroup = isSubItem && (
                    idx === rois.length - 1 || rois[idx + 1].type !== 'sub_item' || rois[idx + 1].id !== r.id
                );
                const subGroupCount = isSubItem ? rois.filter(rx => rx.type === 'sub_item' && rx.id === r.id).length : 0;

                // 복합 다답형 정답 개수 표시
                const ansRoisCount = (isComposite && Array.isArray(r.ans_rois)) ? r.ans_rois.length : 0;
                const compositeAnsInfo = isComposite
                    ? `<div class="composite-ans-list">📦 답안 영역: ${ansRoisCount}개 | 모두 일치 시만 만점 (All-or-Nothing)</div>`
                    : '';
                const splitBtn = isComposite && ansRoisCount >= 2
                    ? `<button class="btn-split-sub" onclick="event.stopPropagation(); splitCompositeToSubItems(${idx})">🔀 소문항으로 분할</button>`
                    : '';
                const mergeBtn = isLastSubInGroup && subGroupCount >= 2
                    ? `<button class="btn-merge-composite" onclick="event.stopPropagation(); mergeSubItemsToComposite(${r.id})">🔗 복합 다답형으로 묶기</button>`
                    : '';
                const addSubBtn = (isSubItem && isLastSubInGroup)
                    ? `<button class="btn-add-sub" onclick="event.stopPropagation(); startAddAnsRoiMode(${idx})">➕ 소문항 추가</button>`
                    : '';
                const addCompBtn = isComposite
                    ? `<button class="btn-add-sub comp" onclick="event.stopPropagation(); startAddAnsRoiMode(${idx})">➕ 답안란 추가</button>`
                    : '';

                const answerPlaceholder = isComposite
                    ? '전체 정답 (쉼표 구분, 예: 체, 거름종이)'
                    : (isSubItem ? '소문항 정답' : (isObj ? '정답 입력 (예: 2)' : '주관식 정답 키워드'));

                const addingGuideHtml = isAddingThisItem
                    ? `<div class="adding-sub-guide ${isComposite ? 'comp' : ''}">✏️ 시험지 위에 추가 ${isComposite ? '답안 영역' : '소문항'}을 드래그하세요 (완료: 휠 / ESC)</div>`
                    : '';

                div.innerHTML = `
                    <span class="roi-page-tag">${r.page}쪽</span>
                    <span class="roi-title">${displayLabel}</span>
                    <div class="type-toggle">
                        <button class="type-btn ${r.type === 'objective' ? 'active obj' : ''}" onclick="event.stopPropagation(); setType(${idx}, 'objective')">객관식</button>
                        <button class="type-btn ${r.type === 'subjective' ? 'active subj' : ''}" onclick="event.stopPropagation(); setType(${idx}, 'subjective')">주관식</button>
                        <button class="type-btn ${isSubItem ? 'active sub' : ''}" onclick="event.stopPropagation(); setType(${idx}, 'sub_item')">소문항</button>
                        <button class="type-btn ${isComposite ? 'active comp' : ''}" onclick="event.stopPropagation(); setType(${idx}, 'composite')">복합</button>
                    </div>
                    ${compositeAnsInfo}
                    ${addingGuideHtml}
                    <input type="text" class="input-answer" 
                           placeholder="${answerPlaceholder}" 
                           value="${r.answer || ''}" 
                           onclick="event.stopPropagation()"
                           onchange="rois[${idx}].answer=this.value.trim()">
                    <div class="score-wrap" onclick="event.stopPropagation()">
                        <input type="number" class="input-score" value="${r.score}" onchange="updateIndividualScore(${idx}, this.value)"> 점
                    </div>
                    ${addSubBtn}
                    ${addCompBtn}
                    ${splitBtn}
                    ${mergeBtn}
                    <button class="btn-delete" onclick="event.stopPropagation(); deleteRoi(${idx})">🗑️</button>
                `;

                // 문항 카드 클릭 시: 해당 문항 페이지로 이동 및 세트 포커스 토글
                div.onclick = () => {
                    if (addAnsTargetIndex !== null && !isAddingThisItem) {
                        addAnsTargetIndex = null;
                        reindexRois();
                    }
                    const targetPageIdx = pages.findIndex(p => p.page_uid === r.page_uid || p.page_num === r.page);
                    const willSelect = (selectedRoiIndex !== idx);
                    if (targetPageIdx >= 0 && targetPageIdx !== activePageIndex) {
                        switchPage(targetPageIdx);
                    }
                    selectedRoiIndex = willSelect ? idx : null;
                    draw();
                    renderSidebar();
                };

                div.addEventListener('dragstart', handleDragStart);
                div.addEventListener('dragover', handleDragOver);
                div.addEventListener('drop', handleDrop);

                roiListContainer.appendChild(div);
            });

            const baseScore = targetScore - currentTotal;
            const baseScoreEl = document.getElementById('summaryBaseScore');

            document.getElementById('summaryCount').innerText = rois.length;
            document.getElementById('summaryTotalScore').innerText = currentTotal;

            if (baseScore < 0) {
                baseScoreEl.className = 'base-score exceeded';
                baseScoreEl.innerText = `초과 (${baseScore}점)`;
            } else {
                baseScoreEl.className = 'base-score';
                baseScoreEl.innerText = `${baseScore}점`;
            }
        }

        function updateIndividualScore(idx, val) {
            rois[idx].score = parseInt(val) || 0;
            renderSidebar();
        }

        function applyTargetScore() {
            const val = parseInt(document.getElementById('targetTotalScore').value);
            if (isNaN(val) || val < 0) {
                alert('올바른 만점 점수를 입력해주세요.');
                return;
            }
            targetScore = val;
            renderSidebar();
        }

        function applyBatchAll() {
            const inputVal = document.getElementById('batchAllVal').value;
            if (inputVal === '') {
                alert('일괄 적용할 점수를 입력해주세요.');
                return;
            }
            const scoreVal = parseInt(inputVal) || 0;
            if (rois.length === 0) return;
            pushUndoState();
            rois.forEach(r => r.score = scoreVal);
            renderSidebar();
        }

        function applyBatchType() {
            const objInput = document.getElementById('batchObjVal').value;
            const subjInput = document.getElementById('batchSubjVal').value;

            if (objInput === '' && subjInput === '') {
                alert('적용할 객관식 또는 주관식 점수를 입력해주세요.');
                return;
            }

            if (rois.length === 0) return;

            pushUndoState();
            rois.forEach(r => {
                if (r.type === 'objective' && objInput !== '') {
                    r.score = parseInt(objInput) || 0;
                } else if (r.type === 'subjective' && subjInput !== '') {
                    r.score = parseInt(subjInput) || 0;
                }
            });
            renderSidebar();
        }

        let dragSrcIndex = null;
        function handleDragStart(e) {
            dragSrcIndex = Number(this.dataset.index);
            e.dataTransfer.effectAllowed = 'move';
        }

        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        }

        function handleDrop(e) {
            e.preventDefault();
            const dropTargetIndex = Number(this.dataset.index);

            if (dragSrcIndex !== null && dragSrcIndex !== dropTargetIndex) {
                pushUndoState();
                const movedItem = rois.splice(dragSrcIndex, 1)[0];
                rois.splice(dropTargetIndex, 0, movedItem);
                reindexRois();
                draw();
                renderSidebar();
            }
        }

        // 페이지 관리 모달 제어
        function openPageManager() {
            renderModalPageList();
            document.getElementById('pageModal').style.display = 'flex';
        }

        function closePageManager() {
            document.getElementById('pageModal').style.display = 'none';
            renderPageTabs();
            loadPage(activePageIndex);
            draw();
            renderSidebar();
        }

        function renderModalPageList() {
            const listEl = document.getElementById('modalPageList');
            listEl.innerHTML = "";

            pages.forEach((page, idx) => {
                const count = rois.filter(r => r.page_uid === page.page_uid).length;
                const row = document.createElement('div');
                row.className = "modal-page-row";

                row.innerHTML = `
                    <div class="modal-page-info">
                        <img src="${page.image_path}" class="modal-page-thumb" alt="${page.page_num}쪽">
                        <div>
                            <div style="font-weight:700; color:#fff;">${page.page_num}쪽 (${page.title || (page.page_num + '쪽')})</div>
                            <div style="font-size:12px; color:#888;">설정된 문항: ${count}개</div>
                        </div>
                    </div>
                    <div class="modal-page-actions">
                        ${idx > 0 ? `<button type="button" class="btn-modal-action" onclick="movePageOrder(${idx}, -1)">▲ 위로</button>` : ''}
                        ${idx < pages.length - 1 ? `<button type="button" class="btn-modal-action" onclick="movePageOrder(${idx}, 1)">▼ 아래로</button>` : ''}
                        ${pages.length > 1 ? `<button type="button" class="btn-modal-action btn-del" onclick="deleteModalPage(${idx})">🗑️ 삭제</button>` : ''}
                    </div>
                `;
                listEl.appendChild(row);
            });
        }

        // 페이지 순서 재정렬 (page_uid를 통해 ROI 무결성 100% 보존!)
        function movePageOrder(idx, dir) {
            const targetIdx = idx + dir;
            if (targetIdx < 0 || targetIdx >= pages.length) return;

            const moved = pages.splice(idx, 1)[0];
            pages.splice(targetIdx, 0, moved);

            // page_num 재정렬
            pages.forEach((p, pIdx) => {
                p.page_num = pIdx + 1;
                p.title = `${p.page_num}쪽`;
            });

            // rois의 표시용 page 번호 동기화 (page_uid는 영구 보존)
            rois.forEach(r => {
                const matched = pages.find(p => p.page_uid === r.page_uid);
                if (matched) {
                    r.page = matched.page_num;
                }
            });

            // 활성 페이지 인덱스 보정
            if (activePageIndex === idx) activePageIndex = targetIdx;
            else if (activePageIndex === targetIdx) activePageIndex = idx;

            pageImages = {}; // 이미지 캐시 리셋
            renderModalPageList();
        }

        function deleteModalPage(idx) {
            const targetPg = pages[idx];
            const qCount = rois.filter(r => r.page_uid === targetPg.page_uid).length;

            let msg = `${targetPg.page_num}쪽 페이지를 삭제하시겠습니까?`;
            if (qCount > 0) {
                msg = `⚠️ 주의: ${targetPg.page_num}쪽에 설정된 ${qCount}개의 문항이 함께 삭제됩니다. 정말 삭제하시겠습니까?`;
            }

            if (confirm(msg)) {
                // 해당 페이지의 ROI 제거
                rois = rois.filter(r => r.page_uid !== targetPg.page_uid);
                reindexRois();

                pages.splice(idx, 1);
                pages.forEach((p, pIdx) => {
                    p.page_num = pIdx + 1;
                    p.title = `${p.page_num}쪽`;
                });

                rois.forEach(r => {
                    const matched = pages.find(p => p.page_uid === r.page_uid);
                    if (matched) r.page = matched.page_num;
                });

                if (activePageIndex >= pages.length) {
                    activePageIndex = pages.length - 1;
                }

                pageImages = {};
                renderModalPageList();
            }
        }

        // 최종 템플릿 저장 (Contract A, B 동시 전송)
        function saveTemplate() {
            if (rois.length === 0) {
                alert('설정된 ROI가 없습니다. 영역을 먼저 지정해주세요.');
                return;
            }

            fetch('/api/templates/save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    template_id: templateId,
                    roi_data: rois,
                    pages_data: pages
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('템플릿 설정(페이지 및 ROI)이 성공적으로 저장되었습니다.');
                    location.href = '/views/dashboard/main.php';
                } else {
                    alert('저장 실패: ' + (data.error || data.message || '알 수 없는 오류'));
                }
            })
            .catch(err => alert('서버 통신 오류가 발생했습니다: ' + err.message));
        }

        // AI 자동 완성 다시 받기 API 호출
        async function reExtractAi() {
            if (rois.length > 0) {
                if (!confirm("기존에 설정된 문항 및 ROI 영역이 Upstage AI 자동 인식 결과로 덮어씌워집니다. 계속하시겠습니까?")) {
                    return;
                }
            }

            showToast("✨ Upstage AI가 시험지 문항과 영역을 정밀하게 다시 추출하고 있습니다...", 6000);

            try {
                const response = await fetch('/api/templates/auto_extract.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ template_id: templateId })
                });
                const data = await response.json();

                if (data.success) {
                    rois = (Array.isArray(data.rois) ? data.rois : []).map((r, i) => {
                        const pageNum = parseInt(r.page) || 1;
                        const matchedPage = pages.find(p => p.page_uid === r.page_uid) || pages[pageNum - 1] || pages[0];
                        const rawId = r.id || (i + 1);
                        const subId = r.sub_id || null;
                        const displayNum = r.display_num || (subId ? `${rawId}-${subId}` : `${rawId}`);
                        return {
                            id: rawId,
                            sub_id: subId,
                            display_num: displayNum,
                            page_uid: r.page_uid || (matchedPage ? matchedPage.page_uid : 'p_1_default'),
                            page: matchedPage ? matchedPage.page_num : pageNum,
                            num_roi: r.num_roi || { x: 50, y: 50, w: 50, h: 30 },
                            ans_roi: r.ans_roi || { x: 110, y: 50, w: 60, h: 35 },
                            ans_rois: Array.isArray(r.ans_rois) ? r.ans_rois : null,
                            type: r.type || 'objective',
                            sub_type: r.sub_type || (r.type === 'subjective' ? 'subjective' : 'objective'),
                            answer: r.answer || '',
                            score: typeof r.score === 'number' ? r.score : 0
                        };
                    });
                    draw();
                    renderSidebar();
                    renderPageTabs();
                    showToast(`🎉 총 ${rois.length}개 문항의 AI 자동 완성이 완료되었습니다!`, 4000);
                } else {
                    alert("AI 자동 완성 실패: " + (data.error || "문항을 추출하지 못했습니다."));
                }
            } catch (err) {
                alert("서버 통신 오류: " + err.message);
            }
        }

        // 전체 문항 ROI 초기화
        function resetAllRois() {
            if (rois.length === 0) return;
            if (confirm("모든 문항 사각형(ROI)을 초기화하시겠습니까? 처음부터 직접 그릴 수 있습니다.")) {
                pushUndoState();
                rois = [];
                tempNumRoi = null;
                currentStage = 'NUM';
                selectedRoiIndex = null;
                draw();
                renderSidebar();
                renderPageTabs();
                showToast("전체 문항 영역이 초기화되었습니다.");
            }
        }

        // 알림 토스트 표시
        function showToast(msg, duration = 3500) {
            const existing = document.querySelector('.toast-banner');
            if (existing) existing.remove();

            const toast = document.createElement('div');
            toast.className = 'toast-banner';
            toast.innerHTML = `<span>🔔</span> <span>${msg}</span>`;
            document.body.appendChild(toast);

            setTimeout(() => {
                if (toast.parentNode) {
                    toast.style.transition = 'opacity 0.4s';
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 400);
                }
            }, duration);
        }

        // 초기 로드 시 ?ai=1 안내 토스트
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('ai') === '1' && rois.length > 0) {
                showToast(`✨ Upstage AI가 총 ${rois.length}개 문항과 정답 영역을 자동 완성했습니다! 영역 크기를 조절하거나 정답을 입력하세요.`, 6000);
            }
        });
    </script>
</body>
</html>
