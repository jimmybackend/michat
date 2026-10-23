<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$html = file_get_contents($root.'/chat.php');
$js = file_get_contents($root.'/js/chat.js');
$css = file_get_contents($root.'/css/chat-files-drawer.css');
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$check(str_contains($html, 'href="#" id="chatBrandHome"'), 'Chat+S3 link should be inert');
$check(!str_contains($html, 'href="s3.php"'), 'Chat+S3 must not navigate to Drive');
$check(str_contains($js, "event.preventDefault();"), 'Brand link must cancel default click');
$check(str_contains($html, 'id="chatFilesOpen"') && str_contains($html, 'id="chatFilesDrawer"'), 'Chat header needs file drawer trigger');
$check(str_contains($html, 'id="chatFilesBackdrop"') && str_contains($html, 'id="chatFilesClose"'), 'Drawer needs close controls');
foreach (['chatSessionFilesList', 'btnAttachmentInspector', 'chatAttachmentsRagMode', 'chat2SourcesPanel', 'sessionFilesInput', 'btnUploadSessionFiles', 'btnIndexSessionAttachments', 'chatFilesUpload'] as $id) {
    $check(substr_count($html, 'id="'.$id.'"') === 1, 'Exactly one file/RAG control ID: '.$id);
}
$sidebarEnd = strpos($html, '<div class="sidebar-footer">');
$drawerStart = strpos($html, 'id="chatFilesDrawer"');
$check($sidebarEnd !== false && $drawerStart !== false && strpos(substr($html, 0, $sidebarEnd), 'id="chatSessionFilesList"') === false,
    'File list must no longer occupy sidebar');
$check(str_contains($html, 'css/chat-files-drawer.css') && str_contains($css, '.chat-files-drawer[hidden]'),
    'Drawer should have its own responsive stylesheet and hidden state');
$check(str_contains($js, 'function openChatFilesDrawer()') && str_contains($js, 'function closeChatFilesDrawer('),
    'Drawer should open and close without reloading page');
$check(str_contains($js, 'loadSessionAttachments(currentSessionId)') && str_contains($js, 'loadAttachmentRagMode(currentSessionId)'),
    'Drawer should reuse existing attachments and RAG APIs');
$check(str_contains($js, 'openSessionAttachmentsManager(') && str_contains($html, 'id="chatFilesManage"'),
    'Drawer should offer inline upload/index functionality');
$check(!str_contains($html, 'id="chat2Attach"') && !str_contains($html, 'id="modalSessionAttachments"'),
    'Only one visible clip, without nested attachments modal');
$check(!str_contains($html, 'id="chat2AttachmentsPanel"'),
    'Unused duplicated attachments panel is removed');
$check(str_contains($js, 'session_upload.php') && str_contains($js, 'FILE_ENDPOINTS.indexFile'),
    'Existing session upload and index endpoints are preserved');
$check(str_contains($js, 'indexPendingFromPanel') && str_contains($html, 'id="chat2SourcesAdd"'),
    'Project sources keep their own scope inside the same file drawer');
$check(str_contains($css, '.chat-files-upload[hidden]'),
    'Collapsed upload region stays hidden until explicitly opened');
if ($failures) {
    fwrite(STDERR, "Chat file drawer contract failures:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}
echo "Chat file drawer contract: OK\n";
