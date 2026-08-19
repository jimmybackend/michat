<?php
require_once __DIR__ . '/app_bootstrap.php';

class S3Manager
{
    private $s3;
    private $bucket;
    private $db;

    public function __construct()
    {
        $this->s3 = Config::getS3();
        $this->bucket = Config::getBucket();

        global $db_connection;
        $this->db = $db_connection;

        if (!$this->db instanceof mysqli) {
            throw new RuntimeException('No existe una conexión mysqli válida en $db_connection');
        }
    }

    public function getBucket()
    {
        return $this->bucket;
    }

    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        $prefix = str_replace('\\', '/', $prefix);
        $prefix = preg_replace('~\.\.(?:/|$)~', '', $prefix);
        $prefix = preg_replace('~/+~', '/', $prefix);
        $prefix = ltrim($prefix, '/');

        if ($prefix === '' || $prefix === '/') {
            $prefix = Config::RUTA_RAIZ ?? 'Data/';
        }

        if (substr($prefix, -1) !== '/') {
            $prefix .= '/';
        }

        return $prefix;
    }

    private function getBasePrefix(): string
    {
        return $this->normalizePrefix(Config::RUTA_RAIZ ?? 'Data/');
    }

    private function getSessionRoute(): string
    {
        $this->ensureSessionStarted();
        return $this->normalizePrefix($_SESSION['ruta_actual'] ?? $this->getBasePrefix());
    }

    private function setSessionRoute(string $prefix): void
    {
        $this->ensureSessionStarted();
        $_SESSION['ruta_actual'] = $this->normalizePrefix($prefix);
    }

    private function updateSessionRouteAfterMove(string $oldPrefix, string $newPrefix): void
    {
        $this->ensureSessionStarted();

        $oldPrefix = $this->normalizePrefix($oldPrefix);
        $newPrefix = $this->normalizePrefix($newPrefix);
        $current   = $this->normalizePrefix($_SESSION['ruta_actual'] ?? $this->getBasePrefix());

        if (strpos($current, $oldPrefix) === 0) {
            $_SESSION['ruta_actual'] = $newPrefix . substr($current, strlen($oldPrefix));
        }
    }

    private function updateSessionRouteAfterDelete(string $deletedPrefix): void
    {
        $this->ensureSessionStarted();

        $deletedPrefix = $this->normalizePrefix($deletedPrefix);
        $current       = $this->normalizePrefix($_SESSION['ruta_actual'] ?? $this->getBasePrefix());

        if (strpos($current, $deletedPrefix) === 0) {
            $parent = $this->parentPrefix($deletedPrefix);
            if ($parent === null || strpos($parent, $this->getBasePrefix()) !== 0) {
                $parent = $this->getBasePrefix();
            }
            $_SESSION['ruta_actual'] = $parent;
        }
    }

    private function folderNameFromPrefix(string $prefix): string
    {
        return basename(rtrim($this->normalizePrefix($prefix), '/'));
    }

    private function parentPrefix(string $prefix): ?string
    {
        $prefix = rtrim($this->normalizePrefix($prefix), '/');
        $pos = strrpos($prefix, '/');

        if ($pos === false) {
            return null;
        }

        $parent = substr($prefix, 0, $pos + 1);
        return $parent === '' ? null : $parent;
    }

    private function resolveUserId(): int
    {
        $this->ensureSessionStarted();

        $candidates = [
            $_SESSION['user_id_'] ?? null,
            $_SESSION['user_id'] ?? null,
            $_SESSION['id_usuario'] ?? null,
            $_SESSION['id_user'] ?? null,
            $_SESSION['id'] ?? null,
            $_POST['user_id_'] ?? null,
            $_GET['user_id_'] ?? null,
        ];

        foreach ($candidates as $value) {
            if ($value !== null && $value !== '' && ctype_digit((string)$value)) {
                return (int)$value;
            }
        }

        throw new RuntimeException('No se pudo resolver user_id_. Ajusta resolveUserId() según tu sesión real.');
    }

    private function executeStmt(mysqli_stmt $stmt, string $context): void
    {
        if (!$stmt->execute()) {
            $error = $stmt->error ?: $this->db->error;
            $stmt->close();
            throw new RuntimeException($context . ': ' . $error);
        }
    }

    public function folderExistsDb(int $userId, string $prefix): bool
    {
        $prefix = $this->normalizePrefix($prefix);

        $sql = "SELECT 1
                FROM S3Folders
                WHERE user_id_ = ?
                  AND Prefix = ?
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando folderExistsDb: ' . $this->db->error);
        }

        $stmt->bind_param('is', $userId, $prefix);
        $this->executeStmt($stmt, 'Error ejecutando folderExistsDb');
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    private function upsertFolderDb(int $userId, string $prefix): void
    {
        $prefix = $this->normalizePrefix($prefix);
        $nombre = $this->folderNameFromPrefix($prefix);
        $parent = $this->parentPrefix($prefix);

        $sql = "INSERT INTO S3Folders
                    (user_id_, Prefix, Nombre, ParentPrefix, Found, AccessType, PasswordHash, SecureHint, SecureUpdatedAt)
                VALUES
                    (?, ?, ?, ?, 1, 'normal', NULL, NULL, NULL)
                ON DUPLICATE KEY UPDATE
                    Nombre = VALUES(Nombre),
                    ParentPrefix = VALUES(ParentPrefix),
                    Found = 1,
                    UpdatedAt = CURRENT_TIMESTAMP";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando upsertFolderDb: ' . $this->db->error);
        }

        $stmt->bind_param('isss', $userId, $prefix, $nombre, $parent);
        $this->executeStmt($stmt, 'Error ejecutando upsertFolderDb');
        $stmt->close();
    }

    /**
     * Método público para crear/actualizar carpetas S3Folders
     * Usado por session_upload.php para crear la jerarquía de carpetas
     */
    public function upsertFolderDbPublic(int $userId, string $prefix): void
    {
        $this->upsertFolderDb($userId, $prefix);
    }

/**
 * ============================================================
 * FUNCTION: renameMoveFolderTreeDb
 * ============================================================
 * DESCRIPCIÓN:
 * Actualiza recursivamente en base de datos el árbol completo de una
 * carpeta movida o renombrada. Recalcula en S3Folders los campos
 * Prefix, ParentPrefix y Nombre, y en FileS3 actualiza Ruta y
 * Encriptado para todos los archivos contenidos dentro de la carpeta
 * afectada y sus subcarpetas, dejando la nueva ruta completa
 * sincronizada con la ubicación real en S3.
 *
 * PARÁMETROS:
 * $userId    → id del usuario propietario
 * $oldPrefix → prefijo anterior de la carpeta
 * $newPrefix → nuevo prefijo de la carpeta
 *
 * RETORNA:
 * array con la cantidad de carpetas y archivos actualizados
 * ============================================================
 */
