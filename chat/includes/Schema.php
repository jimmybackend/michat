<?php
/**
 * Schema.php
 *
 * Constantes espejo de los ENUM de la base de datos.
 *
 * POR QUÉ EXISTE ESTE ARCHIVO
 * ---------------------------
 * El Scout se etiquetaba como 'lint_fix' con literales sueltos repartidos por
 * code_edit.php y nadie lo notó: los datos de TokenUsage quedaron mintiendo
 * durante semanas. Un literal mal escrito en un INSERT no rompe nada visible —
 * MySQL lo acepta si casualmente existe en el ENUM, o lo trunca en silencio si
 * no—, así que el error solo aparece cuando alguien mira las métricas y no
 * cuadran.
 *
 * REGLA: ningún INSERT/UPDATE escribe un literal en una columna ENUM. Siempre
 * una constante de esta clase.
 *
 * La fuente de verdad sigue siendo /schema.sql. Este archivo es su espejo, y
 * tests/schema_constants_test.php compara los dos: si divergen, la suite falla
 * en vez de fallar la producción.
 */

final class Schema
{
    private function __construct() {}

    // =================================================================
    // TokenUsage.phase / ChatMessages.phase  (11 valores, idénticos)
    // =================================================================
    const PHASE_COMPILE   = 'compile';
    const PHASE_RESPOND   = 'respond';
    const PHASE_LINT_FIX  = 'lint_fix';
    const PHASE_EMBEDDING = 'embedding';
    const PHASE_CLASSIFY  = 'classify';
    const PHASE_SCOUT     = 'scout';
    const PHASE_PLAN      = 'plan';
    const PHASE_RAG       = 'rag';
    const PHASE_EDIT      = 'edit';
    const PHASE_SUMMARIZE = 'summarize';
    const PHASE_REVIEW    = 'review';

    const PHASES = [
        self::PHASE_COMPILE,
        self::PHASE_RESPOND,
        self::PHASE_LINT_FIX,
        self::PHASE_EMBEDDING,
        self::PHASE_CLASSIFY,
        self::PHASE_SCOUT,
        self::PHASE_PLAN,
        self::PHASE_RAG,
        self::PHASE_EDIT,
        self::PHASE_SUMMARIZE,
        self::PHASE_REVIEW,
    ];

    // =================================================================
    // ToolCalls.tool  (15 valores — el ENUM NO se amplía)
    // =================================================================
    const TOOL_GREP            = 'grep';
    const TOOL_VIEW            = 'view';
    const TOOL_SEARCH          = 'search';
    const TOOL_STR_REPLACE     = 'str_replace';
    const TOOL_LIST_DIR        = 'list_dir';
    const TOOL_READ_CHUNK      = 'read_chunk';
    const TOOL_RUN_SHELL       = 'run_shell';
    const TOOL_CREATE_FILE     = 'create_file';
    const TOOL_WRITE_FILE      = 'write_file';
    const TOOL_DELETE_FILE     = 'delete_file';
    const TOOL_MOVE_FILE       = 'move_file';
    const TOOL_LINT            = 'lint';
    const TOOL_RUN_TESTS       = 'run_tests';
    const TOOL_PREVIEW_DIFF    = 'preview_diff';
    const TOOL_RESTORE_VERSION = 'restore_version';

    const TOOLS = [
        self::TOOL_GREP,
        self::TOOL_VIEW,
        self::TOOL_SEARCH,
        self::TOOL_STR_REPLACE,
        self::TOOL_LIST_DIR,
        self::TOOL_READ_CHUNK,
        self::TOOL_RUN_SHELL,
        self::TOOL_CREATE_FILE,
        self::TOOL_WRITE_FILE,
        self::TOOL_DELETE_FILE,
        self::TOOL_MOVE_FILE,
        self::TOOL_LINT,
        self::TOOL_RUN_TESTS,
        self::TOOL_PREVIEW_DIFF,
        self::TOOL_RESTORE_VERSION,
    ];

