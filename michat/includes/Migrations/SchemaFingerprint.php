<?php
declare(strict_types=1);

/** Deterministic structural fingerprint for supported-upgrade certification. */
final class SchemaFingerprint
{
    public function __construct(private mysqli $db) {}

    /** @return array<string,list<array<string,mixed>>> */
    public function capture(): array
    {
        return [
            'tables'=>$this->rows("SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"),
            'columns'=>$this->rows("SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION"),
            'indexes'=>$this->rows("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX"),
            'foreign_keys'=>$this->rows("SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION"),
            'checks'=>$this->rows("SELECT t.TABLE_NAME,t.CONSTRAINT_NAME,c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.CONSTRAINT_TYPE='CHECK' ORDER BY t.TABLE_NAME,t.CONSTRAINT_NAME"),
        ];
    }

    /** @return list<string> */
    public static function differences(array $expected,array $actual): array
    {
        $differences=[];
        foreach(array_unique(array_merge(array_keys($expected),array_keys($actual))) as $section){
            $left=$expected[$section]??null;$right=$actual[$section]??null;
            if($left===$right)continue;
            $maximum=max(is_array($left)?count($left):0,is_array($right)?count($right):0);
            for($i=0;$i<$maximum;$i++)if(($left[$i]??null)!==($right[$i]??null)){
                $differences[]=sprintf('%s[%d] clean=%s upgrade=%s',$section,$i,json_encode($left[$i]??null,JSON_UNESCAPED_SLASHES),json_encode($right[$i]??null,JSON_UNESCAPED_SLASHES));
            }
        }
        return $differences;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $result=$this->db->query($sql);$rows=[];
        while($row=$result->fetch_assoc()){
            foreach($row as $key=>$value)if(is_string($value)&&in_array($key,['EXTRA','GENERATION_EXPRESSION','CHECK_CLAUSE'],true))$row[$key]=$this->normalizeExpression($value);
            $rows[]=$row;
        }
        $result->free();return $rows;
    }

    private function normalizeExpression(string $value): string
    {
        return trim((string)preg_replace('/\s+/u',' ',$value));
    }
}
