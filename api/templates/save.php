<?php
/**
 * api/templates/save.php
 * 템플릿 ROI 및 문항 정답/배점 설정 저장 API
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

$inputData  = getJsonInput();
$templateId = (int)($inputData['template_id'] ?? 0);
$roiData    = $inputData['roi_data'] ?? null;
$pagesData  = $inputData['pages_data'] ?? null;

if (!$templateId || !is_array($roiData)) {
    jsonError('올바르지 않은 데이터 형식입니다.', 400);
}

// 소유권 확인
$stmt = $pdo->prepare("SELECT id, image_path FROM templates WHERE id = :id AND user_id = :u");
$stmt->execute(['id' => $templateId, 'u' => $userId]);
$templateRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$templateRow) {
    jsonError('해당 템플릿에 대한 수정 권한이 없습니다.', 403);
}

// SSOT Contract B 정규화: roi_data 내 page 및 page_uid 무결성 보장
$normalizedRoi = [];
foreach ($roiData as $idx => $item) {
    if (!is_array($item)) continue;
    $item['id'] = (int)($item['id'] ?? ($idx + 1));
    $item['page'] = (int)($item['page'] ?? 1);
    if ($item['page'] < 1) $item['page'] = 1;
    if (empty($item['page_uid'])) {
        $item['page_uid'] = 'p_' . $item['page'];
    }
    $normalizedRoi[] = $item;
}
$roiJson = json_encode($normalizedRoi, JSON_UNESCAPED_UNICODE);

// SSOT Contract A 정규화: pages_data 처리
$pagesJson = null;
$firstPageImg = null;
if (is_array($pagesData) && !empty($pagesData)) {
    $normalizedPages = [];
    foreach ($pagesData as $pIdx => $pItem) {
        if (!is_array($pItem)) continue;
        $pageNum = (int)($pItem['page_num'] ?? ($pIdx + 1));
        $normalizedPages[] = [
            'page_uid'   => (string)($pItem['page_uid'] ?? ('p_' . $pageNum . '_' . substr(md5(uniqid()), 0, 4))),
            'page_num'   => $pageNum,
            'title'      => (string)($pItem['title'] ?? ($pageNum . '쪽')),
            'image_path' => (string)($pItem['image_path'] ?? ''),
            'width'      => (int)($pItem['width'] ?? 0),
            'height'     => (int)($pItem['height'] ?? 0),
        ];
    }
    if (!empty($normalizedPages)) {
        $pagesJson = json_encode($normalizedPages, JSON_UNESCAPED_UNICODE);
        if (!empty($normalizedPages[0]['image_path'])) {
            $firstPageImg = $normalizedPages[0]['image_path'];
        }
    }
}

try {
    if ($pagesJson !== null) {
        $updateSql = "UPDATE templates SET roi_data = :roi, pages_data = :pages" . ($firstPageImg ? ", image_path = :img" : "") . " WHERE id = :id";
        $params = [
            'roi'   => $roiJson,
            'pages' => $pagesJson,
            'id'    => $templateId
        ];
        if ($firstPageImg) {
            $params['img'] = $firstPageImg;
        }
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute($params);
    } else {
        $updateStmt = $pdo->prepare("UPDATE templates SET roi_data = :roi WHERE id = :id");
        $updateStmt->execute([
            'roi' => $roiJson,
            'id'  => $templateId
        ]);
    }

    jsonSuccess(['template_id' => $templateId], '템플릿 설정이 성공적으로 저장되었습니다.');
} catch (PDOException $e) {
    jsonError('DB 저장 중 오류: ' . $e->getMessage(), 500);
}
