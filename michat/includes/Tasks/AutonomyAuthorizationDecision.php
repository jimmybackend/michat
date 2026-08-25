<?php
declare(strict_types=1);
final class AutonomyAuthorizationDecision{
 public const ALLOWED='allowed',DENIED='denied',REQUIRES_APPROVAL='requires_approval';
 public function __construct(public readonly string$status,public readonly string$reasonCode,public readonly string$publicReason,public readonly ?string$reservationPublicId=null,public readonly array$remaining=[]){if(!in_array($status,[self::ALLOWED,self::DENIED,self::REQUIRES_APPROVAL],true))throw new InvalidArgumentException('autonomy_authorization_invalid');}
 public static function denied(string$code,string$reason):self{return new self(self::DENIED,$code,$reason);}
 public static function approval():self{return new self(self::REQUIRES_APPROVAL,'approval_required','La continuación requiere aprobación humana.');}
}