private function renameMoveFolderTreeDb(int $userId, string $oldPrefix, string $newPrefix): array
{
    $oldPrefix = $this->normalizePrefix($oldPrefix);
    $newPrefix = $this->normalizePrefix($newPrefix);

    if ($oldPrefix === $newPrefix) {
        return ['foldersUpdated' => 0, 'filesUpdated' => 0];
    }

    /**
     * ============================================================
     * 1) ACTUALIZAR ÁRBOL DE CARPETAS EN S3Folders
     * ============================================================
     * Se actualiza:
     * - Prefix
     * - ParentPrefix
     * - Nombre
     *
     * Esto mantiene consistente el bloque lateral de carpetas,
     * ya que el árbol se arma con Prefix / ParentPrefix.
     * ============================================================
     */
    $sqlSel = "SELECT id_, Prefix, ParentPrefix
               FROM S3Folders
               WHERE user_id_ = ?
                 AND (Prefix = ? OR Prefix LIKE CONCAT(?, '%'))
               ORDER BY LENGTH(Prefix) ASC";

    $stmtSel = $this->db->prepare($sqlSel);
    if (!$stmtSel) {
        throw new RuntimeException('Error preparando lectura de S3Folders: ' . $this->db->error);
    }

    $stmtSel->bind_param('iss', $userId, $oldPrefix, $oldPrefix);
    $this->executeStmt($stmtSel, 'Error ejecutando lectura de S3Folders');
    $res = $stmtSel->get_result();

    $folders = [];
    while ($row = $res->fetch_assoc()) {
        $folders[] = $row;
    }
    $stmtSel->close();

    if (empty($folders)) {
        throw new RuntimeException('La carpeta origen no existe en S3Folders.');
    }

    $sqlUpdFolder = "UPDATE S3Folders
                     SET Prefix = ?, Nombre = ?, ParentPrefix = ?, Found = 1, UpdatedAt = CURRENT_TIMESTAMP
                     WHERE id_ = ? AND user_id_ = ?";

    $stmtUpdFolder = $this->db->prepare($sqlUpdFolder);
    if (!$stmtUpdFolder) {
        throw new RuntimeException('Error preparando update de S3Folders: ' . $this->db->error);
    }

    $foldersUpdated = 0;

    foreach ($folders as $folder) {
        $currentPrefix = $this->normalizePrefix((string)$folder['Prefix']);
        $suffix = substr($currentPrefix, strlen($oldPrefix));
        $newFolderPrefix = $this->normalizePrefix($newPrefix . $suffix);

        if ($currentPrefix === $oldPrefix) {
            $newParent = $this->parentPrefix($newPrefix);
        } else {
            $currentParent = isset($folder['ParentPrefix']) && $folder['ParentPrefix'] !== null
                ? $this->normalizePrefix((string)$folder['ParentPrefix'])
                : null;

            if ($currentParent !== null && strpos($currentParent, $oldPrefix) === 0) {
                $newParent = $this->normalizePrefix($newPrefix . substr($currentParent, strlen($oldPrefix)));
            } else {
                $newParent = $currentParent;
            }
        }

        $newName = $this->folderNameFromPrefix($newFolderPrefix);
        $id = (int)$folder['id_'];

        $stmtUpdFolder->bind_param('sssii', $newFolderPrefix, $newName, $newParent, $id, $userId);
        $this->executeStmt($stmtUpdFolder, 'Error actualizando S3Folders');
        $foldersUpdated++;
    }

    $stmtUpdFolder->close();

    /**
     * ============================================================
     * 2) ACTUALIZAR ARCHIVOS EN FileS3
     * ============================================================
     * Aquí SÍ se debe actualizar:
     * - Ruta
     * - Encriptado
     *
     * Porque otras acciones del sistema leen directamente el campo
     * Encriptado como key/ruta completa.
     * ============================================================
     */
    $sqlSelFiles = "SELECT id_, Ruta, Encriptado
                    FROM FileS3
                    WHERE user_id_ = ?
                      AND Found = 1
                      AND (Ruta = ? OR Ruta LIKE CONCAT(?, '%'))";

    $stmtSelFiles = $this->db->prepare($sqlSelFiles);
    if (!$stmtSelFiles) {
        throw new RuntimeException('Error preparando lectura de FileS3: ' . $this->db->error);
    }

    $stmtSelFiles->bind_param('iss', $userId, $oldPrefix, $oldPrefix);
    $this->executeStmt($stmtSelFiles, 'Error leyendo FileS3 para mover carpeta');
    $resFiles = $stmtSelFiles->get_result();

    $files = [];
    while ($row = $resFiles->fetch_assoc()) {
        $files[] = $row;
    }
    $stmtSelFiles->close();

    $sqlUpdFile = "UPDATE FileS3
                   SET Ruta = ?, Encriptado = ?, Found = 1
                   WHERE id_ = ? AND user_id_ = ?";

    $stmtUpdFile = $this->db->prepare($sqlUpdFile);
    if (!$stmtUpdFile) {
        throw new RuntimeException('Error preparando update individual de FileS3: ' . $this->db->error);
    }

    $filesUpdated = 0;

    foreach ($files as $fileRow) {
        $rutaActualFile = $this->normalizePrefix((string)$fileRow['Ruta']);
        $encActual = trim((string)$fileRow['Encriptado']);

        /**
         * Puede venir:
         * - solo basename: abc123.pdf
         * - o key completa: Data/Clientes/abc123.pdf
         *
         * En ambos casos tomamos el basename real y reconstruimos
         * la nueva key completa.
         */
        $basenameEnc = basename(str_replace('\\', '/', $encActual));
        if ($basenameEnc === '') {
            throw new RuntimeException('Archivo con Encriptado vacío o inválido. ID: ' . (int)$fileRow['id_']);
        }

        if (strpos($rutaActualFile, $oldPrefix) !== 0) {
            throw new RuntimeException('Ruta fuera del árbol origen. ID: ' . (int)$fileRow['id_']);
        }

        $newRutaFile = $this->normalizePrefix($newPrefix . substr($rutaActualFile, strlen($oldPrefix)));
        $newEncriptado = $this->normalizeFileKey($newRutaFile . $basenameEnc);

        $idFile = (int)$fileRow['id_'];
        $stmtUpdFile->bind_param('ssii', $newRutaFile, $newEncriptado, $idFile, $userId);
        $this->executeStmt($stmtUpdFile, 'Error actualizando FileS3 al mover carpeta');
        $filesUpdated++;
    }

    $stmtUpdFile->close();

    return [
        'foldersUpdated' => $foldersUpdated,
        'filesUpdated'   => $filesUpdated
    ];
}

