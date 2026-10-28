<?php
/**
 * includes/common/utils.php
 * 공통 유틸리티 헬퍼 함수
 */

if (!function_exists('e')) {
    /**
     * XSS 방지를 위한 HTML 특수문자 이스케이프 함수
     */
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('getBaseUrl')) {
    /**
     * 현재 요청의 기본 프로토콜 및 호스트 URL 반환 (예: https://example.com)
     */
    function getBaseUrl(): string {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$protocol}://{$host}";
    }
}
