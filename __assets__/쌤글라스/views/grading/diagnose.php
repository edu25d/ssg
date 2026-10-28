<?php
/**
 * views/grading/diagnose.php
 * AI 채점 파이프라인 정밀 진단 & 시각적 검증 도구
 * (원본 이미지, 크롭 이미지, FFDH 합성 이미지, Gemini API 통신 원문 및 응답 진단)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';
require_once APP_ROOT . '/includes/grading/objective.php';

// 교사 권한 확인
$userId = requireTeacherAuth();

// ==========================================
// [AJAX Action Handler]
// ==========================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'list_models') {
    header('Content-Type: application/json; charset=utf-8');
    $apiKey = getGeminiApiKey();
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'API 키가 등록되어 있지 않습니다.']);
        exit;
    }

    $results = [];
    foreach (['v1', 'v1beta'] as $ver) {
        $url = "https://generativelanguage.googleapis.com/{$ver}/models?key=" . urlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $data = json_decode($resp, true);
        $modelNames = [];
        if ($code === 200 && !empty($data['models'])) {
            foreach ($data['models'] as $m) {
                if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [])) {
                    $modelNames[] = str_replace('models/', '', $m['name']);
                }
            }
        }
        $results[$ver] = [
            'http_code' => $code,
            'models'    => $modelNames,
            'error'     => $err ?: ($code !== 200 ? ($data['error']['message'] ?? $resp) : null)
        ];
    }
    echo json_encode(['success' => true, 'data' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'clear_log') {
    header('Content-Type: application/json; charset=utf-8');
    $logFile = APP_ROOT . '/gemini_debug.log';
    if (file_exists($logFile)) {
        @file_put_contents($logFile, '');
    }
    echo json_encode(['success' => true, 'message' => '디버그 로그가 초기화되었습니다.']);
    exit;
}

if ($action === 'test_gemini') {
    header('Content-Type: application/json; charset=utf-8');
    $resultId = (int)($_POST['result_id'] ?? $_GET['result_id'] ?? 0);
    if (!$resultId) {
        echo json_encode(['success' => false, 'error' => 'result_id가 지정되지 않았습니다.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM exam_results WHERE id = ?");
    $stmt->execute([$resultId]);
    $resultRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$resultRow) {
        echo json_encode(['success' => false, 'error' => '해당 제출 내역을 찾을 수 없습니다.']);
        exit;
    }

    $rawList = json_decode($resultRow['raw_results'], true);
    if (!is_array($rawList) && isset($rawList['items'])) {
        $rawList = $rawList['items'];
    }
    $rawList = is_array($rawList) ? $rawList : [];

    // 객관식 문항 필터링 및 크롭 이미지 로드
    $testItems = [];
    $missingFiles = [];
    foreach ($rawList as $it) {
        $type = strtolower($it['type'] ?? 'objective');
        if ($type !== 'subjective' && !in_array($type, ['essay', 'short', 'text', '주관식'])) {
            $cropUrl = $it['crop_url'] ?? $it['crop_image_url'] ?? '';
            $cropPath = APP_ROOT . $cropUrl;
            if (file_exists($cropPath)) {
                $gdImg = @imagecreatefromjpeg($cropPath);
                if ($gdImg) {
                    $testItems[] = [
                        'id'        => (int)($it['id'] ?? $it['item_id']),
                        'item_id'   => (int)($it['id'] ?? $it['item_id']),
                        'score'     => (float)($it['score'] ?? 0),
                        'answer'    => $it['answer'] ?? '',
                        'image_res' => $gdImg,
                        'crop_url'  => $cropUrl
                    ];
                } else {
                    $missingFiles[] = "크롭 이미지 디코딩 실패: {$cropUrl}";
                }
            } else {
                $missingFiles[] = "크롭 파일 없음: {$cropUrl}";
            }
        }
    }

    if (empty($testItems)) {
        echo json_encode([
            'success' => false,
            'error'   => '테스트할 객관식 크롭 이미지가 없습니다.',
            'missing' => $missingFiles
        ]);
        exit;
    }

    // Gemini API 단독 테스트 실행
    $startTime = microtime(true);
    $gradedResult = gradeObjectiveItems($testItems);
    $elapsed = round(microtime(true) - $startTime, 3);

    echo json_encode([
        'success'       => true,
        'elapsed_sec'   => $elapsed,
        'missing_files' => $missingFiles,
        'debug'         => $gradedResult['debug'] ?? [],
        'items'         => $gradedResult['items'] ?? []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'apply_to_db') {
    header('Content-Type: application/json; charset=utf-8');
    $resultId = (int)($_POST['result_id'] ?? 0);
    $gradedItemsJson = $_POST['items_json'] ?? '';
    $gradedItems = json_decode($gradedItemsJson, true);

    if (!$resultId || !is_array($gradedItems) || empty($gradedItems)) {
        echo json_encode(['success' => false, 'error' => '유효하지 않은 채점 데이터입니다.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT raw_results FROM exam_results WHERE id = ?");
    $stmt->execute([$resultId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => '시험 결과를 찾을 수 없습니다.']);
        exit;
    }

    $rawList = json_decode($existing['raw_results'], true);
    if (!is_array($rawList) && isset($rawList['items'])) $rawList = $rawList['items'];
    $rawList = is_array($rawList) ? $rawList : [];

    $gradedMap = [];
    foreach ($gradedItems as $g) {
        $gradedMap[(string)($g['id'] ?? $g['item_id'])] = $g;
    }

    $newTotal = 0;
    $pendingCount = 0;
    foreach ($rawList as &$it) {
        $idStr = (string)($it['id'] ?? $it['item_id']);
        if (isset($gradedMap[$idStr])) {
            $it['status']       = (int)$gradedMap[$idStr]['status'];
            $it['currentScore'] = (float)$gradedMap[$idStr]['currentScore'];
            $it['rawText']      = (string)$gradedMap[$idStr]['rawText'];
        }
        if ((int)($it['status'] ?? -1) === -1) {
            $pendingCount++;
        }
        $newTotal += (float)($it['currentScore'] ?? 0);
    }
    unset($it);

    $isReviewed = ($pendingCount === 0) ? 1 : 0;
    $upd = $pdo->prepare("UPDATE exam_results SET raw_results = :raw, total_score = :score, is_reviewed = :rev WHERE id = :id");
    $upd->execute([
        'raw'   => json_encode($rawList, JSON_UNESCAPED_UNICODE),
        'score' => $newTotal,
        'rev'   => $isReviewed,
        'id'    => $resultId
    ]);

    echo json_encode(['success' => true, 'new_total' => $newTotal, 'is_reviewed' => $isReviewed]);
    exit;
}

// ==========================================
// [화면 렌더링 준비]
// ==========================================
$assignId = (int)($_GET['assign_id'] ?? 0);
$selectedResultId = (int)($_GET['result_id'] ?? 0);

// 최근 제출된 시험지 20건 조회
$sqlList = "
    SELECT e.id, e.assignment_id, e.student_id, COALESCE(s.name, '학생') AS student_name, 
           COALESCE(t.title, '평가') AS exam_title, e.total_score, e.is_reviewed, e.submitted_at
    FROM exam_results e
    JOIN assignments a ON e.assignment_id = a.id
    LEFT JOIN templates t ON a.template_id = t.id
    LEFT JOIN students s ON e.student_id = s.id
";
if ($assignId > 0) {
    $sqlList .= " WHERE e.assignment_id = " . (int)$assignId;
}
$sqlList .= " ORDER BY e.id DESC LIMIT 20";
$recentList = $pdo->query($sqlList)->fetchAll(PDO::FETCH_ASSOC);

// 선택된 시험지 없으면 가장 최근 시험지로 자동 선택
if (!$selectedResultId && !empty($recentList)) {
    $selectedResultId = (int)$recentList[0]['id'];
}

$targetResult = null;
if ($selectedResultId) {
    $stmt = $pdo->prepare("
        SELECT e.*, COALESCE(s.name, '학생') AS student_name, COALESCE(t.title, '평가') AS exam_title, a.id AS assignment_id
        FROM exam_results e
        JOIN assignments a ON e.assignment_id = a.id
        LEFT JOIN templates t ON a.template_id = t.id
        LEFT JOIN students s ON e.student_id = s.id
        WHERE e.id = ?
    ");
    $stmt->execute([$selectedResultId]);
    $targetResult = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 문항 및 원본 이미지 분석
$parsedItems = [];
$originalImagePath = '';
$originalFileExists = false;
$originalFileSize = 0;

if ($targetResult) {
    $rawList = json_decode($targetResult['raw_results'], true);
    if (!is_array($rawList) && isset($rawList['items'])) {
        $rawList = $rawList['items'];
    }
    $parsedItems = is_array($rawList) ? $rawList : [];

    // 원본 시험지 경로 추출
    if (!empty($parsedItems[0]['image_path'])) {
        $originalImagePath = $parsedItems[0]['image_path'];
    } else {
        // uploads/originals 폴더에서 검색
        $origDir = APP_ROOT . '/public/uploads/originals/' . $targetResult['assignment_id'];
        if (is_dir($origDir)) {
            $files = glob($origDir . '/*.*');
            if (!empty($files)) {
                $originalImagePath = str_replace(APP_ROOT, '', $files[0]);
            }
        }
    }

    if ($originalImagePath && file_exists(APP_ROOT . $originalImagePath)) {
        $originalFileExists = true;
        $originalFileSize = round(filesize(APP_ROOT . $originalImagePath) / 1024, 1);
    }
}

// 환경 점검
$apiKey = getGeminiApiKey();
$maskedKey = $apiKey ? (substr($apiKey, 0, 7) . '...' . substr($apiKey, -4) . ' (' . strlen($apiKey) . '자)') : '❌ 등록 안 됨';
$gdSupport = extension_loaded('gd');
$jpegSupport = function_exists('imagecreatefromjpeg');
$curlSupport = function_exists('curl_init');
$logFile = APP_ROOT . '/gemini_debug.log';
$logExists = file_exists($logFile);
$logLines = [];
if ($logExists) {
    $rawLog = @file_get_contents($logFile);
    if ($rawLog) {
        $allLines = explode("\n", trim($rawLog));
        $logLines = array_slice($allLines, -40); // 최근 40줄
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>AI 자동 채점 파이프라인 정밀 진단실</title>
    <link rel="stylesheet" href="/public/assets/css/dashboard.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            margin: 0;
            padding: 24px;
        }
        .diag-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #1e293b;
            padding: 16px 24px;
            border-radius: 12px;
            border: 1px solid #334155;
            margin-bottom: 24px;
        }
        .diag-title { font-size: 1.35rem; font-weight: 800; color: #38bdf8; display: flex; align-items: center; gap: 8px; }
        .diag-select {
            background: #0f172a;
            color: #f8fafc;
            border: 1.5px solid #38bdf8;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 700;
        }
        .btn-top {
            padding: 8px 16px;
            background: #475569;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: 0.2s;
        }
        .btn-top:hover { background: #64748b; }

        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card-title {
            font-size: 1.12rem;
            font-weight: 800;
            margin-top: 0;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .env-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 700;
            margin-right: 8px;
            margin-bottom: 6px;
        }
        .badge-ok { background: #065f46; color: #34d399; }
        .badge-warn { background: #92400e; color: #fde047; }
        .badge-err { background: #991b1b; color: #fca5a5; }

        .grid-preview-box {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .preview-col {
            flex: 1;
            min-width: 320px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 14px;
        }
        .preview-img {
            max-width: 100%;
            max-height: 480px;
            border-radius: 8px;
            display: block;
            margin: 0 auto;
            border: 1px solid #475569;
            background: white;
        }

        .item-crops-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 14px;
        }
        .item-crop-card {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 12px;
        }
        .item-crop-card.is-obj { border-left: 4px solid #38bdf8; }
        .item-crop-card.is-subj { border-left: 4px solid #f59e0b; }
        .crop-thumb {
            width: 100%;
            height: 80px;
            object-fit: contain;
            background: white;
            border-radius: 4px;
            border: 1px solid #475569;
            margin: 8px 0;
            display: block;
        }

        .btn-run-test {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1.15rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(37,99,235,0.4);
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-run-test:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37,99,235,0.5);
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }
        .btn-run-test:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .log-terminal {
            background: #000000;
            color: #4ade80;
            font-family: Consolas, monospace;
            font-size: 0.84rem;
            line-height: 1.5;
            padding: 14px;
            border-radius: 8px;
            max-height: 280px;
            overflow-y: auto;
            border: 1px solid #22c55e;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .raw-json-box {
            background: #0b0f19;
            color: #e2e8f0;
            font-family: Consolas, monospace;
            font-size: 0.85rem;
            line-height: 1.4;
            padding: 14px;
            border-radius: 8px;
            max-height: 280px;
            overflow-y: auto;
            border: 1px solid #334155;
            white-space: pre-wrap;
            word-break: break-all;
        }

        .result-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
        }
        .result-table th, .result-table td {
            border: 1px solid #334155;
            padding: 10px;
            text-align: center;
        }
        .result-table th { background: #0f172a; color: #94a3b8; }
        .result-table td { background: #1e293b; }
        .score-pass { color: #34d399; font-weight: 800; }
        .score-fail { color: #f87171; font-weight: 800; }
    </style>
</head>
<body>

    <!-- 상단 헤더 및 시험지 선택 바 -->
    <div class="diag-header">
        <div class="diag-title">
            <span>🔬 AI 채점 파이프라인 정밀 진단실</span>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <label style="font-weight: 700; color: #94a3b8;">제출 시험지 선택:</label>
            <select class="diag-select" onchange="location.href='?assign_id=<?= $assignId ?>&result_id=' + this.value">
                <?php if (empty($recentList)): ?>
                    <option value="">제출된 시험지 없음</option>
                <?php else: ?>
                    <?php foreach ($recentList as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= ($r['id'] == $selectedResultId) ? 'selected' : '' ?>>
                            #<?= $r['id'] ?> - <?= e($r['student_name']) ?> (<?= e($r['exam_title']) ?>) | 총점: <?= $r['total_score'] ?>점 | <?= substr($r['submitted_at'], 5, 11) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <?php if ($assignId): ?>
                <a href="/views/grading/review.php?assign_id=<?= $assignId ?>" class="btn-top">⬅ 채점 검수판으로 복귀</a>
            <?php else: ?>
                <a href="/views/teacher/main.php" class="btn-top">🏠 교사 메인</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 0. 서버 환경 & API 키 점검 카드 -->
    <div class="card">
        <div class="card-title">
            <span>⚙️ 0단계: 서버 호스팅 & Gemini 환경 상태</span>
            <span style="font-size: 0.85rem; color: #94a3b8;">닷홈(Dothome) PHP 7.4 호환성</span>
        </div>
        <div>
            <span class="env-badge <?= $apiKey ? 'badge-ok' : 'badge-err' ?>">
                🔑 GEMINI_API_KEY: <?= e($maskedKey) ?>
            </span>
            <span class="env-badge <?= ($gdSupport && $jpegSupport) ? 'badge-ok' : 'badge-err' ?>">
                🖼️ GD JPEG 라이브러리: <?= ($gdSupport && $jpegSupport) ? '지원됨 (OK)' : '미지원 (경고)' ?>
            </span>
            <span class="env-badge <?= $curlSupport ? 'badge-ok' : 'badge-err' ?>">
                🌐 cURL 통신: <?= $curlSupport ? '정상 (OK)' : '미지원' ?>
            </span>
            <span class="env-badge badge-ok">
                🤖 타겟 모델: v1 & v1beta 다변화 자동 폴백
            </span>
            <button type="button" class="btn-top" style="background: #0284c7; padding: 4px 12px; font-size: 0.82rem;" onclick="checkGoogleModels()">
                📋 내 API 키로 사용 가능한 Google 모델 실시간 조회
            </button>
            <div id="modelsResultBox" style="display:none; margin-top:10px; font-size:0.85rem; background:#0f172a; padding:10px; border-radius:6px; border:1px solid #0284c7;"></div>
        </div>
    </div>

    <?php if (!$targetResult): ?>
        <div class="card" style="text-align:center; padding: 50px;">
            <h3>⚠️ 점검할 시험지 제출 내역이 없습니다.</h3>
            <p style="color: #94a3b8;">학생 화면에서 시험지를 1건 이상 제출한 후 진단실을 다시 열어주세요.</p>
        </div>
    <?php else: ?>

        <!-- 1. 원본 시험지 및 문항 크롭 상태 점검 -->
        <div class="card">
            <div class="card-title">
                <span>📄 1단계: 원본 시험지 & 저장 경로 검증</span>
                <span style="font-size: 0.88rem; color: #38bdf8;">
                    학생: <b><?= e($targetResult['student_name']) ?></b> (과제 #<?= $targetResult['assignment_id'] ?>) | 제출: <?= $targetResult['submitted_at'] ?>
                </span>
            </div>

            <div class="grid-preview-box">
                <!-- 원본 시험지 이미지 -->
                <div class="preview-col">
                    <div style="font-weight: 700; margin-bottom: 8px; color: #94a3b8;">
                        📸 학생 제출 원본 이미지
                        <span class="env-badge <?= $originalFileExists ? 'badge-ok' : 'badge-err' ?>" style="float: right;">
                            <?= $originalFileExists ? "서버 존재 ({$originalFileSize} KB)" : "파일 누락!" ?>
                        </span>
                    </div>
                    <?php if ($originalFileExists): ?>
                        <a href="<?= e($originalImagePath) ?>" target="_blank" title="클릭하여 원본 크게 보기">
                            <img src="<?= e($originalImagePath) ?>" class="preview-img" alt="원본 시험지">
                        </a>
                        <div style="font-size: 0.78rem; color: #64748b; margin-top: 6px; text-align: center; word-break: break-all;">
                            경로: <?= e($originalImagePath) ?>
                        </div>
                    <?php else: ?>
                        <div style="height: 200px; display: flex; align-items: center; justify-content: center; color: #f87171; background: #000;">
                            ❌ 서버에 원본 시험지 파일이 존재하지 않습니다.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 문항별 크롭 및 현재 DB 상태 -->
                <div class="preview-col" style="flex: 2;">
                    <div style="font-weight: 700; margin-bottom: 8px; color: #94a3b8;">
                        ✂️ 문항별 크롭 이미지 및 현재 DB 인식 상태 (총 <?= count($parsedItems) ?>문항)
                    </div>
                    <div class="item-crops-grid">
                        <?php foreach ($parsedItems as $it): ?>
                            <?php 
                                $isSubj = ($it['type'] ?? '') === 'subjective' || in_array($it['type'] ?? '', ['essay', 'short', 'text', '주관식']);
                                $cUrl = $it['crop_url'] ?? $it['crop_image_url'] ?? '';
                                $cExists = $cUrl && file_exists(APP_ROOT . $cUrl);
                                $cSize = $cExists ? round(filesize(APP_ROOT . $cUrl)/1024, 1) : 0;
                            ?>
                            <div class="item-crop-card <?= $isSubj ? 'is-subj' : 'is-obj' ?>">
                                <div style="display:flex; justify-content:space-between; font-size:0.85rem; font-weight:700;">
                                    <span><?= (int)($it['id'] ?? $it['item_id']) ?>번 [<?= $isSubj ? '주관식' : '객관식' ?>]</span>
                                    <span style="color:#f59e0b;">배점: <?= $it['score'] ?? 0 ?>점</span>
                                </div>
                                <?php if ($cExists): ?>
                                    <img src="<?= e($cUrl) ?>" class="crop-thumb" alt="크롭">
                                <?php else: ?>
                                    <div style="height:80px; background:#1e293b; color:#ef4444; font-size:0.75rem; display:flex; align-items:center; justify-content:center;">
                                        ❌ 크롭 파일 없음
                                    </div>
                                <?php endif; ?>
                                <div style="font-size:0.78rem; color:#cbd5e1; line-height: 1.4;">
                                    <div>모범답안: <b><?= e($it['answer'] ?? '-') ?></b></div>
                                    <div>인식된값: <b style="color:#38bdf8;"><?= e($it['rawText'] ?? '(미채점)') ?></b></div>
                                    <div>상태코드: <?= (int)($it['status'] ?? -1) ?> | 획득점수: <?= $it['currentScore'] ?? 0 ?>점</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Gemini API 단독 테스트 실행 & 정밀 결과 카드 -->
        <div class="card" style="border-color: #3b82f6;">
            <div class="card-title">
                <span style="color: #60a5fa;">🚀 2단계: Google Gemini API 단독 호출 & 실시간 응답 진단</span>
                <span style="font-size: 0.85rem; color: #94a3b8;">클릭 시 서버에서 크롭을 FFDH 합성하여 Gemini로 전송하고 결과를 100% 투명하게 분석합니다.</span>
            </div>

            <button type="button" class="btn-run-test" id="btnRunTest" onclick="runGeminiDiagnosis(<?= $selectedResultId ?>)">
                <span id="btnIcon">⚡</span>
                <span id="btnText">지금 Gemini API 호출 및 채점 진단 실행하기</span>
            </button>

            <!-- 진단 결과 컨테이너 (초기 숨김) -->
            <div id="diagResultArea" style="display:none; margin-top: 24px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <div style="font-size:1.1rem; font-weight:800; color:#f8fafc;">
                        📊 실시간 진단 결과: 
                        <span id="resHttpStatus" class="env-badge">대기 중</span>
                        <span id="resElapsed" style="font-size:0.85rem; color:#94a3b8;"></span>
                    </div>
                    <button type="button" id="btnApplyDb" class="btn-top" style="background:#059669; display:none;" onclick="applyDiagnosisResultToDb(<?= $selectedResultId ?>)">
                        💾 이 채점 결과를 데이터베이스에 즉시 반영하기
                    </button>
                </div>

                <div class="grid-preview-box">
                    <!-- 실제 생성된 FFDH 워터마크 합성 이미지 -->
                    <div class="preview-col" style="flex: 1;">
                        <div style="font-weight: 700; margin-bottom: 8px; color: #38bdf8;">
                            🖼️ Gemini에 실제 전송된 FFDH 합성 이미지 (워터마크 포함)
                        </div>
                        <img id="resGridImg" src="" class="preview-img" style="display:none;" alt="합성 이미지">
                        <div id="resGridImgEmpty" style="color:#94a3b8; font-size:0.85rem; padding:20px; text-align:center;">
                            합성 이미지가 아직 생성되지 않았습니다.
                        </div>
                    </div>

                    <!-- Gemini Raw Response 응답 원문 -->
                    <div class="preview-col" style="flex: 1.5;">
                        <div style="font-weight: 700; margin-bottom: 8px; color: #38bdf8;">
                            📡 Google Gemini API 응답 원문 (Raw JSON / Error)
                        </div>
                        <pre class="raw-json-box" id="resRawJson">결과 대기 중...</pre>
                    </div>
                </div>

                <!-- 문항별 채점 시뮬레이션 표 -->
                <div style="margin-top: 20px;">
                    <div style="font-weight: 700; margin-bottom: 8px; color: #38bdf8;">
                        📋 문항별 판정 및 채점 시뮬레이션 결과
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="result-table">
                            <thead>
                                <tr>
                                    <th>문항 번호</th>
                                    <th>모범 답안</th>
                                    <th>Gemini 판독 손글씨</th>
                                    <th>채점 판정 (O / X)</th>
                                    <th>부여 점수</th>
                                </tr>
                            </thead>
                            <tbody id="resTableBody">
                                <tr><td colspan="5" style="color:#94a3b8;">테스트를 실행하면 결과가 여기에 표시됩니다.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>

    <!-- 3. 서버 최근 로그 (gemini_debug.log) 실시간 뷰어 -->
    <div class="card">
        <div class="card-title">
            <span style="color: #4ade80;">📜 3단계: 서버 디버그 로그 뷰어 (`gemini_debug.log`)</span>
            <div>
                <button class="btn-top" onclick="location.reload()" style="padding:4px 10px; font-size:0.8rem;">🔄 새로고침</button>
                <button class="btn-top" onclick="clearDebugLog()" style="padding:4px 10px; font-size:0.8rem; background:#dc2626;">🗑️ 로그 비우기</button>
            </div>
        </div>
        <div class="log-terminal" id="logTerminal">
            <?php if (empty($logLines)): ?>
[기록된 디버그 로그가 없습니다. (정상 작동 중이거나 로그가 비어 있음)]
            <?php else: ?>
<?= htmlspecialchars(implode("\n", $logLines)) ?>
            <?php endif; ?>
        </div>
    </div>

<script>
let lastGradedItems = null;

// [Gemini API 정밀 진단 실행]
function runGeminiDiagnosis(resultId) {
    const btn = document.getElementById('btnRunTest');
    const btnIcon = document.getElementById('btnIcon');
    const btnText = document.getElementById('btnText');
    const area = document.getElementById('diagResultArea');

    btn.disabled = true;
    btnIcon.innerText = "⏳";
    btnText.innerText = "Gemini API와 통신 중입니다... (약 3~5초 소요)";
    area.style.display = 'block';

    const formData = new FormData();
    formData.append('action', 'test_gemini');
    formData.append('result_id', resultId);

    fetch('?action=test_gemini', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btnIcon.innerText = "⚡";
        btnText.innerText = "다시 진단 실행하기";

        if (!data.success) {
            alert("진단 실행 중 오류 발생:\n" + (data.error || '알 수 없는 오류'));
            document.getElementById('resHttpStatus').className = 'env-badge badge-err';
            document.getElementById('resHttpStatus').innerText = '실패';
            document.getElementById('resRawJson').innerText = JSON.stringify(data, null, 2);
            return;
        }

        const debug = data.debug || {};
        const items = data.items || [];
        lastGradedItems = items;

        // 1. 소요 시간 및 상태
        document.getElementById('resElapsed').innerText = `(통신 소요 시간: ${data.elapsed_sec}초)`;
        const statusSpan = document.getElementById('resHttpStatus');
        if (debug.error) {
            statusSpan.className = 'env-badge badge-err';
            statusSpan.innerText = '오류 발생 (Fallback)';
        } else {
            statusSpan.className = 'env-badge badge-ok';
            statusSpan.innerText = '성공 (HTTP 200 OK)';
            document.getElementById('btnApplyDb').style.display = 'inline-block';
        }

        // 2. 합성 이미지 표시
        const gridImg = document.getElementById('resGridImg');
        const emptyGrid = document.getElementById('resGridImgEmpty');
        if (debug.grid_image) {
            gridImg.src = debug.grid_image;
            gridImg.style.display = 'block';
            emptyGrid.style.display = 'none';
        } else {
            gridImg.style.display = 'none';
            emptyGrid.style.display = 'block';
        }

        // 3. Raw Response 표시
        let rawDisplay = '';
        if (debug.model_used) {
            rawDisplay += `[✅ 성공 연결 모델: ${debug.model_used}]\n\n`;
        }
        if (debug.gemini_raw) {
            rawDisplay += debug.gemini_raw;
        } else if (debug.error) {
            rawDisplay += debug.error;
        }
        if (debug.attempts && debug.attempts.length > 0) {
            rawDisplay += `\n\n[📋 모델별 호출 시도 로그]\n` + debug.attempts.join('\n');
        }
        document.getElementById('resRawJson').innerText = rawDisplay || JSON.stringify(debug, null, 2);

        // 4. 채점 판정 표 생성
        const tbody = document.getElementById('resTableBody');
        tbody.innerHTML = '';
        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="color:#ef4444;">채점된 문항이 없습니다. (크롭 이미지 누락 또는 API 오류)</td></tr>';
        } else {
            items.forEach(it => {
                const tr = document.createElement('tr');
                const isPass = (it.status === 1);
                tr.innerHTML = `
                    <td style="font-weight:700;">${it.id}번</td>
                    <td><b>${it.answer || '-'}</b></td>
                    <td style="font-weight:700; color:#38bdf8;">${it.rawText || '미기재'}</td>
                    <td class="${isPass ? 'score-pass' : 'score-fail'}">${isPass ? '✅ 정답 (O)' : '❌ 오답 (X)'}</td>
                    <td style="font-weight:700;">${it.currentScore}점 / ${it.score}점</td>
                `;
                tbody.appendChild(tr);
            });
        }
    })
    .catch(err => {
        btn.disabled = false;
        btnIcon.innerText = "⚡";
        btnText.innerText = "다시 진단 실행하기";
        alert("네트워크 통신 오류: " + err.message);
    });
}

// [진단 결과를 DB에 즉시 적용]
function applyDiagnosisResultToDb(resultId) {
    if (!lastGradedItems || lastGradedItems.length === 0) {
        alert("적용할 채점 데이터가 없습니다.");
        return;
    }

    if (!confirm("이 채점 결과를 실제 학생 시험 결과 데이터베이스에 영구 반영하시겠습니까?")) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'apply_to_db');
    formData.append('result_id', resultId);
    formData.append('items_json', JSON.stringify(lastGradedItems));

    fetch('?action=apply_to_db', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(`성공적으로 DB에 반영되었습니다!\n총점: ${data.new_total}점`);
            location.reload();
        } else {
            alert("DB 반영 실패: " + (data.error || '알 수 없는 오류'));
        }
    })
    .catch(err => alert("네트워크 오류: " + err.message));
}

// [디버그 로그 비우기]
function clearDebugLog() {
    if (!confirm("서버의 디버그 로그(gemini_debug.log)를 모두 비우시겠습니까?")) return;

    fetch('?action=clear_log', { method: 'POST' })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('logTerminal').innerText = '[로그가 초기화되었습니다.]';
            }
        });
}

// [Google 모델 목록 실시간 조회]
function checkGoogleModels() {
    const box = document.getElementById('modelsResultBox');
    box.style.display = 'block';
    box.innerHTML = '⏳ Google 서버에서 지원 모델 목록을 조회 중입니다...';

    fetch('?action=list_models')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                box.innerHTML = `<span style="color:#ef4444;">❌ 실패: ${data.error}</span>`;
                return;
            }
            let html = '<b>Google 응답 결과:</b><br>';
            for (let ver in data.data) {
                const info = data.data[ver];
                html += `<div style="margin-top:6px;"><b>[${ver}]</b> HTTP ${info.http_code}: `;
                if (info.models && info.models.length > 0) {
                    html += `<span style="color:#34d399; font-weight:700;">${info.models.join(', ')}</span>`;
                } else if (info.error) {
                    html += `<span style="color:#f87171;">${info.error}</span>`;
                } else {
                    html += `<span style="color:#94a3b8;">generateContent 지원 모델 없음</span>`;
                }
                html += `</div>`;
            }
            box.innerHTML = html;
        })
        .catch(err => {
            box.innerHTML = `<span style="color:#ef4444;">네트워크 통신 오류: ${err.message}</span>`;
        });
}
</script>

</body>
</html>
