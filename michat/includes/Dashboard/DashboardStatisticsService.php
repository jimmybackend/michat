<?php
declare(strict_types=1);

/** Read-only, owner-scoped statistics; compatible with MySQL and MariaDB. */
final class DashboardStatisticsService
{
    public function __construct(private mysqli $db) {}

    public function month(int $userId, string $month): array
    {
        if ($userId < 1) throw new InvalidArgumentException('Usuario inválido');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) throw new InvalidArgumentException('Mes inválido');
        $from = $month . '-01';
        $until = (new DateTimeImmutable($from))->modify('+1 month')->format('Y-m-d');
        $models = []; $phases = []; $total = 0; $cost = 0.0; $warnings = [];
        $rows = $this->rows("SELECT tu.model_id,tu.phase,COUNT(*) usage_count,SUM(tu.input_tokens) total_input,
            SUM(tu.output_tokens) total_output,SUM(tu.estimated_cost_usd) total_cost
            FROM TokenUsage tu INNER JOIN ChatSessions cs ON cs.id_=tu.session_id_
            WHERE cs.user_id_=? AND tu.created_at>=? AND tu.created_at<? GROUP BY tu.model_id,tu.phase", $userId,$from,$until);
        foreach ($rows as $row) {
            $id = (string)$row['model_id']; $phase = (string)$row['phase'];
            $in = (int)$row['total_input']; $out = (int)$row['total_output']; $value = (float)$row['total_cost'];
            $models[$id] ??= ['model_id'=>$id,'usage_count'=>0,'total_input'=>0,'total_output'=>0,'total_cost'=>0.0,'phases'=>[]];
            $models[$id]['usage_count'] += (int)$row['usage_count'];
            $models[$id]['total_input'] += $in; $models[$id]['total_output'] += $out; $models[$id]['total_cost'] += $value;
            $models[$id]['phases'][$phase] = ['count'=>(int)$row['usage_count'],'input'=>$in,'output'=>$out,'cost'=>$value];
            $phases[$phase] = ($phases[$phase] ?? 0) + $in + $out; $total += $in + $out; $cost += $value;
        }
        $sessions = $this->rows("SELECT cs.id_,cs.title,cs.context_level,cs.last_compressed_at,
            (SELECT COUNT(*) FROM SessionContextBlocks scb WHERE scb.session_id_=cs.id_) block_count
            FROM ChatSessions cs WHERE cs.user_id_=? AND cs.status='open' AND cs.updated_at>=? AND cs.updated_at<?
            ORDER BY cs.updated_at DESC LIMIT 50",$userId,$from,$until);
        $ladder = [];
        // Optional diagnostics must not hide the billing report on older installations.
        try {
            $ladder = $this->rows("SELECT la.model_used,COUNT(*) total_attempts,SUM(la.is_success=1) success_count
                FROM LintAttempts la INNER JOIN FileVersions fv ON fv.id_=la.file_version_id_
                INNER JOIN Projects p ON p.id_=fv.project_id_ WHERE p.user_id_=? AND la.created_at>=? AND la.created_at<? GROUP BY la.model_used",$userId,$from,$until);
        } catch (Throwable $e) { $warnings[] = 'El detalle opcional de lint no está disponible.'; }
        $processing=['estimated_cost'=>0.0,'unpriced_operations'=>0,'services'=>[]];
        try{
            $usage=$this->rows("SELECT service,COUNT(*) operations,SUM(estimated_cost_usd) cost,SUM(estimated_cost_usd IS NULL) unpriced FROM AttachmentProcessingCosts WHERE user_id_=? AND created_at>=? AND created_at<? GROUP BY service",$userId,$from,$until);
            foreach($usage as $row){$processing['estimated_cost']+=(float)$row['cost'];$processing['unpriced_operations']+=(int)$row['unpriced'];$processing['services'][]=$row;}
        }catch(mysqli_sql_exception $e){if($e->getCode()!==1146)throw $e;}
        return ['ok'=>true,'month'=>$month,'scope'=>'authenticated_user','cost_basis'=>'TokenUsage.estimated_cost_usd',
            'cost_note'=>'Estimaciones registradas al ejecutar cada operación; no es una factura de AWS. Un costo guardado como cero no demuestra que el servicio fuera gratuito.',
            'tokens'=>['total'=>$total,'cost'=>round($cost,6),'by_phase'=>$phases],
            'processing'=>$processing,'combined_estimated_cost'=>round($cost+$processing['estimated_cost'],6),'models'=>array_values($models),'ladder'=>$ladder,'sessions'=>$sessions,'warnings'=>$warnings];
    }

    private function rows(string $sql,int $user,string $from,string $until): array
    {
        $s = $this->db->prepare($sql);
        if (!$s) throw new RuntimeException('No se pudo preparar el informe');
        try { $s->bind_param('iss',$user,$from,$until); if (!$s->execute()) throw new RuntimeException('No se pudo consultar el informe'); return $s->get_result()->fetch_all(MYSQLI_ASSOC); }
        finally { $s->close(); }
    }
}
