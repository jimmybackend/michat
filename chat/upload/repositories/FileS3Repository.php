<?php
// upload/repositories/FileS3Repository.php
declare(strict_types=1);

final class FileS3Repository
{
    /** @var mysqli */
    private $db;

    public function __construct($db)
    {
        if (!($db instanceof mysqli)) {
            throw new RuntimeException('FileS3Repository: db no es mysqli');
        }
        $this->db = $db;
    }

    public function insertFile(array $row)
    {
        $sql = "
          INSERT INTO FileS3
            (Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, PasswordHash, SecureHint, SecureUpdatedAt, Fecha, user_id_)
          VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando SQL FileS3: ' . $this->db->error);
        }

        $nombre     = (string)($row['Nombre'] ?? '');
        $encriptado = (string)($row['Encriptado'] ?? '');
        $tamano     = (int)($row['Tamano'] ?? 0);
        $metadatos  = array_key_exists('Metadatos', $row) ? $row['Metadatos'] : null;
        $ruta       = (string)($row['Ruta'] ?? '');
        $found      = (int)($row['Found'] ?? 1);
        $accessType = (string)($row['AccessType'] ?? 'normal');
        $passwordHash = array_key_exists('PasswordHash', $row) ? $row['PasswordHash'] : null;
        $secureHint   = array_key_exists('SecureHint', $row) ? $row['SecureHint'] : null;
        $secureUpdAt  = array_key_exists('SecureUpdatedAt', $row) ? $row['SecureUpdatedAt'] : null;
        $fecha      = (string)($row['Fecha'] ?? date('Y-m-d H:i:s'));
        $userId     = (int)($row['user_id_'] ?? 0);

        $stmt->bind_param(
            'ssississsssi',
            $nombre,
            $encriptado,
            $tamano,
            $metadatos,
            $ruta,
            $found,
            $accessType,
            $passwordHash,
            $secureHint,
            $secureUpdAt,
            $fecha,
            $userId
        );

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Error insert FileS3: ' . $err);
        }

        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function updateSizeByEncriptado($encriptado, $tamano, $userId)
    {
        $sql = "UPDATE FileS3 SET Tamano=? WHERE Encriptado=? AND user_id_=?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('Error preparando UPDATE FileS3: ' . $this->db->error);

        $tamano = (int)$tamano;
        $encriptado = (string)$encriptado;
        $userId = (int)$userId;

        $stmt->bind_param('isi', $tamano, $encriptado, $userId);
        $stmt->execute();
        $stmt->close();
    }
}