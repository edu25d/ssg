<?php
/**
 * views/input/bulk.php
 * OMR 일괄 연속 촬영 입력기 (Pro 카메라 UI & UX 개혁 버전)
 * - 글래스모피즘 HUD & 듀얼 링 알루미늄 셔터 버튼
 * - 가로 스와이프 학생 롤러 (Carousel Strip)로 시험지 순서 뒤바뀜 100% 대응
 * - 셔터 옆 미니 썸네일 프리뷰 및 원터치 확대 검수 모달
 * - OpenCV 실패 시 경고창 중단 없는 무중단 Soft Fallback (토스트 알림)
 * - 햅틱 진동(Vibration) 피드백 및 화면 엣지 펄스 캡처 애니메이션
 * - 유연한 부분 전송 모달 (미촬영 학생이 있어도 완료된 학생 우선 채점)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

$assignId = (int)($_GET['assign_id'] ?? 0);
$studentsArray = [];
$examTitle = '평가';
$className = '';
$totalPages = 1;

if ($assignId) {
    $stmt = $pdo->prepare("
        SELECT a.class_id, t.title as template_title, t.pages_data, c.class_name 
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        JOIN classes c ON a.class_id = c.id
        WHERE a.id = :assign_id
    ");
    $stmt->execute(['assign_id' => $assignId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($assignment) {
        $examTitle = $assignment['template_title'];
        $className = $assignment['class_name'];
        $classId   = (int)$assignment['class_id'];
        
        $pagesData = json_decode($assignment['pages_data'] ?? '[]', true);
        $totalPages = is_array($pagesData) && count($pagesData) > 0 ? count($pagesData) : 1;
        
        $stuStmt = $pdo->prepare("SELECT id, name FROM students WHERE class_id = :class_id AND is_active = 1 ORDER BY order_index ASC, name ASC");
        $stuStmt->execute(['class_id' => $classId]);
        $studentsList = $stuStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($studentsList as $stu) {
            $studentsArray[] = [
                'student_id' => (int)$stu['id'],
                'name'       => $stu['name'],
                'status'     => 'pending',
                'images'     => []
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= htmlspecialchars($examTitle) ?> - OMR 일괄 연속 촬영기</title>
    <style>
        :root {
            --primary: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-400: #94a3b8;
            --slate-200: #e2e8f0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; user-select: none; -webkit-tap-highlight-color: transparent; }
        body { 
            background: #000; 
            color: #fff; 
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif; 
            overflow: hidden; 
            position: fixed; 
            width: 100vw; 
            height: 100vh; 
        }
        
        /* 1. 카메라 뷰파인더 & 가이드 */
        #camera-container { 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100vw; 
            height: 100vh; 
            height: 100dvh;
            z-index: 1; 
            background: #000;
        }
        #video-stream { 
            width: 100%; 
            height: 100%; 
            object-fit: cover; 
        }
        
        /* 1. 스마트 4모서리 가이드 프레임 (A4 용지 최적화 & 상하 대칭 완벽 노출) */
        .guide-box {
            position: fixed;
            top: 64px;
            left: 7%;
            width: 86%;
            bottom: 172px;
            pointer-events: none;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.25);
            border: 1px dashed rgba(16, 185, 129, 0.35);
            border-radius: 8px;
        }
        .corner {
            position: absolute;
            width: 26px;
            height: 26px;
            border: 3.5px solid #10b981;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.5);
        }
        .corner-tl { top: -2px; left: -2px; border-right: none; border-bottom: none; border-radius: 8px 0 0 0; }
        .corner-tr { top: -2px; right: -2px; border-left: none; border-bottom: none; border-radius: 0 8px 0 0; }
        .corner-bl { bottom: -2px; left: -2px; border-right: none; border-top: none; border-radius: 0 0 0 8px; }
        .corner-br { bottom: -2px; right: -2px; border-left: none; border-top: none; border-radius: 0 0 8px 0; }
        .guide-tip {
            position: absolute;
            bottom: -22px;
            width: 100%;
            text-align: center;
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.9);
            text-shadow: 0 1px 4px rgba(0,0,0,0.9);
            font-weight: 700;
        }

        /* 캡처 플래시 엣지 펄스 효과 */
        #flash-effect { 
            position: absolute; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            box-shadow: inset 0 0 0 6px #10b981;
            opacity: 0; 
            pointer-events: none; 
            transition: opacity 0.12s ease-out; 
            z-index: 15; 
        }

        /* 2. 상단 프로 글래스모피즘 HUD */
        #top-hud { 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            padding: 10px 14px; 
            padding-top: max(10px, env(safe-area-inset-top)); 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background: rgba(15, 23, 42, 0.65); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1); 
            z-index: 25; 
        }
        .hud-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-hud-exit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 5px 10px;
            border-radius: 9999px;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
        }
        .hud-center { 
            flex-grow: 1; 
            margin: 0 10px; 
            text-align: center;
        }
        .hud-title-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-bottom: 3px;
        }
        .hud-exam-title {
            font-size: 0.85rem;
            font-weight: 800;
            color: #ffffff;
            max-width: 140px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .hud-count-badge {
            background: rgba(16, 185, 129, 0.25);
            color: #34d399;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 9999px;
            border: 1px solid rgba(16, 185, 129, 0.35);
        }
        .hud-progress-bar { 
            width: 100%; 
            height: 4px; 
            background: rgba(255, 255, 255, 0.15); 
            border-radius: 9999px; 
            overflow: hidden; 
        }
        .hud-progress-fill { 
            height: 100%; 
            background: linear-gradient(90deg, #10b981 0%, #34d399 100%); 
            width: 0%; 
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        .hud-right {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-hud-list { 
            background: rgba(255, 255, 255, 0.15); 
            border: 1px solid rgba(255, 255, 255, 0.2); 
            color: #fff; 
            padding: 5px 10px; 
            border-radius: 9999px; 
            font-size: 0.78rem; 
            font-weight: 700;
            cursor: pointer; 
            transition: all 0.2s;
        }
        .btn-hud-quick-submit {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: #fff;
            padding: 5px 10px;
            border-radius: 9999px;
            font-size: 0.78rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
            display: none;
            transition: all 0.2s;
        }

        /* 3. 하단 초슬림 플로팅 HUD (안 A: 2층 압축 구조 & 점유율 14%) */
        #bottom-bar { 
            position: fixed; 
            bottom: 0; 
            left: 0; 
            width: 100%; 
            padding: 4px 10px; 
            padding-bottom: max(6px, env(safe-area-inset-bottom)); 
            background: rgba(15, 23, 42, 0.45); 
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            gap: 4px;
            z-index: 25; 
        }

        /* 1층: 콤팩트 학생 롤러 + 메타 정보 통합 캡슐 바 */
        .bottom-capsule-row {
            width: 100%;
            max-width: 440px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            align-items: center;
        }

        /* 가로 스와이프 학생 롤러 (Carousel Strip) */
        .student-carousel-container {
            width: 100%;
            overflow-x: auto;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
            padding: 1px 0;
        }
        .student-carousel-container::-webkit-scrollbar { display: none; }
        .student-carousel-strip {
            display: flex;
            gap: 5px;
            align-items: center;
            padding: 0 4px;
        }
        .carousel-chip {
            padding: 3px 9px;
            border-radius: 9999px;
            font-size: 0.74rem;
            font-weight: 700;
            background: rgba(30, 41, 59, 0.65);
            color: #94a3b8;
            border: 1px solid rgba(255, 255, 255, 0.15);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 3px;
        }
        .carousel-chip.completed {
            background: rgba(16, 185, 129, 0.25);
            border-color: rgba(52, 211, 153, 0.45);
            color: #34d399;
        }
        .carousel-chip.active {
            background: rgba(16, 185, 129, 0.45);
            border: 1.8px solid #10b981;
            color: #ffffff;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.5);
            transform: scale(1.03);
        }
        .carousel-chip.absent {
            background: rgba(239, 68, 68, 0.25);
            border-color: rgba(248, 113, 113, 0.45);
            color: #f87171;
        }

        /* 현재 대상 학생 메타 캡슐 */
        .current-student-meta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 1px 8px;
        }
        #current-student-name { 
            font-size: 15px; 
            font-weight: 900; 
            color: #ffffff; 
            text-shadow: 0 1px 4px rgba(0,0,0,0.8);
            letter-spacing: -0.02em;
        }
        #current-student-badge {
            font-size: 0.68rem;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.18);
            color: #e2e8f0;
        }

        /* 결석 미니 토글 칩 */
        .btn-absent-pill {
            background: rgba(239, 68, 68, 0.18);
            border: 1px solid rgba(248, 113, 113, 0.35);
            color: #fca5a5;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 9999px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-absent-pill.is-absent {
            background: #ef4444;
            color: #ffffff;
            border-color: #dc2626;
            box-shadow: 0 0 6px rgba(239, 68, 68, 0.6);
        }

        /* 다쪽 평가지 듀얼 챔버 HUD 인디케이터 */
        .page-chamber-bar {
            display: inline-flex;
            gap: 4px;
            justify-content: center;
            align-items: center;
        }
        .chamber-chip {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 7px;
            border-radius: 9999px;
            font-size: 0.68rem;
            font-weight: 800;
            background: rgba(30, 41, 59, 0.6);
            color: #94a3b8;
            border: 1px solid rgba(255, 255, 255, 0.15);
            cursor: pointer;
            transition: all 0.2s;
        }
        .chamber-chip.active {
            background: rgba(37, 99, 235, 0.45);
            border-color: #3b82f6;
            color: #ffffff;
            box-shadow: 0 0 6px rgba(59, 130, 246, 0.5);
        }
        .chamber-chip.completed {
            background: rgba(16, 185, 129, 0.25);
            border-color: #10b981;
            color: #34d399;
        }
        .chamber-chip .chamber-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }
        .chamber-chip.active .chamber-dot {
            animation: chamber-pulse 1.2s infinite;
        }

        /* 2층: 셔터 및 액션 컨트롤 가로 1열 (대칭 구조) */
        .shutter-control-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            width: 100%; 
            max-width: 320px; 
            padding: 0 10px;
        }
        
        /* 미니 A4 세로형 썸네일 프리뷰 액자 (시험지 종이 비율) */
        .thumb-preview-box {
            width: 38px;
            height: 52px;
            border-radius: 8px;
            border: 1.5px solid rgba(255, 255, 255, 0.6);
            background: rgba(30, 41, 59, 0.85);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s;
            position: relative;
        }
        .thumb-preview-box:active {
            transform: scale(0.92);
        }
        .thumb-preview-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .thumb-placeholder {
            font-size: 1.1rem;
            opacity: 0.5;
        }
        .thumb-badge {
            position: absolute;
            bottom: 1px;
            right: 1px;
            background: #10b981;
            color: white;
            font-size: 0.55rem;
            font-weight: 800;
            padding: 1px 3px;
            border-radius: 3px;
        }

        /* 듀얼 링 알루미늄 셔터 버튼 */
        #btn-shutter { 
            width: 68px; 
            height: 68px; 
            border-radius: 50%; 
            border: 3.5px solid rgba(255, 255, 255, 0.9); 
            background: transparent; 
            outline: none; 
            box-shadow: 0 3px 12px rgba(0,0,0,0.4); 
            cursor: pointer; 
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.15s;
            position: relative;
        }
        .shutter-core {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ffffff 0%, #e2e8f0 100%);
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
            transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
        }
        #btn-shutter:active .shutter-core { 
            transform: scale(0.88); 
            background: #10b981;
        }
        .shutter-page-badge {
            position: absolute;
            bottom: 4px;
            background: #2563eb;
            color: #ffffff;
            font-size: 0.62rem;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 9999px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.5);
            letter-spacing: -0.02em;
            pointer-events: none;
        }

        /* 우측 원형 글래스 되돌리기 (1컷 취소) 단일 액션 버튼 */
        .side-action-group {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-undo-circle {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: rgba(30, 41, 59, 0.75);
            border: 1.5px solid rgba(255, 255, 255, 0.25);
            color: #f1f5f9;
            cursor: pointer;
            backdrop-filter: blur(10px);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
        }
        .btn-undo-circle .undo-icon {
            font-size: 1.05rem;
            line-height: 1;
            font-weight: bold;
        }
        .btn-undo-circle .undo-label {
            font-size: 0.52rem;
            font-weight: 700;
            color: #94a3b8;
            margin-top: 1px;
        }
        .btn-undo-circle:not(:disabled):active {
            transform: scale(0.9);
            background: rgba(239, 68, 68, 0.3);
            border-color: #ef4444;
        }
        .btn-undo-circle:disabled {
            opacity: 0.25;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* 4. 부드러운 카메라 토스트 알림 */
        #camera-toast {
            position: fixed;
            top: 75px;
            left: 50%;
            transform: translateX(-50%) translateY(-20px);
            background: rgba(15, 23, 42, 0.9);
            color: #f8fafc;
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 9px 18px;
            border-radius: 9999px;
            font-size: 0.86rem;
            font-weight: 700;
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
            backdrop-filter: blur(16px);
            opacity: 0;
            pointer-events: none;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 50;
            white-space: nowrap;
        }
        #camera-toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        /* 5. 하단 전체 명단 바텀시트 */
        #bottom-sheet { 
            position: fixed; 
            bottom: 0; 
            left: 0; 
            width: 100%; 
            max-height: 80vh; 
            background: #0f172a; 
            border-radius: 24px 24px 0 0; 
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            z-index: 40; 
            transform: translateY(100%); 
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            padding: 20px; 
            padding-bottom: max(20px, env(safe-area-inset-bottom));
            display: flex; 
            flex-direction: column; 
            box-shadow: 0 -10px 30px rgba(0, 0, 0, 0.6);
        }
        #bottom-sheet.open { transform: translateY(0); }
        .sheet-drag-handle {
            width: 40px;
            height: 4px;
            background: rgba(255, 255, 255, 0.25);
            border-radius: 2px;
            margin: 0 auto 16px auto;
        }
        .sheet-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 15px; 
        }
        .sheet-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: #ffffff;
        }
        .pill-grid { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 8px; 
            overflow-y: auto; 
            max-height: 50vh;
            padding-bottom: 16px; 
        }
        .pill { 
            padding: 9px 14px; 
            border-radius: 12px; 
            font-size: 0.88rem; 
            font-weight: 700; 
            background: #1e293b; 
            color: #94a3b8; 
            border: 1px solid #334155; 
            cursor: pointer; 
            transition: all 0.15s;
        }
        .pill.active { border-color: #3b82f6; color: #fff; background: rgba(59, 130, 246, 0.2); }
        .pill.completed { background: rgba(16, 185, 129, 0.2); color: #34d399; border-color: rgba(52, 211, 153, 0.4); }
        .pill.absent { background: rgba(239, 68, 68, 0.2); color: #f87171; border-color: rgba(248, 113, 113, 0.4); }
        
        .btn-sheet-submit { 
            background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
            color: #fff; 
            border: none; 
            padding: 15px; 
            border-radius: 14px; 
            font-size: 1.05rem; 
            font-weight: 800; 
            width: 100%; 
            margin-top: 10px; 
            cursor: pointer; 
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4);
            transition: all 0.2s;
        }
        .btn-sheet-submit:active { transform: scale(0.98); }

        /* 6. 원터치 썸네일 검수 모달 */
        #review-modal { 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(15, 23, 42, 0.94); 
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            z-index: 50; 
            display: none; 
            flex-direction: column; 
            align-items: center; 
            justify-content: space-between; 
            padding: 24px 20px; 
            padding-top: max(24px, env(safe-area-inset-top));
            padding-bottom: max(24px, env(safe-area-inset-bottom));
        }
        .review-header {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .review-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: #fff;
        }
        .review-page-tabs {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            justify-content: center;
            width: 100%;
        }
        .review-page-tab {
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 0.82rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.12);
            color: #cbd5e1;
            border: 1px solid rgba(255, 255, 255, 0.2);
            cursor: pointer;
            transition: all 0.2s;
        }
        .review-page-tab.active {
            background: #2563eb;
            color: #fff;
            border-color: #3b82f6;
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.5);
        }
        .review-img-container {
            flex-grow: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            max-height: 65vh;
            margin: 16px 0;
        }
        #review-image { 
            max-width: 96%; 
            max-height: 62vh; 
            border-radius: 12px; 
            border: 2px solid rgba(255, 255, 255, 0.2); 
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            object-fit: contain;
        }
        .review-actions { 
            display: flex; 
            gap: 12px; 
            width: 100%; 
            max-width: 400px; 
        }
        .btn-review-retake {
            flex: 1;
            padding: 13px;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 800;
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border: 1px solid rgba(248, 113, 113, 0.3);
            cursor: pointer;
        }
        .btn-review-confirm {
            flex: 1.4;
            padding: 13px;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 800;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        /* 7. 유연한 부분 전송 확인 모달 */
        #partial-submit-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(12px);
            z-index: 60;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .partial-modal-card {
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 20px;
            padding: 24px;
            width: 100%;
            max-width: 360px;
            color: #fff;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
    </style>
</head>
<body>

    <!-- 1. 카메라 뷰파인더 & 프레임 가이드 -->
    <div id="camera-container">
        <video id="video-stream" autoplay playsinline></video>
        <div class="guide-box">
            <div class="corner corner-tl"></div>
            <div class="corner corner-tr"></div>
            <div class="corner corner-bl"></div>
            <div class="corner corner-br"></div>
            <div class="guide-tip">시험지가 프레임 안에 가득 차도록 맞춰주세요</div>
        </div>
        <div id="flash-effect"></div>
    </div>

    <!-- 2. 상단 프로 HUD -->
    <div id="top-hud">
        <div class="hud-left">
            <a href="/views/dashboard/main.php" class="btn-hud-exit">✕ 나가기</a>
        </div>
        <div class="hud-center">
            <div class="hud-title-row">
                <span class="hud-exam-title"><?= htmlspecialchars($className ? "{$className} {$examTitle}" : $examTitle) ?></span>
                <span class="hud-count-badge" id="progress-text">0/0 완료</span>
            </div>
            <div class="hud-progress-bar">
                <div class="hud-progress-fill" id="progress-fill"></div>
            </div>
        </div>
        <div class="hud-right">
            <button type="button" class="btn-hud-list" onclick="toggleSheet()">명단 ☰</button>
            <button type="button" class="btn-hud-quick-submit" id="btn-quick-submit" onclick="openPartialSubmitModal()">🚀 전송</button>
        </div>
    </div>

    <!-- 3. 비침습적 스마트 토스트 알림 -->
    <div id="camera-toast">알림</div>

    <!-- 4. 하단 초슬림 플로팅 HUD (안 A: 2층 압축 구조) -->
    <div id="bottom-bar">
        <div class="bottom-capsule-row">
            <!-- 1층 상부: 가로 스와이프 학생 롤러 (Carousel Strip) -->
            <div class="student-carousel-container">
                <div class="student-carousel-strip" id="student-carousel-strip"></div>
            </div>

            <!-- 1층 하부: 현재 대상 학생 정보 및 다쪽 평가지 듀얼 챔버 HUD + 결석 토글 칩 -->
            <div class="current-student-meta">
                <span id="current-student-name">학생 선택 중</span>
                <span id="current-student-badge">대기</span>
                <div class="page-chamber-bar" id="page-chamber-bar" style="display:none;"></div>
                <button type="button" class="btn-absent-pill" id="btn-absent" onclick="toggleAbsent()" title="결석 상태 전환">결석</button>
            </div>
        </div>

        <!-- 2층: 셔터 및 액션 컨트롤 가로 1열 (A4 썸네일 - 셔터 - 되돌리기 황금 대칭) -->
        <div class="shutter-control-row">
            <!-- 셔터 좌측: A4 세로 비율 미니 페이퍼 썸네일 (탭 시 검수 모달) -->
            <div class="thumb-preview-box" id="thumb-preview-btn" onclick="openReviewModal()" title="방금 촬영한 시험지 검수하기">
                <div class="thumb-placeholder" id="thumb-placeholder">📄</div>
                <img id="thumb-preview-img" src="" alt="미리보기" style="display:none;">
                <div class="thumb-badge" id="thumb-badge" style="display:none;">1쪽</div>
            </div>

            <!-- 중앙: Pro 듀얼 링 알루미늄 셔터 버튼 -->
            <button type="button" id="btn-shutter" onclick="capturePhoto()" aria-label="사진 촬영">
                <div class="shutter-core"></div>
                <span id="shutter-page-badge" class="shutter-page-badge" style="display:none;">1쪽</span>
            </button>

            <!-- 우측: 원형 글래스 되돌리기 (1컷 취소) 단일 버튼 -->
            <div class="side-action-group">
                <button type="button" class="btn-undo-circle" id="btn-undo" onclick="undoLastShot()" title="방금 찍은 1컷 취소" disabled>
                    <span class="undo-icon">↩</span>
                    <span class="undo-label">취소</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 5. 학생 명단 바텀시트 -->
    <div id="bottom-sheet">
        <div class="sheet-drag-handle"></div>
        <div class="sheet-header">
            <h3 class="sheet-title">👥 학생 명단 (<span id="sheet-completed-count">0</span>/<?= count($studentsArray) ?>명 완료)</h3>
            <button type="button" class="btn-hud-list" onclick="toggleSheet(false)">닫기 ✕</button>
        </div>
        <div class="pill-grid" id="pill-grid"></div>
        <button id="btn-submit-final" class="btn-sheet-submit" onclick="openPartialSubmitModal()">🚀 최종 일괄 제출 및 AI 채점 시작</button>
    </div>

    <!-- 6. 원터치 썸네일 검수 모달 -->
    <div id="review-modal">
        <div class="review-header">
            <div class="review-title" id="review-student-name">시험지 검수</div>
            <button type="button" class="btn-hud-list" onclick="closeReviewModal()">닫기 ✕</button>
        </div>
        <!-- 다쪽 시험지 검수 탭 -->
        <div id="review-page-tabs" class="review-page-tabs" style="display:none;"></div>
        <div class="review-img-container">
            <img id="review-image" src="" alt="검수 이미지">
        </div>
        <div class="review-actions">
            <button type="button" class="btn-review-retake" onclick="retakeCurrentStudent()">📸 다시 찍기</button>
            <button type="button" class="btn-review-confirm" onclick="confirmAndNext()">✓ 확인 및 다음 학생</button>
        </div>
    </div>

    <!-- 7. 유연한 부분 전송 확인 모달 -->
    <div id="partial-submit-modal">
        <div class="partial-modal-card">
            <div style="font-size:2rem; margin-bottom:8px;">🚀</div>
            <h3 style="font-size:1.25rem; font-weight:800; margin-bottom:10px;">시험지 일괄 전송</h3>
            <p id="partial-modal-desc" style="font-size:0.9rem; color:#cbd5e1; line-height:1.5; margin-bottom:20px;">
                준비된 시험지를 서버로 전송하고 즉시 AI 자동 채점을 시작하시겠습니까?
            </p>
            <div style="display:flex; flex-direction:column; gap:8px;">
                <button type="button" id="btn-confirm-submit" class="btn-sheet-submit" onclick="submitFinal()" style="margin:0;">
                    지금 전송 및 채점 시작
                </button>
                <button type="button" class="btn-side-action" onclick="closePartialSubmitModal()" style="padding:12px;">
                    계속 촬영하기
                </button>
            </div>
        </div>
    </div>

<script>
    let students = <?= json_encode($studentsArray, JSON_UNESCAPED_UNICODE) ?>;
    let assignId = <?= $assignId ?>;
    let totalPages = <?= (int)($totalPages ?? 1) ?>;
    let currentIndex = 0;
    let currentPageIndex = 0;
    let reviewStudent = null;
    let reviewPageIndex = 0;
    let captureHistory = [];
    let isCapturing = false;
    
    const video = document.getElementById('video-stream');
    let stream = null;
    let cvWorker = null;
    let toastTimeout = null;
    let audioCtx = null;

    // --- [Web Audio API & 촉각 리듬 시스템 (오프라인/클라이언트 100% 무결동작)] ---
    function getAudioContext() {
        if (!audioCtx) {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (AudioContextClass) audioCtx = new AudioContextClass();
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
        return audioCtx;
    }

    function initAudioUnlock() {
        const unlock = () => {
            getAudioContext();
            document.removeEventListener('touchstart', unlock);
            document.removeEventListener('click', unlock);
        };
        document.addEventListener('touchstart', unlock, { passive: true });
        document.addEventListener('click', unlock, { passive: true });
    }

    // 1. 중간 페이지 완료 큐 (800Hz 경쾌한 단음 띵!)
    function playPageTone() {
        try {
            const ctx = getAudioContext();
            if (!ctx) return;
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(800, ctx.currentTime);
            gain.gain.setValueAtTime(0.15, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.12);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.12);
        } catch(e) {}
    }

    // 2. 학생 1명 전체 완료 큐 (도-미-솔 완결 화음)
    function playStudentCompleteChord() {
        try {
            const ctx = getAudioContext();
            if (!ctx) return;
            const notes = [523.25, 659.25, 783.99]; // C5, E5, G5
            notes.forEach((freq, idx) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(freq, ctx.currentTime + idx * 0.08);
                gain.gain.setValueAtTime(0.18, ctx.currentTime + idx * 0.08);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + idx * 0.08 + 0.2);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(ctx.currentTime + idx * 0.08);
                osc.stop(ctx.currentTime + idx * 0.08 + 0.2);
            });
        } catch(e) {}
    }

    // --- [학생 상태 자동 동기화 헬퍼] ---
    function updateStudentStatus(idx) {
        const s = students[idx];
        if (!s || s.status === 'absent') return;
        if (!Array.isArray(s.images)) s.images = [];
        const validCount = s.images.filter(img => Boolean(img)).length;
        if (validCount >= totalPages) {
            s.status = 'completed';
        } else if (validCount > 0) {
            s.status = 'in_progress';
        } else {
            s.status = 'pending';
        }
    }

    // --- [비침습적 토스트 알림] ---
    function showCameraToast(msg, duration = 2500) {
        const toast = document.getElementById('camera-toast');
        if (!toast) return;
        toast.innerText = msg;
        toast.classList.add('show');
        if (toastTimeout) clearTimeout(toastTimeout);
        toastTimeout = setTimeout(() => {
            toast.classList.remove('show');
        }, duration);
    }

    // --- [OpenCV Worker 초기화 (다쪽 pageIndex 완벽 바인딩)] ---
    function initWorker() {
        if (window.Worker) {
            cvWorker = new Worker('/public/assets/js/opencv_worker.js');
            cvWorker.onmessage = function(e) {
                const { studentIndex, pageIndex = 0, imageData, error } = e.data;
                
                if (error) {
                    console.warn("OpenCV Soft Fallback:", error);
                    const pText = totalPages > 1 ? ` ${pageIndex + 1}쪽` : '';
                    showCameraToast(`⚠️ ${students[studentIndex].name}${pText}: 테두리 보정 대신 원본 보존됨`);
                } else if (imageData) {
                    const tempCanvas = document.createElement('canvas');
                    tempCanvas.width = imageData.width;
                    tempCanvas.height = imageData.height;
                    const tempCtx = tempCanvas.getContext('2d');
                    tempCtx.putImageData(imageData, 0, 0);
                    if (!students[studentIndex].images) students[studentIndex].images = [];
                    students[studentIndex].images[pageIndex] = tempCanvas.toDataURL('image/jpeg', 0.85);
                }
                
                updateStudentStatus(studentIndex);
                saveToLocalCache();
                renderUI();
            };
        }
    }

    // --- [IndexedDB 캐시 & 세션 복구] ---
    function openDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open("OMR_Bulk_DB", 1);
            request.onupgradeneeded = (e) => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains("backups")) {
                    db.createObjectStore("backups", { keyPath: "assignId" });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = (e) => reject(e.target.error);
        });
    }

    async function saveToLocalCache() {
        try {
            const db = await openDB();
            const tx = db.transaction("backups", "readwrite");
            const store = tx.objectStore("backups");
            store.put({ assignId: assignId, students: students, timestamp: Date.now() });
        } catch(e) {
            console.error("Cache save error:", e);
        }
    }

    async function loadFromLocalCache() {
        try {
            const db = await openDB();
            const tx = db.transaction("backups", "readonly");
            const store = tx.objectStore("backups");
            const req = store.get(assignId);
            return new Promise((resolve) => {
                req.onsuccess = () => resolve(req.result ? req.result.students : null);
                req.onerror = () => resolve(null);
            });
        } catch(e) {
            return null;
        }
    }

    async function clearIndexedDB() {
        try {
            const db = await openDB();
            const tx = db.transaction("backups", "readwrite");
            tx.objectStore("backups").delete(assignId);
        } catch(e) {}
    }

    // --- [카메라 스트림 초기화] ---
    async function initCamera() {
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { 
                    facingMode: "environment", 
                    width: { ideal: 1920 }, 
                    height: { ideal: 1080 } 
                },
                audio: false
            });
            video.srcObject = stream;
        } catch (err) {
            alert("스마트폰 카메라 접근 권한을 허용해주세요: " + err.message);
        }
    }

    // --- [초기 진입 로직] ---
    async function init() {
        initAudioUnlock();
        initWorker();
        const cached = await loadFromLocalCache();
        if (cached && cached.length === students.length) {
            let cachedCount = 0;
            cached.forEach((cStu, i) => {
                if (students[i] && students[i].student_id === cStu.student_id) {
                    students[i].status = cStu.status;
                    students[i].images = Array.isArray(cStu.images) ? cStu.images : [];
                    updateStudentStatus(i);
                    if (students[i].status === 'completed' || students[i].status === 'in_progress') {
                        cachedCount++;
                    }
                }
            });
            if (cachedCount > 0) {
                showCameraToast(`💾 이전 촬영 기록 ${cachedCount}명이 복구되었습니다.`);
            }
        }
        await initCamera();
        findNextPending(0);
        renderUI();
    }

    // --- [UI 종합 렌더링] ---
    function renderUI() {
        if (students.length === 0) return;
        const current = students[currentIndex] || students[0];

        // 1. 현재 학생 이름 및 상태 배지
        document.getElementById('current-student-name').innerText = current ? `${currentIndex + 1}. ${current.name}` : "완료";
        const badgeEl = document.getElementById('current-student-badge');
        const absentBtn = document.getElementById('btn-absent');
        const undoBtn = document.getElementById('btn-undo');
        if (undoBtn) undoBtn.disabled = (captureHistory.length === 0);

        const validCount = (current && current.images) ? current.images.filter(Boolean).length : 0;

        if (current.status === 'completed') {
            badgeEl.innerText = totalPages > 1 ? `촬영 완료 ✓ (${totalPages}쪽)` : "촬영 완료 ✓";
            badgeEl.style.background = "rgba(16, 185, 129, 0.25)";
            badgeEl.style.color = "#34d399";
            if (absentBtn) { absentBtn.innerText = "결석"; absentBtn.classList.remove('is-absent'); }
        } else if (current.status === 'in_progress') {
            badgeEl.innerText = `${validCount}/${totalPages}쪽 촬영 중 ⏳`;
            badgeEl.style.background = "rgba(37, 99, 235, 0.25)";
            badgeEl.style.color = "#60a5fa";
            if (absentBtn) { absentBtn.innerText = "결석"; absentBtn.classList.remove('is-absent'); }
        } else if (current.status === 'absent') {
            badgeEl.innerText = "결석 ❌";
            badgeEl.style.background = "rgba(239, 68, 68, 0.25)";
            badgeEl.style.color = "#f87171";
            if (absentBtn) { absentBtn.innerText = "결석 취소"; absentBtn.classList.add('is-absent'); }
        } else {
            badgeEl.innerText = "촬영 대기 ⏳";
            badgeEl.style.background = "rgba(255, 255, 255, 0.15)";
            badgeEl.style.color = "#cbd5e1";
            if (absentBtn) { absentBtn.innerText = "결석"; absentBtn.classList.remove('is-absent'); }
        }

        // 2. 다쪽 평가지 듀얼/멀티 챔버 HUD 인디케이터
        const chamberBar = document.getElementById('page-chamber-bar');
        const shutterBadge = document.getElementById('shutter-page-badge');
        if (totalPages > 1) {
            if (chamberBar) {
                chamberBar.style.display = 'flex';
                chamberBar.innerHTML = '';
                for (let p = 0; p < totalPages; p++) {
                    const chip = document.createElement('div');
                    const hasImg = current && current.images && Boolean(current.images[p]);
                    const isActive = (p === currentPageIndex);
                    chip.className = `chamber-chip ${isActive ? 'active' : ''} ${hasImg ? 'completed' : ''}`;
                    chip.innerHTML = `<span class="chamber-dot"></span><span>${p + 1}쪽${hasImg ? ' ✓' : ''}</span>`;
                    chip.onclick = () => selectPage(p);
                    chamberBar.appendChild(chip);
                }
            }
            if (shutterBadge) {
                shutterBadge.style.display = 'block';
                shutterBadge.innerText = `${currentPageIndex + 1}쪽`;
            }
        } else {
            if (chamberBar) chamberBar.style.display = 'none';
            if (shutterBadge) shutterBadge.style.display = 'none';
        }

        // 3. 상단 프로그레스 바
        const completedCount = students.filter(s => s.status === 'completed').length;
        const absentCount = students.filter(s => s.status === 'absent').length;
        const doneTotal = completedCount + absentCount;
        
        document.getElementById('progress-text').innerText = `${doneTotal}/${students.length} 완료`;
        document.getElementById('progress-fill').style.width = `${(doneTotal / students.length) * 100}%`;
        document.getElementById('sheet-completed-count').innerText = doneTotal;

        // 상단 퀵 전송 버튼 (1명 이상 촬영 완료 시 노출)
        const quickSubmitBtn = document.getElementById('btn-quick-submit');
        if (completedCount > 0) {
            quickSubmitBtn.style.display = 'inline-block';
            quickSubmitBtn.innerText = `🚀 ${completedCount}명 전송`;
        } else {
            quickSubmitBtn.style.display = 'none';
        }

        // 4. 셔터 옆 미니 썸네일 프리뷰 갱신 (A4 세로 비율 & 마이크로 쪽수 뱃지)
        const thumbImg = document.getElementById('thumb-preview-img');
        const thumbPlaceholder = document.getElementById('thumb-placeholder');
        const thumbBadge = document.getElementById('thumb-badge');
        
        let previewImgSrc = null;
        let previewExtra = '';

        if (current && current.images && current.images.some(Boolean)) {
            previewImgSrc = current.images[currentPageIndex] || current.images.find(Boolean);
            const curValid = current.images.filter(Boolean).length;
            previewExtra = totalPages > 1 ? `${curValid}/${totalPages}` : '✓';
        } else {
            for (let i = students.length - 1; i >= 0; i--) {
                if (students[i].images && students[i].images.some(Boolean)) {
                    previewImgSrc = students[i].images.find(Boolean);
                    const prevValid = students[i].images.filter(Boolean).length;
                    previewExtra = totalPages > 1 ? `${prevValid}/${totalPages}` : '✓';
                    break;
                }
            }
        }

        if (previewImgSrc) {
            thumbImg.src = previewImgSrc;
            thumbImg.style.display = 'block';
            thumbPlaceholder.style.display = 'none';
            thumbBadge.style.display = 'block';
            thumbBadge.innerText = previewExtra || '✓';
        } else {
            thumbImg.style.display = 'none';
            thumbPlaceholder.style.display = 'block';
            thumbBadge.style.display = 'none';
        }

        // 5. 가로 스와이프 학생 롤러(Carousel Strip) 렌더링
        const strip = document.getElementById('student-carousel-strip');
        strip.innerHTML = '';
        students.forEach((s, idx) => {
            const chip = document.createElement('div');
            let statusBadge = '';
            if (s.status === 'completed') {
                statusBadge = '<b>✓</b>';
            } else if (s.status === 'in_progress') {
                const vCount = s.images ? s.images.filter(Boolean).length : 0;
                statusBadge = `<b style="color:#60a5fa;">[${vCount}/${totalPages}]</b>`;
            } else if (s.status === 'absent') {
                statusBadge = '<b>✕</b>';
            }
            chip.className = `carousel-chip ${s.status} ${idx === currentIndex ? 'active' : ''}`;
            chip.id = `carousel-chip-${idx}`;
            chip.innerHTML = `<span>${idx + 1}. ${s.name}</span> ${statusBadge}`;
            chip.onclick = () => selectStudent(idx);
            strip.appendChild(chip);
        });

        // 현재 활성 칩으로 부드럽게 스크롤 이동
        const activeChip = document.getElementById(`carousel-chip-${currentIndex}`);
        if (activeChip) {
            activeChip.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }

        // 6. 바텀시트 명단 그리드 갱신
        const pillGrid = document.getElementById('pill-grid');
        pillGrid.innerHTML = '';
        students.forEach((s, idx) => {
            const pill = document.createElement('div');
            pill.className = `pill ${s.status} ${idx === currentIndex ? 'active' : ''}`;
            let pExtra = '';
            if (s.status === 'in_progress') {
                const vCount = s.images ? s.images.filter(Boolean).length : 0;
                pExtra = ` (${vCount}/${totalPages})`;
            } else if (s.status === 'completed') {
                pExtra = ' ✓';
            } else if (s.status === 'absent') {
                pExtra = ' ✕';
            }
            pill.innerText = `${idx + 1}. ${s.name}${pExtra}`;
            pill.onclick = () => { selectStudent(idx); toggleSheet(false); };
            pillGrid.appendChild(pill);
        });
    }

    // --- [학생 선택 및 쪽 전환 네비게이션] ---
    function selectStudent(index) {
        if (index < 0 || index >= students.length) return;
        currentIndex = index;
        const s = students[index];
        if (s && s.images && totalPages > 1) {
            let firstMissing = 0;
            for (let p = 0; p < totalPages; p++) {
                if (!s.images[p]) {
                    firstMissing = p;
                    break;
                }
            }
            currentPageIndex = firstMissing;
        } else {
            currentPageIndex = 0;
        }
        renderUI();
    }

    function selectPage(pageIdx) {
        if (pageIdx >= 0 && pageIdx < totalPages) {
            currentPageIndex = pageIdx;
            renderUI();
        }
    }

    function navigateStudent(dir) {
        let newIdx = currentIndex + dir;
        if (newIdx >= 0 && newIdx < students.length) {
            selectStudent(newIdx);
        }
    }

    function findNextPending(startIndex) {
        for (let i = startIndex; i < students.length; i++) {
            if (students[i].status === 'pending' || students[i].status === 'in_progress') {
                selectStudent(i);
                return;
            }
        }
        for (let i = 0; i < startIndex; i++) {
            if (students[i].status === 'pending' || students[i].status === 'in_progress') {
                selectStudent(i);
                return;
            }
        }
    }

    // --- [결석 처리 토글] ---
    function toggleAbsent() {
        const cur = students[currentIndex];
        if (cur.status === 'absent') {
            cur.status = 'pending';
            showCameraToast(`${cur.name} 학생 결석 취소됨`);
        } else {
            cur.status = 'absent';
            cur.images = [];
            showCameraToast(`${cur.name} 학생 결석 처리됨`);
            findNextPending(currentIndex + 1);
        }
        saveToLocalCache();
        renderUI();
    }

    // --- [핵심 촬영 동작 (햅틱/오디오 리듬 + 디바운스 + Undo 스택)] ---
    function capturePhoto() {
        if (!video.videoWidth) return;
        if (isCapturing) return;
        isCapturing = true;
        setTimeout(() => { isCapturing = false; }, 400); // 400ms 하드웨어 연타 디바운스

        const currentTargetIndex = currentIndex;
        const currentTargetPage = currentPageIndex;
        const targetStudent = students[currentTargetIndex];
        if (!targetStudent) return;

        // Undo 기록에 직전 상태 스냅샷 저장
        const prevImg = targetStudent.images ? (targetStudent.images[currentTargetPage] || null) : null;
        const prevStatus = targetStudent.status;
        captureHistory.push({
            studentIndex: currentTargetIndex,
            pageIndex: currentTargetPage,
            previousImage: prevImg,
            previousStatus: prevStatus
        });

        // 1. 비디오 프레임 캡처
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        // 2. 부드러운 엣지 펄스 애니메이션
        const flash = document.getElementById('flash-effect');
        if (flash) {
            flash.style.opacity = '1';
            setTimeout(() => flash.style.opacity = '0', 120);
        }

        // 3. 원본 이미지 기본 백업 (Soft Fallback)
        const fallbackDataUrl = canvas.toDataURL('image/jpeg', 0.85);
        if (!targetStudent.images) targetStudent.images = [];
        targetStudent.images[currentTargetPage] = fallbackDataUrl;

        // 4. OpenCV Worker로 원근 보정 의뢰 (studentIndex 및 pageIndex 전달)
        if (cvWorker) {
            try {
                const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                cvWorker.postMessage({
                    studentIndex: currentTargetIndex,
                    pageIndex: currentTargetPage,
                    imageData: imgData,
                    width: canvas.width,
                    height: canvas.height
                }, [imgData.data.buffer]);
            } catch(err) {
                console.warn("Worker postMessage error:", err);
            }
        }

        const isLastPage = (currentTargetPage >= totalPages - 1);

        if (isLastPage) {
            // 학생 1명 전체 완료 (도-미-솔 완결 화음 + 2회 펄스 진동)
            updateStudentStatus(currentTargetIndex);
            playStudentCompleteChord();
            if (navigator.vibrate) navigator.vibrate([50, 60, 100]);
            showCameraToast(`✓ ${targetStudent.name} 학생 완료 (${totalPages}쪽)`);
            currentPageIndex = 0;
            findNextPending(currentTargetIndex + 1);
        } else {
            // 중간 페이지 완료 (800Hz 높은 단음 띵! + 1회 틱 진동)
            updateStudentStatus(currentTargetIndex);
            playPageTone();
            if (navigator.vibrate) navigator.vibrate(40);
            currentPageIndex = currentTargetPage + 1;
            showCameraToast(`📸 ${targetStudent.name} ${currentTargetPage + 1}쪽 완료 ➔ ${currentPageIndex + 1}쪽(뒷면) 촬영`);
        }

        saveToLocalCache();
        renderUI();
    }

    // --- [원터치 즉시 취소 (1-Tap Undo)] ---
    function undoLastShot() {
        if (captureHistory.length === 0) {
            showCameraToast("취소할 최근 촬영 내역이 없습니다.");
            return;
        }
        const lastAction = captureHistory.pop();
        const targetStudent = students[lastAction.studentIndex];
        if (!targetStudent) return;

        if (lastAction.previousImage) {
            targetStudent.images[lastAction.pageIndex] = lastAction.previousImage;
        } else {
            if (targetStudent.images) {
                targetStudent.images[lastAction.pageIndex] = null;
            }
        }
        updateStudentStatus(lastAction.studentIndex);

        currentIndex = lastAction.studentIndex;
        currentPageIndex = lastAction.pageIndex;

        if (navigator.vibrate) navigator.vibrate(60);
        showCameraToast(`↩️ ${targetStudent.name} ${currentPageIndex + 1}쪽 촬영 취소 (복구 완료)`);

        saveToLocalCache();
        renderUI();
    }

    // --- [원터치 썸네일 검수 모달 조작 (다쪽 탭 지원)] ---
    function openReviewModal() {
        const cur = students[currentIndex];
        let targetStudent = cur && cur.images && cur.images.some(Boolean) ? cur : null;
        
        if (!targetStudent) {
            for (let i = students.length - 1; i >= 0; i--) {
                if (students[i].images && students[i].images.some(Boolean)) {
                    targetStudent = students[i];
                    break;
                }
            }
        }

        if (!targetStudent || !targetStudent.images || !targetStudent.images.some(Boolean)) {
            showCameraToast("아직 촬영된 시험지가 없습니다.");
            return;
        }

        reviewStudent = targetStudent;
        reviewPageIndex = Math.min(currentPageIndex, totalPages - 1);
        if (!reviewStudent.images[reviewPageIndex]) {
            for (let p = 0; p < totalPages; p++) {
                if (reviewStudent.images[p]) {
                    reviewPageIndex = p;
                    break;
                }
            }
        }

        renderReviewModal();
        document.getElementById('review-modal').style.display = 'flex';
    }

    function renderReviewModal() {
        if (!reviewStudent) return;
        document.getElementById('review-student-name').innerText = `🔍 ${reviewStudent.name} 학생 시험지`;

        const tabsContainer = document.getElementById('review-page-tabs');
        if (totalPages > 1) {
            tabsContainer.style.display = 'flex';
            tabsContainer.innerHTML = '';
            for (let p = 0; p < totalPages; p++) {
                const tab = document.createElement('div');
                const hasImg = reviewStudent.images && Boolean(reviewStudent.images[p]);
                tab.className = `review-page-tab ${p === reviewPageIndex ? 'active' : ''}`;
                tab.innerText = `${p + 1}쪽 ${hasImg ? '✓' : '(미촬영)'}`;
                tab.onclick = () => {
                    reviewPageIndex = p;
                    renderReviewModal();
                };
                tabsContainer.appendChild(tab);
            }
        } else {
            tabsContainer.style.display = 'none';
        }

        const reviewImg = document.getElementById('review-image');
        const currentImgSrc = reviewStudent.images ? reviewStudent.images[reviewPageIndex] : null;
        if (currentImgSrc) {
            reviewImg.src = currentImgSrc;
            reviewImg.style.display = 'block';
        } else {
            reviewImg.src = '';
            reviewImg.style.display = 'none';
        }
    }

    function closeReviewModal() {
        document.getElementById('review-modal').style.display = 'none';
    }

    function retakeCurrentStudent() {
        closeReviewModal();
        if (!reviewStudent) return;
        const targetIdx = students.findIndex(s => s.student_id === reviewStudent.student_id);
        if (targetIdx !== -1) {
            currentIndex = targetIdx;
            currentPageIndex = reviewPageIndex;
            if (students[targetIdx].images) {
                students[targetIdx].images[reviewPageIndex] = null;
            }
            updateStudentStatus(targetIdx);
            saveToLocalCache();
            showCameraToast(`${students[targetIdx].name} ${currentPageIndex + 1}쪽을 다시 촬영해주세요.`);
            renderUI();
        }
    }

    function confirmAndNext() {
        closeReviewModal();
        findNextPending(currentIndex + 1);
        renderUI();
    }

    // --- [명단 바텀시트 제어] ---
    function toggleSheet(forceState) {
        const sheet = document.getElementById('bottom-sheet');
        if (forceState !== undefined) {
            sheet.classList.toggle('open', forceState);
        } else {
            sheet.classList.toggle('open');
        }
    }

    // --- [유연한 부분 전송 모달] ---
    function openPartialSubmitModal() {
        toggleSheet(false);
        const completedCount = students.filter(s => s.status === 'completed').length;
        const inProgressCount = students.filter(s => s.status === 'in_progress').length;
        const absentCount = students.filter(s => s.status === 'absent').length;
        const pendingCount = students.filter(s => s.status === 'pending').length;

        if (completedCount === 0) {
            alert("촬영이 완료된 시험지가 1장도 없습니다. 먼저 시험지를 촬영해주세요.");
            return;
        }

        const desc = document.getElementById('partial-modal-desc');
        const notDoneCount = inProgressCount + pendingCount;
        if (notDoneCount > 0) {
            desc.innerHTML = `총 ${students.length}명 중 <b>${completedCount}명</b>의 시험지(${totalPages}쪽 완결)가 촬영되었습니다.<br><span style="color:#f59e0b;">(미완료/미촬영 ${notDoneCount}명 제외)</span><br><br>촬영 완료된 ${completedCount}명만 먼저 서버로 전송하여 AI 채점을 시작하시겠습니까?`;
        } else {
            desc.innerHTML = `전체 ${students.length}명 중 <b>${completedCount}명</b>의 시험지 촬영이 완결되었습니다.<br><br>지금 일괄 전송 및 AI 채점을 시작하시겠습니까?`;
        }

        document.getElementById('btn-confirm-submit').innerText = `🚀 ${completedCount}명 지금 채점 시작`;
        document.getElementById('partial-submit-modal').style.display = 'flex';
    }

    function closePartialSubmitModal() {
        document.getElementById('partial-submit-modal').style.display = 'none';
    }

    // --- [서버 최종 일괄 전송 (단일/다쪽 100% 대응)] ---
    function dataURLtoBlob(dataurl) {
        let arr = dataurl.split(','), mime = arr[0].match(/:(.*?);/)[1];
        let bstr = atob(arr[1]), n = bstr.length, u8arr = new Uint8Array(n);
        while(n--) { u8arr[n] = bstr.charCodeAt(n); }
        return new Blob([u8arr], {type:mime});
    }

    async function submitFinal() {
        const submitBtn = document.getElementById('btn-confirm-submit');
        submitBtn.disabled = true;

        const targetStudents = students.filter(s => s.status === 'completed' && s.images && s.images.some(Boolean));

        if (targetStudents.length === 0) {
            alert("전송할 시험지 이미지가 없습니다.");
            submitBtn.disabled = false;
            return;
        }

        const CHUNK_SIZE = 4; // PHP max_file_uploads(20)를 안전하게 준수하기 위해 4명씩 분할 전송
        let sentCount = 0;

        try {
            for (let i = 0; i < targetStudents.length; i += CHUNK_SIZE) {
                const chunk = targetStudents.slice(i, i + CHUNK_SIZE);
                submitBtn.innerText = `전송 중... (${sentCount}/${targetStudents.length}명) ⏳`;

                const formData = new FormData();
                formData.append('assignment_id', assignId);

                const chunkPayload = [];

                chunk.forEach((s) => {
                    if (totalPages === 1) {
                        const fileKey = `file_${s.student_id}`;
                        const imageBlob = dataURLtoBlob(s.images[0]);
                        formData.append(fileKey, imageBlob, `student_${s.student_id}.jpg`);
                        chunkPayload.push({
                            student_name: s.name,
                            student_id: s.student_id,
                            file_key: fileKey,
                            status: s.status
                        });
                    } else {
                        const pageKeys = [];
                        for (let p = 0; p < totalPages; p++) {
                            if (s.images[p]) {
                                const pKey = `file_${s.student_id}_p${p}`;
                                const pBlob = dataURLtoBlob(s.images[p]);
                                formData.append(pKey, pBlob, `student_${s.student_id}_p${p + 1}.jpg`);
                                pageKeys.push(pKey);
                            }
                        }
                        if (pageKeys.length > 0) {
                            chunkPayload.push({
                                student_name: s.name,
                                student_id: s.student_id,
                                page_files: pageKeys,
                                file_keys: pageKeys,
                                status: s.status
                            });
                        }
                    }
                });

                formData.append('students_json', JSON.stringify(chunkPayload));

                const response = await fetch('/api/grading/submit_images.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (!result.success) {
                    throw new Error(result.error || `일부 학생(${chunk.map(c=>c.name).join(', ')}) 전송 실패`);
                }

                sentCount += chunk.length;
            }

            submitBtn.innerText = "전송 완료! 🎉";
            alert(`총 ${targetStudents.length}명의 시험지가 1명도 빠짐없이 완벽하게 전송 및 접수되었습니다!`);
            await clearIndexedDB();
            window.location.href = `/views/grading/review.php?assign_id=${assignId}`;

        } catch(err) {
            alert("전송 중 오류 발생: " + err.message);
            submitBtn.innerText = "다시 시도";
            submitBtn.disabled = false;
        }
    }

    window.onload = init;
</script>
</body>
</html>
