<?php
/**
 * views/grading/roi_inspector.php
 * ROI 패킹, 전처리 및 Gemini 비전 인식 검증 도구
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

requireTeacherAuth();

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// FFDH 패킹 기반 2D 이미지 합성 함수
function createObjectiveCompositeImage($srcImg, $objectiveItems, $scale, $uploadDir) {
    $imgW = imagesx($srcImg);
    $imgH = imagesy($srcImg);

    $fontPath = APP_ROOT . '/public/assets/fonts/NanumGothic.ttf';
    if (!file_exists($fontPath)) {
        $fontPath = null;
        $possibleFonts = [
            'C:/Windows/Fonts/malgun.ttf',
            '/usr/share/fonts/truetype/nanum/NanumGothic.ttf',
            '/usr/share/fonts/google-noto/NotoSansKR-Regular.ttf'
        ];
        foreach ($possibleFonts as $f) {
            if (file_exists($f)) { $fontPath = $f; break; }
        }
    }

    $crops = [];

    foreach ($objectiveItems as $item) {
        $itemId = $item['id'];
        $ans = (string)($item['answer'] ?? '');
        $roi = $item['ans_roi'] ?? $item;

        $cropX = max(0, min((int)(($roi['x'] ?? 0) * $scale), $imgW - 10));
        $cropY = max(0, min((int)(($roi['y'] ?? 0) * $scale), $imgH - 10));
        $cropW = max(10, min((int)(($roi['w'] ?? 0) * $scale), $imgW - $cropX));
        $cropH = max(10, min((int)(($roi['h'] ?? 0) * $scale), $imgH - $cropY));

        $cropImg = imagecreatetruecolor($cropW, $cropH);
        imagecopyresampled($cropImg, $srcImg, 0, 0, $cropX, $cropY, $cropW, $cropH, $cropW, $cropH);

        // 파란색 워터마크 [Q번호]
        $blueColor = imagecolorallocate($cropImg, 0, 85, 255);
        $watermarkText = "[{$itemId}]";

        if ($fontPath) {
            imagettftext($cropImg, 11, 0, 3, 14, $blueColor, $fontPath, $watermarkText);
        } else {
            imagestring($cropImg, 3, 2, 2, $watermarkText, $blueColor);
        }

        $crops[] = [
            'item_id' => $itemId,
            'answer'  => $ans,
            'score'   => (int)($item['score'] ?? 10),
            'gd_img'  => $cropImg,
            'w'       => $cropW,
            'h'       => $cropH
        ];
    }

    if (empty($crops)) return null;

    // 세로 높이 내림차순 정렬 (FFDH)
    usort($crops, function($a, $b) {
        return $b['h'] <=> $a['h'];
    });

    $maxWLimit = 750;
    $padding = 3;
    $rows = [];
    $currentRow = ['items' => [], 'w' => 0, 'h' => 0];

    foreach ($crops as $c) {
        if (!empty($currentRow['items']) && ($currentRow['w'] + $c['w'] + $padding > $maxWLimit)) {
            $rows[] = $currentRow;
            $currentRow = ['items' => [], 'w' => 0, 'h' => 0];
        }

        $currentRow['items'][] = $c;
        $currentRow['w'] += $c['w'] + $padding;
        $currentRow['h'] = max($currentRow['h'], $c['h']);
    }
    if (!empty($currentRow['items'])) {
        $rows[] = $currentRow;
    }

    $maxRowW = 0;
    foreach ($rows as $r) {
        if ($r['w'] > $maxRowW) $maxRowW = $r['w'];
    }
    $totalW = max(300, $maxRowW + $padding);
    $totalH = array_sum(array_column($rows, 'h')) + (count($rows) * $padding) + $padding;

    $compositeImg = imagecreatetruecolor($totalW, $totalH);
    $bgColor = imagecolorallocate($compositeImg, 245, 247, 250);
    imagefill($compositeImg, 0, 0, $bgColor);

    $currY = $padding;
    foreach ($rows as $r) {
        $currX = $padding;
        foreach ($r['items'] as $item) {
            imagecopy($compositeImg, $item['gd_img'], $currX, $currY, 0, 0, $item['w'], $item['h']);
            imagedestroy($item['gd_img']);
            $currX += $item['w'] + $padding;
        }
        $currY += $r['h'] + $padding;
    }

    $cropDir = $uploadDir . 'crops/';
    if (!file_exists($cropDir)) @mkdir($cropDir, 0777, true);

    $timeTag = time();
    $origFilePath = $cropDir . 'composite_orig_' . $timeTag . '.jpg';
    $compFilePath = $cropDir . 'composite_compressed_' . $timeTag . '.jpg';

    imagejpeg($compositeImg, $origFilePath, 100);
    imagejpeg($compositeImg, $compFilePath, 25);
    imagedestroy($compositeImg);

    return [
        'orig_path'           => $origFilePath,
        'compressed_path'     => $compFilePath,
        'compressed_url'      => '/uploads/crops/composite_compressed_' . $timeTag . '.jpg',
        'orig_size_str'       => formatBytes(filesize($origFilePath)),
        'compressed_size_str' => formatBytes(filesize($compFilePath)),
        'crops'               => $crops
    ];
}

function callGeminiFlash($imagePath, $itemMetaStr, $objectiveItems) {
    $apiKey = env('GEMINI_API_KEY');

    if (empty($apiKey)) {
        return ['status' => 'error', 'data' => '[오류] .env 파일에 GEMINI_API_KEY가 없습니다.'];
    }

    if (!file_exists($imagePath)) {
        return ['status' => 'error', 'data' => '[오류] 합성 이미지 파일이 없습니다.'];
    }

    $imageData = base64_encode(file_get_contents($imagePath));

    $properties = [];
    $requiredKeys = [];

    foreach ($objectiveItems as $item) {
        $itemIdStr = (string)$item['id'];
        $properties[$itemIdStr] = ['type' => 'STRING'];
        $requiredKeys[] = $itemIdStr;
    }

    $prompt = "문항 번호(파란색 워터마크)와 [문항정보] 조건을 결합해 판독하라.\n\n"
            . "- 빈칸: '미기재'\n"
            . "- 불분명: '판독 불가'\n"
            . "- 복수: 쉼표(,) 구분\n\n"
            . "[문항정보] 번호:답개수:유형(N=숫자,S=문자)\n"
            . $itemMetaStr;

    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt],
                    [
                        "inline_data" => [
                            "mime_type" => "image/jpeg",
                            "data" => $imageData
                        ]
                    ]
                ]
            ]
        ],
        "generationConfig" => [
            "response_mime_type" => "application/json",
            "response_schema"    => [
                "type"       => "OBJECT",
                "properties" => $properties,
                "required"   => $requiredKeys
            ]
        ]
    ];

    $model = env('GEMINI_MODEL', 'gemini-3.6-flash');
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['status' => 'error', 'data' => "[cURL 오류] " . $curlErr];
    }

    if ($httpCode !== 200) {
        return ['status' => 'error', 'data' => "[HTTP {$httpCode}] " . $response];
    }

    $resData = json_decode($response, true);
    $rawText = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $parsed = json_decode($rawText, true);

    return [
        'status'       => 'success',
        'raw_json_str' => $rawText,
        'parsed_json'  => is_array($parsed) ? $parsed : []
    ];
}

$fixedTemplateId = 12;
$pipelineResult = null;

$students = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM students ORDER BY id ASC");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $students = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['std_image'])) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $file = $_FILES['std_image'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $uploadDir = APP_ROOT . '/uploads/';
        if (!file_exists($uploadDir)) @mkdir($uploadDir, 0777, true);

        $stdFilename = 'std_gemini_' . time() . '_' . $studentId . '.jpg';
        $stdPath = $uploadDir . $stdFilename;

        if (move_uploaded_file($file['tmp_name'], $stdPath)) {
            $templateItems = [];
            $stmt = $pdo->prepare("SELECT roi_data FROM templates WHERE id = ?");
            $stmt->execute([$fixedTemplateId]);
            $tmplRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($tmplRow && !empty($tmplRow['roi_data'])) {
                $decoded = json_decode($tmplRow['roi_data'], true);
                if (is_array($decoded)) {
                    $templateItems = $decoded['items'] ?? $decoded['rois'] ?? $decoded;
                }
            }

            $objectiveItems = array_values(array_filter($templateItems, function($i) {
                return ($i['type'] ?? 'objective') === 'objective';
            }));

            $metaParts = [];
            foreach ($objectiveItems as $item) {
                $itemId = $item['id'];
                $ans = (string)($item['answer'] ?? '');
                
                $ansCount = max(1, !empty($ans) ? count(array_filter(array_map('trim', explode(',', $ans)))) : 1);
                $isNumeric = preg_match('/^[0-9\s,.-]+$/u', $ans);
                $typeCode = $isNumeric ? 'N' : 'S';

                $metaParts[] = "{$itemId}:{$ansCount}:{$typeCode}";
            }
            $itemMetaStr = implode(', ', $metaParts);

            $srcImg = @imagecreatefromstring(file_get_contents($stdPath));
            if ($srcImg) {
                $imgW = imagesx($srcImg);
                $scale = $imgW / 1000;

                $compResult = createObjectiveCompositeImage($srcImg, $objectiveItems, $scale, $uploadDir);
                imagedestroy($srcImg);

                if ($compResult) {
                    $geminiRes = callGeminiFlash($compResult['compressed_path'], $itemMetaStr, $objectiveItems);
                    $extractedMap = $geminiRes['parsed_json'] ?? [];

                    $finalItems = [];
                    foreach ($compResult['crops'] as $c) {
                        $itemId = $c['item_id'];
                        $extAns = trim((string)($extractedMap[$itemId] ?? $extractedMap[(string)$itemId] ?? '미기재'));
                        $modelAns = trim($c['answer']);

                        $isCorrect = false;
                        $statusLabel = '';
                        $statusClass = '';

                        if ($extAns === '미기재') {
                            $statusLabel = '⚪ 안 푼 문제';
                            $statusClass = 'badge bg-secondary fs-6';
                        } elseif ($extAns === '판독 불가') {
                            $statusLabel = '⚠️ 오류';
                            $statusClass = 'badge bg-warning text-dark fs-6';
                        } else {
                            $extArr = array_values(array_filter(array_map('trim', explode(',', $extAns)), 'strlen'));
                            $modelArr = array_values(array_filter(array_map('trim', explode(',', $modelAns)), 'strlen'));

                            sort($extArr);
                            sort($modelArr);

                            if ($extArr === $modelArr) {
                                $isCorrect = true;
                                $statusLabel = '⭕ 정답';
                                $statusClass = 'text-success fw-bold fs-5';
                            } else {
                                $statusLabel = '❌ 오답';
                                $statusClass = 'text-danger fw-bold fs-5';
                            }
                        }

                        $finalItems[] = [
                            'item_id'      => $itemId,
                            'info_text'    => "[{$itemId}] 워터마크",
                            'extracted'    => $extAns,
                            'model_ans'    => $modelAns,
                            'is_correct'   => $isCorrect,
                            'status_label' => $statusLabel,
                            'status_class' => $statusClass
                        ];
                    }

                    usort($finalItems, function($a, $b) {
                        return $a['item_id'] <=> $b['item_id'];
                    });

                    $pipelineResult = [
                        'composite_url'      => $compResult['compressed_url'],
                        'orig_size_str'      => $compResult['orig_size_str'],
                        'compressed_size_str'=> $compResult['compressed_size_str'],
                        'item_meta_str'      => $itemMetaStr,
                        'gemini_raw'         => $geminiRes['raw_json_str'] ?? $geminiRes['data'],
                        'api_status'         => $geminiRes['status'],
                        'items'              => $finalItems
                    ];
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>ROI Inspector (Gemini 2.5 Flash)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .card-stage { border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        .raw-code { background-color: #1b1d21; color: #00ff66; font-family: monospace; font-size: 13px; max-height: 350px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; }
        .preview-box { border: 1px solid #ced4da; background: #fff; text-align: center; padding: 8px; border-radius: 4px; }
        .preview-box img, .preview-box canvas { max-width: 100%; height: auto; max-height: 350px; }
        .composite-img { max-width: 100%; height: auto; border: 1px solid #ced4da; border-radius: 4px; }
    </style>
    <script async src="https://docs.opencv.org/4.x/opencv.js" onload="onOpenCvReady()"></script>
</head>
<body>
<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="fw-bold m-0">🛠️ ROI Inspector <small class="fs-6 text-muted">(Key-Value 스키마 & 정답 정렬 검증)</small></h3>
        <div>
            <span id="cvStatusBadge" class="badge bg-secondary fs-6">⏳ OpenCV.js 로딩 중...</span>
            <span class="badge bg-primary fs-6 ms-2">고정 템플릿 ID: 12</span>
        </div>
    </div>

    <!-- 1. 입력 폼 -->
    <div class="card card-stage">
        <div class="card-body">
            <form id="inspectorForm" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold">학생 선택</label>
                    <select id="studentSelect" name="student_id" class="form-select" required>
                        <option value="">-- 학생 선택 --</option>
                        <?php foreach ($students as $st): ?>
                            <option value="<?= $st['id'] ?>">[ID: <?= $st['id'] ?>] <?= e($st['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">시험지 사진 선택</label>
                    <input type="file" id="imageInput" class="form-control" accept="image/*" required>
                </div>
                <div class="col-md-2 text-center">
                    <span id="processSpinner" class="spinner-border spinner-border-sm text-primary d-none" role="status"></span>
                    <span id="processStatusText" class="text-muted small">대기 중</span>
                </div>
                <div class="col-md-3">
                    <button type="submit" id="submitBtn" class="btn btn-primary w-100 fw-bold" disabled>
                        ⚡ 원근 보정 & Gemini 판독 제출
                    </button>
                </div>
            </form>

            <div class="row mt-3 d-none" id="previewRow">
                <div class="col-md-6 text-center">
                    <small class="fw-bold text-secondary">1. 원본 촬영 이미지</small>
                    <div class="preview-box mt-1"><img id="rawPreviewImg" alt="Raw Preview"></div>
                </div>
                <div class="col-md-6 text-center">
                    <small class="fw-bold text-success">2. 원근 보정(OpenCV) Canvas</small>
                    <div class="preview-box mt-1"><canvas id="warpedCanvas"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 결과 표시 영역 -->
    <?php if ($pipelineResult): ?>
    <div class="row">
        <div class="col-md-6">
            <div class="card card-stage h-100">
                <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center">
                    <span>🖼️ FFDH 모음 이미지 (가로 750px 패킹)</span>
                    <a href="<?= e($pipelineResult['composite_url']) ?>" target="_blank" class="badge bg-light text-dark text-decoration-none">원본보기</a>
                </div>
                <div class="card-body text-center p-3">
                    <div class="mb-2">
                        <span class="badge bg-secondary me-2">압축 전: <?= e($pipelineResult['orig_size_str']) ?></span>
                        <span class="badge bg-success">75% 압축 후: <?= e($pipelineResult['compressed_size_str']) ?></span>
                    </div>
                    <img src="<?= e($pipelineResult['composite_url']) ?>" class="composite-img" alt="Compressed Objective Image">
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card card-stage h-100">
                <div class="card-header bg-info text-dark fw-bold d-flex justify-content-between">
                    <span>🤖 Gemini API 호출 정보</span>
                    <span class="badge bg-<?= $pipelineResult['api_status'] === 'success' ? 'success' : 'danger' ?>">
                        <?= e($pipelineResult['api_status']) ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="mb-2 fw-bold text-secondary"> 전송된 [문항정보] 메타데이터:</div>
                    <div class="p-2 mb-3 bg-light border rounded font-monospace small"><?= e($pipelineResult['item_meta_str']) ?></div>
                    <div class="fw-bold text-secondary mb-1">Raw JSON 응답 (Key-Value 객체):</div>
                    <pre class="raw-code p-3 rounded mb-0"><?= e($pipelineResult['gemini_raw']) ?></pre>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-stage mt-3">
        <div class="card-header bg-success text-white fw-bold">📊 문항별 자동 판독 및 채점 결과 표</div>
        <div class="card-body p-0">
            <table class="table table-hover table-striped align-middle mb-0 text-center">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 100px;">문항 번호</th>
                        <th style="width: 200px;">식별 표기</th>
                        <th>Gemini Extracted Answer</th>
                        <th>모범 답안 (`answer`)</th>
                        <th style="width: 140px;">판정</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pipelineResult['items'] as $item): ?>
                    <tr>
                        <td class="fw-bold fs-5">Q<?= e($item['item_id']) ?></td>
                        <td><span class="badge bg-secondary fs-6"><?= e($item['info_text']) ?></span></td>
                        <td><span class="badge bg-primary fs-5"><?= e($item['extracted']) ?></span></td>
                        <td><code><?= e($item['model_ans']) ?></code></td>
                        <td>
                            <span class="<?= e($item['status_class']) ?>"><?= e($item['status_label']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
let cvReady = false;

function onOpenCvReady() {
    cvReady = true;
    const badge = document.getElementById('cvStatusBadge');
    badge.className = "badge bg-success fs-6";
    badge.innerText = "🟢 OpenCV.js 준비 완료";
    document.getElementById('submitBtn').disabled = false;
}

document.getElementById('inspectorForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    if (!cvReady) {
        alert("OpenCV.js 로딩 중입니다.");
        return;
    }

    const studentId = document.getElementById('studentSelect').value;
    const fileInput = document.getElementById('imageInput');

    if (!studentId || fileInput.files.length === 0) {
        alert("학생과 이미지를 선택해주세요.");
        return;
    }

    const spinner = document.getElementById('processSpinner');
    const statusText = document.getElementById('processStatusText');
    const submitBtn = document.getElementById('submitBtn');

    spinner.classList.remove('d-none');
    submitBtn.disabled = true;
    statusText.innerText = "이미지 분석 중...";

    const file = fileInput.files[0];
    const imgEl = document.getElementById('rawPreviewImg');
    imgEl.src = URL.createObjectURL(file);

    imgEl.onload = function() {
        statusText.innerText = "OpenCV 원근 보정 수행 중...";
        document.getElementById('previewRow').classList.remove('d-none');

        setTimeout(() => {
            try {
                const fullCanvas = document.createElement('canvas');
                const fullW = imgEl.naturalWidth || imgEl.width;
                const fullH = imgEl.naturalHeight || imgEl.height;
                fullCanvas.width = fullW;
                fullCanvas.height = fullH;

                const ctx = fullCanvas.getContext('2d');
                ctx.drawImage(imgEl, 0, 0, fullW, fullH);

                const canvas = processOpenCVUnscaled(fullCanvas, fullW, fullH);
                statusText.innerText = "Gemini 판독 요청 중...";

                canvas.toBlob((blob) => {
                    const formData = new FormData();
                    formData.append('student_id', studentId);
                    formData.append('std_image', blob, 'std_warped.jpg');

                    fetch(location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(html => {
                        document.open();
                        document.write(html);
                        document.close();
                    })
                    .catch(err => {
                        alert("전송 오류: " + err);
                        spinner.classList.add('d-none');
                        submitBtn.disabled = false;
                    });
                }, 'image/jpeg', 0.95);

            } catch (err) {
                alert("OpenCV 변환 오류: " + err.message);
                spinner.classList.add('d-none');
                submitBtn.disabled = false;
            }
        }, 100);
    };
});

function processOpenCVUnscaled(srcCanvas, naturalW, naturalH) {
    let src = cv.imread(srcCanvas);
    let dst = new cv.Mat();
    let gray = new cv.Mat();
    let blurred = new cv.Mat();
    let edged = new cv.Mat();

    const targetW = Math.max(2000, naturalW);
    const targetH = Math.round(targetW * 1.414);

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
        if (area > 20000) {
            let peri = cv.arcLength(cnt, true);
            let approx = new cv.Mat();
            cv.approxPolyDP(cnt, approx, 0.02 * peri, true);
            if (approx.rows === 4 && area > maxArea) {
                maxArea = area;
                if (maxContour) maxContour.delete();
                maxContour = approx;
            } else {
                approx.delete();
            }
        }
    }

    const warpedCanvas = document.getElementById('warpedCanvas');
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
        let dsize = new cv.Size(targetW, targetH);
        cv.resize(src, dst, dsize, 0, 0, cv.INTER_LINEAR);
        cv.imshow(warpedCanvas, dst);
    }

    src.delete(); dst.delete(); gray.delete(); blurred.delete(); edged.delete(); contours.delete(); hierarchy.delete();

    return warpedCanvas;
}
</script>
</body>
</html>
