<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Chat/ChatActivityTelemetryService.php';

/** Real operation milestones consumed by the existing live activity poller. */
final class AttachmentProgressReporter
{
    public function __construct(private mysqli $db,private string $trace,private int $user,private int $session) {}
    public function __invoke(string $key,string $status,string $title,array $details=[]): void
    {
        if(!preg_match('/^[A-Za-z0-9_-]{16,36}$/',$this->trace))return;
        (new ChatActivityTelemetryService($this->db))->emit($this->trace,$this->user,$this->session,'respond',
            'attachment_'.$key,$status,$title,isset($details['filename'])?(string)$details['filename']:null,$details);
    }
}