private function getFolderRecord($folderRef, bool $throwIfMissing = false): ?array
{
    if (is_int($folderRef) || ctype_digit((string)$folderRef)) {
        $id = (int)$folderRef;

        $sql = "SELECT *
                FROM S3Folders
                WHERE id_ = ?
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando getFolderRecord por id: ' . $this->db->error);
        }

        $stmt->bind_param('i', $id);
        $this->executeStmt($stmt, 'Error ejecutando getFolderRecord por id');
        $result = $stmt->get_result();
        $folder = $result->fetch_assoc();
        $stmt->close();
    } else {
        $prefix = $this->normalizePrefix((string)$folderRef);

        $sql = "SELECT *
                FROM S3Folders
                WHERE Prefix = ?
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando getFolderRecord por prefix: ' . $this->db->error);
        }

        $stmt->bind_param('s', $prefix);
        $this->executeStmt($stmt, 'Error ejecutando getFolderRecord por prefix');
        $result = $stmt->get_result();
        $folder = $result->fetch_assoc();
        $stmt->close();
    }

    if (!$folder && $throwIfMissing) {
        throw new RuntimeException('Carpeta no encontrada.');
    }

    return $folder ?: null;
}

    private function deleteFolderTreeDb(int $userId, string $prefix): array
    {
        $prefix = $this->normalizePrefix($prefix);

        $sqlFiles = "UPDATE FileS3
                     SET Found = 0
                     WHERE user_id_ = ?
                       AND (Ruta = ? OR Ruta LIKE CONCAT(?, '%'))";

        $stmtFiles = $this->db->prepare($sqlFiles);
        if (!$stmtFiles) {
            throw new RuntimeException('Error preparando update de FileS3: ' . $this->db->error);
        }

        $stmtFiles->bind_param('iss', $userId, $prefix, $prefix);
        $this->executeStmt($stmtFiles, 'Error actualizando FileS3 al eliminar carpeta');
        $filesAffected = $stmtFiles->affected_rows;
        $stmtFiles->close();

        $sqlFolders = "DELETE FROM S3Folders
                       WHERE user_id_ = ?
                         AND (Prefix = ? OR Prefix LIKE CONCAT(?, '%'))";

        $stmtFolders = $this->db->prepare($sqlFolders);
        if (!$stmtFolders) {
            throw new RuntimeException('Error preparando delete de S3Folders: ' . $this->db->error);
        }

        $stmtFolders->bind_param('iss', $userId, $prefix, $prefix);
        $this->executeStmt($stmtFolders, 'Error eliminando S3Folders');
        $foldersAffected = $stmtFolders->affected_rows;
        $stmtFolders->close();

        return [
            'filesAffected'   => $filesAffected,
            'foldersAffected' => $foldersAffected
        ];
    }

    public function listCarpetas($prefix, $showCounts = false)
    {
        $prefix = $this->normalizePrefix($prefix);

        $result = $this->s3->listObjectsV2([
            'Bucket'    => $this->bucket,
            'Prefix'    => $prefix,
            'Delimiter' => '/',
        ]);

        $carpetas = [];

        foreach ($result['CommonPrefixes'] ?? [] as $prefixItem) {
            $folderPrefix = $prefixItem['Prefix'];
            $nombre = basename(rtrim($folderPrefix, '/'));
            $info = [
                'ruta'   => $folderPrefix,
                'nombre' => $nombre
            ];

            if ($showCounts) {
                $subList = $this->s3->listObjectsV2([
                    'Bucket'    => $this->bucket,
                    'Prefix'    => $folderPrefix,
                    'Delimiter' => '/',
                ]);

                $info['fileCount'] = count(array_filter($subList['Contents'] ?? [], function ($obj) use ($folderPrefix) {
                    return $obj['Key'] !== $folderPrefix;
                }));

                $info['subfolderCount'] = count($subList['CommonPrefixes'] ?? []);
            }

            $carpetas[] = $info;
        }

        return $carpetas;
    }

    public function listArchivos($prefix, $includeMeta = false)
    {
        $prefix = $this->normalizePrefix($prefix);

        $result = $this->s3->listObjectsV2([
            'Bucket'    => $this->bucket,
            'Prefix'    => $prefix,
            'Delimiter' => '/',
        ]);

        $archivos = [];

        foreach ($result['Contents'] ?? [] as $obj) {
            $key = $obj['Key'];

            if ($key === $prefix || substr($key, -1) === '/') {
                continue;
            }

            if ($includeMeta) {
                try {
                    $head = $this->s3->headObject([
                        'Bucket' => $this->bucket,
                        'Key'    => $key
                    ]);
                    $obj['meta'] = $head['Metadata'] ?? [];
                } catch (Exception $e) {
                    $obj['meta'] = [];
                }
            }

            $archivos[] = $obj;
        }

        return $archivos;
    }

    public function obtenerTodasLasCarpetas($prefix = 'Data/')
    {
        $prefix = $this->normalizePrefix($prefix);

        $resultado = [];
        $continuationToken = null;

        do {
            $params = [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ];

            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }

            $result = $this->s3->listObjectsV2($params);

            if (!empty($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    $key = $object['Key'];

                    if (substr($key, -1) === '/') {
                        $resultado[] = $key;
                    } else {
                        $carpeta = dirname($key) . '/';
                        if ($carpeta !== './' && !in_array($carpeta, $resultado, true)) {
                            $resultado[] = $carpeta;
                        }
                    }
                }
            }

            $continuationToken = $result['NextContinuationToken'] ?? null;
        } while ($continuationToken);

        return array_values(array_unique($resultado));
    }

    public function listarCarpetasDesdeDb(?int $userId = null, ?string $basePrefix = null, bool $incluirBase = true): array
    {
        $userId = $userId ?? $this->resolveUserId();
        $basePrefix = $this->normalizePrefix($basePrefix ?? $this->getBasePrefix());

        $sql = "SELECT Prefix
                FROM S3Folders
                WHERE user_id_ = ?
                  AND Found = 1
                  AND (Prefix = ? OR Prefix LIKE CONCAT(?, '%'))
                ORDER BY Prefix ASC";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando listarCarpetasDesdeDb: ' . $this->db->error);
        }

        $stmt->bind_param('iss', $userId, $basePrefix, $basePrefix);
        $this->executeStmt($stmt, 'Error ejecutando listarCarpetasDesdeDb');
        $res = $stmt->get_result();

        $carpetas = [];
        while ($row = $res->fetch_assoc()) {
            $prefix = $this->normalizePrefix((string)$row['Prefix']);
            $carpetas[$prefix] = true;
        }
        $stmt->close();

        if ($incluirBase) {
            $carpetas[$basePrefix] = true;
        }

        $lista = array_keys($carpetas);
        natcasesort($lista);

        return array_values($lista);
    }

    public function crearCarpeta(string $rutaBase, string $nombreCarpeta): void
    {
        $userId = $this->resolveUserId();

        $rutaBase = trim($rutaBase) !== ''
            ? $this->normalizePrefix($rutaBase)
            : $this->getSessionRoute();

        $nombreCarpeta = trim($nombreCarpeta);

        if ($nombreCarpeta === '') {
            throw new RuntimeException('Debes indicar un nombre de carpeta.');
        }

        if (!preg_match('/^[^\\\\\/:*?"<>|]+$/u', $nombreCarpeta)) {
            throw new RuntimeException('El nombre de la carpeta contiene caracteres no permitidos.');
        }

        $base = $this->getBasePrefix();
        if (strpos($rutaBase, $base) !== 0) {
            throw new RuntimeException('Ruta fuera de la carpeta base.');
        }

        $carpetaKey = $this->normalizePrefix($rutaBase . $nombreCarpeta);

        if ($this->folderExistsDb($userId, $carpetaKey)) {
            throw new RuntimeException('La carpeta ya existe en la base de datos.');
        }

        $probe = $this->s3->listObjectsV2([
            'Bucket'  => $this->bucket,
            'Prefix'  => $carpetaKey,
            'MaxKeys' => 1
        ]);

        if (!empty($probe['KeyCount'])) {
            throw new RuntimeException('La carpeta ya existe en S3.');
        }

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key'    => $carpetaKey,
            'Body'   => '',
            'ACL'    => 'private'
        ]);

        $this->db->begin_transaction();

        try {
            $this->upsertFolderDb($userId, $carpetaKey);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->setSessionRoute($rutaBase);
    }