    /**
     * Mapeo obligatorio de operación interna -> valor de ToolCalls.tool.
     *
     * El ENUM no se amplía para acomodar nombres internos nuevos: la operación
     * se traduce a una de las 15 herramientas existentes.
     */
    const OPERATION_TO_TOOL = [
        'apply_edit'      => self::TOOL_STR_REPLACE,     // ancla old_string/new_string
        'str_replace'     => self::TOOL_STR_REPLACE,
        'full_rewrite'    => self::TOOL_WRITE_FILE,      // reescritura completa
        'write_file'      => self::TOOL_WRITE_FILE,
        'create_file'     => self::TOOL_CREATE_FILE,     // creación de archivo nuevo
        'read'            => self::TOOL_VIEW,            // action=read
        'view'            => self::TOOL_VIEW,
        'delete'          => self::TOOL_DELETE_FILE,     // borrado
        'delete_file'     => self::TOOL_DELETE_FILE,
        'move_file'       => self::TOOL_MOVE_FILE,
        'lint'            => self::TOOL_LINT,
        'dry_run'         => self::TOOL_PREVIEW_DIFF,    // dry-run
        'preview_diff'    => self::TOOL_PREVIEW_DIFF,
        'rollback'        => self::TOOL_RESTORE_VERSION, // rollback
        'restore_version' => self::TOOL_RESTORE_VERSION,
        'run_tests'       => self::TOOL_RUN_TESTS,
        'run_shell'       => self::TOOL_RUN_SHELL,
        'grep'            => self::TOOL_GREP,
        'search'          => self::TOOL_SEARCH,
        'list_dir'        => self::TOOL_LIST_DIR,
        'read_chunk'      => self::TOOL_READ_CHUNK,
    ];

    /*
     * =================================================================
     * DETECCIÓN DE BUCLES — corrección a la Tarea B.4 del prompt original
     * =================================================================
     *
     * La consulta que traía B.4 no habría disparado nunca:
     *
     *     AND params_hash = SHA2(CAST(? AS CHAR CHARSET utf8mb4), 256)
     *
     * `params_hash` se genera sobre `cast(params as char charset utf8mb4)`,
     * es decir sobre el JSON YA normalizado por MySQL al guardarlo
     * (separadores y orden de claves canónicos). El lado de la consulta, en
     * cambio, hashea el JSON crudo que serializó PHP. Los dos hashes no
     * coinciden salvo por casualidad, así que la detección parece funcionar
     * y no detecta nada — que es peor que no tenerla.
     *
     * En vez de normalizar el lado de la consulta, la Fase 4 registra
     * primero y compara después contra la propia columna generada de la fila
     * recién insertada. Así los dos lados salen de MySQL y no dependen de que
     * PHP mande un JSON parseable ni de cómo lo serialice:
     *
     *   1) INSERT de la fila en ToolCalls; guardar $db->insert_id.
     *   2) Detección:
     *
     *      SELECT COUNT(*) FROM ToolCalls t
     *      JOIN ToolCalls self ON self.id_ = ?
     *      WHERE t.session_id_ = self.session_id_
     *        AND t.tool = self.tool
     *        AND t.params_hash = self.params_hash
     *        AND t.created_at > NOW() - INTERVAL 5 MINUTE;
     *
     *      > 3  ->  warning 'bucle_detectado' en la respuesta
     *
     * El índice idx_tc_loop_detect (session_id_, tool, params_hash,
     * created_at) cubre exactamente ese WHERE.
     *
     * Nota: la fila propia entra en el conteo, así que el umbral > 3 cuenta
     * la llamada actual como la cuarta repetición.
     */

    // =================================================================
    // ToolCalls.status
    // =================================================================
    const TOOL_STATUS_OK      = 'ok';
    const TOOL_STATUS_ERROR   = 'error';
    const TOOL_STATUS_TIMEOUT = 'timeout';

    const TOOL_STATUSES = [
        self::TOOL_STATUS_OK,
        self::TOOL_STATUS_ERROR,
        self::TOOL_STATUS_TIMEOUT,
    ];

