<?php
/**
 * api/google/callback.php
 * 구글 OAuth 2.0 인증 콜백 처리 (토큰 발급 및 루트 폴더 연동)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

if (!isset($_GET['code'])) {
    echo "<script>alert('Google 인증 코드를 받지 못했습니다.'); window.location.href='/views/dashboard/main.php';</script>";
    exit;
}

$code = $_GET['code'];

$clientId     = env('GOOGLE_CLIENT_ID', '');
$clientSecret = env('GOOGLE_CLIENT_SECRET', '');
$redirectUri  = env('GOOGLE_REDIRECT_URI', getBaseUrl() . '/google_callback.php');

$tokenUrl = 'https://oauth2.googleapis.com/token';
$postData = [
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code'
];

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false
]);
$response = curl_exec($ch);
curl_close($ch);

$tokenData = json_decode($response, true);

if (isset($tokenData['error']) || empty($tokenData['access_token'])) {
    $errMsg = $tokenData['error_description'] ?? ($tokenData['error'] ?? '구글 토큰 발급 실패');
    echo "<script>alert('구글 토큰 발급에 실패했습니다: " . addslashes($errMsg) . "'); window.location.href='/views/dashboard/main.php';</script>";
    exit;
}

$accessToken  = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? null;

try {
    $userStmt = $pdo->prepare("SELECT google_root_folder_id, google_folder_name FROM users WHERE id = :id");
    $userStmt->execute(['id' => $userId]);
    $existingUser = $userStmt->fetch(PDO::FETCH_ASSOC);

    $existingFolderId   = $existingUser['google_root_folder_id'] ?? null;
    $existingFolderName = !empty($existingUser['google_folder_name']) ? $existingUser['google_folder_name'] : '쌤글라스';

    $targetFolderId = null;

    if ($existingFolderId) {
        $chVerify = curl_init("https://www.googleapis.com/drive/v3/files/{$existingFolderId}?fields=id,trashed");
        curl_setopt_array($chVerify, [
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $verifyRes = json_decode(curl_exec($chVerify), true);
        curl_close($chVerify);

        if (!empty($verifyRes['id']) && empty($verifyRes['trashed'])) {
            $targetFolderId = $verifyRes['id'];
        }
    }

    if (!$targetFolderId) {
        $targetFolderId = createDriveFolder($accessToken, $existingFolderName);
    }

    if ($refreshToken) {
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET google_access_token = :token, 
                google_refresh_token = :refresh_token, 
                google_root_folder_id = :folder_id, 
                google_folder_name = :folder_name 
            WHERE id = :id
        ");
        $updateStmt->execute([
            'token'         => $accessToken,
            'refresh_token' => $refreshToken,
            'folder_id'     => $targetFolderId,
            'folder_name'   => $existingFolderName,
            'id'            => $userId
        ]);
    } else {
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET google_access_token = :token, 
                google_root_folder_id = :folder_id, 
                google_folder_name = :folder_name 
            WHERE id = :id
        ");
        $updateStmt->execute([
            'token'       => $accessToken,
            'folder_id'   => $targetFolderId,
            'folder_name' => $existingFolderName,
            'id'          => $userId
        ]);
    }

    header("Location: /views/dashboard/main.php?google_connected=1");
    exit;

} catch (Exception $e) {
    echo "<script>alert('연동 저장 중 오류 발생: " . addslashes($e->getMessage()) . "'); window.location.href='/views/dashboard/main.php';</script>";
    exit;
}
