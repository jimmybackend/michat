<?php
declare(strict_types=1);

use Aws\S3\S3Client;

require_once __DIR__ . '/../core/UploaderInterface.php';
require_once __DIR__ . '/../repositories/FileS3Repository.php';

final class LocalPresignedPutUploader implements UploaderInterface
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
    // Respeta tu sistema actual:
    $carpeta = isset($_SESSION['ruta_actual']) && $_SESSION['ruta_actual'] !== ''
      ? trim((string)$_SESSION['ruta_actual'], '/')
      : trim((string)Config::RUTA_COMPARTIDA, '/');
    return $carpeta;
  }

  public function init(array $req): array
  {
    $nombreOriginal = (string)($req['nombre'] ?? '');
    if ($nombreOriginal === '') {
      throw new RuntimeException('Falta parámetro nombre');
    }

    $carpeta = $this->carpetaSesion(); // sin / al final
    $ext = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
    $nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
    $key = $carpeta . '/' . $nombreEncriptado;

    // URL firmada PUT
    $s3 = $this->s3();
    $cmd = $s3->getCommand('PutObject', [
      'Bucket' => $this->bucket(),
      'Key'    => $key,
      'ACL'    => 'private',
    ]);
    $request = $s3->createPresignedRequest($cmd, '+1 hour');
    $url = (string)$request->getUri();

    // Metadatos DB (como tu firmado.php, pero además guardamos Ruta)
    $metadatos = json_encode([
      'ip_origen'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
      'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido',
      'referer'        => $_SERVER['HTTP_REFERER'] ?? 'ninguno',
      'fecha_servidor' => date('Y-m-d'),
      'hora_servidor'  => date('H:i:s'),
      'usuario_envio'  => $_SESSION['usuario'] ?? 'publico'
    ], JSON_UNESCAPED_UNICODE);

    $userId = (int)($_SESSION['user_id'] ?? 0);

    $repo = new FileS3Repository($this->db());
    $fileId = $repo->insertFile([
      'Nombre'     => $nombreOriginal,
      'Encriptado' => $nombreEncriptado,
      'Tamano'     => 0,
      'Metadatos'  => $metadatos,
      'Ruta'       => rtrim($carpeta, '/') . '/', // Ruta guardada como prefijo
      'Found'      => 1,
      'AccessType' => 'normal',
      'Fecha'      => date('Y-m-d H:i:s'),
      'user_id_'   => $userId,
    ]);

    return [
      'url'              => $url,
      'key'              => $key,
      'carpeta'          => $carpeta,
      'nombreOriginal'   => $nombreOriginal,
      'nombreEncriptado' => $nombreEncriptado,
      'file_id'          => $fileId,
    ];
  }

  public function part(array $req): array
  {
    // No aplica para local_put
    return ['ok' => true];
  }

  public function complete(array $req): array
  {
    // Opcional: permitir que el front avise tamaño real al final
    $nombreEncriptado = (string)($req['nombreEncriptado'] ?? '');
    $tamano = (int)($req['tamano'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($nombreEncriptado !== '' && $tamano > 0) {
      $repo = new FileS3Repository($this->db());
      $repo->updateSizeByEncriptado($nombreEncriptado, $tamano, $userId);
      return ['ok' => true, 'updated' => true];
    }

    return ['ok' => true, 'updated' => false];
  }
}