public function eliminarCarpetaCompleta($ruta): array
{
    $ruta = $this->normalizePrefix((string)$ruta);
    return $this->deleteFolderRecursive($ruta);
}

    public function moverCarpeta(string $rutaOrigen, string $rutaDestino): void
    {
        $userId = $this->resolveUserId();
    
        $rutaOrigen = $this->normalizePrefix($rutaOrigen);
        $rutaDestino = trim($rutaDestino) !== ''
            ? $this->normalizePrefix($rutaDestino)
            : $this->getSessionRoute();
    
        // El destino final conserva el nombre de la carpeta movida
        $rutaDestino = $this->normalizePrefix(
            $rutaDestino . $this->folderNameFromPrefix($rutaOrigen)
        );
    
        $base = $this->getBasePrefix();
    
        if (strpos($rutaOrigen, $base) !== 0 || strpos($rutaDestino, $base) !== 0) {
            throw new RuntimeException('Ruta fuera de la carpeta base.');
        }
    
        if ($rutaOrigen === $base) {
            throw new RuntimeException('No puedes mover la carpeta raíz.');
        }
    
        if ($rutaOrigen === $rutaDestino) {
            throw new RuntimeException('El destino es igual al origen.');
        }
    
        if (strpos($rutaDestino, $rutaOrigen) === 0) {
            throw new RuntimeException('No puedes mover una carpeta dentro de sí misma.');
        }
    
        $probe = $this->s3->listObjectsV2([
            'Bucket'  => $this->bucket,
            'Prefix'  => $rutaDestino,
            'MaxKeys' => 1
        ]);
    
        if (!empty($probe['KeyCount']) || $this->folderExistsDb($userId, $rutaDestino)) {
            throw new RuntimeException('La carpeta destino ya existe.');
        }
    
        $continuationToken = null;
        $borrar = [];
    
        do {
            $params = [
                'Bucket'  => $this->bucket,
                'Prefix'  => $rutaOrigen,
                'MaxKeys' => 1000
            ];
    
            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }
    
            $objetos = $this->s3->listObjectsV2($params);
    
            foreach ($objetos['Contents'] ?? [] as $objeto) {
                $origen = $objeto['Key'];
    
                /**
                 * IMPORTANTE:
                 * Solo cambia el prefijo de la carpeta.
                 * El resto de la key se conserva exactamente igual,
                 * por lo tanto el nombre real del archivo en S3 no cambia.
                 */
                $nuevo = $rutaDestino . substr($origen, strlen($rutaOrigen));
    
                $this->s3->copyObject([
                    'Bucket'            => $this->bucket,
                    'CopySource'        => rawurlencode($this->bucket . '/' . $origen),
                    'Key'               => $nuevo,
                    'ACL'               => 'private',
                    'MetadataDirective' => 'COPY'
                ]);
    
                $borrar[] = ['Key' => $origen];
    
                if (count($borrar) >= 1000) {
                    $this->s3->deleteObjects([
                        'Bucket' => $this->bucket,
                        'Delete' => ['Objects' => $borrar, 'Quiet' => true]
                    ]);
                    $borrar = [];
                }
            }
    
            $continuationToken = !empty($objetos['IsTruncated'])
                ? ($objetos['NextContinuationToken'] ?? null)
                : null;
    
        } while ($continuationToken);
    
        if (!empty($borrar)) {
            $this->s3->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => $borrar, 'Quiet' => true]
            ]);
        }
    
        $this->db->begin_transaction();
    
        try {
            /**
             * Aquí está la parte importante para la BD:
             * renameMoveFolderTreeDb() debe actualizar solo Ruta,
             * dejando intactos Nombre y Encriptado.
             */
            $this->renameMoveFolderTreeDb($userId, $rutaOrigen, $rutaDestino);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    
        $this->updateSessionRouteAfterMove($rutaOrigen, $rutaDestino);
    }

public function renombrarCarpeta(string $rutaAntigua, string $nuevoNombre): void
{
    $userId = $this->resolveUserId();

    $rutaAntigua = $this->normalizePrefix($rutaAntigua);
    $nuevoNombre = trim($nuevoNombre);

    if ($nuevoNombre === '') {
        throw new RuntimeException('Debes indicar el nuevo nombre de la carpeta.');
    }

    if (!preg_match('/^[^\\\\\/:*?"<>|]+$/u', $nuevoNombre)) {
        throw new RuntimeException('El nuevo nombre contiene caracteres no permitidos.');
    }

    $base = $this->getBasePrefix();

    if (strpos($rutaAntigua, $base) !== 0) {
        throw new RuntimeException('Ruta fuera de la carpeta base.');
    }

    if ($rutaAntigua === $base) {
        throw new RuntimeException('No puedes renombrar la carpeta raíz.');
    }

    $nuevaRuta = $this->normalizePrefix($this->getRutaPadre($rutaAntigua) . $nuevoNombre);

    if ($rutaAntigua === $nuevaRuta) {
        return;
    }

    $probe = $this->s3->listObjectsV2([
        'Bucket'  => $this->bucket,
        'Prefix'  => $nuevaRuta,
        'MaxKeys' => 1
    ]);

    if (!empty($probe['KeyCount']) || $this->folderExistsDb($userId, $nuevaRuta)) {
        throw new RuntimeException('Ya existe una carpeta con ese nombre.');
    }

    $continuationToken = null;
    $borrar = [];

    do {
        $params = [
            'Bucket'  => $this->bucket,
            'Prefix'  => $rutaAntigua,
            'MaxKeys' => 1000
        ];

        if ($continuationToken) {
            $params['ContinuationToken'] = $continuationToken;
        }

        $objetos = $this->s3->listObjectsV2($params);

        foreach ($objetos['Contents'] ?? [] as $objeto) {
            $origen = $objeto['Key'];

            /**
             * IMPORTANTE:
             * Solo cambia el prefijo/carpeta.
             * El resto de la key se conserva igual,
             * así el nombre real del archivo en S3 no cambia.
             */
            $destino = $nuevaRuta . substr($origen, strlen($rutaAntigua));

            $this->s3->copyObject([
                'Bucket'            => $this->bucket,
                'CopySource'        => rawurlencode($this->bucket . '/' . $origen),
                'Key'               => $destino,
                'ACL'               => 'private',
                'MetadataDirective' => 'COPY'
            ]);

            $borrar[] = ['Key' => $origen];

            if (count($borrar) >= 1000) {
                $this->s3->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => ['Objects' => $borrar, 'Quiet' => true]
                ]);
                $borrar = [];
            }
        }

        $continuationToken = !empty($objetos['IsTruncated'])
            ? ($objetos['NextContinuationToken'] ?? null)
            : null;

    } while ($continuationToken);

    if (!empty($borrar)) {
        $this->s3->deleteObjects([
            'Bucket' => $this->bucket,
            'Delete' => ['Objects' => $borrar, 'Quiet' => true]
        ]);
    }

    $this->db->begin_transaction();

    try {
        /**
         * Aquí está la clave:
         * renameMoveFolderTreeDb() debe actualizar solo Ruta en FileS3,
         * sin tocar Nombre ni Encriptado.
         */
        $this->renameMoveFolderTreeDb($userId, $rutaAntigua, $nuevaRuta);
        $this->db->commit();
    } catch (Throwable $e) {
        $this->db->rollback();
        throw $e;
    }

    $this->updateSessionRouteAfterMove($rutaAntigua, $nuevaRuta);
}

/**
 * ============================================================
 * FUNCTION: deleteFolderRecursive
 * ============================================================
 * DESCRIPCIÓN:
 * Elimina de forma recursiva una carpeta completa:
 * - borra todos los objetos del bucket S3 bajo ese prefijo
 * - borra todos los registros relacionados en FileS3
 * - borra la carpeta y sus subcarpetas en S3Folders
 *
 * PARÁMETROS:
 * $folderRef → id_ o Prefix de la carpeta
 *
 * RETORNA:
 * array con cantidad de objetos S3, archivos BD y carpetas BD eliminadas
 * ============================================================
 */
