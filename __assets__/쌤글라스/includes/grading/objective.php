<?php
/**
 * includes/grading/objective.php
 * Gemini API 기반 객관식 OMR 크롭 합성 및 지능형 자동 채점 모듈
 */

require_once dirname(__DIR__, 2) . '/config/env.php';

function getGeminiApiKey(): string {
    $key = (string)env('GEMINI_API_KEY', '');
    if (empty($key)) {
        $key = (string)env('GOOGLE_API_KEY', '');
    }
    return $key;
}

function gradeObjectiveItems(array $items): array {
    if (empty($items)) {
        return ['items' => [], 'debug' => []];
    }

    // 폰트 경로 (public/assets/fonts/NanumGothic.ttf 우선)
    $fontPath = APP_ROOT . '/public/assets/fonts/NanumGothic.ttf';
    if (!file_exists($fontPath)) {
        $fontPath = null;
        $possibleFonts = [
            'C:/Windows/Fonts/malgun.ttf',
            '/usr/share/fonts/truetype/nanum/NanumGothic.ttf',
            '/usr/share/fonts/google-noto/NotoSansKR-Regular.ttf'
        ];
        foreach ($possibleFonts as $f) {
            if (file_exists($f)) {
                $fontPath = $f;
                break;
            }
        }
    }

    $crops = [];
    $map = []; 
    $metaParts = [];
    $properties = [];
    $requiredKeys = [];

    // 1. 크롭 이미지 준비 및 동적 메타데이터/스키마 생성
    foreach ($items as $item) {
        $id = $item['id'];
        $map[$id] = $item;

        $ans = trim((string)($item['answer'] ?? ''));
        if ($ans === '') {
            $ansCount = 1;
            $typeCode = 'S'; // 정답 미지정 시 기본 문자형
        } else {
            $ansArr = array_values(array_filter(array_map('trim', explode(',', $ans)), 'strlen'));
            $ansCount = max(1, count($ansArr));
            $isNumeric = preg_match('/^[0-9\s,.-]+$/u', $ans);
            $typeCode = $isNumeric ? 'N' : 'S';
        }
        $metaParts[] = "{$id}:{$ansCount}:{$typeCode}";

        // JSON 스키마 동적 구성
        $properties[(string)$id] = ['type' => 'STRING'];
        $requiredKeys[] = (string)$id;

        // 크롭 이미지 복사 및 워터마크 그려두기 (PHP 7.x resource 및 PHP 8.x GdImage 모두 호환)
        $isCompositeItem = !empty($item['is_composite']) && !empty($item['image_res_arr']) && is_array($item['image_res_arr']) && count($item['image_res_arr']) >= 2;

        if ($isCompositeItem) {
            // 복합 다답형: 복수 크롭을 가로로 타일 합성하여 단일 이미지로 묶음
            $subCrops = $item['image_res_arr'];
            $padding = 4;
            $totalSubW = array_sum(array_map('imagesx', $subCrops)) + $padding * (count($subCrops) + 1);
            $maxSubH = max(array_map('imagesy', $subCrops));
            $totalSubH = $maxSubH + $padding * 2;
            $combinedImg = imagecreatetruecolor($totalSubW, $totalSubH);
            $bgC = imagecolorallocate($combinedImg, 245, 247, 250);
            imagefill($combinedImg, 0, 0, $bgC);
            $blueColor = imagecolorallocate($combinedImg, 0, 85, 255);
            $currSubX = $padding;
            foreach ($subCrops as $si => $subCrop) {
                $subW = imagesx($subCrop);
                $subH = imagesy($subCrop);
                imagecopy($combinedImg, $subCrop, $currSubX, $padding, 0, 0, $subW, $subH);
                // 소분류 워터마크: [3-A], [3-B] 형태
                $subLabel = "[{$id}-" . chr(65 + $si) . "]";
                if ($fontPath) {
                    imagettftext($combinedImg, 7, 0, $currSubX + 2, $padding + 9, $blueColor, $fontPath, $subLabel);
                } else {
                    imagestring($combinedImg, 1, $currSubX + 2, $padding + 1, $subLabel, $blueColor);
                }
                $currSubX += $subW + $padding;
            }
            // 전체 묶음 워터마크: [3] 번호 표시
            $watermarkText = "[{$id}]";
            if ($fontPath) {
                imagettftext($combinedImg, 8, 0, 2, 9, $blueColor, $fontPath, $watermarkText);
            } else {
                imagestring($combinedImg, 2, 2, 1, $watermarkText, $blueColor);
            }
            $crops[] = [
                'id'     => $id,
                'gd_img' => $combinedImg,
                'w'      => $totalSubW,
                'h'      => $totalSubH
            ];
        } elseif (!empty($item['image_res']) && (is_resource($item['image_res']) || is_object($item['image_res']))) {
            $srcW = imagesx($item['image_res']);
            $srcH = imagesy($item['image_res']);

            $cropImg = imagecreatetruecolor($srcW, $srcH);
            imagecopy($cropImg, $item['image_res'], 0, 0, 0, 0, $srcW, $srcH);

            $blueColor = imagecolorallocate($cropImg, 0, 85, 255);
            $watermarkText = "[{$id}]";

            if ($fontPath) {
                imagettftext($cropImg, 8, 0, 2, 9, $blueColor, $fontPath, $watermarkText);
            } else {
                imagestring($cropImg, 2, 2, 1, $watermarkText, $blueColor);
            }

            $crops[] = [
                'id'     => $id,
                'gd_img' => $cropImg,
                'w'      => $srcW,
                'h'      => $srcH
            ];
        }
    }

    if (empty($crops)) {
        @file_put_contents(APP_ROOT . '/gemini_debug.log', date('Y-m-d H:i:s') . " [Empty Crops] items count: " . count($items) . "\n", FILE_APPEND);
        return ['items' => [], 'debug' => ['error' => '유효한 크롭 이미지가 없습니다.']];
    }

    $itemMetaStr = implode(', ', $metaParts);

    // 2. FFDH (First Fit Decreasing Height) 패킹 기반 합성 이미지 생성 (비율 유지)
    usort($crops, function($a, $b) {
        return $b['h'] <=> $a['h'];
    });

    // [동적 적응형 최적 가로폭 계산 알고리즘]
    // 문항 수와 평균 크롭 너비를 기반으로 최적 열(Column) 개수를 산출하여
    // 여백 낭비(Waste)를 최소화하고, Gemini 단일 타일(768x768) 내 정방형으로 패킹
    $cropCount = count($crops);
    $avgCropW = array_sum(array_column($crops, 'w')) / max(1, $cropCount);

    if ($cropCount <= 6) {
        $optimalCols = 2; // 6문항 이하: 2열 배치 (384x384 초저지연 영역 타겟)
    } elseif ($cropCount <= 14) {
        $optimalCols = 3; // 7~14문항: 3열 배치 (충진율 93% 극대화)
    } else {
        $optimalCols = 4; // 15문항 이상: 4열 배치 (세로 초과 방지)
    }
    $padding = 3;
    $calculatedMaxW = (int)ceil(($optimalCols * $avgCropW) + (($optimalCols + 1) * $padding));
    // Gemini 768x768 단일 타일(258 토큰) 경계를 넘지 않도록 740px로 안전 상한선 설정
    $maxWLimit = min(740, max(360, $calculatedMaxW));
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

    $gridImg = imagecreatetruecolor($totalW, $totalH);
    $bgColor = imagecolorallocate($gridImg, 245, 247, 250);
    imagefill($gridImg, 0, 0, $bgColor);

    $currY = $padding;
    foreach ($rows as $r) {
        $currX = $padding;
        foreach ($r['items'] as $c) {
            imagecopy($gridImg, $c['gd_img'], $currX, $currY, 0, 0, $c['w'], $c['h']);
            imagedestroy($c['gd_img']);
            $currX += $c['w'] + $padding;
        }
        $currY += $r['h'] + $padding;
    }

    // 3. API 전송용 고효율 인코딩 및 Base64 인코딩 (JPEG 70% 최적화: 용량 약 25~35KB, Base64 약 40KB로 초고속 전송)
    ob_start();
    imagejpeg($gridImg, null, 70);
    $jpegBinary = ob_get_clean();
    imagedestroy($gridImg);

    $rawBase64Data = base64_encode($jpegBinary); 
    $base64Grid = 'data:image/jpeg;base64,' . $rawBase64Data;

    // Fallback 헬퍼: Gemini API 오류 발생 시에도 모든 객관식 문항을 '판독대기(status: -1)' 상태로 보존
    $createFallbackResults = function(string $reason) use ($map): array {
        $fallback = [];
        foreach ($map as $id => $item) {
            $fallback[] = [
                'item_id'        => $id,
                'id'             => $id,
                'display_num'    => $item['display_num'] ?? (string)$id,
                'sub_id'         => $item['sub_id'] ?? null,
                'type'           => $item['type'] ?? 'objective',
                'score'          => $item['score'] ?? 0,
                'answer'         => trim((string)($item['answer'] ?? '')),
                'rawText'        => '판독대기',
                'status'         => -1, // 미채점(검수 필요) 상태
                'crop_url'       => $item['crop_url'] ?? '',
                'crop_image_url' => $item['crop_image_url'] ?? ''
            ];
        }
        return $fallback;
    };

    $apiKey = getGeminiApiKey();
    if (empty($apiKey)) {
        return [
            'items' => $createFallbackResults('API 키 누락'),
            'debug' => ['error' => '환경 변수에서 GEMINI_API_KEY를 찾을 수 없습니다.', 'api_status' => 'fallback']
        ];
    }

    // 4. Gemini API 요청 구성 및 전송
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
                            "data" => $rawBase64Data
                        ]
                    ]
                ]
            ]
        ],
        "generationConfig" => [
            "response_mime_type" => "application/json",
            "temperature"        => 0.0,
            "maxOutputTokens"    => 800,
            "response_schema"    => [
                "type"       => "OBJECT",
                "properties" => $properties,
                "required"   => $requiredKeys
            ]
        ]
    ];

    // API 버전(v1, v1beta) 및 모델 후보군 다변화 (실제 유효한 최신 모델 우선 배치)
    $candidateList = [];
    $envModel = trim((string)env('GEMINI_MODEL', ''));
    if ($envModel) {
        $candidateList[] = ['ver' => 'v1beta', 'model' => $envModel];
        $candidateList[] = ['ver' => 'v1',     'model' => $envModel];
    }
    $defaultCandidates = [
        ['ver' => 'v1beta', 'model' => 'gemini-2.5-flash'],
        ['ver' => 'v1',     'model' => 'gemini-2.5-flash'],
        ['ver' => 'v1beta', 'model' => 'gemini-2.0-flash'],
        ['ver' => 'v1',     'model' => 'gemini-2.0-flash'],
        ['ver' => 'v1beta', 'model' => 'gemini-1.5-flash'],
        ['ver' => 'v1',     'model' => 'gemini-1.5-flash'],
        ['ver' => 'v1beta', 'model' => 'gemini-2.5-flash-lite'],
        ['ver' => 'v1beta', 'model' => 'gemini-2.0-flash-exp'],
    ];
    foreach ($defaultCandidates as $dc) {
        $exists = false;
        foreach ($candidateList as $existing) {
            if ($existing['ver'] === $dc['ver'] && $existing['model'] === $dc['model']) {
                $exists = true; break;
            }
        }
        if (!$exists) $candidateList[] = $dc;
    }

    $response = null;
    $httpCode = 0;
    $curlErr  = '';
    $lastErrorMsg = '';
    $attemptLogs = [];
    $usedModelInfo = '';

    foreach ($candidateList as $cand) {
        $candVer   = $cand['ver'];
        $candModel = $cand['model'];
        $apiUrl    = "https://generativelanguage.googleapis.com/{$candVer}/models/{$candModel}:generateContent?key=" . urlencode($apiKey);

        // 429 Rate Limit 발생 시 최대 2회 지수 백오프 재시도
        $maxRetries = 2;
        for ($retry = 0; $retry <= $maxRetries; $retry++) {
            if ($retry > 0) {
                // 429 발생 시 1.2초 대기 후 재시도
                usleep(1200000);
            }

            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200) {
                break; // 성공 시 재시도 탈출
            }

            // 429가 아니면(예: 404 등) 해당 모델에서 재시도하지 않고 다음 모델로 즉시 전환
            if ($httpCode !== 429) {
                break;
            }
        }

        $attemptInfo = "{$candModel} ({$candVer})";
        if ($curlErr) {
            $lastErrorMsg = "cURL 오류 ({$attemptInfo}): " . $curlErr;
            $attemptLogs[] = "[{$attemptInfo}] cURL Error: {$curlErr}";
            @file_put_contents(APP_ROOT . '/gemini_debug.log', date('Y-m-d H:i:s') . " [cURL Error] {$lastErrorMsg}\n", FILE_APPEND);
            continue;
        }

        if ($httpCode === 200) {
            $usedModelInfo = $attemptInfo;
            break;
        } else {
            $lastErrorMsg = "HTTP {$httpCode} ({$attemptInfo}): " . $response;
            $attemptLogs[] = "[{$attemptInfo}] HTTP {$httpCode}: " . substr($response, 0, 500);
            @file_put_contents(APP_ROOT . '/gemini_debug.log', date('Y-m-d H:i:s') . " [Gemini Fail] {$lastErrorMsg}\n", FILE_APPEND);
        }
    }

    if ($httpCode !== 200 || empty($response)) {
        return [
            'items' => $createFallbackResults($lastErrorMsg ?: 'Gemini API 호출 실패'),
            'debug' => [
                'grid_image'  => $base64Grid,
                'error'       => $lastErrorMsg,
                'attempts'    => $attemptLogs,
                'api_status'  => 'fallback'
            ]
        ];
    }

    $apiResult = json_decode($response, true);
    $rawTextResponse = $apiResult['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    @file_put_contents(APP_ROOT . '/gemini_debug.log', date('Y-m-d H:i:s') . " [Gemini Success ({$usedModelInfo})] " . $rawTextResponse . "\n", FILE_APPEND);

    // 마크다운 코드 블록(```json ... ```) 또는 앞뒤 불필요 문자열 제거 후 안전 파싱
    $cleanJson = trim($rawTextResponse);
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $cleanJson, $m)) {
        $cleanJson = trim($m[1]);
    }
    $extractedData = json_decode($cleanJson, true);
    
    if (!is_array($extractedData)) {
        $extractedData = []; 
    }

    // 5. 채점 로직 (구분자 정규화 및 순서 무관 정답 판정 적용)
    $finalResults = [];
    foreach ($map as $id => $item) {
        $rawStudentAns = trim((string)(
            $extractedData[$id] ?? 
            $extractedData[(string)$id] ?? 
            $extractedData["Q{$id}"] ?? 
            $extractedData["q{$id}"] ?? 
            '미기재'
        ));
        $correctAnswerStr = trim((string)($item['answer'] ?? ''));
        $itemType = $item['type'] ?? 'objective';
        
        $isCorrect = false;

        // 예외 응답 문자열 제외 처리 ('미기재', '판독 불가', '판독불가')
        if ($rawStudentAns !== '미기재' && $rawStudentAns !== '판독 불가' && $rawStudentAns !== '판독불가') {
            $normalizedStudentAns = preg_replace('/[\s\/\.]+/u', ',', $rawStudentAns);
            $extArr   = array_values(array_filter(array_map('trim', explode(',', $normalizedStudentAns)), 'strlen'));
            $modelArr = array_values(array_filter(array_map('trim', explode(',', $correctAnswerStr)), 'strlen'));

            if ($itemType === 'composite') {
                // ===== 복합 다답형 All-or-Nothing 판정 =====
                // 정답 배열과 학생 답안 배열을 순서대로 비교 (쉼표 순서 의미 있음)
                if (!empty($modelArr) && count($extArr) === count($modelArr)) {
                    $allMatch = true;
                    foreach ($modelArr as $mi => $mAns) {
                        $sAns = mb_strtolower(trim($extArr[$mi] ?? ''), 'UTF-8');
                        $mAnsLc = mb_strtolower(trim($mAns), 'UTF-8');
                        if ($sAns !== $mAnsLc) {
                            $allMatch = false;
                            break;
                        }
                    }
                    $isCorrect = $allMatch; // 하나라도 불일치 시 false → 0점
                }
            } else {
                // 기존 일반 채점 (순서 무관 정답 판정)
                sort($extArr);
                sort($modelArr);
                if (!empty($modelArr) && $extArr === $modelArr) {
                    $isCorrect = true;
                }
            }
        }
        
        $status = $isCorrect ? 1 : 0;
        $maxScore = (float)($item['score'] ?? 0);
        $currentScore = $isCorrect ? $maxScore : 0;
        
        $finalResults[] = [
            'item_id'        => $id,
            'id'             => $id,
            'display_num'    => $item['display_num'] ?? (string)$id,
            'sub_id'         => $item['sub_id'] ?? null,
            'type'           => $itemType,
            'score'          => $maxScore,
            'currentScore'   => $currentScore,
            'answer'         => $correctAnswerStr,
            'rawText'        => $rawStudentAns,
            'status'         => $status,
            'crop_url'       => $item['crop_url'] ?? '',
            'crop_image_url' => $item['crop_image_url'] ?? ''
        ];
    }
    
    return [
        'items' => $finalResults,
        'debug' => [
            'grid_image'     => $base64Grid,
            'gemini_raw'     => $rawTextResponse, 
            'extracted_data' => $extractedData,
            'api_status'     => 'success'
        ]
    ];
}
