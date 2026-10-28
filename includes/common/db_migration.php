<?php
/**
 * includes/common/db_migration.php
 * 무중단 자가 치유(Self-Healing) DB 스키마 마이그레이션 헬퍼
 * 
 * 쌤글라스 시스템 전반에서 필요한 신규 컬럼 부재 시
 * 웹 요청 시점에 자동 감지하여 무중단으로 생성합니다.
 */

if (!function_exists('ensureMultiPageSchema')) {
    function ensureMultiPageSchema(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }

        // 세션 캐싱으로 불필요한 반복 쿼리 방지 (Zero-Overhead)
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['multipage_schema_checked'])) {
            $checked = true;
            return;
        }

        try {
            // templates.pages_data 컬럼 존재 여부 초고속 검사 (0.1ms)
            $pdo->query("SELECT pages_data FROM templates LIMIT 1");
            
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['multipage_schema_checked'] = true;
            }
            $checked = true;
        } catch (Exception $e) {
            // 컬럼이 없을 경우 자동 추가 실행
            try {
                $pdo->exec("ALTER TABLE `templates` ADD COLUMN `pages_data` TEXT NULL AFTER `image_path`");
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['multipage_schema_checked'] = true;
                }
                $checked = true;
            } catch (Exception $alterEx) {
                // 동시 요청 등으로 이미 생성되었거나 권한 오류인 경우 에러 로그 기록
                @error_log("[DB Migration] templates.pages_data ALTER failed: " . $alterEx->getMessage());
            }
        }
    }
}
