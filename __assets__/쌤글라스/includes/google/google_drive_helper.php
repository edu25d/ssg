<?php
/**
 * includes/google/google_drive_helper.php
 * Google Drive 및 Google Sheets API 연동 비즈니스 로직
 */

require_once dirname(__DIR__, 2) . '/config/db.php';

/**
 * 1. 액세스 토큰 만료 시 Refresh Token으로 자동 갱신
 */
function getValidAccessToken($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT google_access_token, google_refresh_token FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['google_access_token'])) return null;

    $ch = curl_init('https://www.googleapis.com/oauth2/v1/tokeninfo?access_token=' . $user['google_access_token']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        return $user['google_access_token'];
    }

    if (!empty($user['google_refresh_token'])) {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id'     => env('GOOGLE_CLIENT_ID', ''),
                'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
                'refresh_token' => $user['google_refresh_token'],
                'grant_type'    => 'refresh_token'
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['access_token'])) {
            $newToken = $response['access_token'];
            $upStmt = $pdo->prepare("UPDATE users SET google_access_token = :at WHERE id = :id");
            $upStmt->execute(['at' => $newToken, 'id' => $userId]);
            return $newToken;
        }
    }
    return null;
}

/**
 * 2. 구글 드라이브 폴더 생성
 */
function createDriveFolder($accessToken, $folderName, $parentId = null) {
    $metaData = [
        'name' => (string)$folderName,
        'mimeType' => 'application/vnd.google-apps.folder'
    ];
    if ($parentId) $metaData['parents'] = [$parentId];

    $ch = curl_init('https://www.googleapis.com/drive/v3/files');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($metaData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['id'] ?? null;
}

/**
 * 3. 구글 빈 스프레드시트 생성
 */
function createDriveSpreadsheet($accessToken, $title, $parentId) {
    $metaData = [
        'name' => (string)$title,
        'mimeType' => 'application/vnd.google-apps.spreadsheet',
        'parents' => [$parentId]
    ];
    $ch = curl_init('https://www.googleapis.com/drive/v3/files');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($metaData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['id'] ?? null;
}

/**
 * 4. 마스터 시트를 학급 폴더 내부로 복사
 */
function copyMasterSheetToFolder($accessToken, $masterSheetId, $folderId, $className) {
    $url = "https://www.googleapis.com/drive/v3/files/" . $masterSheetId . "/copy";
    
    $postData = json_encode([
        'name' => "[쌤글래스] " . $className,
        'parents' => [$folderId]
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $accessToken,
            "Content-Type: application/json"
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

/**
 * 5. 드라이브 파일 업로드 ('학생이름.jpg')
 */
function uploadDriveFile($accessToken, $filePath, $fileName, $parentId) {
    if (!file_exists($filePath)) return null;

    $boundary = '-------' . md5((string)time());
    $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';

    $metadata = json_encode([
        'name' => (string)$fileName,
        'parents' => [$parentId]
    ]);

    $fileData = file_get_contents($filePath);
    $mimeType = mime_content_type($filePath) ?: 'image/jpeg';

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= "{$metadata}\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: {$mimeType}\r\n\r\n";
    $body .= "{$fileData}\r\n";
    $body .= "--{$boundary}--";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($body)
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['id'] ?? null;
}

/**
 * 6. 드라이브 파일 ID 검색
 */
function getDriveFileIdByName($accessToken, $parentId, $fileName) {
    $query = urlencode("'{$parentId}' in parents and name = '{$fileName}' and trashed = false");
    $url = "https://www.googleapis.com/drive/v3/files?q={$query}&fields=files(id)";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $res['files'][0]['id'] ?? null;
}

/**
 * 7. 드라이브 파일 다운로드
 */
function downloadDriveFile($accessToken, $fileId, $savePath) {
    $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media";
    $ch = curl_init($url);
    $fp = fopen($savePath, 'w+');

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    return ($httpCode === 200);
}

/**
 * 8. _평가템플릿 sheetId 검색 및 마스터 시트 복사 예외처리
 */
function getOrCopyTemplateSheetId($accessToken, $targetSpreadsheetId, $templateTitle = '_평가템플릿') {
    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$targetSpreadsheetId}?fields=sheets.properties";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($res['sheets'])) {
        foreach ($res['sheets'] as $sheet) {
            if (($sheet['properties']['title'] ?? '') === $templateTitle) {
                return $sheet['properties']['sheetId'];
            }
        }
    }

    $masterSpreadsheetId = env('GOOGLE_MASTER_SHEET_ID', '1lxwqbkb3aRl1-wTL1kl1UKXBKPdn-jx0lfrKK9eIpCI'); 

    if (!$masterSpreadsheetId) {
        throw new Exception("마스터 시트 ID(GOOGLE_MASTER_SHEET_ID)가 설정되지 않았습니다.");
    }

    $mUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$masterSpreadsheetId}?fields=sheets.properties";
    $mCh = curl_init($mUrl);
    curl_setopt_array($mCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $mRaw = curl_exec($mCh);
    $mHttpCode = curl_getinfo($mCh, CURLINFO_HTTP_CODE);
    $mRes = json_decode($mRaw, true);
    curl_close($mCh);

    if (isset($mRes['error'])) {
        throw new Exception("구글 API 접근 거부 (" . $mHttpCode . "): " . $mRes['error']['message'] . " / 마스터 시트 공유 권한을 '링크가 있는 모든 사용자'로 변경해 보세요.");
    }

    $masterTemplateSheetId = null;
    if (!empty($mRes['sheets'])) {
        foreach ($mRes['sheets'] as $sheet) {
            if (($sheet['properties']['title'] ?? '') === $templateTitle) {
                $masterTemplateSheetId = $sheet['properties']['sheetId'];
                break;
            }
        }
    }

    if (!$masterTemplateSheetId) {
        throw new Exception("마스터 시트에 접근은 성공했으나, '{$templateTitle}' 탭을 찾을 수 없습니다.");
    }

    $copyUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$masterSpreadsheetId}/sheets/{$masterTemplateSheetId}:copyTo";
    $copyBody = json_encode(['destinationSpreadsheetId' => $targetSpreadsheetId]);
    $cCh = curl_init($copyUrl);
    curl_setopt_array($cCh, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $copyBody,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $cRaw = curl_exec($cCh);
    $cRes = json_decode($cRaw, true);
    curl_close($cCh);

    if (isset($cRes['error'])) {
        throw new Exception("템플릿 복사 실패: " . $cRes['error']['message']);
    }

    return $cRes['sheetId'] ?? null;
}

/**
 * 9. 템플릿 복사 및 시험 탭 생성
 */
function duplicateSheetToExam($accessToken, $spreadsheetId, $templateSheetId, $examName) {
    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}?fields=sheets.properties";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $requests = [];
    $sheetCount = 0;

    if (!empty($res['sheets'])) {
        $sheetCount = count($res['sheets']);
        foreach ($res['sheets'] as $sheet) {
            if (($sheet['properties']['title'] ?? '') === $examName) {
                $requests[] = [
                    'deleteSheet' => ['sheetId' => $sheet['properties']['sheetId']]
                ];
                $sheetCount--;
            }
        }
    }

    $requests[] = [
        'duplicateSheet' => [
            'sourceSheetId' => $templateSheetId,
            'newSheetName'   => $examName,
            'insertSheetIndex' => $sheetCount
        ]
    ];

    $batchUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}:batchUpdate";
    $bCh = curl_init($batchUrl);
    curl_setopt_array($bCh, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode(['requests' => $requests]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $bRes = json_decode(curl_exec($bCh), true);
    curl_close($bCh);

    $newSheetId = null;
    if (!empty($bRes['replies'])) {
        foreach ($bRes['replies'] as $reply) {
            if (isset($reply['duplicateSheet']['properties']['sheetId'])) {
                $newSheetId = $reply['duplicateSheet']['properties']['sheetId'];
            }
        }
    }

    if ($newSheetId !== null) {
        $unhideBody = json_encode([
            'requests' => [
                [
                    'updateSheetProperties' => [
                        'properties' => ['sheetId' => $newSheetId, 'hidden' => false],
                        'fields' => 'hidden'
                    ]
                ]
            ]
        ]);
        $uCh = curl_init($batchUrl);
        curl_setopt_array($uCh, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $unhideBody,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        curl_exec($uCh);
        curl_close($uCh);
    }

    return $newSheetId;
}

/**
 * 10. 평가 결과 및 동적 수식 일괄 기록
 */
function writeExamScoresToSheet($accessToken, $spreadsheetId, $examName, $examData) {
    if (empty($examData)) return false;

    $templateSheetId = getOrCopyTemplateSheetId($accessToken, $spreadsheetId, '_평가템플릿');
    if (!$templateSheetId) {
        throw new Exception("마스터 및 학급 시트에서 '_평가템플릿'을 찾을 수 없습니다.");
    }

    $newSheetId = duplicateSheetToExam($accessToken, $spreadsheetId, $templateSheetId, $examName);

    $studentCount    = count($examData);
    $firstStudentRow = 6;
    $lastStudentRow  = $firstStudentRow + $studentCount - 1;

    $firstRaw = is_string($examData[0]['raw_results'] ?? null) 
        ? json_decode($examData[0]['raw_results'], true) 
        : ($examData[0]['raw_results'] ?? []);
    
    $itemCount = is_array($firstRaw) ? count($firstRaw) : 0;

    $headerRow = ["", "학생 이름", "총점"];
    for ($i = 1; $i <= $itemCount; $i++) {
        $headerRow[] = "Q" . $i;
    }
    $headerRow[] = "채점 일시";
    $headerRow[] = "RAW_DATA_HIDDEN";

    $studentRows = [];
    foreach ($examData as $item) {
        $rawArr = is_string($item['raw_results'] ?? null) 
            ? json_decode($item['raw_results'], true) 
            : ($item['raw_results'] ?? []);
        
        $rawJson = json_encode($rawArr, JSON_UNESCAPED_UNICODE);

        $row = [
            "",
            (string)($item['student_name'] ?? ''),
            (float)($item['total_score'] ?? 0)
        ];

        if (is_array($rawArr)) {
            foreach ($rawArr as $subItem) {
                $row[] = (float)($subItem['currentScore'] ?? ($subItem['isCorrect'] ?? false ? ($subItem['score'] ?? 0) : 0));
            }
        }

        $row[] = date('Y-m-d H:i:s');
        $row[] = $rawJson;

        $studentRows[] = $row;
    }

    $bottomAvgRow = ["", "문항별 평균", ""];
    for ($i = 0; $i < $itemCount; $i++) {
        $colLetter = getColLetter(4 + $i);
        $bottomAvgRow[] = "=AVERAGE({$colLetter}{$firstStudentRow}:{$colLetter}{$lastStudentRow})";
    }

    $kpiRow2 = [
        "",
        "=AVERAGE(C{$firstStudentRow}:C{$lastStudentRow})",
        "=MAX(C{$firstStudentRow}:C{$lastStudentRow})",
        "=MIN(C{$firstStudentRow}:C{$lastStudentRow})",
        "=COUNTIF(C{$firstStudentRow}:C{$lastStudentRow}, \">=80\")"
    ];

    $allValues = [];
    $allValues[0] = $kpiRow2;
    $allValues[1] = [];
    $allValues[2] = [];
    $allValues[3] = $headerRow;

    foreach ($studentRows as $sRow) {
        $allValues[] = $sRow;
    }
    $allValues[] = $bottomAvgRow;

    $range = urlencode("{$examName}!A2");
    $updateUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}?valueInputOption=USER_ENTERED";

    $uCh = curl_init($updateUrl);
    curl_setopt_array($uCh, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode(['values' => $allValues]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = json_decode(curl_exec($uCh), true);
    curl_close($uCh);

    return isset($response['updatedRows']) ? ['success' => true, 'sheet_gid' => $newSheetId] : false;
}

/**
 * 11. 복원 데이터 읽기
 */
function readExamScoresFromSheet($accessToken, $spreadsheetId, $examName) {
    $range = urlencode("{$examName}!A6:ZZ100");
    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $values = $res['values'] ?? [];
    $studentResults = [];

    foreach ($values as $row) {
        if (empty($row[1])) continue;

        $rawJson = '[]';
        for ($i = count($row) - 1; $i >= 3; $i--) {
            $cellVal = trim((string)($row[$i] ?? ''));
            $isJsonFormat = (substr($cellVal, 0, 1) === '[') && (substr($cellVal, -1) === ']');
            if ($isJsonFormat) {
                $rawJson = $cellVal;
                break;
            }
        }

        $studentResults[] = [
            'student_name'     => (string)($row[1]),
            'total_score'      => (float)($row[2] ?? 0),
            'raw_results_json' => $rawJson
        ];
    }

    return $studentResults;
}

/**
 * 12. 구글 드라이브 파일 덮어쓰기 (PATCH)
 */
function updateDriveFile($accessToken, $fileId, $filePath) {
    if (!file_exists($filePath)) return null;

    $url = "https://www.googleapis.com/upload/drive/v3/files/{$fileId}?uploadType=media";
    $fileData = file_get_contents($filePath);
    $mimeType = mime_content_type($filePath) ?: 'image/jpeg';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $fileData,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . strlen($fileData)
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['id'] ?? null;
}

/**
 * 알파벳 열 변환 헬퍼 함수
 */
function getColLetter($num) {
    $num = (int)$num;
    $numeric = ($num - 1) % 26;
    $letter = chr(65 + $numeric);
    $num2 = (int)(($num - 1) / 26);
    if ($num2 > 0) {
        return getColLetter($num2) . $letter;
    } else {
        return $letter;
    }
}

/**
 * 구글 드라이브 파일/폴더 이름 변경 (PATCH)
 */
function renameDriveItem($accessToken, $fileId, $newName) {
    if (!$fileId || !$newName) return false;

    $url = "https://www.googleapis.com/drive/v3/files/{$fileId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode(['name' => $newName]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return isset($res['id']);
}

