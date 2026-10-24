<?php
declare(strict_types=1);

/** Withdraw publication and its derived index. Never deletes FileS3, versions or S3 objects. */
final class ProjectSourceRemovalService
{
    public function __construct(private mysqli $db) {}

    public function withdraw(int $user,int $source): array
    {
        if($user<1||$source<1)throw new InvalidArgumentException('Fuente inválida');
        $this->db->begin_transaction();
        try{
            $s=$this->db->prepare("SELECT ps.id_,ps.project_id_,ps.filename FROM ProjectSources ps
                JOIN Projects p ON p.id_=ps.project_id_ WHERE ps.id_=? AND p.user_id_=? AND p.status<>'deleted' FOR UPDATE");
            $s->bind_param('ii',$source,$user);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
            if(!$row)throw new RuntimeException('Fuente no encontrada o sin permisos');
            // Explicit cleanup also works on installations without cascading foreign keys.
            foreach([
                "DELETE ej FROM EmbeddingJobs ej JOIN SourceChunks sc ON ej.target_type='source_chunk' AND ej.target_id=sc.id_ WHERE sc.source_id_=?",
                'DELETE ce FROM ChunkEmbeddings ce JOIN SourceChunks sc ON ce.chunk_id_=sc.id_ WHERE sc.source_id_=?',
                'DELETE FROM SourceChunks WHERE source_id_=?',
                'DELETE FROM ProjectSources WHERE id_=?'
            ] as $sql){$s=$this->db->prepare($sql);$s->bind_param('i',$source);$s->execute();$s->close();}
            $project=(int)$row['project_id_'];
            $s=$this->db->prepare('UPDATE Projects SET index_gen=index_gen+1 WHERE id_=? AND user_id_=?');
            $s->bind_param('ii',$project,$user);$s->execute();$s->close();
            $this->db->commit();
            return ['ok'=>true,'message'=>'Fuente retirada del proyecto. El archivo original y sus versiones se conservan.',
                'filename'=>$row['filename'],'original_preserved'=>true];
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
}