public function deleteFolderRecursive($folderRef): array
{
    $folder = $this->getFolderRecord($folderRef, true);

    $userId = (int)$folder['user_id_'];
    $prefix = $this->normalizePrefix((string)$folder['Prefix']);

    if ($prefix === '') {
        throw new RuntimeException('Prefijo de carpeta inválido.');
    }

    $deletedS3Objects = 0;
    $deletedFilesDb   = 0;
    $deletedFoldersDb = 0;

    $this->db->begin_transaction();

    try {
        /**
         * ============================================================
         * 1) ELIMINAR OBJETOS EN S3 BAJO EL PREFIJO
         * ============================================================
         */
        $continuationToken = null;

        do {
            $params = [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'MaxKeys' => 1000
            ];

            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }

            $result = $this->s3->listObjectsV2($params);

            $objects = [];
            if (!empty($result['Contents'])) {
                foreach ($result['Contents'] as $obj) {
                    if (!empty($obj['Key'])) {
                        $objects[] = ['Key' => (string)$obj['Key']];
                    }
                }
            }

            if (!empty($objects)) {
                $this->s3->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => [
                        'Objects' => $objects,
                        'Quiet'   => true
                    ]
                ]);

                $deletedS3Objects += count($objects);
            }

            $isTruncated = !empty($result['IsTruncated']);
            $continuationToken = $result['NextContinuationToken'] ?? null;

        } while ($isTruncated);

        /**
         * ============================================================
         * 2) ELIMINAR ARCHIVOS EN FileS3
         * ============================================================
         */
        $sqlFiles = "DELETE FROM FileS3
                     WHERE user_id_ = ?
                       AND (Ruta = ? OR Ruta LIKE CONCAT(?, '%'))";

        $stmtFiles = $this->db->prepare($sqlFiles);
        if (!$stmtFiles) {
            throw new RuntimeException('Error preparando delete de FileS3: ' . $this->db->error);
        }

        $stmtFiles->bind_param('iss', $userId, $prefix, $prefix);
        $this->executeStmt($stmtFiles, 'Error eliminando archivos de FileS3');
        $deletedFilesDb = $stmtFiles->affected_rows;
        $stmtFiles->close();

        /**
         * ============================================================
         * 3) ELIMINAR CARPETAS EN S3Folders
         * ============================================================
         */
        $sqlFolders = "DELETE FROM S3Folders
                       WHERE user_id_ = ?
                         AND (Prefix = ? OR Prefix LIKE CONCAT(?, '%'))";

        $stmtFolders = $this->db->prepare($sqlFolders);
        if (!$stmtFolders) {
            throw new RuntimeException('Error preparando delete de S3Folders: ' . $this->db->error);
        }

        $stmtFolders->bind_param('iss', $userId, $prefix, $prefix);
        $this->executeStmt($stmtFolders, 'Error eliminando carpetas de S3Folders');
        $deletedFoldersDb = $stmtFolders->affected_rows;
        $stmtFolders->close();

        $this->db->commit();
        $this->updateSessionRouteAfterDelete($prefix);

        return [
            'estado'            => 'ok',
            'prefix_eliminado'  => $prefix,
            's3_objects'        => $deletedS3Objects,
            'files_deleted'     => $deletedFilesDb,
            'folders_deleted'   => $deletedFoldersDb
        ];

    } catch (\Throwable $e) {
        $this->db->rollback();
        throw $e;
    }
}

    public function generarPresignedUrl($key, $ttl = '+10 minutes')
    {
        $cmd = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $key
        ]);

        $request = $this->s3->createPresignedRequest($cmd, $ttl);
        return (string)$request->getUri();
    }

    /**
     * ============================================================
     * FUNCTION: normalizeFileKey
     * ============================================================
     * DESCRIPCIÓN:
     * Normaliza una key de archivo para trabajar siempre con el mismo
     * formato interno. Convierte backslashes a slashes, elimina slashes
     * duplicados y quita el slash inicial.
     *
     * PARÁMETROS:
     * $key → key S3 del archivo
     *
     * RETORNA:
     * string normalizado
     * ============================================================
     */
    private function normalizeFileKey(string $key): string
    {
        $key = trim($key);
        $key = str_replace('\\', '/', $key);
        $key = preg_replace('~/+~', '/', $key);
        return ltrim($key, '/');
    }

    /**
     * ============================================================
     * FUNCTION: buildStoredFileKey
     * ============================================================
     * DESCRIPCIÓN:
     * Construye la key real del archivo guardado en S3 a partir del
     * registro de FileS3.
     *
     * PARÁMETROS:
     * $file → fila de FileS3
     *
     * RETORNA:
     * string key completa en S3
     * ============================================================
     */
    private function buildStoredFileKey(array $file): string
    {
        $ruta = $this->normalizePrefix((string)($file['Ruta'] ?? ''));
        $enc  = trim((string)($file['Encriptado'] ?? ''));

        if ($enc === '') {
            throw new RuntimeException('El registro del archivo no tiene valor Encriptado.');
        }

        if (strpos($enc, $ruta) === 0) {
            return $this->normalizeFileKey($enc);
        }

        return $this->normalizeFileKey($ruta . $enc);
    }

    /**
     * ============================================================
     * FUNCTION: getEncryptedBasename
     * ============================================================
     * DESCRIPCIÓN:
     * Obtiene el nombre encriptado real que se guarda en la columna
     * Encriptado. Si la columna ya trae ruta incluida, devuelve solo
     * el basename.
     *
     * PARÁMETROS:
     * $file → fila de FileS3
     *
     * RETORNA:
     * string basename encriptado
     * ============================================================
     */
    private function getEncryptedBasename(array $file): string
    {
        $enc = trim((string)($file['Encriptado'] ?? ''));
        if ($enc === '') {
            throw new RuntimeException('El registro del archivo no tiene valor Encriptado.');
        }

        return basename(str_replace('\\', '/', $enc));
    }

    /**
     * ============================================================
     * FUNCTION: getFileRecord
     * ============================================================
     * DESCRIPCIÓN:
     * Obtiene un registro de FileS3 usando id_ o KEY S3. Si
     * $onlyFound es true, exige Found = 1.
     *
     * PARÁMETROS:
     * $fileRef   → id_ o key S3
     * $onlyFound → filtrar por Found = 1
     *
     * RETORNA:
     * array con la fila encontrada
     * ============================================================
     */
    private function getFileRecord($fileRef, bool $onlyFound = false): array
    {
        if (is_int($fileRef) || ctype_digit((string)$fileRef)) {
            $id = (int)$fileRef;
            $sql = "SELECT *
                    FROM FileS3
                    WHERE id_ = ?" . ($onlyFound ? " AND Found = 1" : "") . "
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Error preparando getFileRecord por id: ' . $this->db->error);
            }

            $stmt->bind_param('i', $id);
            $this->executeStmt($stmt, 'Error ejecutando getFileRecord por id');
            $result = $stmt->get_result();
            $file = $result->fetch_assoc();
            $stmt->close();

            if (!$file) {
                throw new RuntimeException('Archivo no encontrado.');
            }

            return $file;
        }

        $key = $this->normalizeFileKey((string)$fileRef);

        $sql = "SELECT *,
                       CONCAT(Ruta, Encriptado) AS _ruta_enc
                FROM FileS3
                WHERE (CONCAT(Ruta, Encriptado) = ? OR Encriptado = ?)" .
                ($onlyFound ? " AND Found = 1" : "") . "
                ORDER BY id_ DESC
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando getFileRecord por key: ' . $this->db->error);
        }

        $stmt->bind_param('ss', $key, $key);
        $this->executeStmt($stmt, 'Error ejecutando getFileRecord por key');
        $result = $stmt->get_result();
        $file = $result->fetch_assoc();
        $stmt->close();

        if (!$file) {
            throw new RuntimeException('Archivo no encontrado.');
        }

        return $file;
    }

    /**
     * ============================================================
     * FUNCTION: resolveTextContentTypeByName
     * ============================================================
     * DESCRIPCIÓN:
     * Devuelve el Content-Type adecuado para guardar archivos de texto
     * editados desde el navegador.
     *
     * PARÁMETROS:
     * $fileName → nombre del archivo
     *
     * RETORNA:
     * string content-type
     * ============================================================
     */
    private function resolveTextContentTypeByName(string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'json':
                return 'application/json; charset=utf-8';
            case 'html':
            case 'htm':
                return 'text/html; charset=utf-8';
            case 'css':
                return 'text/css; charset=utf-8';
            case 'js':
            case 'mjs':
            case 'cjs':
            case 'jas':
                return 'application/javascript; charset=utf-8';
            case 'md':
                return 'text/markdown; charset=utf-8';
            case 'csv':
                return 'text/csv; charset=utf-8';
            case 'sql':
                return 'text/plain; charset=utf-8';
            case 'xml':
                return 'application/xml; charset=utf-8';
            case 'yml':
            case 'yaml':
                return 'text/yaml; charset=utf-8';
            default:
                return 'text/plain; charset=utf-8';
        }
    }

    private function detectEditorLanguageByName(string $fileName): string
    {
        $base = strtolower(trim(basename(str_replace('\\', '/', $fileName))));
        $ext  = strtolower(pathinfo($base, PATHINFO_EXTENSION));

        $map = [
            'txt' => 'plaintext', 'text' => 'plaintext', 'log' => 'plaintext',
            'md' => 'markdown', 'markdown' => 'markdown',
            'html' => 'html', 'htm' => 'html', 'css' => 'css', 'scss' => 'scss', 'less' => 'less',
            'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript', 'jas' => 'javascript', 'jsx' => 'javascript',
            'ts' => 'typescript', 'tsx' => 'typescript',
            'json' => 'json', 'jsonl' => 'json',
            'xml' => 'xml', 'yaml' => 'yaml', 'yml' => 'yaml', 'toml' => 'ini', 'ini' => 'ini', 'conf' => 'ini', 'cfg' => 'ini',
            'php' => 'php', 'phtml' => 'php', 'inc' => 'php',
            'py' => 'python', 'rb' => 'ruby', 'java' => 'java', 'c' => 'c', 'h' => 'c', 'cpp' => 'cpp', 'hpp' => 'cpp',
            'cs' => 'csharp', 'go' => 'go', 'rs' => 'rust', 'swift' => 'swift', 'kt' => 'kotlin', 'kts' => 'kotlin',
            'sh' => 'shell', 'bash' => 'shell', 'zsh' => 'shell', 'bat' => 'bat', 'cmd' => 'bat', 'ps1' => 'powershell',
            'sql' => 'sql', 'csv' => 'plaintext', 'tsv' => 'plaintext', 'srt' => 'plaintext', 'vtt' => 'plaintext',
            'vue' => 'html'
        ];

        if ($base === 'dockerfile') return 'dockerfile';
        if ($base === 'makefile') return 'makefile';
        if (in_array($base, ['.env', '.gitignore', '.htaccess'], true)) return 'shell';

        return $map[$ext] ?? 'plaintext';
    }

    private function isTextLikeContentType(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $contentType = strtolower(trim($contentType));
        if ($contentType === '') {
            return false;
        }

        return (strpos($contentType, 'text/') === 0)
            || (strpos($contentType, 'application/json') === 0)
            || (strpos($contentType, 'application/xml') === 0)
            || (strpos($contentType, 'application/javascript') === 0)
            || (strpos($contentType, 'application/x-javascript') === 0)
            || (strpos($contentType, 'application/sql') === 0)
            || (strpos($contentType, 'application/x-httpd-php') === 0)
            || (strpos($contentType, 'application/x-sh') === 0)
            || (strpos($contentType, 'application/x-yaml') === 0);
    }

    private function isTextLikeByName(string $fileName): bool
    {
        $base = strtolower(trim(basename(str_replace('\\', '/', $fileName))));
        $ext  = strtolower(pathinfo($base, PATHINFO_EXTENSION));

        if (in_array($base, ['dockerfile', 'makefile', '.env', '.gitignore', '.htaccess', 'readme', 'readme.md', 'readme.txt'], true)) {
            return true;
        }

        return in_array($ext, [
            'txt','text','log','md','markdown','rst','ini','conf','config','cfg','env',
            'csv','tsv','json','jsonl','xml','yaml','yml','toml','properties','sql',
            'html','htm','css','scss','sass','less',
            'js','mjs','cjs','jas','ts','tsx','jsx',
            'php','phtml','inc','phar',
            'py','rb','java','kt','kts','groovy','scala','lua','pl','pm','r','dart','go','rs','swift',
            'c','h','cpp','hpp','cc','hh','cxx','hxx','cs','vb',
            'sh','bash','zsh','fish','bat','cmd','ps1',
            'srt','vtt','ass','ssa',
            'vue','astro','twig','blade','latte','mustache'
        ], true);
    }

    /**
     * ============================================================
     * FUNCTION: uploadFile
     * ============================================================
     * DESCRIPCIÓN:
     * Sube un archivo al bucket S3 y registra inmediatamente el archivo
     * en la tabla FileS3 para mantener consistencia entre S3 y la BD.
     *
     * PARÁMETROS:
     * $tmpPath      → ruta temporal del archivo subido
     * $originalName → nombre original del archivo
     * $ruta         → carpeta destino
     * $userId       → id del usuario
     * $mimeType     → mime type del archivo
     * $fileSize     → tamaño del archivo
     *
     * RETORNA:
     * array con datos del archivo creado
     * ============================================================
     */
