<?php
final class UploadResponse {
    public static function ok(array $data = [], int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    public static function fail(string $error, int $code = 400, array $extra = []): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
    }
}