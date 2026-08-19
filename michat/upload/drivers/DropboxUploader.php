<?php
declare(strict_types=1);

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

require_once __DIR__ . '/../core/UploaderInterface.php';
require_once __DIR__ . '/../repositories/FileS3Repository.php';

final class DropboxUploader implements UploaderInterface
{
    private function db()
    {
        if (isset($GLOBALS['db_connection']) && $GLOBALS['db_connection'] instanceof mysqli) return $GLOBALS['db_connection'];
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) return $GLOBALS['conn'];
        if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof mysqli) return $GLOBALS['mysqli'];
        if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof mysqli) return $GLOBALS['db'];
        throw new RuntimeException('DB no disponible (mysqli). Revisa app_bootstrap.php/db.php');
    }

    private function s3()
    {
        if (!class_exists('Config')) throw new RuntimeException('Config no est¨¢ disponible');
        $s3 = Config::getS3();
        if (!($s3 instanceof S3Client)) throw new RuntimeException('Config::getS3() no devolvi¨® S3Client');
        return $s3;
    }

    private function bucket()
    {
        if (defined('Config::BUCKET')) return (string)Config::BUCKET;
        if (property_exists('Config', 'BUCKET')) return (string)Config::$BUCKET;
        if (property_exists('Config', 'bucket')) return (string)Config::$bucket;
        if (method_exists('Config', 'getBucket')) return (string)Config::getBucket();
        throw new RuntimeException('No pude detectar el bucket en Config.');
    }

    private function rutaBase()
    {
        if (!isset($_SESSION['usuario'])) {
            throw new RuntimeException('Acceso denegado (no hay sesi¨®n usuario)');
        }
        // Usa ruta actual o ra¨ªz
        $ruta = isset($_SESSION['ruta_actual']) && $_SESSION['ruta_actual'] !== ''
            ? (string)$_SESSION['ruta_actual']
            : (defined('Config::RUTA_RAIZ') ? (string)Config::RUTA_RAIZ : 'Data/');

        return rtrim($ruta, '/') . '/';
    }

    public function init(array $req): array
    {
        // Dropzone manda el archivo en $_FILES['file'] normalmente
        $files = isset($req['_files']) && is_array($req['_files']) ? $req['_files'] : $_FILES;

        if (empty($files['file'])) {
            throw new RuntimeException('No se recibi¨® archivo (field "file")');
        }

        $bucket = $this->bucket();
        $s3 = $this->s3();
        $repo = new FileS3Repository($this->db());
        $rutaBase = $this->rutaBase();

        $f = $files['file'];

        // Normaliza single/multi
        if (!is_array($f['tmp_name'])) {
            $f = [
                'name'     => [$f['name']],
                'type'     => [$f['type']],
                'tmp_name' => [$f['tmp_name']],
                'error'    => [$f['error']],
                'size'     => [$f['size']],
            ];
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $resultados = [];

        for ($i = 0; $i < count($f['name']); $i++) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $resultados[] = ['estado' => 'error', 'mensaje' => 'UPLOAD_ERR=' . (int)$f['error'][$i]];
                continue;
            }

            $tmpFile = (string)$f['tmp_name'][$i];
            $nombreOriginal = (string)$f['name'][$i];

            $ext = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
            $nombreHash = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
            $keyFinal = $rutaBase . $nombreHash;

            $metadatosArray = [
                'tipo'        => @mime_content_type($tmpFile) ?: ($f['type'][$i] ?? 'application/octet-stream'),
                'tamano_kb'   => round((int)@filesize($tmpFile) / 1024, 2),
                'hash_sha256' => @hash_file('sha256', $tmpFile) ?: '',
                'subido_por'  => $_SESSION['usuario'] ?? 'publico',
                'ip_origen'   => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'fecha'       => date('Y-m-d'),
                'hora'        => date('H:i:s'),
                'navegador'   => $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido'
            ];
            $metadatosJSON = json_encode($metadatosArray, JSON_UNESCAPED_UNICODE);

            try {
                $s3->putObject([
                    'Bucket'     => $bucket,
                    'Key'        => $keyFinal,
                    'SourceFile' => $tmpFile,
                    'ACL'        => 'private',
                    'Metadata'   => $metadatosArray
                ]);

                $tamano = (int)@filesize($tmpFile);

                $fileId = $repo->insertFile([
                    'Nombre'     => $nombreOriginal,
                    'Encriptado' => $nombreHash,
                    'Tamano'     => $tamano,
                    'Metadatos'  => $metadatosJSON,
                    'Ruta'       => $rutaBase,
                    'Found'      => 1,
                    'AccessType' => 'normal',
                    'Fecha'      => date('Y-m-d H:i:s'),
                    'user_id_'   => $userId,
                ]);

                $resultados[] = ['estado' => 'ok', 'key' => $keyFinal, 'file_id' => $fileId];
            } catch (AwsException $e) {
                $resultados[] = ['estado' => 'error', 'mensaje' => $e->getAwsErrorMessage()];
            } catch (Throwable $e) {
                $resultados[] = ['estado' => 'error', 'mensaje' => $e->getMessage()];
            }
        }

        return ['estado' => 'ok', 'resultados' => $resultados];
    }

    public function part(array $req): array
    {
        return ['ok' => true];
    }

    public function complete(array $req): array
    {
        return ['ok' => true];
    }
}