    // =================================================================
    // FileVersions.status
    //
    // Ciclo de vida de la ESCRITURA, lo pone el sistema. No confundir con
    // FileVersions.is_stable, que es "el humano marcó esta versión como la
    // buena". Son conceptos distintos y viven en columnas distintas.
    // =================================================================
    const FV_DRAFT       = 'draft';
    const FV_COMMITTED   = 'committed';
    const FV_FAILED      = 'failed';
    const FV_ROLLED_BACK = 'rolled_back';

    const FILE_VERSION_STATUSES = [
        self::FV_DRAFT,
        self::FV_COMMITTED,
        self::FV_FAILED,
        self::FV_ROLLED_BACK,
    ];

    // =================================================================
    // ProjectSources.status
    // =================================================================
    const SOURCE_PENDING = 'pending';
    const SOURCE_INDEXED = 'indexed';
    const SOURCE_STALE   = 'stale';
    const SOURCE_ERROR   = 'error';

    const SOURCE_STATUSES = [
        self::SOURCE_PENDING,
        self::SOURCE_INDEXED,
        self::SOURCE_STALE,
        self::SOURCE_ERROR,
    ];

    // =================================================================
    // Contrato que verifica tests/schema_constants_test.php
    //
    // Cada entrada dice: "esta constante debe contener exactamente los
    // valores del ENUM de esta tabla.columna en /schema.sql".
    // Al añadir un ENUM nuevo al espejo, añádelo también aquí.
    // =================================================================
    const ENUM_CONTRACT = [
        ['TokenUsage',     'phase',  'PHASES'],
        ['ChatMessages',   'phase',  'PHASES'],
        ['ToolCalls',      'tool',   'TOOLS'],
        ['ToolCalls',      'status', 'TOOL_STATUSES'],
        ['FileVersions',   'status', 'FILE_VERSION_STATUSES'],
        ['ProjectSources', 'status', 'SOURCE_STATUSES'],
    ];

    // =================================================================
    // Validadores
    //
    // Se usan en la frontera: cuando el valor viene de un cálculo, de un
    // mapa o del cliente. Fallan ruidosamente en vez de dejar que MySQL
    // trunque el valor en silencio.
    // =================================================================

    /** Valida un valor de TokenUsage.phase / ChatMessages.phase. */
    public static function phase(string $phase): string
    {
        if (!in_array($phase, self::PHASES, true)) {
            throw new InvalidArgumentException("Fase desconocida: '{$phase}'. No está en el ENUM de schema.sql.");
        }
        return $phase;
    }

    /** Valida un valor de ToolCalls.tool. */
    public static function tool(string $tool): string
    {
        if (!in_array($tool, self::TOOLS, true)) {
            throw new InvalidArgumentException("Herramienta desconocida: '{$tool}'. El ENUM de ToolCalls.tool no se amplía.");
        }
        return $tool;
    }

    /**
     * Traduce una operación interna al valor de ToolCalls.tool que le
     * corresponde según el mapeo obligatorio.
     */
    public static function toolForOperation(string $operation): string
    {
        if (!isset(self::OPERATION_TO_TOOL[$operation])) {
            throw new InvalidArgumentException("Operación sin mapeo a ToolCalls.tool: '{$operation}'.");
        }
        return self::OPERATION_TO_TOOL[$operation];
    }

    /** Valida un valor de ToolCalls.status. */
    public static function toolStatus(string $status): string
    {
        if (!in_array($status, self::TOOL_STATUSES, true)) {
            throw new InvalidArgumentException("Estado de herramienta desconocido: '{$status}'.");
        }
        return $status;
    }

    /** Valida un valor de FileVersions.status. */
    public static function fileVersionStatus(string $status): string
    {
        if (!in_array($status, self::FILE_VERSION_STATUSES, true)) {
            throw new InvalidArgumentException("Estado de FileVersions desconocido: '{$status}'.");
        }
        return $status;
    }

    /** Valida un valor de ProjectSources.status. */
    public static function sourceStatus(string $status): string
    {
        if (!in_array($status, self::SOURCE_STATUSES, true)) {
            throw new InvalidArgumentException("Estado de ProjectSources desconocido: '{$status}'.");
        }
        return $status;
    }
}
