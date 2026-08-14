<?php
/**
 * SessionRetriever - Clase para recuperación de memoria de sesión a largo plazo
 * 
 * Esta clase proporciona funcionalidad de recuperación de contexto histórico
 * de sesiones de chat para enriquecer el prompt del compilador.
 */

class SessionRetriever {
    
    /**
     * @var mysqli Conexión a la base de datos
     */
    private $db;
    
    /**
     * @var mixed|null Parámetro adicional (puede ser null)
     */
    private $options;
    
    /**
     * Constructor
     * 
     * @param mysqli $db_connection Conexión a la base de datos
     * @param mixed|null $options Opciones adicionales (puede ser null)
     */
    public function __construct(mysqli $db_connection, $options = null) {
        $this->db = $db_connection;
        $this->options = $options;
    }
    
    /**
     * Recupera información relevante de la sesión para el contexto
     * 
     * @param int $session_id ID de la sesión actual
     * @param string $text Texto de consulta del usuario
     * @param int|null $projectId ID del proyecto asociado (puede ser null)
     * @return array Información recuperada de la sesión
     */
    public function retrieve(int $session_id, string $text, ?int $projectId): array {
        $result = [
            'session_info' => null,
            'recent_context' => [],
            'project_context' => null,
            'summary' => null
        ];
        
        // 1. Obtener información básica de la sesión
        $stmt = $this->db->prepare("SELECT id_, project_id_, meta, created_at FROM ChatSessions WHERE id_ = ?");
        if ($stmt) {
            $stmt->bind_param("i", $session_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $result['session_info'] = $row;
                
                // Parsear meta si existe
                if (!empty($row['meta'])) {
                    $meta = json_decode($row['meta'], true);
                    if (is_array($meta)) {
                        $result['session_meta'] = $meta;
                        
                        // Extraer summary si existe
                        if (isset($meta['summary'])) {
                            $result['summary'] = $meta['summary'];
                        }
                    }
                }
            }
            $stmt->close();
        }
        
        // 2. Obtener mensajes recientes de la sesión (últimos 5-10 mensajes)
        $stmt = $this->db->prepare("
            SELECT role, content, created_at 
            FROM ChatMessages 
            WHERE session_id_ = ? AND role IN ('user', 'assistant')
            ORDER BY id_ DESC 
            LIMIT 10
        ");
        if ($stmt) {
            $stmt->bind_param("i", $session_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $messages = [];
            while ($row = $res->fetch_assoc()) {
                $messages[] = $row;
            }
            // Invertir para tener orden cronológico
            $result['recent_context'] = array_reverse($messages);
            $stmt->close();
        }
        
        // 3. Si hay projectId, obtener contexto del proyecto
        if ($projectId !== null && $projectId > 0) {
            $stmt = $this->db->prepare("SELECT meta, root_prefix FROM Projects WHERE id_ = ?");
            if ($stmt) {
                $stmt->bind_param("i", $projectId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $result['project_context'] = $row;
                    
                    if (!empty($row['meta'])) {
                        $projectMeta = json_decode($row['meta'], true);
                        if (is_array($projectMeta)) {
                            $result['project_meta'] = $projectMeta;
                        }
                    }
                }
                $stmt->close();
            }
        }
        
        return $result;
    }
    
    /**
     * Formatea el contexto recuperado para ser incluido en el prompt del compilador
     * 
     * @param array $sessionRetrieval Datos recuperados del método retrieve()
     * @return string Contexto formateado para el compilador
     */
    public function formatContextForCompiler(array $sessionRetrieval): string {
        $contextParts = [];
        
        // 1. Agregar summary de la sesión si existe
        if (!empty($sessionRetrieval['summary'])) {
            $contextParts[] = "RESUMEN DE SESIÓN: " . $sessionRetrieval['summary'];
        }
        
        // 2. Agregar contexto reciente si existe
        if (!empty($sessionRetrieval['recent_context']) && count($sessionRetrieval['recent_context']) > 0) {
            $recentText = "";
            foreach ($sessionRetrieval['recent_context'] as $msg) {
                $roleLabel = ($msg['role'] === 'user') ? 'USUARIO' : 'ASISTENTE';
                $contentPreview = mb_substr($msg['content'], 0, 150);
                if (mb_strlen($msg['content']) > 150) {
                    $contentPreview .= "...";
                }
                $recentText .= "[$roleLabel]: $contentPreview\n";
            }
            
            if (!empty($recentText)) {
                $contextParts[] = "CONTEXTO RECIENTE:\n" . trim($recentText);
            }
        }
        
        // 3. Agregar información del proyecto si existe
        if (!empty($sessionRetrieval['project_context'])) {
            $projCtx = $sessionRetrieval['project_context'];
            $projectInfo = "PROYECTO ACTIVO";
            
            if (!empty($projCtx['root_prefix'])) {
                $projectInfo .= " (Ruta: {$projCtx['root_prefix']})";
            }
            
            if (!empty($sessionRetrieval['project_meta'])) {
                $pm = $sessionRetrieval['project_meta'];
                if (isset($pm['description'])) {
                    $projectInfo .= "\nDescripción: " . $pm['description'];
                }
                if (isset($pm['instructions'])) {
                    $projectInfo .= "\nInstrucciones: " . $pm['instructions'];
                }
            }
            
            $contextParts[] = $projectInfo;
        }
        
        // Unir todas las partes
        if (empty($contextParts)) {
            return "";
        }
        
        return implode("\n\n---\n\n", $contextParts);
    }
}
