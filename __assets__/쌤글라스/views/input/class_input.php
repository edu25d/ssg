<?php
/**
 * views/input/class_input.php
 * 학급 전용 평가 포털 (키오스크 및 개별 제출 선택 페이지)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/utils.php';

$classId = (int)($_GET['class_id'] ?? 0);
if (!$classId) {
    die("학급 식별 정보가 누락되었습니다.");
}

$stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :id AND is_active = 1");
$stmt->execute(['id' => $classId]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    die("존재하지 않거나 비활성화된 학급입니다.");
}

// 진행 중 평가 목록
$stmt = $pdo->prepare("
    SELECT a.id AS assign_id, t.title 
    FROM assignments a
    JOIN templates t ON a.template_id = t.id
    WHERE a.class_id = :cid AND a.status = 'in_progress'
    ORDER BY a.created_at DESC
");
$stmt->execute(['cid' => $classId]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($class['class_name']) ?> - 우리 반 평가 포털</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; padding: 40px 20px; text-align: center; margin: 0; }
        .card { background: white; padding: 30px; border-radius: 12px; max-width: 500px; margin: 0 auto 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .btn { display: inline-block; width: 80%; padding: 15px; margin: 10px 0; font-size: 1.1rem; font-weight: bold; color: white; border-radius: 8px; text-decoration: none; }
        .btn-kiosk { background: #0d6efd; }
        .btn-kiosk:hover { background: #0b5ed7; }
        .btn-single { background: #198754; }
        .btn-single:hover { background: #157347; }
        .assign-item { padding: 12px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .assign-title { font-weight: bold; color: #333; text-align: left; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🏫 <?= e($class['class_name']) ?> 평가 포털</h1>
        <p>제출 방식을 선택해 주세요.</p>
        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
        
        <a href="/views/input/kiosk.php?class_id=<?= $classId ?>" class="btn btn-kiosk">🖥️ 학급 공용 키오스크 실행</a>
    </div>

    <div class="card">
        <h3 style="margin-top: 0;">📋 진행 중인 평가 목록</h3>
        <?php if (empty($assignments)): ?>
            <p style="color: #6c757d; margin: 20px 0;">현재 진행 중인 평가가 없습니다.</p>
        <?php else: ?>
            <?php foreach ($assignments as $a): ?>
                <div class="assign-item">
                    <span class="assign-title"><?= e($a['title']) ?></span>
                    <a href="/views/input/single.php?assign_id=<?= (int)$a['assign_id'] ?>" class="btn btn-single" style="width: auto; padding: 8px 15px; margin: 0;">📷 개별 제출</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
