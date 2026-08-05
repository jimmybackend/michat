<?php
// upload/storage/UploadStateStore.php
declare(strict_types=1);

final class UploadStateStore
{
    /** @var string */
    private $dir;

    public function __construct($dir)
    {
        $this->dir = rtrim((string)$dir, '/\\') . DIRECTORY_SEPARATOR;
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function save($id, array $data)
    {
        $path = $this->path($id);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar estado JSON');
        }
        if (@file_put_contents($path, $json) === false) {
            throw new RuntimeException('No se pudo guardar estado en ' . $path);
        }
    }

    public function load($id)
    {
        $path = $this->path($id);
        if (!is_file($path)) return null;

        $raw = (string)@file_get_contents($path);
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    public function delete($id)
    {
        $path = $this->path($id);
        if (is_file($path)) @unlink($path);
    }

    private function path($id)
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', (string)$id);
        return $this->dir . $safe . '.json';
    }
}