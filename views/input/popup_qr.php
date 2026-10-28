<?php
/**
 * views/input/popup_qr.php
 * 학생 개별 제출용 반응형 QR 미니 팝업
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/utils.php';

$classId  = (int)($_GET['class_id'] ?? 0);
$assignId = (int)($_GET['assign_id'] ?? 0);

if (!$assignId && !$classId) {
    die("잘못된 접근입니다. (평가 또는 학급 ID 누락)");
}

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

if ($classId) {
    // 1. '내 공부방' 포털 QR 모드
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :id AND is_active = 1");
    $stmt->execute(['id' => $classId]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        die("존재하지 않는 학급입니다.");
    }
    $pageTitle = e($class['class_name']) . " 내 공부방 - 접속 QR";
    $badgeText = "🏫 " . e($class['class_name']) . " 내 공부방";
    $mainTitle = "내 공부방 접속하기";
    $subTitle  = "교실 TV에 띄우거나 스마트폰/태블릿으로 스캔하세요.";
    $targetUrl = "{$protocol}://{$host}/views/student/login.php?class_id={$classId}";
} else {
    // 2. 특정 평가 제출 QR 모드
    $stmt = $pdo->prepare("
        SELECT a.*, t.title AS template_title, c.class_name
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        JOIN classes c ON a.class_id = c.id
        WHERE a.id = :id
    ");
    $stmt->execute(['id' => $assignId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        die("존재하지 않는 평가 항목입니다.");
    }
    $pageTitle = e($assignment['template_title']) . " - 제출 QR";
    $badgeText = "🏫 " . e($assignment['class_name']);
    $mainTitle = e($assignment['template_title']);
    $subTitle  = "스마트폰/태블릿으로 스캔하여 시험지를 제출하세요.";
    $targetUrl = "{$protocol}://{$host}/views/input/single.php?assign_id={$assignId}";
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <script src="/__preview-bridge.js" data-preview-bridge></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: #ffffff;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            padding: 4vh 4vw;
            user-select: none;
        }

        .header-section {
            text-align: center;
            width: 100%;
        }

        .class-badge {
            display: inline-block;
            background: #e7f5ff;
            color: #0d6efd;
            font-size: clamp(0.8rem, 2.5vh, 1.2rem);
            font-weight: bold;
            padding: 0.4em 0.8em;
            border-radius: 20px;
            margin-bottom: 1vh;
            border: 1px solid #bedbfd;
        }

        .title {
            font-size: clamp(1.1rem, 3.5vh, 2rem);
            font-weight: 800;
            color: #2c3e50;
            word-break: keep-all;
            line-height: 1.3;
        }

        .qr-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            margin: 2vh 0;
        }

        #qrcode {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        #qrcode img {
            border: 8px solid #f8f9fa;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .footer-section {
            text-align: center;
            width: 100%;
        }

        .url-text {
            font-family: monospace;
            font-size: clamp(0.75rem, 2vh, 1.1rem);
            color: #495057;
            background: #f1f3f5;
            padding: 0.5em 1em;
            border-radius: 6px;
            word-break: break-all;
            display: inline-block;
            max-width: 100%;
        }
    </style>
</head>
<body>

    <div class="header-section">
        <div class="class-badge"><?= $badgeText ?></div>
        <div class="title"><?= $mainTitle ?></div>
        <?php if (!empty($subTitle)): ?>
            <div style="font-size: clamp(0.85rem, 2vh, 1.1rem); color: #64748b; margin-top: 6px; font-weight: 600;"><?= $subTitle ?></div>
        <?php endif; ?>
    </div>

    <div class="qr-container" id="qrBox">
        <div id="qrcode"></div>
    </div>

    <div class="footer-section">
        <div class="url-text"><?= e($targetUrl) ?></div>
    </div>

    <script>
        const targetUrl = "<?= e($targetUrl) ?>";
        const qrBox = document.getElementById('qrBox');
        const qrcodeDiv = document.getElementById('qrcode');
        let qrcodeObj = null;

        function renderResponsiveQR() {
            qrcodeDiv.innerHTML = '';
            const boxWidth = qrBox.clientWidth;
            const boxHeight = qrBox.clientHeight;
            
            // 박스 크기의 90%까지 자동 확대, 최대 900px까지 지원 (대형 TV/모니터 꽉 차게)
            let qrSize = Math.min(boxWidth, boxHeight) * 0.90;
            qrSize = Math.max(140, Math.min(900, qrSize));

            qrcodeObj = new QRCode(qrcodeDiv, {
                text: targetUrl,
                width: qrSize,
                height: qrSize,
                colorDark : "#0f172a",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });
        }

        window.addEventListener('load', renderResponsiveQR);
        window.addEventListener('resize', renderResponsiveQR);
    </script>
</body>
</html>