public function uploadFile($tmpPath, $originalName, $ruta, $userId, $mimeType, $fileSize)
{
    $originalName = trim((string)$originalName);
    if ($originalName === '') {
        throw new RuntimeException('El nombre original del archivo es obligatorio.');
    }

    $ruta = $this->normalizePrefix((string)$ruta);
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);

    $nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4));
    if ($extension !== '') {
        $nombreEncriptado .= '.' . $extension;
    }

    $key = $this->normalizeFileKey($ruta . $nombreEncriptado);

    $this->s3->putObject([
        'Bucket'      => $this->bucket,
        'Key'         => $key,
        'SourceFile'  => $tmpPath,
        'ContentType' => (string)$mimeType,
        'ACL'         => 'private'
    ]);

    $metadatos = json_encode([
        'ip_origen'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'fecha'       => date('Y-m-d'),
        'hora'        => date('H:i:s'),
        'hash_sha256' => @hash_file('sha256', $tmpPath) ?: null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $found = 1;
    $accessType = 'normal';
    $passwordHash = null;
    $secureHint = null;
    $secureUpdatedAt = null;
    $fechaActual = date('Y-m-d H:i:s');

    $sql = "INSERT INTO FileS3
            (
                Nombre,
                Encriptado,
                Tamano,
                Metadatos,
                Ruta,
                Found,
                AccessType,
                PasswordHash,
                SecureHint,
                SecureUpdatedAt,
                Fecha,
                user_id_
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $this->db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Error preparando uploadFile: ' . $this->db->error);
    }

    $stmt->bind_param(
        'ssississsssi',
        $originalName,
        $key,
        $fileSize,
        $metadatos,
        $ruta,
        $found,
        $accessType,
        $passwordHash,
        $secureHint,
        $secureUpdatedAt,
        $fechaActual,
        $userId
    );

    $this->executeStmt($stmt, 'Error insertando FileS3 en uploadFile');
    $fileId = (int)$stmt->insert_id;
    $stmt->close();

    return [
        'id'                => $fileId,
        'nombre_original'   => $originalName,
        'nombre_encriptado' => $key,
        'ruta'              => $ruta,
        'key_s3'            => $key,
        'tamano'            => (int)$fileSize
    ];
}

    /**
     * ============================================================
     * FUNCTION: renameFile
     * ============================================================
     * DESCRIPCIÓN:
     * Cambia únicamente el nombre visible del archivo en la tabla
     * FileS3, campo Nombre.
     *
     * IMPORTANTE:
     * Esta función NO modifica el archivo real en S3.
     * Esta función NO cambia el campo Encriptado.
     * Esta función NO cambia el campo Ruta.
     * Esta función NO copia ni borra objetos en S3.
     *
     * El campo Encriptado debe conservar el KEY real del archivo
     * en S3, por ejemplo:
     *
     * Data/Chat/GenerationsImages/14/f_68c1dfa1c81f74.38475569_e68671b5.mp4
     *
     * El campo Nombre es solamente el nombre visible para el usuario,
     * por ejemplo:
     *
     * output.mp4
     *
     * PARÁMETROS:
     * $fileRef     → id_ o KEY S3 del archivo
     * $nuevoNombre → nuevo nombre visible
     * $rutaActual  → no se usa; se mantiene por compatibilidad
     *
     * RETORNA:
     * array con datos actualizados, conservando Encriptado y Ruta
     * ============================================================
     */
    public function renameFile($fileRef, $nuevoNombre, $rutaActual = null)
    {
        $nuevoNombre = trim((string)$nuevoNombre);

        if ($nuevoNombre === '') {
            throw new RuntimeException('Debes indicar el nuevo nombre del archivo.');
        }

        /*
         * Seguridad:
         * Nombre es solo el nombre visible del archivo.
         * No debe recibir rutas ni diagonales.
         *
         * Correcto:
         * output.mp4
         *
         * Incorrecto:
         * Data/Carpeta/output.mp4
         */
        if (
            strpos($nuevoNombre, '/') !== false ||
            strpos($nuevoNombre, '\\') !== false
        ) {
            throw new RuntimeException('El nombre del archivo no debe contener rutas.');
        }

        /*
         * Obtenemos el registro actual del archivo.
         * Puede buscar por id_ o por KEY S3, según lo que reciba $fileRef.
         *
         * El segundo parámetro true indica que solo debe buscar archivos
         * marcados como encontrados/activos.
         */
        $file = $this->getFileRecord($fileRef, true);

        $id = (int)$file['id_'];

        /*
         * Conservamos los valores técnicos actuales.
         * Estos valores NO se deben modificar al renombrar visualmente.
         */
        $nombreAnterior    = (string)($file['Nombre'] ?? '');
        $encriptadoActual = (string)($file['Encriptado'] ?? '');
        $rutaActualDb     = (string)($file['Ruta'] ?? '');

        /*
         * KEY real del archivo en S3.
         * Solo lo calculamos para regresarlo como referencia.
         * NO se usa para copiar, borrar ni renombrar en S3.
         */
        $keyS3Actual = $this->buildStoredFileKey($file);

        /*
         * IMPORTANTE:
         * Aquí solo se actualiza el campo Nombre.
         *
         * No tocar:
         * - Encriptado
         * - Ruta
         * - Found
         *
         * Así evitamos perder el KEY real del archivo en S3.
         */
        $sql = "UPDATE FileS3
                SET Nombre = ?
                WHERE id_ = ?";

        $stmt = $this->db->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException('Error preparando renameFile: ' . $this->db->error);
        }

        $stmt->bind_param('si', $nuevoNombre, $id);
        $this->executeStmt($stmt, 'Error actualizando Nombre en FileS3 en renameFile');
        $stmt->close();

        return [
            'id'                    => $id,
            'nombre_anterior'       => $nombreAnterior,
            'nombre'                => $nuevoNombre,
            'encriptado'            => $encriptadoActual,
            'ruta'                  => $rutaActualDb,
            'key_s3'                => $keyS3Actual,
            's3_modificado'         => false,
            'encriptado_modificado' => false
        ];
    }

    /**
     * ============================================================
     * FUNCTION: deleteFile
     * ============================================================
     * DESCRIPCIÓN:
     * Elimina un archivo en S3 y marca Found = 0 en FileS3. Acepta
     * id_ o KEY S3.
     *
     * PARÁMETROS:
     * $fileRef    → id_ o key S3
     * $rutaActual → no se usa; se mantiene por compatibilidad
     *
     * RETORNA:
     * array con resultado de la operación
     * ============================================================
     */
    public function deleteFile($fileRef, $rutaActual = null)
    {
        $file = $this->getFileRecord($fileRef, false);
        $key  = $this->buildStoredFileKey($file);
        $id   = (int)$file['id_'];

        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $key
        ]);

        $sql = "UPDATE FileS3
                SET Found = 0
                WHERE id_ = ?";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando deleteFile: ' . $this->db->error);
        }

        $stmt->bind_param('i', $id);
        $this->executeStmt($stmt, 'Error actualizando FileS3 en deleteFile');
        $stmt->close();

        return [
            'id'     => $id,
            'key_s3' => $key,
            'estado' => 'eliminado'
        ];
    }

