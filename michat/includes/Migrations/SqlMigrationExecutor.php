<?php
declare(strict_types=1);

final class SqlMigrationExecutor
{
    public function __construct(private mysqli $db) {}

    public function executeFile(string $path): void
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('MIGRATION FAILED: SQL file cannot be read');
        foreach (self::splitStatements($bytes) as $statement) {
            $result=$this->db->query($statement);
            if($result instanceof mysqli_result)$result->free();
            while ($this->db->more_results()) {
                $this->db->next_result();
                if ($result = $this->db->store_result()) $result->free();
            }
        }
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $statements=[];$buffer='';$delimiter=';';$state='normal';$lineStart=true;$length=strlen($sql);
        for ($i=0;$i<$length;) {
            if ($state==='normal' && $lineStart) {
                $end=strpos($sql,"\n",$i);if($end===false)$end=$length;
                $line=substr($sql,$i,$end-$i);
                if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i',rtrim($line,"\r"),$match)) {
                    $delimiter=$match[1];$i=$end<$length?$end+1:$end;$lineStart=true;continue;
                }
            }
            $char=$sql[$i];$next=$i+1<$length?$sql[$i+1]:'';
            if ($state==='normal') {
                if (substr($sql,$i,strlen($delimiter))===$delimiter) {
                    if (trim($buffer)!=='')$statements[]=trim($buffer);$buffer='';$i+=strlen($delimiter);$lineStart=false;continue;
                }
                if ($char==="'")$state='single';elseif($char==='"')$state='double';elseif($char==='`')$state='backtick';
                elseif($char==='-'&&$next==='-'&&($i+2===$length||ctype_space($sql[$i+2]))){$state='line_comment';}
                elseif($char==='#')$state='line_comment';elseif($char==='/'&&$next==='*'){$state='block_comment';}
            } elseif ($state==='single' || $state==='double' || $state==='backtick') {
                $quote=['single'=>"'",'double'=>'"','backtick'=>'`'][$state];
                if ($char==='\\' && $state!=='backtick' && $next!=='') {$buffer.=$char.$next;$i+=2;$lineStart=false;continue;}
                if ($char===$quote) {
                    if ($next===$quote) {$buffer.=$char.$next;$i+=2;$lineStart=false;continue;}
                    $state='normal';
                }
            } elseif ($state==='line_comment' && $char==="\n") $state='normal';
            elseif ($state==='block_comment' && $char==='*' && $next==='/') {$buffer.='*/';$i+=2;$state='normal';$lineStart=false;continue;}
            $buffer.=$char;$i++;$lineStart=$char==="\n";
        }
        if ($state==='single'||$state==='double'||$state==='backtick'||$state==='block_comment') throw new RuntimeException('INVALID SQL: unterminated quote or comment');
        if(trim($buffer)!=='')$statements[]=trim($buffer);
        return $statements;
    }
}
