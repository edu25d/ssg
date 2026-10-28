<?php
/**
 * api/grading/submit_images.php
 * OMR 시험지 이미지 업로드, ROI 크롭 및 Gemini AI 자동 채점 처리
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';
require_once APP_ROOT . '/includes/grading/objective.php';

// [함수] 정답 유형 판별
function getAttributeFromAnswer($ans) {
    $isChar = preg_match('/[ㄱ-ㅎ]/u', $ans);
    $isMulti = strpos($ans, ',') !== false;
    if ($isChar) return $isMulti ? 'Char Multi' : 'Char Single';
    return $isMulti ? 'Num Multi' : 'Num Single';
}

// [함수] 객관식/주관식 결과 병합
function mergeGradingResults($objResults, $subjCrops) {
    $merged = [];
    if (is_array($objResults)) {
        foreach ($objResults as $item) {
            $status = $item['status'] ?? 0;
            $score = $item['score'] ?? $item['max_score'] ?? 0; 
            $currentScore = ($status === 1) ? $score : (($status === 2) ? ($item['currentScore'] ?? 0) : 0);
            $merged[] = [
                'item_id'      => $item['item_id'] ?? $item['id'] ?? 0, 
                'id'           => $item['id'] ?? $item['item_id'] ?? 0,
                'display_num'  => $item['display_num'] ?? (string)($item['item_id'] ?? $item['id'] ?? 0),
                'sub_id'       => $item['sub_id'] ?? null,
                'page'         => (int)($item['page'] ?? 1),
                'type'         => $item['type'] ?? 'objective', 
                'score'        => $score,
                'currentScore' => $currentScore,
                'status'       => $status,
                'answer'       => $item['answer'] ?? '',
                'rawText'      => $item['rawText'] ?? '',
                'crop_url'     => $item['crop_url'] ?? $item['crop_image_url'] ?? '',
                'image_path'   => $item['image_path'] ?? ''
            ];
        }
    }
    if (is_array($subjCrops)) {
        foreach ($subjCrops as $item) {
            $merged[] = [
                'item_id'      => $item['item_id'] ?? $item['id'] ?? 0,
                'id'           => $item['id'] ?? $item['item_id'] ?? 0,
                'display_num'  => $item['display_num'] ?? (string)($item['item_id'] ?? $item['id'] ?? 0),
                'sub_id'       => $item['sub_id'] ?? null,
                'page'         => (int)($item['page'] ?? 1),
                'type'         => 'subjective',
                'score'        => $item['score'] ?? $item['max_score'] ?? 0,
                'currentScore' => $item['currentScore'] ?? 0,
                'status'       => -1, 
                'answer'       => $item['answer'] ?? '', 
                'crop_url'     => $item['crop_url'] ?? $item['crop_image_url'] ?? '',
                'image_path'   => $item['image_path'] ?? ''
            ];
        }
    }
    usort($merged, function($a, $b) {
        return strnatcmp((string)($a['display_num'] ?? $a['item_id']), (string)($b['display_num'] ?? $b['item_id']));
    });
    return $merged;
}

try {
    // ==========================================
    // [1단계] 제출 데이터 및 권한 수신
    // ==========================================
    $isLoggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['student_id']) || !empty($_SESSION['kiosk_class_id']);
    if (!$isLoggedIn) {
        throw new Exception("로그인이 필요합니다.");
    }

    $assignmentId = (int)($_POST['assignment_id'] ?? $_POST['assign_id'] ?? 0);
    $students     = json_decode($_POST['students_json'] ?? '[]', true);

    if (empty($students) && !empty($_FILES)) {
        // 단일 학생 다쪽 또는 단일 이미지 제출 키 수집 (SSOT Contract C)
        $pageFiles = [];
        $pIdx = 0;
        while (isset($_FILES["image_{$pIdx}"])) {
            $pageFiles[] = "image_{$pIdx}";
            $pIdx++;
        }
        if (empty($pageFiles)) {
            $pageFiles = [array_key_first($_FILES)];
        }
        $students = [[
            'student_name' => trim($_POST['student_name'] ?? $_POST['file_name'] ?? '학생'), 
            'student_id'   => (int)($_POST['student_id'] ?? 0), 
            'file_keys'    => $pageFiles,
            'file_key'     => $pageFiles[0] ?? ''
        ]];
    }

    if (!$assignmentId || empty($students)) {
        throw new Exception("필수 제출 정보가 누락되었습니다.");
    }

    $stmt = $pdo->prepare("
        SELECT a.template_id, a.exam_name, c.class_name, t.roi_data, t.pages_data, t.image_path 
        FROM assignments a 
        JOIN templates t ON a.template_id = t.id 
        LEFT JOIN classes c ON a.class_id = c.id 
        WHERE a.id = :aid
    ");
    $stmt->execute(['aid' => $assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        throw new Exception('평가 정보를 찾을 수 없습니다.');
    }

    $roiList = json_decode($assignment['roi_data'], true) ?: [];

    // 템플릿 원본 페이지별 해상도 맵 구축 (SSOT Contract A)
    $templatePages = json_decode($assignment['pages_data'] ?? '[]', true) ?: [];
    $tplPageDims = [];
    foreach ($templatePages as $p) {
        $pNum = (int)($p['page_num'] ?? 1);
        $tW = (int)($p['width'] ?? 0);
        $tH = (int)($p['height'] ?? 0);
        if ($tW > 0 && $tH > 0) {
            $tplPageDims[$pNum] = ['w' => $tW, 'h' => $tH];
        }
    }
    // 누락 시 실제 템플릿 1쪽 이미지 파일에서 물리적 해상도 측정
    if (empty($tplPageDims) && !empty($assignment['image_path'])) {
        $tplImgPath = APP_ROOT . $assignment['image_path'];
        if (file_exists($tplImgPath)) {
            $sz = @getimagesize($tplImgPath);
            if ($sz) {
                $tplPageDims[1] = ['w' => (int)$sz[0], 'h' => (int)$sz[1]];
            }
        }
    }

    // ==========================================
    // [2단계] 이미지 저장 디렉토리 준비
    // ==========================================
    $pdo->beginTransaction();
    $processedResults = [];
    $tasks = [];

    $uploadDir   = APP_ROOT . '/public/uploads/crops/' . $assignmentId;
    $originalDir = APP_ROOT . '/public/uploads/originals/' . $assignmentId;
    if (!is_dir($uploadDir))   @mkdir($uploadDir, 0777, true);
    if (!is_dir($originalDir)) @mkdir($originalDir, 0777, true);

    // ==========================================
    // [3단계] 학생별 제출 이미지 처리 루프 (다쪽 라우팅 크롭)
    // ==========================================
    foreach ($students as $student) {
        $studentName = trim($student['student_name'] ?? '');
        $studentId   = (int)($student['student_id'] ?? 0);

        // 학생 제출 파일 키 목록 수집 (단일/다쪽/일괄 호환)
        $targetFileKeys = [];
        if (!empty($student['file_keys']) && is_array($student['file_keys'])) {
            $targetFileKeys = $student['file_keys'];
        } elseif (!empty($student['page_files']) && is_array($student['page_files'])) {
            $targetFileKeys = $student['page_files'];
        } elseif (!empty($student['file_key'])) {
            $targetFileKeys = [$student['file_key']];
        }

        $pageImages = [];
        $pageOriginalUrls = [];
        $pageDims = [];
        $isMultiPageStudent = count($targetFileKeys) > 1;

        // 각 페이지별 원본 파일 저장 및 메모리 로드
        foreach ($targetFileKeys as $pIdx => $fKey) {
            $pNum = $pIdx + 1;
            if (!isset($_FILES[$fKey]) || $_FILES[$fKey]['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            $imgData = file_get_contents($_FILES[$fKey]['tmp_name']);
            if (!$imgData) continue;

            // 원본 파일명: 1쪽 단일이면 {studentId}.jpg, 다쪽이면 {studentId}_p{pNum}.jpg
            $origFileName = $isMultiPageStudent ? sprintf("%d_p%d.jpg", $studentId, $pNum) : sprintf("%d.jpg", $studentId);
            $origSavePath = $originalDir . '/' . $origFileName;
            file_put_contents($origSavePath, $imgData);
            $pageOriginalUrls[$pNum] = '/public/uploads/originals/' . $assignmentId . '/' . $origFileName;

            $srcImage = @imagecreatefromstring($imgData);
            if ($srcImage) {
                $w = imagesx($srcImage);
                $h = imagesy($srcImage);
                $pageImages[$pNum] = $srcImage;
                $pageDims[$pNum] = ['w' => $w, 'h' => $h];
            }
        }

        if (empty($pageImages)) {
            continue;
        }

        $objectiveItems = [];
        $subjectiveItems = [];

        // 크롭 진행 루프 (문항별 소속 페이지 라우팅 크롭)
        foreach ($roiList as $idx => $roi) {
            $rawId = $roi['id'] ?? $roi['item_id'] ?? $roi['num'] ?? ($idx + 1);
            $subId = $roi['sub_id'] ?? null;
            $displayNum = (string)($roi['display_num'] ?? ($subId ? "{$rawId}-{$subId}" : (string)$rawId));
            $itemId = $displayNum;
            $targetPage = (int)($roi['page'] ?? 1);
            if ($targetPage < 1) $targetPage = 1;

            // 해당 문항의 소속 페이지 원본 이미지 선택 (누락 시 1쪽 폴백)
            $srcImage = $pageImages[$targetPage] ?? ($pageImages[1] ?? reset($pageImages));
            if (!$srcImage) continue;

            $imgW = $pageDims[$targetPage]['w'] ?? imagesx($srcImage);
            $imgH = $pageDims[$targetPage]['h'] ?? imagesy($srcImage);

            // 템플릿 원본 대비 실제 제출 이미지의 정밀 2차원 스케일 팩터 계산
            $tplW = $tplPageDims[$targetPage]['w'] ?? ($tplPageDims[1]['w'] ?? 1200);
            $tplH = $tplPageDims[$targetPage]['h'] ?? ($tplPageDims[1]['h'] ?? 1700);
            $scaleX = $imgW / (float)$tplW;
            $scaleY = $imgH / (float)$tplH;

            $targetRoi = $roi['ans_roi'] ?? $roi['num_roi'] ?? $roi;
            if (!isset($targetRoi['x'], $targetRoi['y'], $targetRoi['w'], $targetRoi['h'])) continue;

            $w = $targetRoi['w'] * $scaleX;
            $h = $targetRoi['h'] * $scaleY;
            $cx = ($targetRoi['x'] * $scaleX) + ($w / 2);
            $cy = ($targetRoi['y'] * $scaleY) + ($h / 2);
            
            $newW = $w * 1.15;
            $newH = $h * 1.15;
            $newX = max(0, min($cx - ($newW / 2), $imgW - 10));
            $newY = max(0, min($cy - ($newH / 2), $imgH - 10));
            $newW = max(10, min($newW, $imgW - $newX));
            $newH = max(10, min($newH, $imgH - $newY));

            $cropRes = @imagecrop($srcImage, ['x' => (int)$newX, 'y' => (int)$newY, 'width' => (int)$newW, 'height' => (int)$newH]);
            if ($cropRes === false) {
                $cropRes = imagecreatetruecolor(10, 10);
            }
            
            $safeItemKey = str_replace(['-', ' '], ['_', ''], $itemId);
            $fileName = sprintf("student_%d_item_%s_p%d_%s.jpg", $studentId, $safeItemKey, $targetPage, uniqid());
            $savePath = $uploadDir . '/' . $fileName;
            imagejpeg($cropRes, $savePath, 75);
            $cropUrl = '/public/uploads/crops/' . $assignmentId . '/' . $fileName;
            $itemOriginalUrl = $pageOriginalUrls[$targetPage] ?? ($pageOriginalUrls[1] ?? reset($pageOriginalUrls));

            $type = strtolower($roi['type'] ?? 'objective');
            $isComposite = ($type === 'composite');
            $isSubj = in_array($type, ['subjective', 'essay', 'short', 'text', '주관식']) || ($type === 'sub_item' && ($roi['sub_type'] ?? '') === 'subjective');

            if ($isComposite && !empty($roi['ans_rois']) && is_array($roi['ans_rois']) && count($roi['ans_rois']) >= 2) {
                // ===== 복합 다답형 전용 처리: 복수 ans_rois 각각 크롭 후 하나의 묶음으로 전달 =====
                $compositeSubCrops = [];
                foreach ($roi['ans_rois'] as $arIdx => $ar) {
                    if (!isset($ar['x'], $ar['y'], $ar['w'], $ar['h'])) continue;

                    $arW = $ar['w'] * $scaleX;
                    $arH = $ar['h'] * $scaleY;
                    $arCx = ($ar['x'] * $scaleX) + ($arW / 2);
                    $arCy = ($ar['y'] * $scaleY) + ($arH / 2);
                    $arNewW = $arW * 1.15;
                    $arNewH = $arH * 1.15;
                    $arNewX = max(0, min($arCx - ($arNewW / 2), $imgW - 10));
                    $arNewY = max(0, min($arCy - ($arNewH / 2), $imgH - 10));
                    $arNewW = max(10, min($arNewW, $imgW - $arNewX));
                    $arNewH = max(10, min($arNewH, $imgH - $arNewY));

                    $arCropRes = @imagecrop($srcImage, ['x' => (int)$arNewX, 'y' => (int)$arNewY, 'width' => (int)$arNewW, 'height' => (int)$arNewH]);
                    if ($arCropRes === false) $arCropRes = imagecreatetruecolor(10, 10);
                    $compositeSubCrops[] = $arCropRes;
                }

                // 복합 크롭들을 하나의 composite objectiveItem으로 등록 (image_res_arr로 전달)
                $safeItemKey = str_replace(['-', ' '], ['_', ''], $itemId);
                
                // 복합 다답형 대표 이미지: 모든 서브 크롭을 가로로 나란히 합성하여 하나의 이미지로 저장
                $repFileName = sprintf("student_%d_item_%s_p%d_%s.jpg", $studentId, $safeItemKey, $targetPage, uniqid());
                $repSavePath = $uploadDir . '/' . $repFileName;
                
                if (!empty($compositeSubCrops)) {
                    $pad = 6;
                    $combinedW = array_sum(array_map('imagesx', $compositeSubCrops)) + ($pad * (count($compositeSubCrops) + 1));
                    $maxH = max(array_map('imagesy', $compositeSubCrops));
                    $combinedH = $maxH + ($pad * 2);

                    $combinedRes = imagecreatetruecolor($combinedW, $combinedH);
                    $bgCol = imagecolorallocate($combinedRes, 248, 250, 252);
                    imagefill($combinedRes, 0, 0, $bgCol);
                    $borderCol = imagecolorallocate($combinedRes, 203, 213, 225);

                    $curX = $pad;
                    foreach ($compositeSubCrops as $ci => $subCr) {
                        $sW = imagesx($subCr);
                        $sH = imagesy($subCr);
                        imagecopy($combinedRes, $subCr, $curX, $pad, 0, 0, $sW, $sH);
                        // 서브 크롭 외곽선
                        imagerectangle($combinedRes, $curX - 1, $pad - 1, $curX + $sW, $pad + $sH, $borderCol);
                        $curX += $sW + $pad;
                    }
                    imagejpeg($combinedRes, $repSavePath, 85);
                    imagedestroy($combinedRes);
                }
                
                $cropUrl = '/public/uploads/crops/' . $assignmentId . '/' . $repFileName;
                $itemOriginalUrl = $pageOriginalUrls[$targetPage] ?? ($pageOriginalUrls[1] ?? reset($pageOriginalUrls));

                $objectiveItems[] = [
                    'id'             => $itemId,
                    'item_id'        => $itemId,
                    'display_num'    => $displayNum,
                    'sub_id'         => $subId,
                    'page'           => $targetPage,
                    'score'          => $roi['score'] ?? 0,
                    'answer'         => $roi['answer'] ?? '',
                    'attribute'      => getAttributeFromAnswer($roi['answer'] ?? ''),
                    'type'           => 'composite',
                    'image_res'      => $compositeSubCrops[0] ?? imagecreatetruecolor(10, 10),
                    'image_res_arr'  => $compositeSubCrops, // composite 전용: 복수 크롭 배열
                    'is_composite'   => true,
                    'crop_url'       => $cropUrl,
                    'crop_image_url' => $cropUrl,
                    'image_path'     => $itemOriginalUrl
                ];
            } elseif ($isSubj) {
                $subjectiveItems[] = [
                    'id'             => $itemId,
                    'item_id'        => $itemId,
                    'display_num'    => $displayNum,
                    'sub_id'         => $subId,
                    'page'           => $targetPage,
                    'score'          => $roi['score'] ?? 0,
                    'answer'         => $roi['answer'] ?? '',
                    'crop_url'       => $cropUrl,
                    'crop_image_url' => $cropUrl,
                    'image_path'     => $itemOriginalUrl
                ];
                imagedestroy($cropRes);
            } else {
                $objectiveItems[] = [
                    'id'             => $itemId,
                    'item_id'        => $itemId,
                    'display_num'    => $displayNum,
                    'sub_id'         => $subId,
                    'page'           => $targetPage,
                    'score'          => $roi['score'] ?? 0,
                    'answer'         => $roi['answer'] ?? '',
                    'attribute'      => getAttributeFromAnswer($roi['answer'] ?? ''),
                    'image_res'      => $cropRes,
                    'crop_url'       => $cropUrl,
                    'crop_image_url' => $cropUrl,
                    'image_path'     => $itemOriginalUrl
                ];
            }
        }

        // 엔지니어링 원칙 2: 크롭 완료 즉시 GD 이미지 리소스 완전 해제 (메모리 누수 원천 차단)
        foreach ($pageImages as $pImg) {
            if (!empty($pImg) && (is_resource($pImg) || is_object($pImg))) {
                @imagedestroy($pImg);
            }
        }

        // 4단계: 초기 제출 레코드 생성 (비동기 낙관적 UI를 위해 먼저 안전 저장)
        $initialObjectiveItems = [];
        foreach ($objectiveItems as $oi) {
            $initialObjectiveItems[] = [
                'item_id'        => $oi['id'],
                'id'             => $oi['id'],
                'page'           => $oi['page'] ?? 1,
                'type'           => 'objective',
                'score'          => $oi['score'] ?? 0,
                'currentScore'   => 0,
                'status'         => -1,
                'answer'         => $oi['answer'] ?? '',
                'rawText'        => '판독대기',
                'crop_url'       => $oi['crop_url'] ?? '',
                'crop_image_url' => $oi['crop_image_url'] ?? '',
                'image_path'     => $oi['image_path'] ?? ''
            ];
        }

        $primaryOriginalUrl = $pageOriginalUrls[1] ?? (reset($pageOriginalUrls) ?: '');
        $initialRawResults = mergeGradingResults($initialObjectiveItems, $subjectiveItems);
        foreach ($initialRawResults as &$rItem) {
            if (empty($rItem['image_path'])) {
                $rItem['image_path'] = $primaryOriginalUrl;
            }
        }
        unset($rItem);

        $hasSubjective = !empty($subjectiveItems);
        $initialJsonPayload = json_encode($initialRawResults, JSON_UNESCAPED_UNICODE);

        $stmtChk = $pdo->prepare("SELECT id FROM exam_results WHERE assignment_id = ? AND student_id = ?");
        $stmtChk->execute([$assignmentId, $studentId]);
        $existingId = $stmtChk->fetchColumn();

        if ($existingId) {
            $recordId = (int)$existingId;
            $pdo->prepare("
                UPDATE exam_results 
                SET total_score = 0, is_reviewed = 0, raw_results = ?, submitted_at = NOW() 
                WHERE id = ?
            ")->execute([$initialJsonPayload, $recordId]);
        } else {
            $pdo->prepare("
                INSERT INTO exam_results (assignment_id, student_id, total_score, is_reviewed, raw_results, submitted_at) 
                VALUES (?, ?, 0, 0, ?, NOW())
            ")->execute([$assignmentId, $studentId, $initialJsonPayload]);
            $recordId = (int)$pdo->lastInsertId();
        }

        $processedResults[] = ['db_id' => $recordId, 'student_name' => $studentName, 'student_id' => $studentId];

        $tasks[] = [
            'record_id'               => $recordId,
            'objective_items'         => $objectiveItems,
            'subjective_items'        => $subjectiveItems,
            'initial_objective_items' => $initialObjectiveItems,
            'has_subjective'          => $hasSubjective,
            'local_original_url'      => $primaryOriginalUrl
        ];
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    // [방안 B: 비동기 실시간 갱신형]
    // 세션 잠금 해제 (학생 대시보드 화면 및 폴링 API가 세션 파일 락에 걸려 지연되지 않도록 방지)
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $fastcgiSupported = function_exists('fastcgi_finish_request');
    if ($fastcgiSupported) {
        ignore_user_abort(true);
        set_time_limit(120);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => '제출이 완료되었습니다. AI 채점을 실시간 진행합니다.',
            'data'    => $processedResults
        ], JSON_UNESCAPED_UNICODE);

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        fastcgi_finish_request();
    }

    // 5단계: Gemini AI 객관식 채점 수행 (FastCGI 환경이면 백그라운드, 아니면 동기 수행)
    foreach ($tasks as $taskIdx => $task) {
        if ($taskIdx > 0) {
            // Google API Rate Limit 방지를 위한 0.5초 안전 딜레이
            usleep(500000);
        }

        $recordId              = $task['record_id'];
        $objectiveItems        = $task['objective_items'];
        $subjectiveItems       = $task['subjective_items'];
        $initialObjectiveItems = $task['initial_objective_items'];
        $hasSubjective         = $task['has_subjective'];
        $localOriginalUrl      = $task['local_original_url'];

        $objectiveResults = gradeObjectiveItems($objectiveItems);

        if (!empty($objectiveResults['debug']['error'])) {
            @file_put_contents(APP_ROOT . '/gemini_debug.log', date('Y-m-d H:i:s') . ' [Objective Error] ' . json_encode($objectiveResults['debug'], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        }

        foreach ($objectiveItems as $oi) {
            if (!empty($oi['image_res']) && (is_resource($oi['image_res']) || is_object($oi['image_res']))) {
                @imagedestroy($oi['image_res']);
            }
            // composite 전용: 복수 크롭 배열 해제
            if (!empty($oi['image_res_arr']) && is_array($oi['image_res_arr'])) {
                foreach ($oi['image_res_arr'] as $subCrop) {
                    if ($subCrop && (is_resource($subCrop) || is_object($subCrop))) {
                        @imagedestroy($subCrop);
                    }
                }
            }
        }

        $actualObjectiveItems = $objectiveResults['items'] ?? [];
        if (empty($actualObjectiveItems) && !empty($objectiveItems)) {
            $actualObjectiveItems = $initialObjectiveItems;
        }

        $finalRawResults = mergeGradingResults($actualObjectiveItems, $subjectiveItems);
        $finalTotalScore = array_reduce($finalRawResults, function($acc, $item) {
            return $acc + (float)($item['currentScore'] ?? 0);
        }, 0);

        foreach ($finalRawResults as &$rItem) {
            if (empty($rItem['image_path'])) {
                $rItem['image_path'] = $localOriginalUrl;
            }
        }
        unset($rItem);

        $isReviewed = $hasSubjective ? 0 : 1;
        $finalJsonPayload = json_encode($finalRawResults, JSON_UNESCAPED_UNICODE);

        // 채점 완료 결과로 DB 즉각 업데이트
        $updateStmt = $pdo->prepare("UPDATE exam_results SET total_score = ?, is_reviewed = ?, raw_results = ?, submitted_at = NOW() WHERE id = ?");
        $updateStmt->execute([$finalTotalScore, $isReviewed, $finalJsonPayload, $recordId]);
    }

    if (!$fastcgiSupported) {
        jsonSuccess(['data' => $processedResults], '채점 처리가 완료되었습니다.');
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('채점 처리 실패: ' . $e->getMessage());
}
