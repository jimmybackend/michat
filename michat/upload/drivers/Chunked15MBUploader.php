<?php
// upload/drivers/Chunked15MBUploader.php
declare(strict_types=1);

require_once __DIR__ . '/../storage/UploadStateStore.php';

final class Chunked15MBUploader implements UploaderInterface
{
    /** @var UploadStateStore */
    private $store;

    public function __construct()
    {
        $this->store = new UploadStateStore(__DIR__ . '/../storage/state');
    }

  private function db(): mysqli {
    global $db_connection;
    if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
      throw new RuntimeException('DB no disponible ($db_connection). Revisa db.php/app_bootstrap.php');
    }
    return $db_connection;
  }

private function s3()
{
    if (!class_exists('Config')) {
        throw new RuntimeException('Config no está disponible');
    }

    $s3 = Config::getS3();

    // Caso 1: ya es el cliente correcto
    if ($s3 instanceof \Aws\S3\S3Client) {
        return $s3;
    }

    // Caso 2: Config devuelve el objeto Aws\Sdk
    if ($s3 instanceof \Aws\Sdk) {
        return $s3->createS3();
    }

    // Caso 3: Config devuelve un array con credenciales/región/etc
    if (is_array($s3)) {
        return new \Aws\S3\S3Client($s3);
    }

    // Debug útil: qué devolvió realmente
    $tipo = is_object($s3) ? get_class($s3) : gettype($s3);
    throw new RuntimeException('Config::getS3() devolvió: ' . $tipo);
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

  private function signature(string $filename, int $filesize): string {
    return sha1($filename . '|' . $filesize);
  }

  public function init(array $req): array
  {
    $filename = trim((string)($req['filename'] ?? ''));
    $filesize = (int)($req['filesize'] ?? 0);
    $mime     = trim((string)($req['mime'] ?? 'application/octet-stream'));
    if ($filename === '' || $filesize <= 0) {
      throw new RuntimeException('Datos inválidos: filename/filesize');
    }

    $sig = $this->signature($filename, $filesize);

    // key: usa carpeta de sesión + prefijo uploads/YYYYMMDD/
    $carpeta = $this->carpetaSesion();
    $ymd = gmdate('Ymd');
    $safeBase = preg_replace('/[^\w\-.]+/u', '_', basename($filename));
    $key = $carpeta . '/' . $sig . '-' . $safeBase; ///uploads/' . $ymd . '

    $s3 = $this->s3();
    $bucket = $this->bucket();

    $res = $s3->createMultipartUpload([
      'Bucket'      => $bucket,
      'Key'         => $key,
      'ContentType' => $mime,
      'ACL'         => 'private',
      'Metadata'    => [
        'original-name' => mb_substr($filename, 0, 1024),
        'original-size' => (string)$filesize
      ],
    ]);

    $uploadId = (string)$res->get('UploadId');

    $stateId = $sig; // id estable
    $this->store->save($stateId, [
      'stateId'  => $stateId,
      'uploadId' => $uploadId,
      'key'      => $key,
      'filename' => $filename,
      'filesize' => $filesize,
      'mime'     => $mime,
      'parts'    => [], // num => etag
      'created'  => time(),
    ]);

    return [
      'ok'       => true,
      'stateId'  => $stateId,
      'uploadId' => $uploadId,
      'key'      => $key,
    ];
  }

  public function part(array $req): array
  {
    $step = (string)($req['step'] ?? 'sign'); // sign|resume

    if ($step === 'resume') {
      $stateId  = (string)($req['stateId'] ?? '');
      $uploadId = (string)($req['uploadId'] ?? '');
      $key      = (string)($req['key'] ?? '');

      $meta = $stateId !== '' ? $this->store->load($stateId) : null;
      if ($meta) {
        $uploadId = (string)$meta['uploadId'];
        $key      = (string)$meta['key'];
      }

      if ($uploadId === '' || $key === '') {
        throw new RuntimeException('Faltan parámetros para resume (stateId o uploadId+key)');
      }

      $etags = $this->listPartsEtags($uploadId, $key);
      if ($meta && $stateId !== '') {
        $meta['parts'] = $etags;
        $this->store->save($stateId, $meta);
      }

      return ['ok'=>true, 'etags'=>$etags, 'uploadId'=>$uploadId, 'key'=>$key];
    }

    // step=sign (presigned UploadPart)
    $uploadId     = (string)($req['uploadId'] ?? '');
    $key          = (string)($req['key'] ?? '');
    $partNumber   = (int)($req['partNumber'] ?? 0);
    $contentLength= (int)($req['contentLength'] ?? 0);

    if ($uploadId === '' || $key === '' || $partNumber <= 0 || $contentLength <= 0) {
      throw new RuntimeException('Parámetros inválidos para firmar (uploadId,key,partNumber,contentLength)');
    }

    $s3 = $this->s3();
    $bucket = $this->bucket();

    $cmd = $s3->getCommand('UploadPart', [
      'Bucket'        => $bucket,
      'Key'           => $key,
      'UploadId'      => $uploadId,
      'PartNumber'    => $partNumber,
      'ContentLength' => $contentLength,
    ]);

    $presigned = $s3->createPresignedRequest($cmd, '+1 hour');
    $url = (string)$presigned->getUri();

    return ['ok'=>true, 'url'=>$url];
  }

  public function complete(array $req): array
  {
    $stateId  = (string)($req['stateId'] ?? '');
    $uploadId = (string)($req['uploadId'] ?? '');
    $key      = (string)($req['key'] ?? '');

    $meta = $stateId !== '' ? $this->store->load($stateId) : null;
    if ($meta) {
      $uploadId = (string)$meta['uploadId'];
      $key      = (string)$meta['key'];
    }

    $etagsJson = (string)($req['etags'] ?? '');
    $etags = $etagsJson !== '' ? (json_decode($etagsJson, true) ?: []) : [];
    if (empty($etags)) {
      // si no vienen etags del front, intentamos listParts
      if ($uploadId !== '' && $key !== '') {
        $etags = $this->listPartsEtags($uploadId, $key);
      }
    }

    if ($uploadId === '' || $key === '' || empty($etags)) {
      throw new RuntimeException('Faltan parámetros para complete (uploadId,key,etags)');
    }

    $parts = [];
    foreach ($etags as $num => $tag) {
      $parts[] = ['PartNumber' => (int)$num, 'ETag' => '"' . trim((string)$tag, '"') . '"'];
    }
    usort($parts, function($a, $b) {
      return ((int)$a['PartNumber']) <=> ((int)$b['PartNumber']);
    });

    $s3 = $this->s3();
    $bucket = $this->bucket();

    $s3->completeMultipartUpload([
      'Bucket' => $bucket,
      'Key'    => $key,
      'UploadId' => $uploadId,
      'MultipartUpload' => ['Parts' => $parts],
    ]);

    // Registrar DB (FileS3)
    $carpeta = dirname($key) . '/';
    $nombreEncriptado = basename($key);
    $nombreOriginal = $meta['filename'] ?? 'archivo';
    $filesize = (int)($meta['filesize'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $metadatos = json_encode([
      'multipart' => true,
      'uploadId'  => $uploadId,
      'parts'     => array_keys($etags),
      'ip_origen' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
      'usuario'   => $_SESSION['usuario'] ?? 'publico',
      'fecha'     => date('Y-m-d'),
      'hora'      => date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

    $repo = new FileS3Repository($this->db());
    $fileId = $repo->insertFile([
      'Nombre'     => (string)$nombreOriginal,
      'Encriptado' => (string)$nombreEncriptado,
      'Tamano'     => $filesize,
      'Metadatos'  => $metadatos,
      'Ruta'       => $carpeta,
      'Found'      => 1,
      'AccessType' => 'normal',
      'Fecha'      => date('Y-m-d H:i:s'),
      'user_id_'   => $userId,
    ]);

    if ($stateId !== '') $this->store->delete($stateId);

    return [
      'ok'       => true,
      'key'      => $key,
      'file_id'  => $fileId,
      'ruta'     => $carpeta,
      'encriptado'=> $nombreEncriptado,
    ];
  }

  private function listPartsEtags(string $uploadId, string $key): array
  {
    $s3 = $this->s3();
    $bucket = $this->bucket();

    $out = [];
    $marker = null;

    do {
      $args = ['Bucket'=>$bucket,'Key'=>$key,'UploadId'=>$uploadId];
      if ($marker !== null) $args['PartNumberMarker'] = $marker;

      $res = $s3->listParts($args);
      $parts = $res->get('Parts') ?: [];

      foreach ($parts as $p) {
        $num = (int)($p['PartNumber'] ?? 0);
        $etag = trim((string)($p['ETag'] ?? ''), '"');
        if ($num > 0 && $etag !== '') $out[(string)$num] = $etag;
      }

      $isTrunc = (bool)($res->get('IsTruncated') ?? false);
      $marker  = $res->get('NextPartNumberMarker') ?? null;
    } while ($isTrunc);

    return $out;
  }
}