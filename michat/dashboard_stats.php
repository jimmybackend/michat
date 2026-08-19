<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once '../vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

// =====================================================================
// ✅ FUNCIONES DE PRECIOS DEFINITIVAS (Blindadas)
// Se incluyen aquí para recalcular y garantizar precisión en el dashboard,
// corrigiendo cualquier dato histórico que haya sido guardado con precios erróneos.
// =====================================================================
function getModelPricing(string $modelId): array {
    $m = strtolower($modelId);

    if (strpos($m, 'titan-embed') !== false) {
        // Titan Embed V2: ~$0.0001 por 1k tokens = $0.10 por 1M de tokens
        return ['input' => 0.10, 'output' => 0.00];
    }
    if (strpos($m, 'nova-micro') !== false) {
        return ['input' => 0.035, 'output' => 0.14];
    }
    if (strpos($m, 'nova-lite') !== false) {
        return ['input' => 0.06, 'output' => 0.24];
    }
    if (strpos($m, 'nova-pro') !== false) {
        return ['input' => 0.80, 'output' => 3.20];
    }
    
    // Fallback de seguridad: Si hay un modelo desconocido o legacy, 
    // usamos el precio más bajo (Nova Micro) para no inflar el reporte.
    return ['input' => 0.035, 'output' => 0.14];
}

function calculateCost(string $modelId, int $inputTokens, int $outputTokens): float {
    $pricing = getModelPricing($modelId);
    $cost = ($inputTokens / 1000000 * $pricing['input']) + ($outputTokens / 1000000 * $pricing['output']);
    return round($cost, 6);
}
// =====================================================================

