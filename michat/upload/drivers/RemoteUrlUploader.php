<?php
declare(strict_types=1);

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

require_once __DIR__ . '/../core/UploaderInterface.php';
require_once __DIR__ . '/../repositories/FileS3Repository.php';

final class RemoteUrlUploader implements UploaderInterface
{
  private function db(): mysqli {
    global $db_connection;
    if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
      throw new RuntimeException('DB no disponible ($db_connection). Revisa db.php/app_bootstrap.php');
    }
    return $db_connection;
  }

  private function s3(): S3Client {
    if (!class_exists('Config')) throw new RuntimeException('Config no está disponible');
    return Config::getS3();
  }

  private function bucket(): string {
    return (string)Config::BUCKET;
  }

  private function carpetaSesion(): string {
    $carpeta = isset($_SESSION['ruta_actual']) && $_SESSION['ruta_actual'] !== ''
      ? trim((string)$_SESSION['ruta_actual'], '/')
      : trim((string)Config::RUTA_COMPARTIDA, '/');
    return $carpeta;
  }

  // ---------- helpers URL ----------
  private function decodeUrl(array $req): string {
    $u64 = (string)($req['u64'] ?? '');
    if ($u64 !== '') {
      $d = base64_decode($u64, true);
      if ($d === false) throw new RuntimeException('u64 inválido');
      return trim($d);
    }
    $url = trim((string)($req['url'] ?? ''));
    if ($url === '') throw new RuntimeException('Falta parámetro url/u64');
    return $url;
  }

  private function guessFilename(string $url, array $headers): string {
    $cd = $headers['content-disposition'] ?? '';
    if ($cd) {
      if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $cd, $m)) return urldecode(trim($m[1], "\"' "));
      if (preg_match('/filename="?([^";]+)"?/i', $cd, $m)) return trim($m[1], "\"' ");
    }
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $base = basename($path);
    if ($base && $base !== '/' && strpos($base, '.') !== false) return $base;
    return 'archivo';
  }

  public function init(array $req): array
  {
    ignore_user_abort(true);
    set_time_limit(0);

    $url = $this->decodeUrl($req);

    // HEAD (si falla, seguimos igual)
    $headers = [];
    $ch = curl_init($url);
    if (!$ch) throw new RuntimeException('No se pudo inicializar cURL');
    curl_setopt_array($ch, [
      CURLOPT_NOBODY         => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS      => 10,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        return $len;
      },
      CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; RemoteUrlUploader/1.0)',
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_TIMEOUT        => 60,
    ]);
    @curl_exec($ch);
    curl_close($ch);

    $contentType = (string)($headers['content-type'] ?? 'application/octet-stream');
    $nombreOriginal = $this->guessFilename($url, $headers);

    $carpeta = $this->carpetaSesion();
    $ext = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
    if ($ext === '') $ext = 'bin';
    $nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $key = $carpeta . '/' . $nombreEncriptado;

    // Multipart streaming (8MB)
    $partSize = 8 * 1024 * 1024;
    $buffer = '';
    $uploadId = null;
    $parts = [];
    $partNumber = 1;
    $bytes = 0;

    $s3 = $this->s3();
    $bucket = $this->bucket();

    $ch = curl_init($url);
    if (!$ch) throw new RuntimeException('No se pudo inicializar cURL streaming');

    curl_setopt_array($ch, [
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS      => 10,
      CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; RemoteUrlUploader/1.0)',
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_TIMEOUT        => 0,
      CURLOPT_WRITEFUNCTION  => function($curl, $data) use (
        &$buffer, $partSize, &$uploadId, &$parts, &$partNumber,
        $s3, $bucket, $key, $contentType, $nombreOriginal, &$bytes
      ) {
        $buffer .= $data;
        $bytes += strlen($data);

        if ($uploadId === null && strlen($buffer) >= $partSize) {
          $init = $s3->createMultipartUpload([
            'Bucket'      => $bucket,
            'Key'         => $key,
            'ContentType' => $contentType ?: 'application/octet-stream',
            'ACL'         => 'private',
            'Metadata'    => ['OriginalName' => mb_substr($nombreOriginal, 0, 1024)],
          ]);
          $uploadId = (string)$init['UploadId'];
        }

        while ($uploadId !== null && strlen($buffer) >= $partSize) {
          $partData = substr($buffer, 0, $partSize);
          $buffer   = substr($buffer, $partSize);

          $res = $s3->uploadPart([
            'Bucket'     => $bucket,
            'Key'        => $key,
            'UploadId'   => $uploadId,
            'PartNumber' => $partNumber,
            'Body'       => $partData,
          ]);

          $parts[] = ['PartNumber'=>$partNumber,'ETag'=>$res['ETag']];
          $partNumber++;
        }

        return strlen($data);
      },
    ]);

    $ok = curl_exec($ch);
    if ($ok === false) {
      $err = curl_error($ch);
      curl_close($ch);
      if ($uploadId) {
        $s3->abortMultipartUpload(['Bucket'=>$bucket,'Key'=>$key,'UploadId'=>$uploadId]);
      }
      throw new RuntimeException('Error descargando URL: ' . $err);
    }
    curl_close($ch);

    // Si no llegó a partSize nunca, subimos PutObject simple
    if ($uploadId === null) {
      $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $key,
        'Body'        => $buffer,
        'ACL'         => 'private',
        'ContentType' => $contentType ?: 'application/octet-stream',
        'Metadata'    => ['OriginalName' => mb_substr($nombreOriginal, 0, 1024)],
      ]);
    } else {
      // sube último buffer como última parte
      if ($buffer !== '') {
        $res = $s3->uploadPart([
          'Bucket'     => $bucket,
          'Key'        => $key,
          'UploadId'   => $uploadId,
          'PartNumber' => $partNumber,
          'Body'       => $buffer,
        ]);
        $parts[] = ['PartNumber'=>$partNumber,'ETag'=>$res['ETag']];
      }

      $s3->completeMultipartUpload([
        'Bucket' => $bucket,
        'Key'    => $key,
        'UploadId' => $uploadId,
        'MultipartUpload' => ['Parts' => $parts],
      ]);
    }

    // Registrar en DB (Ruta NOT NULL)
    $metadatos = json_encode([
      'source_url'   => $url,
      'content_type' => $contentType,
      'bytes'        => $bytes,
      'ip_origen'    => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
      'usuario_envio'=> $_SESSION['usuario'] ?? 'publico',
      'fecha'        => date('Y-m-d'),
      'hora'         => date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

    $repo = new FileS3Repository($this->db());
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $fileId = $repo->insertFile([
      'Nombre'     => $nombreOriginal,
      'Encriptado' => $nombreEncriptado,
      'Tamano'     => $bytes,
      'Metadatos'  => $metadatos,
      'Ruta'       => rtrim($carpeta, '/') . '/',
      'Found'      => 1,
      'AccessType' => 'normal',
      'Fecha'      => date('Y-m-d H:i:s'),
      'user_id_'   => $userId,
    ]);

    return [
      'ok'              => true,
      'key'             => $key,
      'bytes'           => $bytes,
      'file_id'         => $fileId,
      'nombreOriginal'  => $nombreOriginal,
      'nombreEncriptado'=> $nombreEncriptado,
      'carpeta'         => $carpeta,
    ];
  }

  public function part(array $req): array { return ['ok'=>true]; }
  public function complete(array $req): array { return ['ok'=>true]; }
}