<?php
/**
 * api/google/get_folders.php
 * 구글 드라이브 폴더 목록 조회 API (토큰 만료 시 자동 갱신 지원)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

$token = getValidAccessToken($pdo, $userId);
if (!$token) {
    jsonError('구글 연동이 만료되었거나 토큰이 없습니다. 먼저 구글 계정을 연동해주세요.', 401);
}

$parentId = (!empty($_GET['parent_id'])) ? $_GET['parent_id'] : 'root';
$query = urlencode("'{$parentId}' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false");
$url = "https://www.googleapis.com/drive/v3/files?q={$query}&fields=files(id,name)&pageSize=100";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    CURLOPT_SSL_VERIFYPEER => false
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($res, true);
    jsonSuccess([
        'parent_id' => $parentId,
        'folders'   => $data['files'] ?? []
    ]);
} else if ($httpCode === 401) {
    jsonError('구글 인증이 만료되었습니다. 다시 연동해주세요.', 401);
} else {
    jsonError("구글 API 오류 (응답 코드: {$httpCode})");
}