try {
    $conn = isset($pdo) ? $pdo : (isset($db_connection) ? $db_connection : null);
    if (!$conn) throw new Exception("No se encontró \$pdo ni \$db_connection en app_bootstrap.php");
    $isPDO = ($conn instanceof PDO);

    // 1. Determinar el mes a consultar (por defecto, mes actual YYYY-MM)
    $targetMonth = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $targetMonth)) {
        $targetMonth = date('Y-m');
    }
    $firstDayOfMonth = $targetMonth . '-01';
    $firstDayOfNextMonth = date('Y-m-d', strtotime($firstDayOfMonth . ' +1 month'));

    // 2. TokenUsage filtrado por mes (Agrupado por fase)
    $sqlTokens = "SELECT
        phase,
        COALESCE(SUM(input_tokens), 0) as total_input,
        COALESCE(SUM(output_tokens), 0) as total_output
        FROM TokenUsage
        WHERE created_at >= ? AND created_at < ?
        GROUP BY phase";
        
    if ($isPDO) {
        $stmt = $conn->prepare($sqlTokens);
        $stmt->execute([$firstDayOfMonth, $firstDayOfNextMonth]);
        $rowsTokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->prepare($sqlTokens);
        $stmt->bind_param('ss', $firstDayOfMonth, $firstDayOfNextMonth);
        $stmt->execute();
        $rowsTokens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    $tokensByPhase = ['compile' => 0, 'respond' => 0, 'lint_fix' => 0, 'embedding' => 0];
    $totalTokensAll = 0;
    
    foreach ($rowsTokens as $row) {
        $phase = $row['phase'] ?? 'respond';
        $input = (int)$row['total_input'];
        $output = (int)$row['total_output'];
        if (isset($tokensByPhase[$phase])) {
            $tokensByPhase[$phase] += ($input + $output);
        }
        $totalTokensAll += ($input + $output);
    }

    // 3. Estadísticas por MODELO (TokenUsage) filtrado por mes
    $sqlModels = "SELECT
        model_id, phase, COUNT(*) as usage_count,
        COALESCE(SUM(input_tokens), 0) as total_input,
        COALESCE(SUM(output_tokens), 0) as total_output
        FROM TokenUsage
        WHERE model_id IS NOT NULL AND model_id != ''
        AND created_at >= ? AND created_at < ?
        GROUP BY model_id, phase
        ORDER BY total_input DESC"; // Ordenamos por volumen de tokens para mejor análisis
        
    if ($isPDO) {
        $stmtM = $conn->prepare($sqlModels);
        $stmtM->execute([$firstDayOfMonth, $firstDayOfNextMonth]);
        $rowsModels = $stmtM->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtM = $conn->prepare($sqlModels);
        $stmtM->bind_param('ss', $firstDayOfMonth, $firstDayOfNextMonth);
        $stmtM->execute();
        $rowsModels = $stmtM->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    $modelsAggregated = [];
    $recalculatedTotalCost = 0.0; // ✅ Nuevo: Suma de costos recalculados con precisión

    foreach ($rowsModels as $row) {
        $modelId = $row['model_id'];
        $input = (int)$row['total_input'];
        $output = (int)$row['total_output'];
        
        // ✅ RECÁLCULO EN TIEMPO REAL: Garantiza el precio correcto sin importar cómo se guardó en BD
        $calculatedCost = calculateCost($modelId, $input, $output);

        if (!isset($modelsAggregated[$modelId])) {
            $modelsAggregated[$modelId] = [
                'model_id' => $modelId, 
                'usage_count' => 0, 
                'total_input' => 0,
                'total_output' => 0, 
                'total_cost' => 0.0, 
                'phases' => []
            ];
        }
        
        $modelsAggregated[$modelId]['usage_count'] += (int)$row['usage_count'];
        $modelsAggregated[$modelId]['total_input'] += $input;
        $modelsAggregated[$modelId]['total_output'] += $output;
        $modelsAggregated[$modelId]['total_cost'] += $calculatedCost; // Usamos el costo recalculado
        $recalculatedTotalCost += $calculatedCost;

        $modelsAggregated[$modelId]['phases'][$row['phase']] = [
            'count' => (int)$row['usage_count'], 
            'input' => $input,
            'output' => $output, 
            'cost' => round($calculatedCost, 6)
        ];
    }
    
    $modelsStats = array_values($modelsAggregated);

    // 4. LintAttempts filtrado por mes
    $sqlLadder = "SELECT
        model_used, COUNT(*) as total_attempts,
        SUM(CASE WHEN is_success = 1 THEN 1 ELSE 0 END) as success_count
        FROM LintAttempts
        WHERE created_at >= ? AND created_at < ?
        GROUP BY model_used";
        
    if ($isPDO) {
        $stmtL = $conn->prepare($sqlLadder);
        $stmtL->execute([$firstDayOfMonth, $firstDayOfNextMonth]);
        $ladderStats = $stmtL->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtL = $conn->prepare($sqlLadder);
        $stmtL->bind_param('ss', $firstDayOfMonth, $firstDayOfNextMonth);
        $stmtL->execute();
        $ladderStats = $stmtL->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // 5. Compresión de sesiones (Filtramos por updated_at en el mes para coherencia)
    $sqlCompression = "SELECT
        cs.id_, ANY_VALUE(cs.title) as title, ANY_VALUE(cs.context_level) as context_level,
        ANY_VALUE(cs.last_compressed_at) as last_compressed_at, COUNT(scb.id_) as block_count
        FROM ChatSessions cs
        LEFT JOIN SessionContextBlocks scb ON cs.id_ = scb.session_id_
        WHERE cs.status != 'archived'
        AND cs.updated_at >= ? AND cs.updated_at < ?
        GROUP BY cs.id_
        ORDER BY MAX(cs.updated_at) DESC
        LIMIT 50";
        
    if ($isPDO) {
        $stmtC = $conn->prepare($sqlCompression);
        $stmtC->execute([$firstDayOfMonth, $firstDayOfNextMonth]);
        $sessionsCompression = $stmtC->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtC = $conn->prepare($sqlCompression);
        $stmtC->bind_param('ss', $firstDayOfMonth, $firstDayOfNextMonth);
        $stmtC->execute();
        $sessionsCompression = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // Respuesta JSON con el costo total recalculado para máxima precisión
    echo json_encode([
        'ok' => true,
        'month' => $targetMonth,
        'tokens' => [
            'total' => $totalTokensAll,
            'cost' => round($recalculatedTotalCost, 4), // ✅ Usa el costo recalculado, no el de la BD
            'by_phase' => $tokensByPhase
        ],
        'models' => $modelsStats,
        'ladder' => $ladderStats,
        'sessions' => $sessionsCompression
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error en el servidor: ' . $e->getMessage()]);
}