/**
 * ============================================================
 * FUNCTION: moveFile
 * ============================================================
 * DESCRIPCIÓN:
 * Mueve un archivo a otra carpeta dentro del bucket S3 y actualiza
 * inmediatamente en la tabla FileS3 tanto la columna Ruta como la
 * columna Encriptado. Acepta como referencia el id_ del archivo o
 * su key S3. El valor de Encriptado se guarda con la nueva key
 * completa para mantener compatibilidad con otras funciones del
 * sistema que leen ese campo directamente.
 *
 * PARÁMETROS:
 * $fileRef   → id_ o key S3 del archivo
 * $nuevaRuta → carpeta destino
 *
 * RETORNA:
 * array con los datos actualizados del movimiento, incluyendo
 * ruta anterior, ruta nueva, key original y nueva key S3
 * ============================================================
 */
    public function moveFile($fileRef, $nuevaRuta)
{
    $file = $this->getFileRecord($fileRef, true);

    $rutaActual       = $this->normalizePrefix((string)$file['Ruta']);
    $nombreEncriptado = $this->getEncryptedBasename($file);
    $oldKey           = $this->buildStoredFileKey($file);

    $nuevaRuta = $this->normalizePrefix((string)$nuevaRuta);
    $newKey    = $this->normalizeFileKey($nuevaRuta . $nombreEncriptado);

    if ($oldKey === $newKey) {
        throw new RuntimeException('El archivo ya está en esa misma ruta.');
    }

    $this->s3->copyObject([
        'Bucket'            => $this->bucket,
        'CopySource'        => $this->bucket . '/' . $oldKey,
        'Key'               => $newKey,
        'ACL'               => 'private',
        'MetadataDirective' => 'COPY'
    ]);

    $this->s3->deleteObject([
        'Bucket' => $this->bucket,
        'Key'    => $oldKey
    ]);

    $sql = "UPDATE FileS3
            SET Ruta = ?, Encriptado = ?, Found = 1
            WHERE id_ = ?";

    $stmt = $this->db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Error preparando moveFile: ' . $this->db->error);
    }

    $id = (int)$file['id_'];

    /**
     * Guardamos Encriptado con la key completa nueva
     * para que otras funciones que leen Encriptado
     * tengan la ruta actualizada.
     */
    $encriptadoNuevo = $newKey;

    $stmt->bind_param('ssi', $nuevaRuta, $encriptadoNuevo, $id);
    $this->executeStmt($stmt, 'Error actualizando FileS3 en moveFile');
    $stmt->close();

    return [
        'id'               => $id,
        'ruta_anterior'    => $rutaActual,
        'ruta_nueva'       => $nuevaRuta,
        'encriptado_nuevo' => $encriptadoNuevo,
        'old_key'          => $oldKey,
        'key_s3'           => $newKey
    ];
}

   
    /**
     * ============================================================
     * FUNCTION: downloadFile
     * ============================================================
     * DESCRIPCIÓN:
     * Genera una URL temporal firmada para descargar un archivo usando
     * el nombre visible guardado en la base de datos, no el nombre
     * encriptado real de S3.
     *
     * PARÁMETROS:
     * $fileRef → id_ o key S3
     *
     * RETORNA:
     * array con URL temporal de descarga
     * ============================================================
     */
    public function downloadFile($fileRef)
    {
        $file = $this->getFileRecord($fileRef, true);
        $key  = $this->buildStoredFileKey($file);
    
        $nombreDescarga = trim((string)$file['Nombre']);
        if ($nombreDescarga === '') {
            $nombreDescarga = basename($key);
        }
    
        $nombreDescarga = str_replace(["\r", "\n", '"'], ['', '', "'"], $nombreDescarga);
    
        $cmd = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $key,
            'ResponseContentDisposition' => 'attachment; filename="' . $nombreDescarga . '"'
        ]);
    
        $request = $this->s3->createPresignedRequest($cmd, '+10 minutes');
        $url = (string)$request->getUri();
    
        return [
            'id'           => (int)$file['id_'],
            'nombre'       => $nombreDescarga,
            'key_s3'       => $key,
            'url_descarga' => $url
        ];
    }

    /**
     * ============================================================
     * FUNCTION: getFileUrl
     * ============================================================
     * DESCRIPCIÓN:
     * Genera una URL temporal para visualizar un archivo en el navegador.
     * Acepta id_ o KEY S3.
     *
     * PARÁMETROS:
     * $fileRef → id_ o key S3
     *
     * RETORNA:
     * array con URL temporal de preview
     * ============================================================
     */
    public function getFileUrl($fileRef)
    {
        $file = $this->getFileRecord($fileRef, true);
        $key  = $this->buildStoredFileKey($file);

        $cmd = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $key
        ]);

        $request = $this->s3->createPresignedRequest($cmd, '+10 minutes');
        $url = (string)$request->getUri();

        return [
            'id'          => (int)$file['id_'],
            'nombre'      => (string)$file['Nombre'],
            'key_s3'      => $key,
            'url_preview' => $url
        ];
    }

    /**
     * ============================================================
     * FUNCTION: getTextFile
     * ============================================================
     * DESCRIPCIÓN:
     * Lee el contenido de un archivo de texto desde S3 para editarlo
     * en el navegador. Acepta id_ o KEY S3.
     *
     * PARÁMETROS:
     * $fileRef → id_ o key S3
     *
     * RETORNA:
     * array con nombre, key y contenido
     * ============================================================
     */
    public function getTextFile($fileRef)
    {
        $file = $this->getFileRecord($fileRef, true);
        $key  = $this->buildStoredFileKey($file);
        $nombre = (string)$file['Nombre'];

        $head = $this->s3->headObject([
            'Bucket' => $this->bucket,
            'Key'    => $key
        ]);

        $contentType = isset($head['ContentType']) ? (string)$head['ContentType'] : null;

        if (!$this->isTextLikeByName($nombre) && !$this->isTextLikeContentType($contentType)) {
            throw new RuntimeException('Este archivo no parece ser de texto editable.');
        }

        $result = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key'    => $key
        ]);

        return [
            'id'          => (int)$file['id_'],
            'nombre'      => $nombre,
            'key_s3'      => $key,
            'contenido'   => (string)$result['Body'],
            'contentType' => $contentType,
            'lenguaje'    => $this->detectEditorLanguageByName($nombre)
        ];
    }

    /**
     * ============================================================
     * FUNCTION: updateTextFile
     * ============================================================
     * DESCRIPCIÓN:
     * Guarda cambios en un archivo de texto directamente en S3 y
     * mantiene el registro FileS3 como existente. Acepta id_ o KEY S3.
     *
     * PARÁMETROS:
     * $fileRef   → id_ o key S3
     * $contenido → contenido actualizado
     *
     * RETORNA:
     * array con resultado de la operación
     * ============================================================
     */
    public function updateTextFile($fileRef, $contenido)
    {
        $file = $this->getFileRecord($fileRef, true);
        $key  = $this->buildStoredFileKey($file);
        $nombre = (string)$file['Nombre'];

        $head = $this->s3->headObject([
            'Bucket' => $this->bucket,
            'Key'    => $key
        ]);

        $contentTypeActual = isset($head['ContentType']) ? (string)$head['ContentType'] : null;

        if (!$this->isTextLikeByName($nombre) && !$this->isTextLikeContentType($contentTypeActual)) {
            throw new RuntimeException('Este archivo no parece ser de texto editable.');
        }

        $contentTypeGuardar = $this->resolveTextContentTypeByName($nombre);
        if ($contentTypeGuardar === 'text/plain; charset=utf-8' && $this->isTextLikeContentType($contentTypeActual)) {
            $contentTypeGuardar = $contentTypeActual;
        }

        $this->s3->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'Body'        => (string)$contenido,
            'ACL'         => 'private',
            'ContentType' => $contentTypeGuardar
        ]);

        $sql = "UPDATE FileS3
                SET Found = 1
                WHERE id_ = ?";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando updateTextFile: ' . $this->db->error);
        }

        $id = (int)$file['id_'];
        $stmt->bind_param('i', $id);
        $this->executeStmt($stmt, 'Error actualizando FileS3 en updateTextFile');
        $stmt->close();

        return [
            'id'     => $id,
            'key_s3' => $key,
            'estado' => 'actualizado'
        ];
    }

    /**
     * ============================================================
     * FUNCTION: deleteMultiple
     * ============================================================
     * DESCRIPCIÓN:
     * Elimina múltiples archivos usando un arreglo de KEYs S3 y marca
     * Found = 0 en FileS3 para cada archivo.
     *
     * PARÁMETROS:
     * $keys → arreglo de keys S3
     *
     * RETORNA:
     * array con cantidad procesada
     * ============================================================
     */
    public function deleteMultiple($keys)
    {
        if (!is_array($keys) || empty($keys)) {
            throw new RuntimeException('No hay archivos seleccionados.');
        }

        $total = 0;

        foreach ($keys as $key) {
            $file = $this->getFileRecord($key, false);
            $this->deleteFile((int)$file['id_']);
            $total++;
        }

        return [
            'total'  => $total,
            'estado' => 'eliminados'
        ];
    }

