<?php
/**
 * views/grading/print_slips.php
 * 학생 배부용 정오표 일괄 출력 (A4 2열 대칭 모아찍기 티켓 슬립)
 * UI/UX 전면 고도화 & 실시간 '정답 표시하기' 토글 스위치 연동
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

$userId   = requireTeacherAuth();
$assignId = (int)($_GET['assign_id'] ?? 0);

if (!$assignId) {
    die("잘못된 접근입니다. 과제 ID가 필요합니다.");
}

// 1. 과제, 템플릿, 학급 정보 조회 (실제 DB 스키마 완벽 일치)
$stmtAssign = $pdo->prepare("
    SELECT a.id, a.template_id, a.class_id, a.status, a.show_answer, a.exam_name,
           t.title AS template_title, t.roi_data, t.user_id,
           c.class_name
    FROM assignments a
    JOIN templates t ON a.template_id = t.id
    LEFT JOIN classes c ON a.class_id = c.id
    WHERE a.id = :id
");
$stmtAssign->execute(['id' => $assignId]);
$assign = $stmtAssign->fetch(PDO::FETCH_ASSOC);

if (!$assign) {
    die("존재하지 않는 평가 과제입니다.");
}

$templateTitle = !empty($assign['exam_name']) ? $assign['exam_name'] : $assign['template_title'];
$className     = $assign['class_name'] ?: '학급 미지정';
$showAnswer    = (int)($assign['show_answer'] ?? 0);
$isClosed      = ($assign['status'] === 'completed');

// 2. 템플릿 ROI 기반 문항 기본 목록 파싱
$rawRoiData = json_decode($assign['roi_data'] ?? '[]', true);
if (is_array($rawRoiData) && isset($rawRoiData['items']) && is_array($rawRoiData['items'])) {
    $rawRoiData = $rawRoiData['items'];
}
if (!is_array($rawRoiData)) {
    $rawRoiData = [];
}

// 2. 템플릿 ROI 기반 문항 기본 목록 파싱 (대문항 번호 기준 그룹화)
$templateMainQuestions = [];
$totalPossibleScore = 0;

foreach ($rawRoiData as $idx => $r) {
    $rawId   = $r['id'] ?? null;
    $dispNum = (string)($r['display_num'] ?? '');
    if ($rawId !== null && $rawId !== '') {
        $mainId = (int)$rawId;
    } elseif ($dispNum !== '' && strpos($dispNum, '-') !== false) {
        $mainId = (int)explode('-', $dispNum)[0];
    } else {
        $mainId = (int)($dispNum ?: ($idx + 1));
    }
    
    $subId  = $r['sub_id'] ?? null;
    $qScore = isset($r['score']) ? (float)$r['score'] : 0;
    $qAns   = (string)($r['answer'] ?? '');
    $qType  = $r['type'] ?? 'objective';
    $subKey = $dispNum ?: ($subId ? "{$mainId}-{$subId}" : (string)$mainId);

    if (!isset($templateMainQuestions[$mainId])) {
        $templateMainQuestions[$mainId] = [
            'id'          => $mainId,
            'display_num' => (string)$mainId,
            'score'       => 0,
            'sub_items'   => []
        ];
    }

    $templateMainQuestions[$mainId]['sub_items'][] = [
        'key'         => $subKey,
        'sub_id'      => $subId,
        'display_num' => $dispNum ?: $subKey,
        'type'        => $qType,
        'score'       => $qScore,
        'answer'      => $qAns
    ];
    $templateMainQuestions[$mainId]['score'] += $qScore;
    $totalPossibleScore += $qScore;
}

if (empty($templateMainQuestions)) {
    for ($i = 1; $i <= 10; $i++) {
        $templateMainQuestions[$i] = [
            'id'          => $i,
            'display_num' => (string)$i,
            'score'       => 10,
            'sub_items'   => []
        ];
    }
    $totalPossibleScore = 100;
}

// 3. 채점 완료된 학생 명단 및 결과 데이터 조회 (가나다순 정렬)
$stmtResults = $pdo->prepare("
    SELECT e.id AS result_id, e.student_id, e.total_score, e.is_reviewed, e.raw_results, e.submitted_at,
           COALESCE(s.name, '학생') AS student_name, COALESCE(s.order_index, 0) AS order_index
    FROM exam_results e
    LEFT JOIN students s ON e.student_id = s.id
    WHERE e.assignment_id = :assign_id
    ORDER BY s.name ASC, e.id ASC
");
$stmtResults->execute(['assign_id' => $assignId]);
$resultsRows = $stmtResults->fetchAll(PDO::FETCH_ASSOC);

// 이름 가나다순 안전 정렬
usort($resultsRows, function($a, $b) {
    return strcmp($a['student_name'], $b['student_name']);
});

$studentSlips = [];
$unreviewedCount = 0;

foreach ($resultsRows as $r) {
    $sId = (int)$r['student_id'];
    $stuName = $r['student_name'];
    $orderIndex = (int)$r['order_index'];
    $isReviewed = ((int)$r['is_reviewed'] === 1);
    if (!$isReviewed) {
        $unreviewedCount++;
    }
    $stuTotalScore = (float)$r['total_score'];
    
    $raw = [];
    try {
        $raw = is_string($r['raw_results']) ? json_decode($r['raw_results'], true) : ($r['raw_results'] ?: []);
    } catch (Exception $e) { $raw = []; }
    if (!is_array($raw) && isset($raw['items']) && is_array($raw['items'])) {
        $raw = $raw['items'];
    }
    if (!is_array($raw)) $raw = [];
    
    // 학생의 각 문항별 채점 결과 맵 생성 (문자열 키 매핑: display_num, item_id, id 및 6-1, 6 모두 색인)
    $itemResultMap = [];
    foreach ($raw as $item) {
        $rawId = (string)($item['item_id'] ?? ($item['id'] ?? ''));
        $dispNum = (string)($item['display_num'] ?? '');
        $subId = isset($item['sub_id']) ? (string)$item['sub_id'] : '';

        if ($rawId !== '') {
            $itemResultMap[$rawId] = $item;
        }
        if ($dispNum !== '') {
            $itemResultMap[$dispNum] = $item;
        }
        if ($rawId !== '' && $subId !== '') {
            $itemResultMap["{$rawId}-{$subId}"] = $item;
        }
    }
    
    $qDetails = [];
    $correctCount = 0;
    $incorrectCount = 0;
    $partialCount = 0;
    
    foreach ($templateMainQuestions as $mainId => $mainQ) {
        $subItems = $mainQ['sub_items'];
        $mainMaxScore = $mainQ['score'];
        
        if (count($subItems) <= 1) {
            // 단일 문항 또는 소문항이 1개만 존재하는 문항 (예: 6-1만 남긴 경우)
            $sub = !empty($subItems) ? $subItems[0] : null;
            $subKey = $sub ? $sub['key'] : (string)$mainId;
            
            $item = $itemResultMap[$subKey] ?? ($itemResultMap[(string)$mainId] ?? null);
            
            $maxScore = $sub ? $sub['score'] : $mainMaxScore;
            $modelAns = $sub ? $sub['answer'] : '';
            
            $statusCode = -1;
            if ($item && isset($item['status'])) {
                if ($item['status'] === 1 || $item['status'] === '1' || $item['status'] === 'correct') {
                    $statusCode = 1;
                } elseif ($item['status'] === 0 || $item['status'] === '0' || $item['status'] === 'incorrect') {
                    $statusCode = 0;
                } elseif ($item['status'] === 2 || $item['status'] === '2' || $item['status'] === 'partial') {
                    $statusCode = 2;
                }
            }
            
            $currentScore = 0;
            if ($item && isset($item['currentScore'])) {
                $currentScore = (float)$item['currentScore'];
            } elseif ($statusCode === 1) {
                $currentScore = $maxScore;
            } elseif ($statusCode === 2 && isset($item['score_awarded'])) {
                $currentScore = (float)$item['score_awarded'];
            }
            
            if ($statusCode === 1) $correctCount++;
            elseif ($statusCode === 0) $incorrectCount++;
            elseif ($statusCode === 2) $partialCount++;
            
            $qDetails[] = [
                'id'            => (string)$mainId,
                'type'          => $sub ? $sub['type'] : 'objective',
                'status'        => $statusCode,
                'max_score'     => $maxScore,
                'my_score'      => $currentScore,
                'model_answer'  => $modelAns,
                'sub_breakdown' => ''
            ];
        } else {
            // 소문항이 2개 이상 존재하는 다답형 문항 (예: 6-1, 6-2)
            $totalSubScore = 0;
            $allCorrect = true;
            $allIncorrect = true;
            $hasGraded = false;
            $breakdownParts = [];
            $modelAnsParts = [];
            
            foreach ($subItems as $sub) {
                $subKey = $sub['key'];
                $dispNum = $sub['display_num'];
                $item = $itemResultMap[$subKey] ?? null;
                
                $subMax = $sub['score'];
                $subCode = -1;
                $subScore = 0;
                
                if ($item && isset($item['status'])) {
                    $hasGraded = true;
                    if ($item['status'] === 1 || $item['status'] === '1' || $item['status'] === 'correct') {
                        $subCode = 1;
                        $subScore = isset($item['currentScore']) ? (float)$item['currentScore'] : $subMax;
                        $allIncorrect = false;
                    } elseif ($item['status'] === 0 || $item['status'] === '0' || $item['status'] === 'incorrect') {
                        $subCode = 0;
                        $subScore = 0;
                        $allCorrect = false;
                    } elseif ($item['status'] === 2 || $item['status'] === '2' || $item['status'] === 'partial') {
                        $subCode = 2;
                        $subScore = isset($item['currentScore']) ? (float)$item['currentScore'] : (float)($item['score_awarded'] ?? 0);
                        $allCorrect = false;
                        $allIncorrect = false;
                    }
                } else {
                    $allCorrect = false;
                }
                
                $totalSubScore += $subScore;
                
                $subMark = ($subCode === 1) ? 'O' : (($subCode === 0) ? 'X' : (($subCode === 2) ? '△' : '-'));
                $breakdownParts[] = "{$dispNum} {$subMark}";
                
                if ($sub['answer'] !== '') {
                    $modelAnsParts[] = "{$dispNum}:{$sub['answer']}";
                }
            }
            
            if (!$hasGraded) {
                $mainStatusCode = -1;
            } elseif ($allCorrect) {
                $mainStatusCode = 1;
                $correctCount++;
            } elseif ($allIncorrect) {
                $mainStatusCode = 0;
                $incorrectCount++;
            } else {
                $mainStatusCode = 2;
                $partialCount++;
            }
            
            $breakdownText = '';
            if ($mainStatusCode === 2) {
                // 부분점수(일부 정답)인 경우에만 인라인 소문항 결과 표시: (6-1 O, 6-2 X)
                $breakdownText = '(' . implode(' ', $breakdownParts) . ')';
            }
            
            $qDetails[] = [
                'id'            => (string)$mainId,
                'type'          => 'sub_item',
                'status'        => $mainStatusCode,
                'max_score'     => $mainMaxScore,
                'my_score'      => $totalSubScore,
                'model_answer'  => implode(', ', $modelAnsParts),
                'sub_breakdown' => $breakdownText
            ];
        }
    }
    
    $studentSlips[] = [
        'id'             => $sId,
        'result_id'      => (int)$r['result_id'],
        'name'           => $stuName,
        'order_index'    => $orderIndex,
        'has_result'     => true,
        'is_reviewed'    => $isReviewed,
        'total_score'    => $stuTotalScore,
        'correct_count'  => $correctCount,
        'questions'      => $qDetails
    ];
}

$totalQuestions = count($templateMainQuestions);
$totalGraded    = count($studentSlips);

// 내부 문항 열 수 결정 (8문항 이하: 1열, 18문항 이하: 2열, 28문항 이하: 3열, 그 이상: 4열)
if ($totalQuestions <= 8) {
    $slipCols = 1;
} elseif ($totalQuestions <= 18) {
    $slipCols = 2;
} elseif ($totalQuestions <= 28) {
    $slipCols = 3;
} else {
    $slipCols = 4;
}
$rowsPerCol = ceil($totalQuestions / $slipCols);

// '나의 할 일' 기본 문구 설정
if ($showAnswer) {
    $defaultTodoText = "[ ] 틀린 문제 시험지에 O/X 표시하기\n[ ] 오답 원인 분석하고 스스로 다시 풀기\n[ ] 이해 안 되는 문제 질문하기";
} else {
    $defaultTodoText = "[ ] 틀린 문제 시험지에 O/X 표시하기\n[ ] 스스로 다시 풀고 선생님께 검사받기";
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <script src="/__preview-bridge.js" data-preview-bridge></script>
    <meta charset="UTF-8">
    <title><?= e($templateTitle) ?> - 학생 배부용 정오표</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* ===== 모던 리셋 및 전역 스타일 ===== */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, "Malgun Gothic", sans-serif;
            background: #e2e8f0;
            color: #0f172a;
            padding-bottom: 80px;
            -webkit-font-smoothing: antialiased;
        }

        /* ===== 화면 전용 고정 컨트롤 툴바 (No-Print) ===== */
        .print-control-toolbar {
            background: #ffffff;
            border-bottom: 1px solid #cbd5e1;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.08);
            position: sticky;
            top: 0;
            z-index: 1000;
            padding: 14px 24px 18px 24px;
        }

        /* 1단: 글로벌 헤더 (타이틀 + 옵션 + 액션) */
        .toolbar-top-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .toolbar-title-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .toolbar-title-group h1 {
            font-size: 1.25rem;
            font-weight: 900;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: -0.3px;
        }
        .toolbar-title-group .meta-tags {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            color: #64748b;
            flex-wrap: wrap;
        }
        .meta-pill {
            background: #f1f5f9;
            padding: 3px 9px;
            border-radius: 6px;
            font-weight: 700;
            border: 1px solid #e2e8f0;
        }
        .meta-pill.highlight {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* [신규] '정답 표시하기' 인터랙티브 토글 스위치 필 */
        .toggle-answer-switch {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 30px;
            padding: 6px 14px 6px 10px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
        }
        .toggle-answer-switch:hover {
            border-color: #7c3aed;
            background: #faf5ff;
        }
        .toggle-answer-switch input {
            display: none;
        }
        .switch-slider {
            width: 38px;
            height: 22px;
            background: #cbd5e1;
            border-radius: 20px;
            position: relative;
            transition: background 0.2s ease;
            flex-shrink: 0;
        }
        .switch-slider::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 18px;
            height: 18px;
            background: #ffffff;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.25);
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .toggle-answer-switch.is-on {
            background: #f5f3ff;
            border-color: #7c3aed;
            box-shadow: 0 0 0 2px rgba(124, 58, 237, 0.15);
        }
        .toggle-answer-switch.is-on .switch-slider {
            background: #7c3aed;
        }
        .toggle-answer-switch.is-on .switch-slider::after {
            transform: translateX(16px);
        }
        .switch-label {
            font-size: 0.88rem;
            font-weight: 800;
            color: #475569;
            letter-spacing: -0.2px;
        }
        .toggle-answer-switch.is-on .switch-label {
            color: #6d28d9;
        }

        .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #2563eb;
            color: #ffffff;
            font-size: 0.95rem;
            font-weight: 800;
            padding: 9px 22px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(37, 99, 235, 0.35);
            transition: all 0.15s ease;
        }
        .btn-print:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.45);
        }
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ffffff;
            color: #334155;
            font-size: 0.86rem;
            font-weight: 700;
            padding: 7px 14px;
            border-radius: 6px;
            border: 1.5px solid #cbd5e1;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        /* 경고 배너 */
        .unreviewed-banner {
            background: #fffbeb;
            border: 1.5px solid #fde68a;
            color: #b45309;
            padding: 9px 16px;
            border-radius: 8px;
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.88rem;
            font-weight: 600;
        }
        .unreviewed-banner a {
            color: #d97706;
            font-weight: 800;
            text-decoration: underline;
            margin-left: 8px;
        }

        /* 2단: '나의 할 일' 액션 미션 스튜디오 */
        .editor-panel {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 12px;
        }
        .editor-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .editor-title-group {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.86rem;
            font-weight: 800;
            color: #1e293b;
        }
        .live-sync-indicator {
            font-size: 0.75rem;
            font-weight: 700;
            color: #16a34a;
            background: #dcfce7;
            padding: 1px 7px;
            border-radius: 10px;
        }
        .preset-chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .chip-btn {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            padding: 4px 11px;
            border-radius: 16px;
            font-size: 0.78rem;
            font-weight: 700;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .chip-btn:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }
        .chip-btn.active {
            background: #2563eb !important;
            border-color: #2563eb !important;
            color: #ffffff !important;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.3);
        }
        .chip-btn.btn-clear {
            color: #dc2626;
            border-color: #fecaca;
        }
        .chip-btn.btn-clear:hover {
            background: #fef2f2;
            border-color: #ef4444;
        }
        .todo-textarea {
            width: 100%;
            height: 62px;
            padding: 8px 12px;
            font-family: inherit;
            font-size: 0.85rem;
            line-height: 1.45;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            outline: none;
            resize: vertical;
            transition: all 0.15s;
            background: #ffffff;
        }
        .todo-textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        /* 3단: 학생 디렉토리 필터 바 */
        .selection-box {
            margin-top: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
        }
        .selection-top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .selection-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .selection-label {
            font-size: 0.86rem;
            font-weight: 800;
            color: #1e293b;
        }
        .selection-summary {
            font-size: 0.84rem;
            color: #475569;
            font-weight: 600;
        }
        .selection-summary b {
            color: #2563eb;
            font-weight: 900;
            font-size: 0.95rem;
        }
        .student-chips-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 9px;
            max-height: 135px;
            overflow-y: auto;
            padding: 2px;
        }
        .student-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 18px;
            padding: 4px 12px;
            font-size: 0.82rem;
            font-weight: 700;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
        }
        .student-chip:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
            transform: translateY(-1px);
        }
        .student-chip.active {
            background: #0f172a !important;
            border-color: #0f172a !important;
            color: #ffffff !important;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.2);
        }
        .student-chip .chip-score {
            font-size: 0.74rem;
            font-weight: 600;
            color: #94a3b8;
        }
        .student-chip.active .chip-score {
            color: #60a5fa;
            font-weight: 800;
        }

        /* ===== 실물 A4 종이 캔버스 뷰 ===== */
        .preview-container {
            max-width: 210mm;
            margin: 24px auto;
        }
        .paper-notice {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            font-size: 0.82rem;
            color: #475569;
            padding: 0 4px;
        }

        /* ===== A4 2열 대칭 그리드 & 작두 재단선 ===== */
        .slips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            width: 100%;
            border-top: 1px dashed #94a3b8;
            border-left: 1px dashed #94a3b8;
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            box-sizing: border-box;
        }

        /* 개별 학생 티켓 슬립 카드 */
        .slip-card {
            border-right: 1px dashed #94a3b8;
            border-bottom: 1px dashed #94a3b8;
            padding: 10px 14px;
            background: #ffffff;
            box-sizing: border-box;
            position: relative;
            break-inside: avoid !important;
            page-break-inside: avoid !important;
        }

        /* 인쇄 제외된 학생 */
        .slip-card.excluded {
            display: none !important;
        }

        /* 슬립 상단 컨트롤 (화면 전용) */
        .slip-screen-ctl {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            padding: 3px 8px;
            border-radius: 4px;
            margin-bottom: 6px;
            font-size: 0.74rem;
            color: #64748b;
        }
        .slip-screen-ctl label {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: 700;
        }

        /* 슬립 헤더 (학생명, 과제명, 총점) */
        .slip-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1.5px solid #0f172a;
            padding-bottom: 5px;
            margin-bottom: 6px;
        }
        .slip-header-left {
            flex: 1;
        }
        .slip-exam-sub {
            font-size: 0.68rem;
            color: #64748b;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -0.2px;
        }
        .slip-student-name {
            font-size: 1.15rem;
            font-weight: 900;
            color: #0f172a;
            display: flex;
            align-items: baseline;
            gap: 6px;
            margin-top: 1px;
            line-height: 1.2;
        }
        .slip-student-num {
            font-size: 0.8rem;
            color: #475569;
            font-weight: 700;
        }

        .slip-header-right {
            text-align: right;
        }
        .slip-score-box {
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }
        .slip-score-val {
            font-size: 1.4rem;
            font-weight: 900;
            color: #0f172a;
        }
        .slip-score-max {
            font-size: 0.78rem;
            color: #64748b;
            font-weight: 700;
        }
        .slip-score-sub {
            font-size: 0.68rem;
            color: #64748b;
            margin-top: 2px;
            font-weight: 600;
        }

        /* 문항 채점 그리드 */
        .slip-q-grid {
            display: grid;
            grid-template-columns: repeat(<?= $slipCols ?>, 1fr);
            grid-auto-flow: column;
            grid-template-rows: repeat(<?= $rowsPerCol ?>, auto);
            gap: 2px 8px;
            margin: 6px 0;
        }
        .q-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 2px 3px;
            border-bottom: 1px dotted #e2e8f0;
            font-size: 0.78rem;
            line-height: 1.25;
            background: #ffffff;
        }
        .q-left {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .q-num {
            font-weight: 800;
            color: #475569;
            min-width: 15px;
            font-size: 0.75rem;
        }
        .q-mark {
            font-size: 0.92rem;
            line-height: 1;
            font-weight: 800;
            display: inline-block;
            min-width: 14px;
            text-align: center;
        }
        .mark-o { color: #dc2626; }
        .mark-x { color: #dc2626; }
        .mark-p { color: #dc2626; }
        .mark-dash { color: #94a3b8; }
        .q-sub-breakdown {
            font-size: 0.65rem;
            color: #64748b;
            font-weight: 700;
            margin-left: 2px;
            letter-spacing: -0.3px;
            white-space: nowrap;
        }

        .q-right {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.74rem;
        }
        .q-score {
            font-size: 0.74rem;
            font-weight: 700;
            color: #334155;
            white-space: nowrap;
        }
        .q-score.zero {
            color: #94a3b8;
        }
        .q-score.partial {
            color: #b45309;
            font-weight: 800;
        }
        
        /* [중요] 모범답안 표시 제어: 기본 숨김, .show-answers 클래스 존재 시 노출 */
        .q-correct-ans {
            display: none;
            color: #2563eb;
            font-weight: 800;
            font-size: 0.7rem;
            background: #eff6ff;
            padding: 0 3px;
            border-radius: 3px;
            white-space: nowrap;
        }
        .slips-grid.show-answers .q-correct-ans {
            display: inline-block !important;
        }

        /* 하단 행동 미션 (나의 할 일 박스) */
        .slip-todo-box {
            margin-top: 5px;
            padding: 5px 8px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
        }
        .slip-todo-title {
            font-size: 0.72rem;
            font-weight: 800;
            color: #334155;
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .slip-todo-content {
            font-size: 0.74rem;
            color: #1e293b;
            line-height: 1.35;
        }
        .todo-item {
            display: flex;
            align-items: flex-start;
            gap: 4px;
            margin-bottom: 1.5px;
        }
        .todo-chk {
            font-size: 0.82rem;
            line-height: 1;
            font-weight: bold;
            color: #475569;
        }

        /* ===== 인쇄 스타일 (@media print) ===== */
        @media print {
            @page {
                size: A4 portrait;
                margin: 8mm 6mm;
            }
            body {
                background: #ffffff !important;
                color: #000000 !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .preview-container {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .slips-grid {
                box-shadow: none !important;
                border-top: 1px dashed #64748b !important;
                border-left: 1px dashed #64748b !important;
            }
            .slip-card {
                border-right: 1px dashed #64748b !important;
                border-bottom: 1px dashed #64748b !important;
                background: #ffffff !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
            .slip-todo-box {
                background: #fafafa !important;
                border: 1px solid #94a3b8 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            /* O/X/△ 채점 마크 인쇄 색상 유지 */
            .mark-o, .mark-x, .mark-p { 
                color: #dc2626 !important; 
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .q-sub-breakdown {
                color: #64748b !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .q-correct-ans { 
                color: #000000 !important; 
                background: transparent !important; 
                text-decoration: underline; 
            }
            .slip-header { border-bottom-color: #000000 !important; }
        }
    </style>
</head>
<body>

    <!-- 화면 전용 제어 툴바 -->
    <div class="print-control-toolbar no-print">
        
        <!-- 1단: 글로벌 헤더 (타이틀 + 정답표시 토글 + 인쇄 버튼) -->
        <div class="toolbar-top-row">
            <div class="toolbar-title-group">
                <h1>🖨️ 학생 배부용 정오표 일괄 출력</h1>
                <div class="meta-tags">
                    <span class="meta-pill highlight">📋 <?= e($templateTitle) ?></span>
                    <span class="meta-pill">🏫 <?= e($className) ?></span>
                    <span class="meta-pill">총 <?= $totalQuestions ?>문항 (<?= $totalPossibleScore ?>점 만점)</span>
                    <span class="meta-pill">채점 완료: <?= $totalGraded ?>명</span>
                </div>
            </div>

            <div class="toolbar-actions">
                <!-- '정답 표시하기' 인터랙티브 토글 스위치 -->
                <label class="toggle-answer-switch <?= $showAnswer ? 'is-on' : '' ?>" id="toggleAnswerContainer" title="체크 시 정오표에 모범 답안이 노출되고, 해제 시 학생 답안만 노출됩니다.">
                    <input type="checkbox" id="chkShowAnswer" onchange="toggleShowAnswer(this.checked)" <?= $showAnswer ? 'checked' : '' ?>>
                    <span class="switch-slider"></span>
                    <span class="switch-label" id="switchLabelText">
                        <?= $showAnswer ? '💡 정답 표시됨' : '🔒 정답 숨김' ?>
                    </span>
                </label>

                <button type="button" class="btn-secondary" onclick="window.close()">✖️ 닫기</button>
                <button type="button" class="btn-print" onclick="window.print()">🖨️ 인쇄하기 (Ctrl+P)</button>
            </div>
        </div>

        <?php if ($unreviewedCount > 0): ?>
            <div class="unreviewed-banner">
                <div>
                    <span>⚠️ 아직 교사 검수가 완료되지 않은 학생이 <b><?= $unreviewedCount ?>명</b> 있습니다.</span>
                    <span style="font-size:0.82rem; color:#92400e;">(자동 채점 결과를 그대로 인쇄하기 전 검수 완료를 권장합니다.)</span>
                </div>
                <a href="/views/grading/review.php?assign_id=<?= $assignId ?>" target="_blank">📝 채점 검수판 바로가기 ↗</a>
            </div>
        <?php endif; ?>

        <!-- 2단: '나의 할 일' 액션 미션 스튜디오 -->
        <div class="editor-panel">
            <div class="editor-panel-header">
                <div class="editor-title-group">
                    <span>📌 나의 할 일 / 전달 메시지</span>
                    <span class="live-sync-indicator">🟢 실시간 자동 동기화</span>
                </div>
                <div class="preset-chips">
                    <button type="button" class="chip-btn" data-preset="[ ] 시험지 오른쪽 위에 풀로 붙이기" onclick="togglePreset(this.getAttribute('data-preset'))">📎 풀로 붙이기</button>
                    <button type="button" class="chip-btn" data-preset="[ ] 틀린 문제 스스로 다시 풀고 오답 정리하기" onclick="togglePreset(this.getAttribute('data-preset'))">✍️ 오답 정리</button>
                    <button type="button" class="chip-btn" data-preset="시험 보느라 수고 많았어요! 틀린 문제는 성장의 기회입니다." onclick="togglePreset(this.getAttribute('data-preset'))">🌱 칭찬/격려</button>
                    <button type="button" class="chip-btn" data-preset="[ ] 시험 결과 부모님께 보여드리고 확인 서명 받기" onclick="togglePreset(this.getAttribute('data-preset'))">🏠 부모님 확인</button>
                    <button type="button" class="chip-btn btn-clear" onclick="clearTodo()">🚫 비우기</button>
                </div>
            </div>
            <textarea id="todoInput" class="todo-textarea" oninput="syncTodoContent(this.value); updateChipStates();" placeholder="학생들에게 전달할 행동 미션이나 메시지를 입력하세요 (예: [ ] 시험지에 풀로 붙이기)"><?= e($defaultTodoText) ?></textarea>
        </div>

        <!-- 3단: 가나다순 학생 출력 대상 선택 바 -->
        <div class="selection-box">
            <div class="selection-top-bar">
                <div class="selection-actions">
                    <span class="selection-label">출력 대상 학생 (가나다순):</span>
                    <button type="button" class="btn-secondary" style="padding:4px 10px; font-size:0.8rem;" onclick="selectAll(true)">전체 선택</button>
                    <button type="button" class="btn-secondary" style="padding:4px 10px; font-size:0.8rem;" onclick="selectAll(false)">전체 해제</button>
                </div>
                <div class="selection-summary">
                    선택된 학생: <b id="selectedCount"><?= $totalGraded ?></b> / <?= $totalGraded ?>명
                </div>
            </div>
            
            <div class="student-chips-wrapper" id="studentChipsWrapper">
                <?php if (empty($studentSlips)): ?>
                    <span style="font-size:0.82rem; color:#ef4444; padding:4px 0;">⚠️ 채점 완료된 학생 데이터가 없습니다.</span>
                <?php else: ?>
                    <?php foreach ($studentSlips as $stu): ?>
                        <button type="button" 
                                class="student-chip active" 
                                id="chip_stu_<?= $stu['id'] ?>" 
                                data-student-id="<?= $stu['id'] ?>" 
                                onclick="toggleStudentChip(<?= $stu['id'] ?>)"
                                title="클릭 시 가나다순 정오표 출력 대상에서 추가/제외">
                            <span class="chip-name"><?= e($stu['name']) ?></span>
                            <span class="chip-score"><?= $stu['total_score'] ?>점</span>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 인쇄 프리뷰 안내 및 실물 A4 캔버스 -->
    <div class="preview-container">
        <div class="paper-notice no-print">
            <span>✂️ <b>작두질(재단) 가이드:</b> 점선을 따라 자르면 학생 배부용 티켓 슬립으로 분할됩니다.</span>
            <span style="color:#2563eb; font-weight:700;">💡 브라우저 인쇄 설정에서 여백 '기본' 또는 '최소' 권장</span>
        </div>

        <!-- A4 2열 대칭 그리드 (가나다순 배열, show-answers 클래스로 정답 노출 제어) -->
        <div class="slips-grid <?= $showAnswer ? 'show-answers' : '' ?>" id="slipsGrid">
            <?php if (empty($studentSlips)): ?>
                <div style="grid-column: span 2; padding: 60px 20px; text-align: center; color: #64748b; font-size: 1.05rem; line-height: 1.6;">
                    📝 <b>채점 완료된 학생 데이터가 없습니다.</b><br>
                    <span style="font-size: 0.9rem; color: #94a3b8;">학생들이 시험지를 제출하거나 교사 일괄 촬영으로 채점을 진행한 후 다시 출력해 주세요.</span>
                </div>
            <?php else: ?>
                <?php foreach ($studentSlips as $stu): 
                    $orderNum = !empty($stu['order_index']) ? (int)$stu['order_index'] : '';
                ?>
                    <div class="slip-card" id="slip_<?= $stu['id'] ?>" data-student-id="<?= $stu['id'] ?>" data-has-result="1" data-is-reviewed="<?= $stu['is_reviewed'] ? '1' : '0' ?>">
                        
                        <!-- 화면 전용 선택 체크박스 -->
                        <div class="slip-screen-ctl no-print">
                            <label>
                                <input type="checkbox" class="student-select-chk" data-student-id="<?= $stu['id'] ?>" checked onchange="toggleSlip(<?= $stu['id'] ?>, this.checked)">
                                <span>인쇄 포함</span>
                            </label>
                            <span><?= $stu['is_reviewed'] ? '✅ 검수완료' : '⏳ 미검수' ?></span>
                        </div>

                        <!-- 1. 슬립 상단 헤더 -->
                        <div class="slip-header">
                            <div class="slip-header-left">
                                <div class="slip-exam-sub"><?= e($templateTitle) ?> · <?= e($className) ?></div>
                                <div class="slip-student-name">
                                    <?php if ($orderNum): ?>
                                        <span class="slip-student-num"><?= $orderNum ?>번</span>
                                    <?php endif; ?>
                                    <span><?= e($stu['name']) ?></span>
                                </div>
                            </div>
                            <div class="slip-header-right">
                                <div class="slip-score-box">
                                    <span class="slip-score-val"><?= $stu['total_score'] ?></span>
                                    <span class="slip-score-max">/ <?= $totalPossibleScore ?>점</span>
                                </div>
                                <div class="slip-score-sub">
                                    맞은 개수: <b><?= $stu['correct_count'] ?></b>/<?= $totalQuestions ?>
                                </div>
                            </div>
                        </div>

                        <!-- 2. 정오표 문항 채점 결과 (내부 가변 다열 그리드) -->
                        <div class="slip-q-grid">
                            <?php foreach ($stu['questions'] as $q): 
                                $status = $q['status'];
                                $markClass = 'mark-dash';
                                $markIcon  = '-';
                                if ($status === 1) {
                                    $markClass = 'mark-o';
                                    $markIcon  = 'O';
                                } elseif ($status === 0) {
                                    $markClass = 'mark-x';
                                    $markIcon  = 'X';
                                } elseif ($status === 2) {
                                    $markClass = 'mark-p';
                                    $markIcon  = '△';
                                }
                            ?>
                                <div class="q-item">
                                    <div class="q-left">
                                        <span class="q-num"><?= $q['id'] ?></span>
                                        <span class="q-mark <?= $markClass ?>"><?= $markIcon ?></span>
                                        <?php if (!empty($q['sub_breakdown'])): ?>
                                            <span class="q-sub-breakdown"><?= e($q['sub_breakdown']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="q-right">
                                        <!-- 모범답안 태그 (slips-grid.show-answers 클래스에 따라 실시간 토글) -->
                                        <?php if ($status !== 1 && $q['model_answer'] !== ''): ?>
                                            <span class="q-correct-ans" title="모범 답안">답:<?= e($q['model_answer']) ?></span>
                                        <?php endif; ?>
                                        <span class="q-score <?= $status === 0 ? 'zero' : ($status === 2 ? 'partial' : '') ?>"><?= (float)$q['my_score'] ?>점</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- 3. 나의 할 일 / 전달 사항 박스 -->
                        <div class="slip-todo-box">
                            <div class="slip-todo-title">
                                <span>📌 나의 할 일</span>
                            </div>
                            <div class="slip-todo-content" id="todo_content_<?= $stu['id'] ?>"></div>
                        </div>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const assignId = <?= $assignId ?>;

        // HTML 이스케이프 유틸
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // '나의 할 일' 텍스트를 체크박스 HTML로 변환
        function renderTodoHtml(text) {
            const lines = text.split('\n');
            let html = '';
            lines.forEach(line => {
                const trimmed = line.trim();
                if (!trimmed) return;
                if (trimmed.startsWith('[ ]') || trimmed.startsWith('[]')) {
                    const content = trimmed.replace(/^\[\s*\]\s*/, '');
                    html += `<div class="todo-item"><span class="todo-chk">☐</span> <span>${escapeHtml(content)}</span></div>`;
                } else if (trimmed.startsWith('[x]') || trimmed.startsWith('[X]')) {
                    const content = trimmed.replace(/^\[[xX]\]\s*/, '');
                    html += `<div class="todo-item"><span class="todo-chk">☑</span> <span>${escapeHtml(content)}</span></div>`;
                } else {
                    html += `<div class="todo-item"><span>${escapeHtml(trimmed)}</span></div>`;
                }
            });
            return html;
        }

        // 실시간 전체 슬립 동기화
        function syncTodoContent(text) {
            const html = renderTodoHtml(text);
            document.querySelectorAll('.slip-todo-content').forEach(el => {
                el.innerHTML = html;
            });
        }

        // 프리셋 토글 (누르면 추가, 한 번 더 누르면 삭제)
        function togglePreset(presetText) {
            const textarea = document.getElementById('todoInput');
            const target = presetText.trim();
            let lines = textarea.value.split('\n').map(l => l.trim()).filter(l => l.length > 0);
            
            const existingIndex = lines.findIndex(l => l === target || l.includes(target));
            if (existingIndex >= 0) {
                lines.splice(existingIndex, 1);
            } else {
                lines.push(target);
            }
            
            textarea.value = lines.join('\n');
            syncTodoContent(textarea.value);
            updateChipStates();
            textarea.focus();
        }

        // 텍스트 내용에 따라 프리셋 칩의 활성(파란색) 상태 실시간 동기화
        function updateChipStates() {
            const text = document.getElementById('todoInput').value;
            document.querySelectorAll('.chip-btn[data-preset]').forEach(chip => {
                const preset = chip.getAttribute('data-preset').trim();
                if (text.includes(preset)) {
                    chip.classList.add('active');
                } else {
                    chip.classList.remove('active');
                }
            });
        }

        // 전달 메시지 비우기
        function clearTodo() {
            const textarea = document.getElementById('todoInput');
            textarea.value = '';
            syncTodoContent('');
            updateChipStates();
        }

        // [핵심] '정답 표시하기' 실시간 토글 및 DB 백그라운드 동기화
        function toggleShowAnswer(isChecked) {
            const grid = document.getElementById('slipsGrid');
            const container = document.getElementById('toggleAnswerContainer');
            const label = document.getElementById('switchLabelText');

            if (grid) {
                grid.classList.toggle('show-answers', isChecked);
            }
            if (container) {
                container.classList.toggle('is-on', isChecked);
            }
            if (label) {
                label.textContent = isChecked ? '💡 정답 표시됨' : '🔒 정답 숨김';
            }

            // DB 비동기 백그라운드 동기화
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
                    console.warn('정답 공개 상태 저장 경고:', data.error);
                }
            })
            .catch(err => {
                console.error('정답 공개 비동기 저장 실패:', err);
            });
        }

        // 상단 가나다순 학생 칩 클릭 시 해당 학생 슬립 추가/제외 토글
        function toggleStudentChip(studentId) {
            const card = document.getElementById(`slip_${studentId}`);
            const chip = document.getElementById(`chip_stu_${studentId}`);
            const chk = document.querySelector(`.student-select-chk[data-student-id="${studentId}"]`);
            
            if (card) {
                const isCurrentlyExcluded = card.classList.contains('excluded');
                const willBeActive = isCurrentlyExcluded;
                card.classList.toggle('excluded', !willBeActive);
                if (chip) chip.classList.toggle('active', willBeActive);
                if (chk) chk.checked = willBeActive;
            }
            updateSelectedCount();
        }

        // 개별 학생 슬립 인쇄 포함/제외 토글 (슬립 내부 체크박스 클릭 시)
        function toggleSlip(studentId, isChecked) {
            const card = document.getElementById(`slip_${studentId}`);
            const chip = document.getElementById(`chip_stu_${studentId}`);
            if (card) {
                card.classList.toggle('excluded', !isChecked);
            }
            if (chip) {
                chip.classList.toggle('active', isChecked);
            }
            updateSelectedCount();
        }

        // 전체 선택 / 해제
        function selectAll(checkState) {
            document.querySelectorAll('.student-select-chk').forEach(chk => {
                chk.checked = checkState;
            });
            document.querySelectorAll('.slip-card').forEach(card => {
                card.classList.toggle('excluded', !checkState);
            });
            document.querySelectorAll('.student-chip').forEach(chip => {
                chip.classList.toggle('active', checkState);
            });
            updateSelectedCount();
        }

        // 선택 인원 수 업데이트
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.student-chip.active').length;
            const counter = document.getElementById('selectedCount');
            if (counter) counter.textContent = selected;
        }

        // 초기 로드 시 할 일 내용 전체 슬립에 동기화 및 상태 초기화
        document.addEventListener('DOMContentLoaded', () => {
            const initialText = document.getElementById('todoInput').value;
            syncTodoContent(initialText);
            updateChipStates();
            updateSelectedCount();
        });
    </script>
</body>
</html>
