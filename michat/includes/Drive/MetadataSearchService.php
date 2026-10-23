<?php
declare(strict_types=1);

/** The model proposes data filters only. It never supplies SQL or sees file content. */
final class MetadataSearchService
{
    public function __construct(private mysqli $db, private $planner = null) {}

    public static function filters(array $input): array
    {
        $out = ['terms'=>[],'extensions'=>[],'date_from'=>null,'date_to'=>null];
        foreach (array_slice((array)($input['terms'] ?? []),0,6) as $term) {
            if (is_string($term) && trim($term) !== '') $out['terms'][] = mb_substr(trim($term),0,100);
        }
        foreach (array_slice((array)($input['extensions'] ?? []),0,12) as $ext) {
            if (is_string($ext)) { $ext=ltrim(trim($ext),'.'); if (preg_match('/^[a-z0-9]{1,12}$/Di',$ext)) $out['extensions'][]=strtolower($ext); }
        }
        foreach (['date_from','date_to'] as $key) {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') continue;
            if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) throw new InvalidArgumentException('Fecha de búsqueda inválida');
            $out[$key] = $value;
        }
        if ($out['date_from'] && $out['date_to'] && $out['date_from'] > $out['date_to']) throw new InvalidArgumentException('Rango de fechas inválido');
        return $out;
    }

    public function search(int $user, string $query, bool $ai, array $explicit = [], int $cursor = 0): array
    {
        if ($user < 1) throw new InvalidArgumentException('Usuario inválido');
        $query = mb_substr(trim($query),0,500);
        $filters = ['terms'=>$query !== '' ? [$query] : []]; $used = false;
        if ($ai && $query !== '') {
            if (!is_callable($this->planner)) throw new RuntimeException('El planificador IA no está disponible. Puedes usar búsqueda literal.');
            $filters = ($this->planner)($query); $used = true;
        }
        // Explicit controls win over the model. Empty controls keep inferred filters.
        foreach (['extensions','date_from','date_to'] as $key) if (!empty($explicit[$key])) $filters[$key] = $explicit[$key];
        $filters = self::filters($filters);
        $where = ["user_id_=?","Found=1","AccessType<>'secure'","id_>?"];
        $types = 'ii'; $params = [$user,max(0,$cursor)];
        foreach ($filters['terms'] as $term) {
            $like = '%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';
            $where[] = "(Nombre LIKE ? ESCAPE '!' OR Ruta LIKE ? ESCAPE '!' OR Metadatos LIKE ? ESCAPE '!')";
            $types .= 'sss'; array_push($params,$like,$like,$like);
        }
        if ($filters['extensions']) {
            $where[] = "LOWER(SUBSTRING_INDEX(Nombre,'.',-1)) IN (".implode(',',array_fill(0,count($filters['extensions']),'?')).')';
            $types .= str_repeat('s',count($filters['extensions'])); array_push($params,...$filters['extensions']);
        }
        if ($filters['date_from']) { $where[] = 'Fecha>=?'; $types.='s'; $params[]=$filters['date_from'].' 00:00:00'; }
        if ($filters['date_to']) { $where[] = 'Fecha<?'; $types.='s'; $params[]=(new DateTimeImmutable($filters['date_to']))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
        $s = $this->db->prepare('SELECT id_,Nombre,Ruta,Tamano,Fecha FROM FileS3 WHERE '.implode(' AND ',$where).' ORDER BY id_ ASC LIMIT 41');
        if (!$s) throw new RuntimeException('No se pudo consultar FileS3');
        try { $s->bind_param($types,...$params); if (!$s->execute()) throw new RuntimeException('No se pudo buscar'); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); }
        finally { $s->close(); }
        $more = count($rows)>40; if ($more) array_pop($rows);
        return ['files'=>$rows,'filters'=>$filters,'ai_used'=>$used,'has_more'=>$more,'next_cursor'=>$rows?(int)end($rows)['id_']:0,'search_scope'=>'FileS3 metadata only'];
    }
}