/**
 * ============================================================
 * FUNCTION: moveMultiple
 * ============================================================
 * DESCRIPCIÓN:
 * Mueve múltiples archivos a una nueva carpeta dentro del bucket S3,
 * usando las keys S3 recibidas desde la interfaz. Por cada archivo,
 * realiza el movimiento físico en S3 y actualiza en la tabla FileS3
 * tanto la columna Ruta como la columna Encriptado, dejando ambas
 * con la nueva ubicación del archivo.
 *
 * PARÁMETROS:
 * $keys      → arreglo de keys S3 de los archivos a mover
 * $nuevaRuta → carpeta destino
 *
 * RETORNA:
 * array con la cantidad procesada, la ruta destino y el estado
 * del movimiento
 * ============================================================
 */
public function moveMultiple($keys, $nuevaRuta)
{
    if (!is_array($keys) || empty($keys)) {
        throw new RuntimeException('No hay archivos seleccionados.');
    }

    $nuevaRuta = $this->normalizePrefix((string)$nuevaRuta);
    $total = 0;

    foreach ($keys as $key) {
        $file = $this->getFileRecord($key, true);
        $this->moveFile((int)$file['id_'], $nuevaRuta);
        $total++;
    }

    return [
        'total'      => $total,
        'ruta_nueva' => $nuevaRuta,
        'estado'     => 'movidos'
    ];
}

    /**
     * ============================================================
     * FUNCTION: downloadMultiple
     * ============================================================
     * DESCRIPCIÓN:
     * Descarga múltiples archivos en un ZIP temporal usando las KEYs S3
     * seleccionadas en la interfaz.
     *
     * PARÁMETROS:
     * $keys → arreglo de keys S3
     *
     * RETORNA:
     * string ruta temporal del ZIP generado
     * ============================================================
     */
    public function downloadMultiple($keys)
    {
        if (!is_array($keys) || empty($keys)) {
            throw new RuntimeException('No hay archivos seleccionados.');
        }

        $zipFile = sys_get_temp_dir() . '/archivos_' . time() . '_' . bin2hex(random_bytes(4)) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP.');
        }

        foreach ($keys as $key) {
            $file = $this->getFileRecord($key, true);
            $realKey = $this->buildStoredFileKey($file);

            $result = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $realKey
            ]);

            $contenido = (string)$result['Body'];
            $nombreZip = (string)$file['Nombre'];

            if ($nombreZip === '') {
                $nombreZip = basename($realKey);
            }

            $zip->addFromString($nombreZip, $contenido);
        }

        $zip->close();

        return $zipFile;
    }


    public function getRutaPadre($ruta)
    {
        $ruta = rtrim((string)$ruta, '/');
        $partes = explode('/', $ruta);
        array_pop($partes);
        return implode('/', $partes) . '/';
    }
}