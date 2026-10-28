<?php
/**
 * views/templates/list.php
 * 등록된 평가지 템플릿 보관함 및 ROI 관리 뷰
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

$userId = requireTeacherAuth();

$stmt = $pdo->prepare("SELECT * FROM templates WHERE user_id = :u ORDER BY id DESC");
$stmt->execute(['u' => $userId]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📋 평가지 템플릿 보관함 - 쌤글라스</title>
    <link rel="stylesheet" href="/public/assets/css/dashboard.css">
    <style>
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }
        .template-card {
            background: #ffffff;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            flex-direction: column;
        }
        .template-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-hover-shadow);
        }
        .template-thumb-wrap {
            position: relative;
            width: 100%;
            height: 180px;
            background: #f1f5f9;
            overflow: hidden;
            border-bottom: 1px solid var(--gray-200);
        }
        .template-thumb {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: top;
            transition: transform 0.3s;
        }
        .template-card:hover .template-thumb {
            transform: scale(1.03);
        }
        .template-info {
            padding: 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .template-title {
            margin: 0 0 8px 0;
            font-size: 1.15rem;
            font-weight: 800;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .template-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 12px;
        }
        .template-roi-summary {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            font-size: 0.88rem;
            margin-bottom: 15px;
        }
        .roi-stat-badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.78rem;
            margin-right: 4px;
        }
        .template-actions {
            margin-top: auto;
            display: flex;
            gap: 8px;
        }
        .template-actions .btn {
            flex: 1;
        }
    </style>
</head>
<body>

    <div class="dashboard-container">
        <!-- 상단 헤더 바 -->
        <header class="header-bar">
            <div>
                <h1 class="header-title">📋 평가지 템플릿 보관함</h1>
                <small style="color: #64748b;">등록된 시험지 템플릿의 문항 정보와 정답/배점 ROI를 확인하고 수정합니다.</small>
            </div>
            <div style="display:flex; gap:8px;">
                <a href="/views/dashboard/main.php" class="btn btn-secondary">← 대시보드</a>
                <a href="/views/templates/upload.php" class="btn btn-primary">+ 새 템플릿 등록</a>
            </div>
        </header>

        <!-- 템플릿 카드 그리드 -->
        <?php if (empty($templates)): ?>
            <div style="background: #ffffff; border: 1px dashed var(--gray-300); border-radius: var(--radius-md); padding: 60px 20px; text-align: center; color: #64748b;">
                <p style="font-size: 1.15rem; margin-bottom: 16px;">등록된 평가지 템플릿이 없습니다.</p>
                <a href="/views/templates/upload.php" class="btn btn-primary">+ 지금 새 템플릿 등록하기</a>
            </div>
        <?php else: ?>
            <div class="template-grid">
                <?php foreach ($templates as $t): 
                    $tId = (int)$t['id'];
                    $rois = json_decode($t['roi_data'] ?? '[]', true) ?: [];
                    
                    $totalItems = count($rois);
                    $objCount = 0;
                    $subjCount = 0;
                    $totalScore = 0;

                    foreach ($rois as $r) {
                        $type = strtolower($r['type'] ?? 'objective');
                        if (in_array($type, ['subjective', 'essay', 'short', '주관식'])) {
                            $subjCount++;
                        } else {
                            $objCount++;
                        }
                        $totalScore += (float)($r['score'] ?? 0);
                    }
                ?>
                    <div class="template-card" id="template-card-<?= $tId ?>">
                        <div class="template-thumb-wrap">
                            <img src="<?= e($t['image_path']) ?>" alt="템플릿 이미지" class="template-thumb" onerror="this.src='/public/assets/images/no_preview.png';">
                        </div>

                        <div class="template-info">
                            <h3 class="template-title" title="<?= e($t['title']) ?>">
                                📝 <?= e($t['title']) ?>
                            </h3>

                            <div class="template-meta-row">
                                <span>등록일: <?= e(substr($t['created_at'], 0, 10)) ?></span>
                                <span style="font-weight:bold; color:#0d6efd;">ID: #<?= $tId ?></span>
                            </div>

                            <!-- ROI 문항 통계 요약 -->
                            <div class="template-roi-summary">
                                <?php if ($totalItems === 0): ?>
                                    <span style="color:#dc3545; font-weight:bold;">⚠️ ROI 미설정 (영역 지정 필요)</span>
                                <?php else: ?>
                                    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                        <strong>총 문항수: <?= $totalItems ?>문항</strong>
                                        <strong style="color:#198754;">총 배점: <?= $totalScore ?>점</strong>
                                    </div>
                                    <div>
                                        <span class="roi-stat-badge" style="background:#e7f1ff; color:#0d6efd;">객관식 <?= $objCount ?></span>
                                        <span class="roi-stat-badge" style="background:#d1e7dd; color:#198754;">주관식 <?= $subjCount ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="template-actions">
                                <a href="/views/templates/editor.php?id=<?= $tId ?>" class="btn btn-primary" title="마킹 영역, 정답 및 배점을 수정합니다">
                                    ✏️ ROI/정답 편집
                                </a>
                                <button type="button" class="btn btn-outline-danger" style="flex:0.4;" onclick="deleteTemplate(<?= $tId ?>, '<?= e($t['title']) ?>')" title="템플릿 삭제">
                                    🗑️
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 토스트 알림 컨테이너 -->
    <div id="toast"></div>

    <script>
        function showToast(message) {
            const toast = document.getElementById('toast');
            if (!toast) return;
            toast.textContent = message;
            toast.className = "show";
            setTimeout(() => { toast.className = toast.className.replace("show", ""); }, 2500);
        }

        function deleteTemplate(id, title) {
            if (!confirm(`[${title}] 템플릿을 정말로 삭제하시겠습니까?\n이미 평가에 배정된 템플릿은 삭제할 수 없습니다.`)) {
                return;
            }

            fetch('/api/templates/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ template_id: id })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('템플릿이 삭제되었습니다.');
                    const card = document.getElementById(`template-card-${id}`);
                    if (card) {
                        card.style.opacity = '0.3';
                        setTimeout(() => location.reload(), 600);
                    } else {
                        location.reload();
                    }
                } else {
                    alert('삭제 실패: ' + (data.error || data.message || '오류 발생'));
                }
            })
            .catch(() => alert('서버 통신 오류가 발생했습니다.'));
        }
    </script>
</body>
</html>

