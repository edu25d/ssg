<?php
/**
 * api/grading/expand_crop.php
 * 채점 검토 화면에서 판독 이미지 영역(상/하/좌/우) 동적 확장 API
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$resultId  = (int)($_GET['db_id'] ?? 0);
$itemId    = $_GET['item_id'] ?? '';
$direction = $_GET['direction'] ?? '';
$subIndex  = isset($_GET['sub_index']) ? (int)$_GET['sub_index'] : 0;

if (!$resultId || $itemId === '' || !in_array($direction, ['top', 'bottom', 'left', 'right'])) {
    jsonError('잘못된 요청 파라미터입니다.');
}

try {
    $stmt = $pdo->prepare("
        SELECT er.raw_results, t.roi_data, t.pages_data, t.image_path AS tpl_image_path 
        FROM exam_results er
        JOIN assignments a ON er.assignment_id = a.id
        JOIN templates t ON a.template_id = t.id
        WHERE er.id = :id
    ");
    $stmt->execute(['id' => $resultId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        jsonError('데이터를 찾을 수 없습니다.');
    }

    $rawResults  = json_decode($data['raw_results'] ?? '[]', true) ?: [];
    
    // 템플릿 원본 페이지별 해상도 맵 구축 (SSOT Contract A)
    $templatePages = json_decode($data['pages_data'] ?? '[]', true) ?: [];
    $tplPageDims = [];
    foreach ($templatePages as $p) {
        $pNum = (int)($p['page_num'] ?? 1);
        $tW = (int)($p['width'] ?? 0);
        $tH = (int)($p['height'] ?? 0);
        if ($tW > 0 && $tH > 0) {
            $tplPageDims[$pNum] = ['w' => $tW, 'h' => $tH];
        }
    }
    if (empty($tplPageDims) && !empty($data['tpl_image_path'])) {
        $tplImgPath = APP_ROOT . $data['tpl_image_path'];
        if (file_exists($tplImgPath)) {
            $sz = @getimagesize($tplImgPath);
            if ($sz) {
                $tplPageDims[1] = ['w' => (int)$sz[0], 'h' => (int)$sz[1]];
            }
        }
    }

    // 타겟 문항 검색 및 해당 문항의 소속 페이지 원본 이미지 경로 추출 (SSOT Contract D)
    $targetItem = null;
    $resultItemIndex = -1;
    $currentRoi = null;

    foreach ($rawResults as $index => $item) {
        $currentId = (string)($item['display_num'] ?? ($item['id'] ?? ($item['item_id'] ?? '')));
        if ($currentId === (string)$itemId || (string)($item['id'] ?? '') === (string)$itemId || (string)($item['item_id'] ?? '') === (string)$itemId) {
            $targetItem = $item;
            $resultItemIndex = $index;
            if (isset($item['current_roi'])) {
                $currentRoi = $item['current_roi'];
            }
            break;
        }
    }

    $targetPage = (int)($targetItem['page'] ?? 1);
    if ($targetPage < 1) $targetPage = 1;

    // targetPage와 일치하는 이미지를 rawResults에서 탐색 (2페이지 문항이면 2페이지 이미지 사용)
    $imgPath     = $targetItem['image_path'] ?? null;
    $driveFileId = $targetItem['drive_file_id'] ?? null;
    if (!$imgPath && !$driveFileId) {
        // targetItem에 이미지 정보가 없으면 같은 페이지의 다른 문항에서 찾음
        foreach ($rawResults as $item) {
            if ((int)($item['page'] ?? 1) === $targetPage && (!empty($item['image_path']) || !empty($item['drive_file_id']))) {
                $imgPath     = $item['image_path'] ?? null;
                $driveFileId = $item['drive_file_id'] ?? null;
                break;
            }
        }
    }
    // 그래도 없으면 0번 인덱스(1페이지) 폴백
    if (!$imgPath && !$driveFileId) {
        $imgPath     = $rawResults[0]['image_path'] ?? ($rawResults['image_path'] ?? '');
        $driveFileId = $rawResults[0]['drive_file_id'] ?? null;
    }


    $physicalPath = '';

    // 구글 드라이브 이미지 캐시 처리
    if (strpos($imgPath, 'drive:') === 0 || $driveFileId) {
        if (!$driveFileId) {
            $driveFileId = str_replace('drive:', '', $imgPath);
        }
        
        $cacheDir = APP_ROOT . '/public/uploads/temp_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        
        $physicalPath = $cacheDir . '/' . $driveFileId . '.jpg';
        
        if (!file_exists($physicalPath)) {
            $userId = $_SESSION['user_id'] ?? null;
            if (!$userId) {
                jsonError('로그인이 필요합니다. (토큰 조회 불가)', 401);
            }
            
            $accessToken = getValidAccessToken($pdo, $userId);
            if (!$accessToken) {
                jsonError('구글 드라이브 연동 상태를 확인해 주세요.');
            }
            
            $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$driveFileId}?alt=media");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer " . $accessToken],
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $fileData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $fileData) {
                file_put_contents($physicalPath, $fileData);
            } else {
                jsonError('구글 드라이브에서 이미지를 임시 다운로드할 수 없습니다.');
            }
        }
    } else if (!empty($imgPath)) {
        // 로컬 이미지 경로 해석
        $cleanPath = ltrim($imgPath, '/');
        // /public/uploads/ 또는 /uploads/ 매핑
        if (strpos($cleanPath, 'public/') === 0) {
            $physicalPath = APP_ROOT . '/' . $cleanPath;
        } else {
            $physicalPath = APP_ROOT . '/public/' . $cleanPath;
            if (!file_exists($physicalPath)) {
                $physicalPath = APP_ROOT . '/' . $cleanPath;
            }
        }
    }

    if (empty($physicalPath) || !file_exists($physicalPath)) {
        jsonError('원본 이미지를 찾을 수 없습니다.');
    }

    $ext = strtolower(pathinfo($physicalPath, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $img = @imagecreatefromjpeg($physicalPath);
    } elseif ($ext === 'png') {
        $img = @imagecreatefrompng($physicalPath);
    } else {
        jsonError('지원하지 않는 이미지 형식입니다.');
    }

    if (!$img) {
        jsonError('이미지 파일을 읽을 수 없습니다.');
    }

    $imgW = imagesx($img);
    $imgH = imagesy($img);

    // 템플릿 원본 해상도 대비 스케일 계산 ($targetPage 없으면 이미지 크기로 fallback)
    if (!isset($tplPageDims[$targetPage])) {
        $tplPageDims[$targetPage] = ['w' => $imgW, 'h' => $imgH];
    }
    $tplW   = $tplPageDims[$targetPage]['w'] ?? ($tplPageDims[1]['w'] ?? 1200);
    $tplH   = $tplPageDims[$targetPage]['h'] ?? ($tplPageDims[1]['h'] ?? 1700);
    $scaleX = $imgW / (float)$tplW;
    $scaleY = $imgH / (float)$tplH;


    // 템플릿 ROI 메타데이터 조회
    $roiList = json_decode($data['roi_data'], true) ?: [];
    $matchedTemplateRoi = null;
    foreach ($roiList as $idx => $roi) {
        $rawId = $roi['id'] ?? $roi['item_id'] ?? $roi['num'] ?? ($idx + 1);
        $subId = $roi['sub_id'] ?? null;
        $displayNum = (string)($roi['display_num'] ?? ($subId ? "{$rawId}-{$subId}" : (string)$rawId));
        if ($displayNum === (string)$itemId || (string)$rawId === (string)$itemId) {
            $matchedTemplateRoi = $roi;
            break;
        }
    }

    $isComposite = ($matchedTemplateRoi && ($matchedTemplateRoi['type'] ?? '') === 'composite' && !empty($matchedTemplateRoi['ans_rois']) && is_array($matchedTemplateRoi['ans_rois']));

    $expandPx = 25; // 템플릿 기준 확장 픽셀

    if ($isComposite) {
        // 복합 다답형: 여러 개의 서브 ROI 관리
        $currentRois = $targetItem['current_rois'] ?? null;
        if (!$currentRois || !is_array($currentRois)) {
            // 초기 확장 시 15% 기본 마진 적용
            $currentRois = [];
            foreach ($matchedTemplateRoi['ans_rois'] as $ar) {
                $x = (float)$ar['x']; $y = (float)$ar['y']; $w = (float)$ar['w']; $h = (float)$ar['h'];
                $cx = $x + ($w / 2); $cy = $y + ($h / 2);
                $w = $w * 1.15; $h = $h * 1.15;
                $currentRois[] = [
                    'x' => $cx - ($w / 2),
                    'y' => $cy - ($h / 2),
                    'w' => $w,
                    'h' => $h
                ];
            }
        }

        $targetSubIdx = max(0, min($subIndex, count($currentRois) - 1));

        // 방향에 따라 ROI 확대
        if ($direction === 'top') {
            $currentRois[$targetSubIdx]['y'] -= $expandPx;
            $currentRois[$targetSubIdx]['h'] += $expandPx;
        } elseif ($direction === 'bottom') {
            $currentRois[$targetSubIdx]['h'] += $expandPx;
        } elseif ($direction === 'left') {
            $currentRois[$targetSubIdx]['x'] -= $expandPx;
            $currentRois[$targetSubIdx]['w'] += $expandPx;
        } elseif ($direction === 'right') {
            $currentRois[$targetSubIdx]['w'] += $expandPx;
        }
        // ROI가 템플릿 경계를 벗어나지 않도록 클램핑
        $currentRois[$targetSubIdx]['x'] = max(0, $currentRois[$targetSubIdx]['x']);
        $currentRois[$targetSubIdx]['y'] = max(0, $currentRois[$targetSubIdx]['y']);
        $currentRois[$targetSubIdx]['w'] = min($tplW - $currentRois[$targetSubIdx]['x'], $currentRois[$targetSubIdx]['w']);
        $currentRois[$targetSubIdx]['h'] = min($tplH - $currentRois[$targetSubIdx]['y'], $currentRois[$targetSubIdx]['h']);


        // 모든 서브 괄호를 각각 크롭
        $subCrops = [];
        foreach ($currentRois as $cRoi) {
            $x_real = max(0, min($cRoi['x'] * $scaleX, $imgW - 10));
            $y_real = max(0, min($cRoi['y'] * $scaleY, $imgH - 10));
            $w_real = max(10, min($cRoi['w'] * $scaleX, $imgW - $x_real));
            $h_real = max(10, min($cRoi['h'] * $scaleY, $imgH - $y_real));

            $cr = @imagecrop($img, [
                'x'      => (int)$x_real,
                'y'      => (int)$y_real,
                'width'  => (int)$w_real,
                'height' => (int)$h_real
            ]);
            $subCrops[] = $cr ?: imagecreatetruecolor(10, 10);
        }

        // 가로 스트립으로 통합 합성
        $pad = 6;
        $combinedW = array_sum(array_map('imagesx', $subCrops)) + ($pad * (count($subCrops) + 1));
        $maxH = max(array_map('imagesy', $subCrops));
        $combinedH = $maxH + ($pad * 2);

        $combinedRes = imagecreatetruecolor($combinedW, $combinedH);
        $bgCol = imagecolorallocate($combinedRes, 248, 250, 252);
        imagefill($combinedRes, 0, 0, $bgCol);
        $borderCol = imagecolorallocate($combinedRes, 203, 213, 225);

        $curX = $pad;
        foreach ($subCrops as $ci => $subCr) {
            $sW = imagesx($subCr);
            $sH = imagesy($subCr);
            imagecopy($combinedRes, $subCr, $curX, $pad, 0, 0, $sW, $sH);
            imagerectangle($combinedRes, $curX - 1, $pad - 1, $curX + $sW, $pad + $sH, $borderCol);
            $curX += $sW + $pad;
            imagedestroy($subCr);
        }

        ob_start();
        imagejpeg($combinedRes, null, 90);
        $base64 = 'data:image/jpeg;base64,' . base64_encode(ob_get_clean());

        imagedestroy($combinedRes);
        imagedestroy($img);

        // DB에 current_rois 배열 보존
        if ($resultItemIndex !== -1) {
            $rawResults[$resultItemIndex]['current_rois'] = $currentRois;
            $upStmt = $pdo->prepare("UPDATE exam_results SET raw_results = :res WHERE id = :id");
            $upStmt->execute([
                'res' => json_encode($rawResults, JSON_UNESCAPED_UNICODE),
                'id'  => $resultId
            ]);
        }

        jsonSuccess([
            'new_image_base64' => $base64,
            'sub_index' => $targetSubIdx,
            'is_composite' => true
        ]);

    } else {
        // 단일 일반 문항 처리
        $isFirstExpansion = false;
        // 저장된 current_roi가 현재 템플릿 페이지 범위를 크게 벗어나면 (구형 버그 데이터) 무효화
        if ($currentRoi) {
            $rx = (float)$currentRoi['x'];
            $ry = (float)$currentRoi['y'];
            $rw = (float)$currentRoi['w'];
            $rh = (float)$currentRoi['h'];
            if ($rx < 0 || $ry < 0 || $rx > $tplW * 1.1 || $ry > $tplH * 1.1 || $rw <= 0 || $rh <= 0) {
                $currentRoi = null; // 범위 이탈 → 폐기하고 템플릿 좌표로 새로 시작
            }
        }
        if ($currentRoi) {
            $x_base = (float)$currentRoi['x'];
            $y_base = (float)$currentRoi['y'];
            $w_base = (float)$currentRoi['w'];
            $h_base = (float)$currentRoi['h'];
        } else {
            $targetRoi = $matchedTemplateRoi ? ($matchedTemplateRoi['ans_roi'] ?? $matchedTemplateRoi['num_roi'] ?? $matchedTemplateRoi) : null;
            if (!$targetRoi) {
                imagedestroy($img);
                jsonError('문항 좌표를 찾을 수 없습니다.');
            }
            $x_base = (float)($targetRoi['x'] ?? 0);
            $y_base = (float)($targetRoi['y'] ?? 0);
            $w_base = (float)($targetRoi['w'] ?? 0);
            $h_base = (float)($targetRoi['h'] ?? 0);
            $isFirstExpansion = true;
        }

        if ($isFirstExpansion) {
            $cx = $x_base + ($w_base / 2);
            $cy = $y_base + ($h_base / 2);
            $w_base = $w_base * 1.15;
            $h_base = $h_base * 1.15;
            $x_base = $cx - ($w_base / 2);
            $y_base = $cy - ($h_base / 2);
        }

        if ($direction === 'top') {
            $y_base -= $expandPx;
            $h_base += $expandPx;
        } elseif ($direction === 'bottom') {
            $h_base += $expandPx;
        } elseif ($direction === 'left') {
            $x_base -= $expandPx;
            $w_base += $expandPx;
        } elseif ($direction === 'right') {
            $w_base += $expandPx;
        }

        $x_real = max(0, min($x_base * $scaleX, $imgW - 10));
        $y_real = max(0, min($y_base * $scaleY, $imgH - 10));
        $w_real = max(10, min($w_base * $scaleX, $imgW - $x_real));
        $h_real = max(10, min($h_base * $scaleY, $imgH - $y_real));

        $cropped = @imagecrop($img, [
            'x'      => (int)$x_real,
            'y'      => (int)$y_real,
            'width'  => (int)$w_real,
            'height' => (int)$h_real
        ]);

        if (!$cropped) {
            imagedestroy($img);
            jsonError('크롭 생성에 실패했습니다.');
        }

        ob_start();
        imagejpeg($cropped, null, 90);
        $base64 = 'data:image/jpeg;base64,' . base64_encode(ob_get_clean());

        imagedestroy($cropped);
        imagedestroy($img);

        if ($resultItemIndex !== -1) {
            $rawResults[$resultItemIndex]['current_roi'] = [
                'x' => $x_base,
                'y' => $y_base,
                'w' => $w_base,
                'h' => $h_base
            ];
            
            $upStmt = $pdo->prepare("UPDATE exam_results SET raw_results = :res WHERE id = :id");
            $upStmt->execute([
                'res' => json_encode($rawResults, JSON_UNESCAPED_UNICODE),
                'id'  => $resultId
            ]);
        }

        jsonSuccess([
            'new_image_base64' => $base64,
            'roi' => [
                'x' => $x_base,
                'y' => $y_base,
                'w' => $w_base,
                'h' => $h_base
            ]
        ]);
    }

} catch (Exception $e) {
    jsonError('이미지 확장 중 오류: ' . $e->getMessage());
}
