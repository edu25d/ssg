<?php
/**
 * includes/common/response.php
 * 표준화된 JSON API 응답 헬퍼
 */

if (!function_exists('jsonSuccess')) {
    function jsonSuccess(array $data = [], string $message = ''): void {
        header('Content-Type: application/json; charset=utf-8');
        $response = array_merge(['success' => true], $data);
        if ($message !== '') {
            $response['message'] = $message;
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('jsonError')) {
    function jsonError(string $error, int $httpStatus = 200, array $extra = []): void {
        header('Content-Type: application/json; charset=utf-8');
        if ($httpStatus !== 200) {
            http_response_code($httpStatus);
        }
        $response = array_merge([
            'success' => false,
            'error'   => $error,
            'message' => $error
        ], $extra);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('getJsonInput')) {
    function getJsonInput(): array {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
