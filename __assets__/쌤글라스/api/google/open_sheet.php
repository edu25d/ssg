<?php
/**
 * api/google/open_sheet.php
 * 마감된 평가의 해당 학급 구글 스프레드시트 탭(#gid=...) 직행 열기
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

$assignId = (int)($_GET['assign_id'] ?? 0);
if (!$assignId) {
    die("평가 ID가 유효하지 않습니다.");
}

try {
    // sheet_gid 컬럼 부재 시 자동 추가 (Self-Healing)
    try {
        $pdo->query("SELECT sheet_gid FROM assignments LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN sheet_gid VARCHAR(64) NULL DEFAULT NULL");
    }

    $stmt = $pdo->prepare("
        SELECT 
            a.id, 
            a.sheet_gid, 
            t.title AS exam_name, 
            c.sheet_id
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        JOIN classes c ON a.class_id = c.id
        WHERE a.id = :assign_id AND c.user_id = :user_id
    ");
    $stmt->execute([
        'assign_id' => $assignId,
        'user_id'   => $userId
    ]);
    $assign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assign || empty($assign['sheet_id'])) {
        die("연동된 구글 시트 정보를 찾을 수 없습니다.");
    }

    $sheetId  = $assign['sheet_id'];
    $sheetGid = $assign['sheet_gid'];
    $examName = $assign['exam_name'];

    // 1. 이미 sheet_gid가 캐싱되어 있는 경우 즉시 이동
    if (!empty($sheetGid)) {
        header("Location: https://docs.google.com/spreadsheets/d/{$sheetId}/edit#gid={$sheetGid}");
        exit;
    }

    // 2. DB에 sheet_gid가 없는 경우 (이전 마감 건 등): 구글 Sheets API로 해당 탭 gid 탐색
    $accessToken = getValidAccessToken($pdo, $userId);
    if ($accessToken) {
        $metaUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}?fields=sheets.properties";
        $ch = curl_init($metaUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 5
        ]);
        $metaRes = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!empty($metaRes['sheets'])) {
            foreach ($metaRes['sheets'] as $s) {
                if (($s['properties']['title'] ?? '') === $examName) {
                    $foundGid = (string)($s['properties']['sheetId'] ?? '');
                    if ($foundGid !== '') {
                        // DB에 영구 캐싱
                        try {
                            $upStmt = $pdo->prepare("UPDATE assignments SET sheet_gid = :gid WHERE id = :id");
                            $upStmt->execute(['gid' => $foundGid, 'id' => $assignId]);
                        } catch (Exception $ignore) {}

                        header("Location: https://docs.google.com/spreadsheets/d/{$sheetId}/edit#gid={$foundGid}");
                        exit;
                    }
                }
            }
        }
    }

    // 3. 탭 탐색 실패 시 시트 루트로 안전하게 폴백
    header("Location: https://docs.google.com/spreadsheets/d/{$sheetId}/edit");
    exit;

} catch (Exception $e) {
    die("구글 시트 연결 오류: " . htmlspecialchars($e->getMessage()));
}
