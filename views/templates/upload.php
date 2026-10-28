<?php
/**
 * views/templates/upload.php
 * 새 평가지 템플릿 등록 뷰 (PDF 고해상도 변환 & 다중 이미지 순차/순서 재정렬 지원)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

$userId = requireTeacherAuth();
$username = $_SESSION['username'] ?? ('user_' . $userId);

$errorMessage = '';

// POST 처리: 단일 이미지 레거시 전송 및 AJAX 멀티파트 전송 통합 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $isAjax = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
           || (!empty($_POST['is_ajax']));

    if (!$title) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => '평가지 제목은 필수입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $errorMessage = "평가지 제목은 필수입니다.";
    } else {
        $uploadDir = APP_ROOT . "/template/images/{$username}/";
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $pagesData = [];
        $firstWebPath = '';
        $pageCount = (int)($_POST['page_count'] ?? 0);

        // 1. 다중 페이지 멀티파트 수신 (page_file_0, page_file_1 ...)
        if ($pageCount > 0) {
            $pagesMeta = json_decode($_POST['pages_meta'] ?? '[]', true) ?: [];

            for ($i = 0; $i < $pageCount; $i++) {
                $fileKey = "page_file_{$i}";
                if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION)) ?: 'jpg';
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) $ext = 'jpg';

                    $fileName = 'tpl_' . time() . '_' . uniqid() . "_p" . ($i + 1) . '.' . $ext;
                    $targetPath = $uploadDir . $fileName;
                    $webPath = "/template/images/{$username}/" . $fileName;

                    if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $targetPath)) {
                        $meta = $pagesMeta[$i] ?? [];
                        $pageUid = (string)($meta['page_uid'] ?? ('p_' . ($i + 1) . '_' . substr(uniqid(), -4)));
                        $pageTitle = (string)($meta['title'] ?? (($i + 1) . '쪽'));
                        
                        $imgSize = @getimagesize($targetPath);
                        $w = $imgSize ? $imgSize[0] : (int)($meta['width'] ?? 1200);
                        $h = $imgSize ? $imgSize[1] : (int)($meta['height'] ?? 1700);

                        $pagesData[] = [
                            'page_uid'   => $pageUid,
                            'page_num'   => $i + 1,
                            'title'      => $pageTitle,
                            'image_path' => $webPath,
                            'width'      => $w,
                            'height'     => $h
                        ];

                        if ($i === 0) {
                            $firstWebPath = $webPath;
                        }
                    }
                }
            }
        } 
        // 2. 단일 파일 레거시 폼 전송 호환 (template_image)
        elseif (isset($_FILES['template_image']) && $_FILES['template_image']['error'] === UPLOAD_ERR_OK) {
            $fileExt = strtolower(pathinfo($_FILES['template_image']['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($fileExt, $allowedExts)) {
                $fileName = 'tpl_' . time() . '_' . uniqid() . '.' . $fileExt;
                $targetPath = $uploadDir . $fileName;
                $webPath = "/template/images/{$username}/" . $fileName;

                if (move_uploaded_file($_FILES['template_image']['tmp_name'], $targetPath)) {
                    $imgSize = @getimagesize($targetPath);
                    $firstWebPath = $webPath;
                    $pagesData[] = [
                        'page_uid'   => 'p_1_' . substr(uniqid(), -4),
                        'page_num'   => 1,
                        'title'      => '1쪽',
                        'image_path' => $webPath,
                        'width'      => $imgSize ? $imgSize[0] : 1200,
                        'height'     => $imgSize ? $imgSize[1] : 1700
                    ];
                }
            }
        }

        if (empty($pagesData) || empty($firstWebPath)) {
            $err = "유효한 평가지 파일이 업로드되지 않았습니다.";
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $errorMessage = $err;
        } else {
            $pagesJson = json_encode($pagesData, JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare("
                INSERT INTO templates (user_id, title, image_path, pages_data, roi_data, created_at) 
                VALUES (:u, :t, :p, :pages, '[]', NOW())
            ");
            $stmt->execute([
                'u'     => $userId,
                't'     => $title,
                'p'     => $firstWebPath,
                'pages' => $pagesJson
            ]);

            $templateId = (int)$pdo->lastInsertId();

            $autoAiExtracted = false;

            // 3. AI 자동 문항 & ROI 추출 처리 (PDF 업로드 시)
            if (!empty($_POST['ai_auto_extract']) && isset($_FILES['original_pdf']) && $_FILES['original_pdf']['error'] === UPLOAD_ERR_OK) {
                $origPdfName = "tpl_{$templateId}_original.pdf";
                $origPdfPath = $uploadDir . $origPdfName;
                if (move_uploaded_file($_FILES['original_pdf']['tmp_name'], $origPdfPath)) {
                    // 방안 A: 클라이언트 PDF.js에서 추출한 정밀 글리프(괄호/번호) 데이터 수신 및 영구 캐시 보존
                    $pageGlyphs = [];
                    if (!empty($_POST['page_glyphs'])) {
                        $pageGlyphs = json_decode($_POST['page_glyphs'], true) ?: [];
                        $glyphsPath = $uploadDir . "tpl_{$templateId}_glyphs.json";
                        file_put_contents($glyphsPath, json_encode($pageGlyphs, JSON_UNESCAPED_UNICODE));
                    }

                    require_once APP_ROOT . '/includes/common/upstage.php';
                    $upstageData = parseDocumentWithUpstage($origPdfPath);
                    if ($upstageData) {
                        $extractedRois = extractRoisFromUpstageData($upstageData, $pagesData, $pageGlyphs);
                        if (!empty($extractedRois)) {
                            $initialRoi = json_encode($extractedRois, JSON_UNESCAPED_UNICODE);
                            $upStmt = $pdo->prepare("UPDATE templates SET roi_data = :roi WHERE id = :id");
                            $upStmt->execute(['roi' => $initialRoi, 'id' => $templateId]);
                            $autoAiExtracted = true;
                        }
                    }
                }
            }

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success'           => true,
                    'template_id'       => $templateId,
                    'auto_ai_extracted' => $autoAiExtracted,
                    'redirect'          => '/views/templates/editor.php?id=' . $templateId . ($autoAiExtracted ? '&ai=1' : '')
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            header("Location: /views/templates/editor.php?id=" . $templateId . ($autoAiExtracted ? '&ai=1' : ''));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <script src="/__preview-bridge.js" data-preview-bridge></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>새 평가지 템플릿 등록 - 쌤글라스</title>
    <!-- PDF.js CDN (Mozilla 공식) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    </script>
    <style>
        :root {
            --primary: #0d6efd;
            --primary-hover: #0b5ed7;
            --success: #198754;
            --danger: #dc3545;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --gray-800: #212529;
            --radius: 10px;
        }
        * { box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif; 
            background-color: #f1f5f9; 
            margin: 0; 
            padding: 40px 20px; 
            color: #333; 
        }
        .container { 
            background: white; 
            max-width: 680px; 
            margin: 0 auto; 
            padding: 35px; 
            border-radius: var(--radius); 
            box-shadow: 0 6px 24px rgba(0,0,0,0.08); 
            border: 1px solid var(--gray-300);
        }
        h2 { margin-top: 0; color: #1e293b; font-size: 1.6rem; text-align: center; font-weight: 800; }
        .subtitle { text-align: center; color: var(--gray-600); font-size: 0.95rem; margin-bottom: 25px; }
        
        .form-group { margin-bottom: 24px; }
        label { display: block; font-weight: 700; margin-bottom: 8px; color: #334155; font-size: 0.95rem; }
        input[type="text"] { 
            width: 100%; 
            padding: 13px 16px; 
            border: 1.5px solid #cbd5e1; 
            border-radius: 8px; 
            font-size: 1rem; 
            transition: border-color 0.2s;
        }
        input[type="text"]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(13,110,253,0.15); }
        
        /* AI 안내 배너 및 토글 */
        .ai-banner {
            background: linear-gradient(135deg, #eff6ff 0%, #e0e7ff 100%);
            border: 1.5px solid #bfdbfe;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 22px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .ai-banner-icon { font-size: 1.5rem; flex-shrink: 0; }
        .ai-banner-title { font-size: 0.95rem; font-weight: 800; color: #1e3a8a; margin-bottom: 4px; }
        .ai-banner-desc { font-size: 0.85rem; color: #3b82f6; line-height: 1.5; }
        .ai-banner-desc strong { color: #1d4ed8; }
        
        .ai-toggle-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 14px;
        }
        .ai-toggle-label {
            font-size: 0.92rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ai-badge {
            background: #3b82f6;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.78rem;
            font-weight: 800;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .3s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: #0d6efd; }
        input:checked + .slider:before { transform: translateX(20px); }
        
        /* 드롭존 영역 */
        .dropzone {
            border: 2px dashed #94a3b8;
            border-radius: 12px;
            padding: 32px 20px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .dropzone:hover, .dropzone.dragover {
            border-color: var(--primary);
            background: #eff6ff;
        }
        .dropzone-icon { font-size: 2.8rem; margin-bottom: 8px; display: block; }
        .dropzone-text { font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        .dropzone-subtext { font-size: 0.85rem; color: #64748b; }

        /* 페이지 썸네일 스트립 */
        .pages-strip-container {
            margin-top: 20px;
            display: none;
        }
        .strip-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .strip-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .badge-count {
            background: #e2e8f0;
            color: #475569;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .btn-add-more {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #334155;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }
        .btn-add-more:hover { background: #e2e8f0; }

        .pages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(135px, 1fr));
            gap: 12px;
            max-height: 380px;
            overflow-y: auto;
            padding: 4px;
        }
        .page-card {
            background: white;
            border: 2px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.04);
            transition: transform 0.15s, border-color 0.15s;
        }
        .page-card:hover { border-color: var(--primary); }
        .page-card.dragging { opacity: 0.4; border-color: #64748b; }
        .page-thumb-wrap {
            width: 100%;
            height: 160px;
            background: #f1f5f9;
            border-radius: 4px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 6px;
        }
        .page-thumb {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .page-info {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .page-badge {
            font-size: 0.8rem;
            font-weight: 800;
            color: #0f172a;
            background: #e2e8f0;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .page-actions {
            display: flex;
            gap: 3px;
        }
        .btn-tool {
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            padding: 2px 4px;
            border-radius: 4px;
            color: #64748b;
        }
        .btn-tool:hover { background: #fee2e2; color: #dc2626; }
        .btn-reorder:hover { background: #e0f2fe; color: #0284c7; }

        .btn-submit { 
            width: 100%; 
            padding: 15px; 
            background: var(--primary); 
            color: white; 
            border: none; 
            border-radius: 8px; 
            font-size: 1.05rem; 
            font-weight: 800; 
            cursor: pointer; 
            transition: background 0.2s; 
            margin-top: 20px;
        }
        .btn-submit:hover { background: var(--primary-hover); }
        .btn-submit:disabled { background: #94a3b8; cursor: not-allowed; }
        .btn-back { 
            display: block; 
            text-align: center; 
            margin-top: 16px; 
            color: var(--gray-600); 
            text-decoration: none; 
            font-size: 0.92rem; 
            font-weight: 600; 
        }
        .btn-back:hover { color: #1e293b; }
        .error { background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.92rem; font-weight: 600; }

        /* 로딩 오버레이 */
        .loading-overlay {
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(4px);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            color: white;
            display: none;
        }
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255,255,255,0.2);
            border-top-color: #38bdf8;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-bottom: 16px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-text { font-size: 1.15rem; font-weight: 800; }
        .loading-subtext { font-size: 0.88rem; color: #cbd5e1; margin-top: 6px; }
    </style>
</head>
<body>

    <div class="container">
        <h2>📤 새 평가지 템플릿 등록</h2>
        <div class="subtitle">PDF 파일(자동 분할) 또는 낱장 이미지들을 등록하여 다쪽 시험지를 생성합니다.</div>
        
        <div class="ai-banner">
            <span class="ai-banner-icon">✨</span>
            <div>
                <div class="ai-banner-title">Upstage AI 문항 자동 완성 지원</div>
                <div class="ai-banner-desc">
                    PDF 시험지를 등록하시면 <strong>문항 번호와 정답 영역(ROI)을 AI가 1초 만에 자동 계산</strong>하여 캔버스에 배치해 드립니다.<br>
                    *(한글 .hwp 문서는 한컴오피스에서 <strong>[파일] → [PDF로 저장하기]</strong> 후 등록하시면 시각적 오차 없이 완벽하게 인식됩니다.)*
                </div>
            </div>
        </div>
        
        <?php if (!empty($errorMessage)): ?>
            <div class="error"><?= e($errorMessage) ?></div>
        <?php endif; ?>

        <form id="uploadForm" action="" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="is_ajax" value="1">
            <div class="form-group">
                <label for="title">평가지 제목</label>
                <input type="text" name="title" id="title" required placeholder="예: 2026학년도 1학기 중간고사 수학" value="<?= e($_POST['title'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>시험지 파일 (PDF 또는 이미지 복수 선택 가능)</label>
                <!-- 실제 파일 인풋 (숨김) -->
                <input type="file" id="fileInput" accept="application/pdf,image/jpeg,image/png,image/webp" multiple style="display: none;">
                
                <div class="dropzone" id="dropzone">
                    <span class="dropzone-icon">📄</span>
                    <div class="dropzone-text">PDF 문서 또는 시험지 이미지 끌어다 놓기</div>
                    <div class="dropzone-subtext">클릭하여 파일을 선택하거나 여러 장의 이미지를 동시에 드롭하세요.</div>
                </div>

                <div class="ai-toggle-box" id="aiToggleBox">
                    <div class="ai-toggle-label">
                        <span>🤖 Upstage AI 문항 및 정답란(ROI) 자동 생성</span>
                        <span class="ai-badge">추천</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="aiToggle" checked>
                        <span class="slider"></span>
                    </label>
                </div>

                <!-- 페이지 썸네일 스트립 (페이지 순서 변경 / 삭제) -->
                <div class="pages-strip-container" id="pagesStrip">
                    <div class="strip-header">
                        <div class="strip-title">
                            <span>📑 등록된 페이지 목록</span>
                            <span class="badge-count" id="badgePageCount">0쪽</span>
                        </div>
                        <button type="button" class="btn-add-more" onclick="document.getElementById('fileInput').click()">➕ 페이지 추가</button>
                    </div>
                    <div class="pages-grid" id="pagesGrid"></div>
                </div>
            </div>

            <button type="button" class="btn-submit" id="submitBtn" onclick="submitTemplate()">🚀 평가지 등록 및 ROI 지정 이동</button>
            <a href="/views/dashboard/main.php" class="btn-back">← 메인 대시보드로 돌아가기</a>
        </form>
    </div>

    <!-- 로딩 모달 -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <div class="loading-text" id="loadingTitle">PDF 페이지 고해상도 변환 중...</div>
        <div class="loading-subtext" id="loadingDesc">선명한 문항 인식을 위해 고화질 캔버스로 렌더링하고 있습니다.</div>
    </div>

    <script>
        // 전역 페이지 큐 (SSOT Contract A 호환)
        let pagesQueue = [];
        let isProcessing = false;
        let originalPdfFile = null;
        let pageGlyphsMap = {}; // 방안 A: 페이지별 정밀 글리프(괄호/번호) 맵

        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const pagesStrip = document.getElementById('pagesStrip');
        const pagesGrid = document.getElementById('pagesGrid');
        const badgeCount = document.getElementById('badgePageCount');
        const overlay = document.getElementById('loadingOverlay');
        const overlayTitle = document.getElementById('loadingTitle');
        const overlayDesc = document.getElementById('loadingDesc');

        // [방안 A] PDF.js 텍스트 레이어로부터 정답 괄호 ( ) 및 문항 번호 글리프 좌표 정밀 추출
        function extractPageGlyphsFromTextContent(textContent, viewport) {
            const items = textContent.items || [];
            if (!items.length) return { brackets: [], numbers: {} };

            const scale = viewport.scale || 2.0;
            const viewW = viewport.width;
            const viewH = viewport.height;

            // 1. 각 텍스트 아이템을 캔버스 뷰포트 좌표계로 정밀 변환
            const parsedItems = items.map(item => {
                const [vx, vy] = viewport.convertToViewportPoint(item.transform[4], item.transform[5]);
                const fontH = Math.hypot(item.transform[2], item.transform[3]) * scale;
                const fontW = Math.hypot(item.transform[0], item.transform[1]) * scale;
                const widthPx = (item.width || 0) * scale;
                const str = (item.str || '').trim();

                return {
                    str: item.str || '',
                    cleanStr: str,
                    vx: vx,
                    vy: vy, // baseline Y
                    widthPx: widthPx,
                    fontH: Math.max(fontH, fontW, 14),
                    charW: item.str && item.str.length > 0 ? (widthPx / item.str.length) : 0
                };
            });

            const brackets = [];
            const numbers = {}; // qNum -> array of candidate positions or best position

            // 2. 문항 번호 글리프 감지 ("1.", "2 ", "5." 등)
            // 풋터(하단 9% 쪽수) 및 기사 인용 날짜("2023. 3. 12.") 오인식 배제
            parsedItems.forEach((item, idx) => {
                // 하단 9% 영역(쪽 번호 footer)은 번호 후보에서 원천 배제
                if (item.vy > viewH * 0.91) return;

                const m = item.cleanStr.match(/^(\d{1,2})\s*([\.|\s|\)|번]|$)/);
                if (m) {
                    const qNum = parseInt(m[1], 10);
                    if (qNum >= 1 && qNum <= 100) {
                        // 날짜 및 인용구 검사: 바로 앞 아이템에 연도(202X)나 출처 기호(『)가 있으면 배제
                        const prevStr = (idx > 0) ? parsedItems[idx - 1].cleanStr : '';
                        if (/202\d|『|년|\/|-/.test(prevStr)) return;

                        const numW = Math.max(Math.round(item.widthPx + 8), (qNum >= 10 ? 40 : 34));
                        const numH = Math.max(Math.round(item.fontH + 6), 26);
                        const numX = Math.round(item.vx - 2);
                        const numY = Math.round(item.vy - 0.85 * item.fontH);

                        const candidate = {
                            x: Math.max(0, numX),
                            y: Math.max(0, numY),
                            w: numW,
                            h: numH,
                            midX: numX + numW / 2,
                            midY: numY + numH / 2,
                            normX: numX / viewW,
                            normY: numY / viewH,
                            normW: numW / viewW,
                            normH: numH / viewH
                        };

                        if (!numbers[qNum]) {
                            numbers[qNum] = candidate;
                        }
                    }
                }
            });

            // 3. 정답 괄호 ( ) 정밀 감지
            for (let i = 0; i < parsedItems.length; i++) {
                const item = parsedItems[i];
                const str = item.str;

                // A. 단일 텍스트 아이템 내에 '(' 와 ')' 가 모두 존재하는 경우
                const lParenIdx = str.search(/[\(\（]/);
                if (lParenIdx !== -1) {
                    const rParenIdx = str.substring(lParenIdx + 1).search(/[\)\）]/);
                    if (rParenIdx !== -1) {
                        const actualRParenIdx = lParenIdx + 1 + rParenIdx;
                        const inside = str.substring(lParenIdx + 1, actualRParenIdx).trim();
                        const afterR = str.substring(actualRParenIdx + 1).trim();

                        // [Q5 & Q11 해결] 지시문 괄호 완벽 배제:
                        // 1) 괄호 바로 뒤 텍스트 또는 다음 텍스트 아이템 전방 탐색
                        let nextText = afterR;
                        if (!nextText && (i + 1 < parsedItems.length)) {
                            nextText = parsedItems[i + 1].cleanStr;
                        }
                        const isInstructionClause = /^(안에서|안에|안의|속에서|속에|속의|중에서|중에|중의|에\s*들어갈|을\s*골라|을\s*찾아|을\s*채우|에\s*써|에\s*알맞은)/u.test(nextText)
                            || (/다음\s*(문장|글|중)/.test(str) && /(안에서|안에|속에서|속에)/.test(nextText));
                        if (isInstructionClause) {
                            continue;
                        }

                        // 빈 괄호 또는 선택지 괄호 (실천 , 모른 척 등 쉼표/슬래시 포함)
                        const isAnswerBracket = (inside === '' || /^\s*$/.test(inside) || /^·+$/.test(inside) || /,|\/|vs/i.test(inside));

                        if (isAnswerBracket) {
                            const leftX = item.vx + (lParenIdx * item.charW);
                            const rightX = item.vx + ((actualRParenIdx + 1) * item.charW);
                            const dist = Math.max(rightX - leftX, 20);

                            const midX = (leftX + rightX) / 2;
                            const midY = item.vy - (0.35 * item.fontH);

                            // [요청 1 반영] 가로는 괄호 외곽에 딱 맞추고, 세로는 상하 여유 공간(32~36px) 확보
                            const boxW = Math.max(Math.round(dist), 38);
                            const boxH = Math.max(Math.round(item.fontH * 1.35 + 8), 34);
                            const boxX = Math.round(leftX);
                            const boxY = Math.round(midY - boxH / 2);

                            brackets.push({
                                x: boxX,
                                y: boxY,
                                w: boxW,
                                h: boxH,
                                midX: midX,
                                midY: midY,
                                normX: boxX / viewW,
                                normY: boxY / viewH,
                                normW: boxW / viewW,
                                normH: boxH / viewH,
                                isChoiceBracket: /,|\/|vs/i.test(inside),
                                text: str.substring(lParenIdx, actualRParenIdx + 1)
                            });
                            continue;
                        }
                    }
                }

                // B. '(' 가 있고 ')' 는 이후 텍스트 아이템에 분리되어 있는 경우 (Q8, Q17 장폭 괄호 등)
                if (/[\(\（]/.test(str)) {
                    const lIdx = str.lastIndexOf('(') !== -1 ? str.lastIndexOf('(') : str.lastIndexOf('（');
                    const afterL = str.substring(lIdx + 1).trim();
                    // '(' 뒤에 '1) 학교생활...' 처럼 긴 일반 문장이 이어지면 배제
                    if (afterL.length > 2 && !/^·+$/.test(afterL)) {
                        continue;
                    }

                    const leftX = item.vx + (lIdx * item.charW);

                    // [Q8, Q17 해결] 최대 35개 아이템까지 탐색하여 장폭 빈칸 괄호 완벽 페어링
                    for (let j = i + 1; j < Math.min(parsedItems.length, i + 35); j++) {
                        const nextItem = parsedItems[j];
                        // 기준선(baseline) 오차 10px 이내 동일 행 검사
                        if (Math.abs(nextItem.vy - item.vy) > 10) break;

                        const rIdx = nextItem.str.indexOf(')') !== -1 ? nextItem.str.indexOf(')') : nextItem.str.indexOf('）');
                        if (rIdx !== -1) {
                            const afterR = nextItem.str.substring(rIdx + 1).trim();
                            // 지시문 괄호 배제
                            let nextText = afterR;
                            if (!nextText && (j + 1 < parsedItems.length)) {
                                nextText = parsedItems[j + 1].cleanStr;
                            }
                            const isInstructionClause = /^(안에서|안에|안의|속에서|속에|속의|중에서|중에|중의|에\s*들어갈|을\s*골라|을\s*찾아|을\s*채우|에\s*써|에\s*알맞은)/u.test(nextText);
                            if (isInstructionClause) break;

                            const rightX = nextItem.vx + ((rIdx + 1) * nextItem.charW);
                            const dist = rightX - leftX;

                            // 괄호 간 거리가 최대 450px(단답형 전폭) 이내인 경우 모두 인정
                            if (dist > 15 && dist < 450) {
                                const midX = (leftX + rightX) / 2;
                                const midY = ((item.vy + nextItem.vy) / 2) - (0.35 * item.fontH);

                                // [요청 1 반영] 좌측 괄호부터 우측 괄호까지 정확히 포괄하고 상하 넉넉히
                                const boxW = Math.max(Math.round(dist), 38);
                                const boxH = Math.max(Math.round(item.fontH * 1.35 + 8), 34);
                                const boxX = Math.round(leftX);
                                const boxY = Math.round(midY - boxH / 2);

                                brackets.push({
                                    x: boxX,
                                    y: boxY,
                                    w: boxW,
                                    h: boxH,
                                    midX: midX,
                                    midY: midY,
                                    normX: boxX / viewW,
                                    normY: boxY / viewH,
                                    normW: boxW / viewW,
                                    normH: boxH / viewH,
                                    isChoiceBracket: false,
                                    text: '( )'
                                });
                                i = j;
                                break;
                            }
                        }
                    }
                }
            }

            return { brackets, numbers };
        }

        // [주관식 2·3단계] 캔버스 고속 스캔을 통한 수평 밑줄(1~3줄) 및 사각형 박스 감지
        function detectPageLinesAndBoxes(canvas, viewport, textContent) {
            const W = canvas.width;
            const H = canvas.height;
            const ctx = canvas.getContext('2d');

            const underlines = [];
            const boxes = [];

            try {
                // 다운샘플링 스캔: 높이 방향 2px 간격으로 고속 탐색
                const imgData = ctx.getImageData(0, 0, W, H);
                const data = imgData.data;

                // 2단 시험지 기준 좌/우 컬럼 영역 정의
                const columns = (W > 800) ? [
                    { isLeft: true,  minX: Math.round(W * 0.05), maxX: Math.round(W * 0.49) },
                    { isLeft: false, minX: Math.round(W * 0.51), maxX: Math.round(W * 0.95) }
                ] : [
                    { isLeft: true,  minX: Math.round(W * 0.05), maxX: Math.round(W * 0.95) }
                ];

                // 컬럼 폭의 최소 35% 이상 가로선만 인정 (단어 밑줄, 분수선, 짧은 기호 배제)
                const minLineLen = Math.round(W * 0.14);
                const yStart = Math.round(H * 0.08); // 헤더(상단 8%) 제외
                const yEnd = Math.round(H * 0.91);   // 풋터(하단 9%) 제외

                columns.forEach(col => {
                    const foundRuns = [];

                    for (let y = yStart; y < yEnd; y += 2) {
                        let runStart = -1;
                        for (let x = col.minX; x <= col.maxX; x += 3) {
                            const idx = (y * W + x) * 4;
                            const brightness = (data[idx] + data[idx + 1] + data[idx + 2]) / 3;
                            const isDark = (brightness < 130);

                            if (isDark) {
                                if (runStart === -1) runStart = x;
                            } else {
                                if (runStart !== -1) {
                                    const len = x - runStart;
                                    if (len >= minLineLen) {
                                        foundRuns.push({ y, x1: runStart, x2: x, len });
                                    }
                                    runStart = -1;
                                }
                            }
                        }
                        if (runStart !== -1) {
                            const len = col.maxX - runStart;
                            if (len >= minLineLen) {
                                foundRuns.push({ y, x1: runStart, x2: col.maxX, len });
                            }
                        }
                    }

                    // 연속된 Y좌표 선 굵기 병합 (선 두께 1~4px 통합)
                    const mergedLines = [];
                    let curGroup = [];

                    foundRuns.forEach(r => {
                        if (!curGroup.length) {
                            curGroup.push(r);
                        } else {
                            const last = curGroup[curGroup.length - 1];
                            if (r.y - last.y <= 4 && Math.abs(r.x1 - last.x1) < 30 && Math.abs(r.x2 - last.x2) < 30) {
                                curGroup.push(r);
                            } else {
                                const avgY = Math.round(curGroup.reduce((s, c) => s + c.y, 0) / curGroup.length);
                                const minX1 = Math.min(...curGroup.map(c => c.x1));
                                const maxX2 = Math.max(...curGroup.map(c => c.x2));
                                mergedLines.push({ y: avgY, x1: minX1, x2: maxX2, len: maxX2 - minX1 });
                                curGroup = [r];
                            }
                        }
                    });
                    if (curGroup.length) {
                        const avgY = Math.round(curGroup.reduce((s, c) => s + c.y, 0) / curGroup.length);
                        const minX1 = Math.min(...curGroup.map(c => c.x1));
                        const maxX2 = Math.max(...curGroup.map(c => c.x2));
                        mergedLines.push({ y: avgY, x1: minX1, x2: maxX2, len: maxX2 - minX1 });
                    }

                    // 각 선이 밑줄(Underline)인지, 아니면 사각형 박스/표(Box) 테두리인지 판별
                    // 선의 양 끝(x1, x2)에서 수직 아래 방향 검사
                    mergedLines.forEach(line => {
                        let hasDownVert = false;
                        for (let dy = 6; dy <= 22; dy += 4) {
                            const checkY = line.y + dy;
                            if (checkY >= H) break;
                            const idx1 = (checkY * W + line.x1) * 4;
                            const idx2 = (checkY * W + line.x2) * 4;
                            const b1 = (data[idx1] + data[idx1 + 1] + data[idx1 + 2]) / 3;
                            const b2 = (data[idx2] + data[idx2 + 1] + data[idx2 + 2]) / 3;
                            if (b1 < 130 && b2 < 130) {
                                hasDownVert = true;
                                break;
                            }
                        }

                        if (!hasDownVert) {
                            // 양 끝이 트여 있는 순수 밑줄 (학생 작성용 밑줄)
                            underlines.push({
                                y: line.y,
                                x: line.x1,
                                w: line.len,
                                normX: line.x1 / W,
                                normY: line.y / H,
                                normW: line.len / W,
                                normH: 30 / H,
                                isLeft: col.isLeft
                            });
                        }
                    });
                });
            } catch (scanErr) {
                console.warn("[Line Scanner] 캔버스 라인 스캔 건너뜀:", scanErr);
            }

            return { underlines, boxes };
        }

        // 드래그 앤 드롭 이벤트
        dropzone.addEventListener('click', () => fileInput.click());
        dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
        dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                handleIncomingFiles(Array.from(e.dataTransfer.files));
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files && e.target.files.length > 0) {
                handleIncomingFiles(Array.from(e.target.files));
                fileInput.value = ''; // 재선택 가능하게 리셋
            }
        });

        async function handleIncomingFiles(fileList) {
            showLoading("파일 분석 중...", "파일 형식을 확인하고 있습니다.");
            
            for (let file of fileList) {
                const ext = file.name.split('.').pop().toLowerCase();
                if (file.type === 'application/pdf' || ext === 'pdf') {
                    await processPdfFile(file);
                } else if (file.type.startsWith('image/') || ['jpg','jpeg','png','webp'].includes(ext)) {
                    await processImageFile(file);
                }
            }

            hideLoading();
            renderPagesGrid();
        }

        // PDF 파일 처리 (PDF.js 고해상도 렌더링 2.0x 스케일 & 방안 A 정밀 글리프 좌표 추출)
        async function processPdfFile(file) {
            originalPdfFile = file;
            pageGlyphsMap = {};
            try {
                showLoading("PDF 페이지 렌더링 중...", `${file.name} 문서를 고화질 이미지로 변환하고 있습니다.`);
                const arrayBuffer = await file.arrayBuffer();
                const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
                const numPages = pdf.numPages;

                for (let i = 1; i <= numPages; i++) {
                    showLoading(`PDF 렌더링 중 (${i}/${numPages}쪽)...`, "인식률 향상을 위한 고해상도 캔버스 및 글리프 분석 중");
                    const page = await pdf.getPage(i);
                    const scale = 2.0; // 300 DPI급 고화질
                    const viewport = page.getViewport({ scale: scale });

                    // 1. 방안 A: 텍스트 레이어에서 괄호( ) 및 문항 번호 정밀 좌표 추출
                    let textContent = null;
                    try {
                        textContent = await page.getTextContent();
                        const glyphs = extractPageGlyphsFromTextContent(textContent, viewport);
                        pageGlyphsMap[i] = glyphs;
                    } catch (glyphErr) {
                        console.warn(`[Glyph Extraction] ${i}쪽 글리프 추출 건너뜀:`, glyphErr);
                        pageGlyphsMap[i] = { brackets: [], numbers: {} };
                    }

                    // 2. 고화질 캔버스 렌더링
                    const offCanvas = document.createElement('canvas');
                    offCanvas.width = viewport.width;
                    offCanvas.height = viewport.height;
                    const ctx = offCanvas.getContext('2d');

                    await page.render({ canvasContext: ctx, viewport: viewport }).promise;

                    // 3. 고속 캔버스 스캔: 수평 밑줄(underlines) 및 사각형 박스(boxes) 감지
                    try {
                        const linesAndBoxes = detectPageLinesAndBoxes(offCanvas, viewport, textContent);
                        if (pageGlyphsMap[i]) {
                            pageGlyphsMap[i].underlines = linesAndBoxes.underlines || [];
                            pageGlyphsMap[i].boxes = linesAndBoxes.boxes || [];
                        }
                    } catch (lineErr) {
                        console.warn(`[Line Extraction] ${i}쪽 밑줄 스캔 건너뜀:`, lineErr);
                    }

                    const blob = await new Promise(r => offCanvas.toBlob(r, 'image/jpeg', 0.90));
                    const dataUrl = offCanvas.toDataURL('image/jpeg', 0.80);

                    pagesQueue.push({
                        page_uid: 'p_' + (pagesQueue.length + 1) + '_' + Math.random().toString(36).substr(2, 4),
                        blob: blob,
                        previewUrl: dataUrl,
                        width: Math.round(viewport.width),
                        height: Math.round(viewport.height),
                        sourceName: `${file.name} (${i}쪽)`
                    });
                }
            } catch (err) {
                alert("PDF 변환 중 오류가 발생했습니다: " + err.message);
            }
        }

        // 일반 이미지 파일 처리
        async function processImageFile(file) {
            return new Promise((resolve) => {
                const reader = new FileReader();
                reader.onload = function(evt) {
                    const img = new Image();
                    img.onload = function() {
                        pagesQueue.push({
                            page_uid: 'p_' + (pagesQueue.length + 1) + '_' + Math.random().toString(36).substr(2, 4),
                            blob: file,
                            previewUrl: evt.target.result,
                            width: img.naturalWidth,
                            height: img.naturalHeight,
                            sourceName: file.name
                        });
                        resolve();
                    };
                    img.src = evt.target.result;
                };
                reader.readAsDataURL(file);
            });
        }

        // 페이지 스트립 렌더링
        function renderPagesGrid() {
            pagesGrid.innerHTML = "";
            if (pagesQueue.length === 0) {
                pagesStrip.style.display = "none";
                badgeCount.innerText = "0쪽";
                return;
            }

            pagesStrip.style.display = "block";
            badgeCount.innerText = `${pagesQueue.length}쪽`;

            pagesQueue.forEach((page, index) => {
                const card = document.createElement('div');
                card.className = "page-card";
                card.draggable = true;
                card.dataset.index = index;

                card.innerHTML = `
                    <div class="page-thumb-wrap">
                        <img src="${page.previewUrl}" class="page-thumb" alt="${index + 1}쪽">
                    </div>
                    <div class="page-info">
                        <span class="page-badge">${index + 1}쪽</span>
                        <div class="page-actions">
                            ${index > 0 ? `<button type="button" class="btn-tool btn-reorder" onclick="movePage(${index}, -1)" title="앞으로">◀</button>` : ''}
                            ${index < pagesQueue.length - 1 ? `<button type="button" class="btn-tool btn-reorder" onclick="movePage(${index}, 1)" title="뒤로">▶</button>` : ''}
                            <button type="button" class="btn-tool" onclick="deletePage(${index})" title="삭제">🗑️</button>
                        </div>
                    </div>
                `;

                // 드래그 앤 드롭 정렬
                card.addEventListener('dragstart', handleDragStart);
                card.addEventListener('dragover', handleDragOver);
                card.addEventListener('drop', handleDrop);
                card.addEventListener('dragend', handleDragEnd);

                pagesGrid.appendChild(card);
            });
        }

        // 페이지 순서 이동
        function movePage(index, dir) {
            const targetIdx = index + dir;
            if (targetIdx < 0 || targetIdx >= pagesQueue.length) return;
            const item = pagesQueue.splice(index, 1)[0];
            pagesQueue.splice(targetIdx, 0, item);
            renderPagesGrid();
        }

        // 페이지 삭제
        function deletePage(index) {
            if (confirm(`${index + 1}쪽을 목록에서 제거하시겠습니까?`)) {
                pagesQueue.splice(index, 1);
                renderPagesGrid();
            }
        }

        // 드래그 정렬 핸들러
        let dragSrcIdx = null;
        function handleDragStart(e) {
            dragSrcIdx = Number(this.dataset.index);
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        }
        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        }
        function handleDrop(e) {
            e.preventDefault();
            const dropTargetIdx = Number(this.dataset.index);
            if (dragSrcIdx !== null && dragSrcIdx !== dropTargetIdx) {
                const moved = pagesQueue.splice(dragSrcIdx, 1)[0];
                pagesQueue.splice(dropTargetIdx, 0, moved);
                renderPagesGrid();
            }
        }
        function handleDragEnd() {
            this.classList.remove('dragging');
        }

        function showLoading(title, desc) {
            overlayTitle.innerText = title;
            overlayDesc.innerText = desc;
            overlay.style.display = "flex";
        }
        function hideLoading() {
            overlay.style.display = "none";
        }

        // 최종 평가지 템플릿 생성 제출 (AJAX 멀티파트)
        async function submitTemplate() {
            const titleInput = document.getElementById('title');
            const title = titleInput.value.trim();

            if (!title) {
                alert("평가지 제목을 입력해주세요.");
                titleInput.focus();
                return;
            }

            if (pagesQueue.length === 0) {
                alert("최소 1페이지 이상의 시험지 파일(PDF 또는 이미지)을 등록해주세요.");
                return;
            }

            const aiToggle = document.getElementById('aiToggle');
            const isAiEnabled = !!(aiToggle && aiToggle.checked && originalPdfFile);

            if (isAiEnabled) {
                showLoading("✨ Upstage AI 시험지 자동 분석 중...", `총 ${pagesQueue.length}개 페이지의 문항 번호와 정답 영역을 AI가 자동 계산하고 있습니다.`);
            } else {
                showLoading("평가지 템플릿 생성 중...", `총 ${pagesQueue.length}개 페이지를 서버에 안전하게 등록하고 있습니다.`);
            }
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;

            try {
                const formData = new FormData();
                formData.append('title', title);
                formData.append('page_count', pagesQueue.length);
                formData.append('is_ajax', '1');

                if (isAiEnabled) {
                    formData.append('ai_auto_extract', '1');
                    formData.append('original_pdf', originalPdfFile, originalPdfFile.name);
                    formData.append('page_glyphs', JSON.stringify(pageGlyphsMap));
                }

                const pagesMeta = pagesQueue.map((p, idx) => ({
                    page_uid: p.page_uid,
                    page_num: idx + 1,
                    title: `${idx + 1}쪽`,
                    width: p.width,
                    height: p.height
                }));
                formData.append('pages_meta', JSON.stringify(pagesMeta));

                pagesQueue.forEach((p, idx) => {
                    formData.append(`page_file_${idx}`, p.blob, `page_${idx + 1}.jpg`);
                });

                const response = await fetch('/views/templates/upload.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success && result.redirect) {
                    location.href = result.redirect;
                } else {
                    hideLoading();
                    submitBtn.disabled = false;
                    alert("업로드 실패: " + (result.error || "알 수 없는 오류가 발생했습니다."));
                }
            } catch (err) {
                hideLoading();
                submitBtn.disabled = false;
                alert("서버 전송 중 네트워크 오류가 발생했습니다: " + err.message);
            }
        }
    </script>
</body>
</html>
