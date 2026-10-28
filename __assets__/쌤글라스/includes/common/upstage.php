<?php
/**
 * includes/common/upstage.php
 * Upstage Document Parse API 통신 및 시험지 문항/ROI 자동 추출 엔진
 */

require_once dirname(__DIR__, 2) . '/config/env.php';

if (!function_exists('parseDocumentWithUpstage')) {
    /**
     * Upstage Document Parse API 호출 (PDF 파일 전송)
     *
     * @param string $filePath 로컬 PDF 절대 경로
     * @return array|null 성공 시 파싱된 연관 배열, 실패 시 null
     */
    function parseDocumentWithUpstage(string $filePath): ?array {
        if (!file_exists($filePath)) {
            error_log("[Upstage API] 파일이 존재하지 않습니다: {$filePath}");
            return null;
        }

        $apiKey = env('UPSTAGE_API_KEY');
        if (!$apiKey) {
            error_log("[Upstage API] .env에 UPSTAGE_API_KEY가 설정되지 않았습니다.");
            return null;
        }

        $url = 'https://api.upstage.ai/v1/document-ai/document-parse';

        $cFile = new CURLFile($filePath, 'application/pdf', basename($filePath));
        $postData = [
            'document' => $cFile,
            'ocr'      => 'auto'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) {
            error_log("[Upstage API] 호출 실패 HTTP {$httpCode}: {$curlErr} | Response: {$response}");
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('extractRoisFromUpstageData')) {
    /**
     * Upstage 파싱 결과(JSON) 및 PDF.js 정밀 글리프 좌표로부터 SSOT Contract B 규격의 문항 번호 및 답안 영역(ROI) 자동 산출 (방안 A)
     *
     * @param array $upstageData Upstage API 응답 배열
     * @param array $pagesData templates.pages_data 배열
     * @param array $pageGlyphs PDF.js 텍스트 레이어에서 추출한 정밀 괄호( ) 및 번호 좌표 배열
     * @return array SSOT Contract B 규격의 roi_data 배열
     */
    function extractRoisFromUpstageData(array $upstageData, array $pagesData, array $pageGlyphs = []): array {
        $elements = $upstageData['elements'] ?? [];
        if (empty($elements)) {
            return [];
        }

        // 페이지별 크기 매핑: page_num -> ['width' => w, 'height' => h, 'page_uid' => uid]
        $pageMap = [];
        foreach ($pagesData as $idx => $p) {
            $pNum = (int)($p['page_num'] ?? ($idx + 1));
            $pageMap[$pNum] = [
                'page_uid' => $p['page_uid'] ?? ('p_' . $pNum),
                'width'    => (int)($p['width'] ?? 1200),
                'height'   => (int)($p['height'] ?? 1700)
            ];
        }

        // 1단계: 문항 번호로 시작하는 요소 수집
        $questions = [];
        $elCount = count($elements);

        for ($i = 0; $i < $elCount; $i++) {
            $el = $elements[$i];
            $cat = $el['category'] ?? '';
            if ($cat === 'header' || $cat === 'footer') {
                continue;
            }

            $html = $el['content']['html'] ?? '';
            if (!$html) continue;

            $cleanText = trim(strip_tags($html));
            $cleanText = html_entity_decode($cleanText, ENT_QUOTES, 'UTF-8');

            // 문항 시작 패턴: "1 ", "1. ", "1) ", "1번 " 등
            if (preg_match('/^\s*(\d{1,2})\s*([\.|\s|\)|번]|\s*$)/u', $cleanText, $m)) {
                $qNum = (int)$m[1];
                if ($qNum >= 1 && $qNum <= 100) {
                    $coords = $el['coordinates'] ?? [];
                    if (!empty($coords)) {
                        $xs = array_column($coords, 'x');
                        $ys = array_column($coords, 'y');
                        $minX = min($xs); $maxX = max($xs);
                        $minY = min($ys); $maxY = max($ys);

                        $questions[] = [
                            'el_index'  => $i,
                            'num'       => $qNum,
                            'page'      => (int)($el['page'] ?? 1),
                            'minX'      => $minX,
                            'maxX'      => $maxX,
                            'minY'      => $minY,
                            'maxY'      => $maxY,
                            'cleanText' => $cleanText
                        ];
                    }
                }
            }
        }

        if (empty($questions)) {
            return [];
        }

        // 페이지 및 문항 번호 순 정렬
        usort($questions, function($a, $b) {
            if ($a['page'] !== $b['page']) return $a['page'] <=> $b['page'];
            return $a['num'] <=> $b['num'];
        });

        // 2단계: 문항별 num_roi 및 ans_roi 좌표 산출
        $rois = [];
        $totalQ = count($questions);
        $scorePerQuestion = ($totalQ > 0) ? (int)floor(100 / $totalQ) : 5;

        // 페이지별 사용된 괄호 및 밑줄 추적 (중복 할당 방지)
        $usedBrackets = [];
        $usedUnderlines = [];

        for ($idx = 0; $idx < $totalQ; $idx++) {
            $q = $questions[$idx];
            $pNum = $q['page'];
            $pInfo = $pageMap[$pNum] ?? [
                'page_uid' => 'p_' . $pNum,
                'width'    => 1200,
                'height'   => 1700
            ];

            $pw = $pInfo['width'];
            $ph = $pInfo['height'];

            // 클라이언트 PDF.js에서 추출된 기하 구조 데이터 확인 (괄호, 번호, 밑줄, 박스)
            $pGlyphData = $pageGlyphs[$pNum] ?? ($pageGlyphs[(string)$pNum] ?? null);
            $pageBracketsList = [];
            $pageNumbersList = [];
            $pageUnderlinesList = [];
            $pageBoxesList = [];

            if (is_array($pGlyphData)) {
                if (isset($pGlyphData['brackets'])) {
                    $pageBracketsList = $pGlyphData['brackets'];
                    $pageNumbersList = $pGlyphData['numbers'] ?? [];
                    $pageUnderlinesList = $pGlyphData['underlines'] ?? [];
                    $pageBoxesList = $pGlyphData['boxes'] ?? [];
                } else {
                    // 괄호 목록만 직접 전달된 경우
                    $pageBracketsList = $pGlyphData;
                }
            }

            // 2-1. num_roi (문항 번호: 공간적 앵커링을 통해 Upstage 감지 문항 블록 좌상단 50px 이내 글리프만 채택)
            $numRoi = null;
            $qCanvasX1 = (int)round($q['minX'] * $pw);
            $qCanvasY1 = (int)round($q['minY'] * $ph);

            if (!empty($pageNumbersList[$q['num']])) {
                $gn = $pageNumbersList[$q['num']];
                $gnX = isset($gn['normX']) ? (int)round($gn['normX'] * $pw) : (int)$gn['x'];
                $gnY = isset($gn['normY']) ? (int)round($gn['normY'] * $ph) : (int)$gn['y'];
                $gnW = isset($gn['normW']) ? (int)round($gn['normW'] * $pw) : (int)$gn['w'];
                $gnH = isset($gn['normH']) ? (int)round($gn['normH'] * $ph) : (int)$gn['h'];

                // [Q1 풋터 및 Q3 날짜 오인식 방지] 문항 블록 시작점 반경 60px 이내에 있을 때만 채택!
                if (abs($gnX - $qCanvasX1) <= 65 && abs($gnY - $qCanvasY1) <= 45) {
                    $numRoi = ['x' => $gnX, 'y' => $gnY, 'w' => $gnW, 'h' => $gnH];
                }
            }

            if (!$numRoi) {
                $numW = ($q['num'] >= 10) ? 40 : 34; // 1~9번 34px, 10번 이상 40px
                $numH = 26; // 지문 줄 수와 상관없이 번호 1줄 높이만 정밀 타이트하게 감쌈
                $numRoi = ['x' => $qCanvasX1, 'y' => $qCanvasY1, 'w' => $numW, 'h' => $numH];
            }

            // 2-2. 유형 판별 (기호 선택형 -> 객관식, 단답형/서답형/표기형 -> 주관식 정밀 분류)
            $text = $q['cleanText'];
            $hasSubjectiveKeywords = (bool)preg_match('/쓰시오|서술|설명하시오|바르게\s*고쳐|○표|○\s*표|V표|v표|체크|동그라미|단답|찾아\s*쓰|까닭|이유/u', $text);
            $isSymbolType = (bool)preg_match('/기호[를|로]|번호[를|로]|알맞은\s*기호/u', $text) && !preg_match('/○표|○\s*표|V표|v표|체크|동그라미/u', $text);

            if ($isSymbolType) {
                // [Q12 해결] '기호를 쓰시오', '번호를 쓰시오'는 ㉠, 1 등을 적는 선택형이므로 객관식 [객] 분류
                $isSubjective = false;
            } elseif ($hasSubjectiveKeywords) {
                // [Q10 해결] '까닭을 쓰시오', '단어를 쓰시오', '○표를 하시오' 등은 주관식 [주] 분류
                $isSubjective = true;
            } else {
                // 일반 객관식
                $isSubjective = false;
            }

            // 2-3. ans_roi 결정 (방안 A: 괄호의 높이와 거리 기준 크기 결정 및 괄호 한가운데로 완벽 조준)
            $ansRoi = null;
            $qCanvasX2 = $q['maxX'] * $pw;
            $qCanvasY2 = $q['maxY'] * $ph;
            $qMidX = ($qCanvasX1 + $qCanvasX2) / 2;
            $isLeftCol = ($qMidX < $pw * 0.52);

            // 다음 문항의 시작 Y 좌표 계산 (동일 페이지 & 동일 단의 문항)
            $nextQY = null;
            for ($n = $idx + 1; $n < $totalQ; $n++) {
                if ($questions[$n]['page'] === $pNum) {
                    $nMidX = (($questions[$n]['minX'] + $questions[$n]['maxX']) / 2) * $pw;
                    $nIsLeft = ($nMidX < $pw * 0.52);
                    if ($nIsLeft === $isLeftCol) {
                        $nextQY = $questions[$n]['minY'] * $ph;
                        break;
                    }
                }
            }

            // 방안 A: PDF.js 추출 괄호 중 최적 매칭 검색 (단일 괄호 및 복수 괄호 군집 매칭)
            $matchedBrackets = [];
            if (!empty($pageBracketsList)) {
                $candidates = [];

                foreach ($pageBracketsList as $bIdx => $b) {
                    // 이미 선행 문항에 할당된 괄호는 제외
                    if (!empty($usedBrackets[$pNum][$bIdx])) continue;

                    $bMidX = (float)($b['midX'] ?? ($b['x'] + $b['w'] / 2));
                    $bMidY = (float)($b['midY'] ?? ($b['y'] + $b['h'] / 2));

                    // 단(Column) 일치 검사 (좌측 단 vs 우측 단)
                    if ($pw > 800) {
                        $bIsLeft = ($bMidX < $pw * 0.52);
                        if ($bIsLeft !== $isLeftCol) continue;
                    }

                    // Y 범위 검사: 문항 시작보다 현저히 위이거나 다음 문항 아래인 경우 배제
                    if ($bMidY < $qCanvasY1 - 20) continue;
                    if ($nextQY !== null && $bMidY >= $nextQY - 8) continue;
                    if ($bMidY > $qCanvasY2 + 50 && $nextQY === null) continue;

                    // X 범위 검사: 문항 번호보다는 오른쪽에 위치해야 함
                    if ($bMidX < $qCanvasX1 + 25) continue;

                    $isOnHeadingLine = (abs($bMidY - $qCanvasY1) <= 25);
                    $isChoice = !empty($b['isChoiceBracket']);

                    $candidates[] = [
                        'bIdx'            => $bIdx,
                        'b'               => $b,
                        'bMidX'           => $bMidX,
                        'bMidY'           => $bMidY,
                        'isOnHeadingLine' => $isOnHeadingLine,
                        'isChoice'        => $isChoice
                    ];
                }

                // 지시문 첫 줄 괄호 필터링: 만약 문항 내에 다른 괄호가 존재한다면 지시문 첫 줄 괄호는 제외
                $nonHeading = array_filter($candidates, function($c) { return !$c['isOnHeadingLine']; });
                $validList = !empty($nonHeading) ? array_values($nonHeading) : $candidates;

                if (!empty($validList)) {
                    // Y좌표 (오차 15px 이내면 동일 줄로 보고 X좌표 오름차순), 다른 줄이면 Y좌표 오름차순 정렬
                    usort($validList, function($a, $b) {
                        if (abs($a['bMidY'] - $b['bMidY']) <= 15) {
                            return $a['bMidX'] <=> $b['bMidX'];
                        }
                        return $a['bMidY'] <=> $b['bMidY'];
                    });

                    foreach ($validList as $vl) {
                        $usedBrackets[$pNum][$vl['bIdx']] = true;
                        $matchedB = $vl['b'];
                        if (isset($matchedB['normX']) && isset($matchedB['normW'])) {
                            $bRoi = [
                                'x' => (int)round($matchedB['normX'] * $pw),
                                'y' => (int)round($matchedB['normY'] * $ph),
                                'w' => (int)round($matchedB['normW'] * $pw),
                                'h' => (int)round($matchedB['normH'] * $ph)
                            ];
                        } else {
                            $bRoi = [
                                'x' => (int)$matchedB['x'],
                                'y' => (int)$matchedB['y'],
                                'w' => (int)$matchedB['w'],
                                'h' => (int)$matchedB['h']
                            ];
                        }
                        $matchedBrackets[] = $bRoi;
                    }

                    $ansRoi = $matchedBrackets[0];
                }
            }

            // [2단계] 주관식 밑줄(Underlines) 1~3줄 정밀 매칭 (줄 간격 Δy 비례 동적 높이)
            if (!$ansRoi && !empty($pageUnderlinesList)) {
                $qLines = [];
                foreach ($pageUnderlinesList as $uIdx => $u) {
                    if (!empty($usedUnderlines[$pNum][$uIdx])) continue;
                    $uY = (float)(isset($u['normY']) ? ($u['normY'] * $ph) : $u['y']);
                    $uX = (float)(isset($u['normX']) ? ($u['normX'] * $pw) : $u['x']);
                    $uW = (float)(isset($u['normW']) ? ($u['normW'] * $pw) : $u['w']);
                    $uIsLeft = isset($u['isLeft']) ? $u['isLeft'] : ($uX < $pw * 0.52);

                    // 단(Column) 일치 검사
                    if ($pw > 800 && $uIsLeft !== $isLeftCol) continue;

                    // Y 범위 검사: 문항 번호 아래, 다음 문항 직전
                    if ($uY < $qCanvasY1 + 18) continue;
                    if ($nextQY !== null && $uY >= $nextQY - 8) continue;
                    if ($uY > $ph * 0.92) continue;

                    $qLines[] = [
                        'uIdx' => $uIdx,
                        'y' => $uY,
                        'x' => $uX,
                        'w' => $uW
                    ];
                }

                if (!empty($qLines)) {
                    // Y좌표 오름차순 정렬
                    usort($qLines, function($a, $b) { return $a['y'] <=> $b['y']; });

                    // 사용 처리
                    foreach ($qLines as $ql) {
                        $usedUnderlines[$pNum][$ql['uIdx']] = true;
                    }

                    $lineCount = count($qLines);
                    $firstY = $qLines[0]['y'];
                    $lastY = $qLines[$lineCount - 1]['y'];

                    // 사용자 설계 반영: 줄 간격 Δy 동적 산출 (출제자가 정한 1행 글씨 높이)
                    if ($lineCount >= 2) {
                        $deltaY = ($lastY - $firstY) / ($lineCount - 1);
                        $deltaY = max(24, min(65, $deltaY));
                    } else {
                        // 1줄인 경우 표준 손글씨 높이(34px) 적용
                        $deltaY = 34;
                    }

                    // 시작점: 첫 번째 밑줄 위로 줄 간격(Δy)만큼 정확히 확보
                    $ansStartY = (int)round($firstY - $deltaY);
                    // 종료점: 마지막 밑줄 아래 8px 여유
                    $ansEndY = (int)round($lastY + 8);

                    $minX = min(array_column($qLines, 'x'));
                    $maxW = max(array_column($qLines, 'w'));

                    $ansRoi = [
                        'x' => (int)round($minX),
                        'y' => (int)round($ansStartY),
                        'w' => (int)round($maxW),
                        'h' => (int)round($ansEndY - $ansStartY)
                    ];
                }
            }

            // [3단계] 빈 사각형 박스 (Boxes) 매칭
            if (!$ansRoi && !empty($pageBoxesList)) {
                foreach ($pageBoxesList as $bBox) {
                    if (empty($bBox['isEmpty'])) continue;
                    $bx = (float)(isset($bBox['normX']) ? ($bBox['normX'] * $pw) : $bBox['x']);
                    $by = (float)(isset($bBox['normY']) ? ($bBox['normY'] * $ph) : $bBox['y']);
                    $bw = (float)(isset($bBox['normW']) ? ($bBox['normW'] * $pw) : $bBox['w']);
                    $bh = (float)(isset($bBox['normH']) ? ($bBox['normH'] * $ph) : $bBox['h']);
                    $bIsLeft = ($bx < $pw * 0.52);

                    if ($pw > 800 && $bIsLeft !== $isLeftCol) continue;
                    if ($by < $qCanvasY1 + 18) continue;
                    if ($nextQY !== null && $by + $bh >= $nextQY - 5) continue;

                    $ansRoi = [
                        'x' => (int)round($bx + 4),
                        'y' => (int)round($by + 4),
                        'w' => (int)round($bw - 8),
                        'h' => (int)round($bh - 8)
                    ];
                    break;
                }
            }

            // [4단계] 폴백: PDF.js 괄호/밑줄 매칭이 없을 경우 Upstage BBox 기반 보정
            if (!$ansRoi) {
                $bracketBBox = null;
                $lookAheadLimit = min($elCount, $q['el_index'] + 4);
                for ($k = $q['el_index'] + 1; $k < $lookAheadLimit; $k++) {
                    $followEl = $elements[$k];
                    if ((int)($followEl['page'] ?? 1) !== $pNum) break;

                    $fHtml = $followEl['content']['html'] ?? '';
                    $fText = trim(strip_tags($fHtml));
                    $fText = html_entity_decode($fText, ENT_QUOTES, 'UTF-8');

                    // [Q8, Q17 해결] '(' 와 ')' 가 두 개 연속된 요소로 나뉜 경우 결합
                    if (preg_match('/^\s*[\(\（]\s*$/u', $fText) && ($k + 1 < $lookAheadLimit)) {
                        $nextEl = $elements[$k + 1];
                        $nText = trim(strip_tags($nextEl['content']['html'] ?? ''));
                        if (preg_match('/^\s*[\)\）]\s*$/u', $nText)) {
                            $leftXs = array_column($followEl['coordinates'] ?? [], 'x');
                            $rightXs = array_column($nextEl['coordinates'] ?? [], 'x');
                            $ys = array_column($followEl['coordinates'] ?? [], 'y');
                            if (!empty($leftXs) && !empty($rightXs)) {
                                $bX = (int)round(min($leftXs) * $pw);
                                $bMaxX = (int)round(max($rightXs) * $pw);
                                $bY = (int)round(min($ys) * $ph);
                                $bracketBBox = [
                                    'x' => $bX,
                                    'y' => $bY,
                                    'w' => max(38, $bMaxX - $bX),
                                    'h' => 32
                                ];
                                break;
                            }
                        }
                    }

                    // 단일 요소 내 괄호 ( )
                    if (preg_match('/[\(\（]\s*[\)\）]/u', $fText)) {
                        $fCoords = $followEl['coordinates'] ?? [];
                        if (!empty($fCoords)) {
                            $fxs = array_column($fCoords, 'x');
                            $fys = array_column($fCoords, 'y');
                            $fMinX = min($fxs);
                            $fMaxX = max($fxs);
                            $fMinY = min($fys);

                            $bracketBBox = [
                                'x' => (int)round($fMinX * $pw),
                                'y' => (int)round($fMinY * $ph),
                                'w' => max(38, (int)round(($fMaxX - $fMinX) * $pw)),
                                'h' => 32
                            ];
                            break;
                        }
                    }
                }

                $hasHeadingBracket = (mb_strpos($q['cleanText'], '(') !== false && mb_strpos($q['cleanText'], ')') !== false)
                                  || (mb_substr($q['cleanText'], -1) === '(');

                if ($bracketBBox) {
                    $ansRoi = $bracketBBox;
                } elseif ($hasHeadingBracket) {
                    $ansW = 42;
                    $ansH = 32;
                    $ansX = (int)round($q['maxX'] * $pw) - $ansW;
                    $ansY = (int)round($q['maxY'] * $ph) - $ansH;
                    $ansRoi = ['x' => $ansX, 'y' => $ansY, 'w' => $ansW, 'h' => $ansH];
                } else {
                    // [사례 1 & 사례 4 해결] 주관식 서술형 밑줄 2~3줄 및 단(Column) 격리 완벽 보정
                    // 1. 단(Column) 기준 좌우 X 범위 결정 (반대편 단 침범 원천 차단)
                    if ($isLeftCol) {
                        $colMinX = (int)round($pw * 0.06);
                        $colMaxX = (int)round($pw * 0.48);
                    } else {
                        $colMinX = (int)round($pw * 0.52);
                        $colMaxX = (int)round($pw * 0.94);
                    }

                    // 2. 상하 Y 범위 결정
                    // 시작점: 문항 지시문(보통 1~3줄) 높이 바로 아래
                    $promptHeight = min(80, max(30, (int)round(($q['maxY'] - $q['minY']) * $ph)));
                    $ansStartY = $qCanvasY1 + $promptHeight + 10;

                    // 종료점: 같은 단의 다음 문항 직전, 또는 페이지 하단 마진(90%) 직전
                    if ($nextQY !== null) {
                        $maxAllowedEndY = (int)round($nextQY - 15);
                    } else {
                        $maxAllowedEndY = (int)round($ph * 0.90);
                    }

                    if ($ansStartY >= $maxAllowedEndY) {
                        $ansStartY = max($qCanvasY1 + 35, $maxAllowedEndY - 75);
                    }
                    $ansEndY = max($ansStartY + 65, $maxAllowedEndY);

                    $ansX = $colMinX;
                    $ansW = $colMaxX - $colMinX;
                    $ansY = $ansStartY;
                    $ansH = min(180, max(65, $ansEndY - $ansStartY));

                    $ansRoi = ['x' => $ansX, 'y' => $ansY, 'w' => $ansW, 'h' => $ansH];
                }
            }

            if (count($matchedBrackets) >= 2) {
                $subCount = count($matchedBrackets);
                $subScore = (int)round($scorePerQuestion / $subCount);
                if ($subScore <= 0) $subScore = 1;

                foreach ($matchedBrackets as $subIdx => $mRoi) {
                    $subNum = $subIdx + 1;
                    $rois[] = [
                        'id'          => $q['num'],
                        'sub_id'      => $subNum,
                        'display_num' => "{$q['num']}-{$subNum}",
                        'page'        => $pNum,
                        'page_uid'    => $pInfo['page_uid'],
                        'num_roi'     => $numRoi,
                        'ans_roi'     => $mRoi,
                        'type'        => 'sub_item',
                        'sub_type'    => $isSubjective ? 'subjective' : 'objective',
                        'answer'      => '',
                        'score'       => $subScore
                    ];
                }
            } else {
                $rois[] = [
                    'id'          => $q['num'],
                    'sub_id'      => null,
                    'display_num' => (string)$q['num'],
                    'page'        => $pNum,
                    'page_uid'    => $pInfo['page_uid'],
                    'num_roi'     => $numRoi,
                    'ans_roi'     => $ansRoi,
                    'type'        => $isSubjective ? 'subjective' : 'objective',
                    'sub_type'    => $isSubjective ? 'subjective' : 'objective',
                    'answer'      => '',
                    'score'       => $scorePerQuestion
                ];
            }
        }

        // 문항 번호 및 소문항 기준 오름차순 정렬
        usort($rois, function($a, $b) {
            if ($a['id'] === $b['id']) {
                return ($a['sub_id'] ?? 0) <=> ($b['sub_id'] ?? 0);
            }
            return $a['id'] <=> $b['id'];
        });

        return $rois;
    }
}
