<?php
declare(strict_types=1);

/** Local imported shares, using ArcadeCloud's actual schema. Never follows AccessUrl. */
final class FederationImportedAccess
{
    public function __construct(private mysqli $db) {}

    public function allows(array $file): bool
    {
        $meta=json_decode((string)($file['Metadatos']??''),true)?:[];
        $ref=$meta['federationcloud']??null;
        if (!is_array($ref) || ($ref['source']??'')!=='share_received') return true;
        $share=(string)($ref['share_id']??'');$resource=(string)($ref['resource_id']??'');$node=(string)($ref['remote_node_id']??'');
        if ($share==='' || $resource==='' || $node==='') return false;
        try {
            $s=$this->db->prepare("SELECT s.LocalS3Key,s.ImportedContentId,s.ImportedResourceUpdatedAt,
                    r.ContentId,r.UpdatedAt AS ResourceUpdatedAt
                FROM FederationShares s JOIN FederatedResources r ON r.ResourceId=s.ResourceId AND r.Tombstoned=0
                WHERE s.ShareId=? AND s.UserId=? AND s.Direction='received' AND s.Status='active'
                  AND (s.ExpiresAt IS NULL OR s.ExpiresAt>UTC_TIMESTAMP(6))
                  AND s.ResourceId=? AND s.RemoteNodeId=? AND s.LocalFileId=?
                  AND r.OriginNodeId=s.RemoteNodeId LIMIT 1");
            if (!$s) return false;
            $user=(int)$file['user_id_'];$id=(int)$file['id_'];
            $s->bind_param('sissi',$share,$user,$resource,$node,$id);
            $s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
            if (!$row || (string)$row['LocalS3Key']!==idx_build_s3_key($file)) return false;
            // Metadata discovery is not proof that an old import is the current resource.
            if (trim((string)$row['ContentId'])!=='') {
                return trim((string)$row['ImportedContentId'])!==''
                    && hash_equals((string)$row['ContentId'],(string)$row['ImportedContentId']);
            }
            return !empty($row['ImportedResourceUpdatedAt']) && !empty($row['ResourceUpdatedAt'])
                && strcmp((string)$row['ImportedResourceUpdatedAt'],(string)$row['ResourceUpdatedAt'])>=0;
        } catch (mysqli_sql_exception $e) {
            // Optional federation schema is owned by ArcadeCloud, never created/repaired here.
            error_log('MICHAT_FEDERATION_ACCESS schema/query unavailable code='.$e->getCode());
            return false;
        }
    }
}
