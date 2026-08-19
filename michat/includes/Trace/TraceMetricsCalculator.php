<?php

declare(strict_types=1);

/**
 * Fase 7.7 · Cálculo común de métricas/costos.
 *
 * Los precios conocidos replican deliberadamente la tabla usada actualmente
 * por dashboard_stats.php para que ambas vistas hablen el mismo idioma.
 * Todo costo es una ESTIMACIÓN; TokenUsage.estimated_cost_usd se conserva
 * además como valor histórico almacenado.
 */
final class TraceMetricsCalculator
{
    /** @return array{input:float,output:float,label:string,fallback:bool} */
    public static function pricing(string $modelId): array
    {
        $m = strtolower(trim($modelId));

        if (str_contains($m, 'titan-embed')) {
            return ['input' => 0.10, 'output' => 0.00, 'label' => 'Titan Embed', 'fallback' => false];
        }
        if (str_contains($m, 'nova-micro')) {
            return ['input' => 0.035, 'output' => 0.14, 'label' => 'Nova Micro', 'fallback' => false];
        }
        if (str_contains($m, 'nova-lite')) {
            return ['input' => 0.06, 'output' => 0.24, 'label' => 'Nova Lite', 'fallback' => false];
        }
        if (str_contains($m, 'nova-pro')) {
            return ['input' => 0.80, 'output' => 3.20, 'label' => 'Nova Pro', 'fallback' => false];
        }

        // Mantener el mismo fallback conservador del dashboard existente.
        return ['input' => 0.035, 'output' => 0.14, 'label' => 'Fallback Nova Micro', 'fallback' => true];
    }

    /** @return array{cost:float,pricing:array{input:float,output:float,label:string,fallback:bool}} */
    public static function calculate(string $modelId, int $inputTokens, int $outputTokens): array
    {
        $pricing = self::pricing($modelId);
        $cost = (($inputTokens / 1000000) * $pricing['input'])
            + (($outputTokens / 1000000) * $pricing['output']);

        return [
            'cost' => round($cost, 6),
            'pricing' => $pricing,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows Filas TokenUsage o agregados compatibles.
     * @return array<string,mixed>
     */
    public static function aggregateTokenRows(array $rows): array
    {
        $input = 0;
        $output = 0;
        $storedCost = 0.0;
        $recalculatedCost = 0.0;
        $duration = 0;
        $calls = 0;
        $fallbackModels = [];
        $byPhase = [];
        $byModel = [];

        foreach ($rows as $row) {
            $model = trim((string)($row['model_id'] ?? ''));
            $phase = trim((string)($row['phase'] ?? 'unknown')) ?: 'unknown';
            $rowInput = (int)($row['input_tokens'] ?? $row['total_input'] ?? 0);
            $rowOutput = (int)($row['output_tokens'] ?? $row['total_output'] ?? 0);
            $rowStored = (float)($row['estimated_cost_usd'] ?? $row['stored_cost_usd'] ?? $row['total_stored_cost'] ?? 0);
            $rowDuration = (int)($row['duration_ms'] ?? $row['total_duration_ms'] ?? 0);
            $rowCalls = max(1, (int)($row['usage_count'] ?? $row['calls'] ?? 1));
            $calc = self::calculate($model, $rowInput, $rowOutput);
            $rowCalc = (float)$calc['cost'];

            // Si esta fila ya es un agregado por modelo/fase, calculate() opera
            // sobre los tokens agregados, que es matemáticamente equivalente.
            $input += $rowInput;
            $output += $rowOutput;
            $storedCost += $rowStored;
            $recalculatedCost += $rowCalc;
            $duration += $rowDuration;
            $calls += $rowCalls;

            if (!empty($calc['pricing']['fallback']) && $model !== '') {
                $fallbackModels[$model] = true;
            }

            if (!isset($byPhase[$phase])) {
                $byPhase[$phase] = [
                    'phase' => $phase,
                    'calls' => 0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                    'stored_cost_usd' => 0.0,
                    'recalculated_cost_usd' => 0.0,
                    'duration_ms' => 0,
                ];
            }
            $byPhase[$phase]['calls'] += $rowCalls;
            $byPhase[$phase]['input_tokens'] += $rowInput;
            $byPhase[$phase]['output_tokens'] += $rowOutput;
            $byPhase[$phase]['total_tokens'] += $rowInput + $rowOutput;
            $byPhase[$phase]['stored_cost_usd'] += $rowStored;
            $byPhase[$phase]['recalculated_cost_usd'] += $rowCalc;
            $byPhase[$phase]['duration_ms'] += $rowDuration;

            $modelKey = $model !== '' ? $model : '(sin modelo)';
            if (!isset($byModel[$modelKey])) {
                $byModel[$modelKey] = [
                    'model_id' => $model,
                    'calls' => 0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                    'stored_cost_usd' => 0.0,
                    'recalculated_cost_usd' => 0.0,
                    'duration_ms' => 0,
                    'pricing' => $calc['pricing'],
                    'phases' => [],
                ];
            }
            $byModel[$modelKey]['calls'] += $rowCalls;
            $byModel[$modelKey]['input_tokens'] += $rowInput;
            $byModel[$modelKey]['output_tokens'] += $rowOutput;
            $byModel[$modelKey]['total_tokens'] += $rowInput + $rowOutput;
            $byModel[$modelKey]['stored_cost_usd'] += $rowStored;
            $byModel[$modelKey]['recalculated_cost_usd'] += $rowCalc;
            $byModel[$modelKey]['duration_ms'] += $rowDuration;
            if (!isset($byModel[$modelKey]['phases'][$phase])) {
                $byModel[$modelKey]['phases'][$phase] = ['calls' => 0, 'tokens' => 0];
            }
            $byModel[$modelKey]['phases'][$phase]['calls'] += $rowCalls;
            $byModel[$modelKey]['phases'][$phase]['tokens'] += $rowInput + $rowOutput;
        }

        foreach ($byPhase as &$phaseRow) {
            $phaseRow['stored_cost_usd'] = round((float)$phaseRow['stored_cost_usd'], 6);
            $phaseRow['recalculated_cost_usd'] = round((float)$phaseRow['recalculated_cost_usd'], 6);
        }
        unset($phaseRow);

        foreach ($byModel as &$modelRow) {
            $modelRow['stored_cost_usd'] = round((float)$modelRow['stored_cost_usd'], 6);
            $modelRow['recalculated_cost_usd'] = round((float)$modelRow['recalculated_cost_usd'], 6);
            $modelRow['phases'] = array_map(
                static fn(array $p, string $name): array => ['phase' => $name] + $p,
                array_values($modelRow['phases']),
                array_keys($modelRow['phases'])
            );
        }
        unset($modelRow);

        $phaseValues = array_values($byPhase);
        usort($phaseValues, static fn(array $a, array $b): int => ($b['total_tokens'] <=> $a['total_tokens']));
        $modelValues = array_values($byModel);
        usort($modelValues, static fn(array $a, array $b): int => ($b['total_tokens'] <=> $a['total_tokens']));

        return [
            'calls' => $calls,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'stored_cost_usd' => round($storedCost, 6),
            'recalculated_cost_usd' => round($recalculatedCost, 6),
            'duration_ms_sum' => $duration,
            'fallback_pricing_models' => array_values(array_keys($fallbackModels)),
            'by_phase' => $phaseValues,
            'by_model' => $modelValues,
            'pricing_basis' => 'Misma tabla de precios usada por dashboard_stats.php; fallback conservador Nova Micro para modelos no reconocidos.',
        ];
    }
}
