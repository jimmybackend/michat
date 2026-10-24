# On-demand ArcadeCloud and chat attachments

## Attach now, process after a request

ArcadeCloud selection creates or reuses a private revision-pinned working copy in `Data/Chat/Uploads/<owner>/<UTC year>/<month>/<day>/<session>/`. Direct uploads use the same chat namespace. These operations store ordinary FileS3 metadata and mark `ChatSessions.meta.attachment_processing_policy = on_request`. They do not download content for extraction, create SessionContextBlocks, start embeddings or vision, generate summaries, or write an analysis JSON. Originals remain unchanged. Server-side S3 copy/storage requests still have their normal AWS costs.

The browser returns to the chat and prepares a question containing the selected filename. It does **not** send the question. Existing drafts are preserved. Direct uploads also prepare a named question when the composer is empty. New attachments show “Adjunto · pendiente de solicitud”. Manual Indexar/Semántica buttons remain explicit processing actions.

## Deterministic request planning

Both legacy and Task chat paths use ContextBuilder. Conversations with the on-request policy resolve file intent through AttachmentRequestService before attaching content to the prompt. Unrelated messages and merely announcing a file upload perform metadata checks only. Name, path, size and date questions use the real catalog without reading S3 content. Multiple unspecified documents require clarification; a missing or revoked requested filename never silently selects a different file. Relative follow-ups can refer to the last file actually requested, rather than the last attachment merely selected.

After an explicit content request, the existing literal extraction/index/JSON path runs with summaries and embeddings disabled. It validates live session ownership, original permissions and pinned revisions, reuses verified cached chunks, and persists evidence only at this point. Images require the configured vision service after the request; their descriptions are generated interpretations, not literal OCR evidence. Attachment-specific requests skip unrelated project/semantic query embeddings. Existing chat generation still uses its configured model to answer the user.

- Literal search scans every extracted chunk with a literal string, never a shell command or user-supplied regex. It sends matching lines with nearby context and original locators. A zero-match result is limited to extracted chunks; cross-chunk phrases and unextracted content are not certified absent.
- PHP classes/functions/methods use `token_get_all` without executing code, ignore comments and strings, and preserve declaration lines. Other languages currently receive bounded excerpts and explicitly lack a specialized structural parser.
- General explanations sample up to eight chunks distributed through the extracted document. Reviews/comparisons select up to 24. Context has a 48,000-character budget divided across selected files. Coverage reports extracted chunks, selected items, extraction partiality and context omissions. Sampling is **not** an exhaustive review; this change does not implement an autonomous all-pages model loop.

The existing 64 MiB immediate-download limit and 200,000-character extraction limit remain; extraction limits are reported as partial, never proof of full-document analysis. Large documents still require a dedicated batch processing workflow. Model/token savings come from passive attachments and narrower evidence, not from claiming all documents can be reviewed in one prompt.

## Original JSON, permissions and cleanup

Copy provenance uses the existing FileS3.Metadatos.drive_source fields: original owner/id/key/ETag, working-copy ETag and original sibling JSON key. After content is requested, reusable literal analysis and subsequent selected-file Q&A are written to `<original-key>.michat-u<owner>.analysis.v1.json` using revision validation, per-original locks and conditional writes. The original bytes and metadata stay intact. Direct uploads keep their JSON beside the uploaded object. Deleting a chat working copy does not delete the catalog original or its sibling JSON. Revoked original access invalidates copied-file delivery and retrieval.

No migration, dependency/lockfile, credentials, bucket permissions or infrastructure changes are required. Already-indexed evidence is retained. Existing conversations adopt on-request policy when an attachment is selected/uploaded; legacy indexed attachments also require an explicit file-related question for retrieval. If S3 succeeds but the attachment DB transaction fails, an unlinked private object can remain; no automatic bucket cleanup is introduced.

## Request selection and live progress

The automatic question “Analiza … dónde se encuentra cada dato …” selects an explanation; its location clause must never become a literal search for the rest of the question. Quoted filenames/terms are excluded from intent detection, “se encuentra” is a location clause, and searching for errors is a review unless a literal is explicitly quoted. A real zero-match search is identified as an available index with no matches, not an unindexed file.

Missing evidence is prepared automatically on a content request, including pre-policy conversations and a requested file whose conversation already has a different indexed file. Existing ready central indexes retain their retrieval path. Legacy filename filtering also recognizes ordinary unquoted filenames. Actual absent S3 objects, revoked permissions, unsupported readers and resource limits still produce factual errors; the application never creates a fictional original or certifies missing content.

AttachmentProgressReporter writes milestones into the existing ChatActivityEvents trace while operations execute: chosen action, revision/cache verification, extraction started/completed, validated evidence, action execution and context ready (or the actual error/OCR wait). Legacy HTTP and Task preparation pass the same trace ID used by the live activity card. Events contain operation metadata/counts, never raw source contents or private model reasoning. The existing AJAX poller accepts the supported numeric session identity keys, retains owner isolation and keeps polling after attachment preparation until the response finishes. No migration or fabricated percentages are introduced.

## Validation and deployment

The real-MariaDB working-copy suite covers passive attach, idle messages even with an over-eager router, metadata-only questions, first literal request, narrowed context, tokenizer structure, no embeddings/model calls during deterministic preparation, cache reuse, ambiguity, revoked originals, source JSON and existing copy isolation. Planner tests check last-chunk matches, partial coverage, quoted target/term separation and fake PHP declarations in comments/strings. Browser VM tests verify the named draft is prepared without sending it and preserve existing drafts/session guards. The existing extraction, delivery, migration, PHP and JavaScript suites remain required. AWS SDK result objects are used in CI; AWS transport/model generation still requires a production smoke check.

After updating EC2, attach one file and confirm the pending state with no new content blocks or embedding jobs. Send a greeting: the file must stay pending. Send the prepared question: extraction and source JSON should then appear. Search a known late-file literal and verify its source line; ask for PHP classes and methods; attach another file and try an ambiguous request. Check both own-file and revoked-original access.

## AWS SDK response compatibility

`HeadObject` returns `Aws\Result` in production, an `ArrayAccess` object rather than a PHP array. The original strict array parameters caused a `TypeError` before `GetObject` ran, wrapped as “No se pudo descargar la revisión original desde S3.” Download, document dispatch and PDF/OCR now accept both arrays and `ArrayAccess`, preserving size limits, ETag checks and version-pinned OCR. This defect was reproduced with the actual SDK Result implementation before the fix. Integration fixtures now return objects; CI requires the installed SDK Result instead of silently falling back to arrays. Dedicated checks cover streamed and SaveAs downloads, PHP extraction without execution, and OCR version metadata. A failed attachment preparation also must not auto-send a question or hide its error.

After deployment, retry indexing the existing failed attachment; its presence in the file list only confirms metadata, not extracted content. Verify nonzero chunks and a response citing the source before treating it as ready. No migration or AWS policy change is required for this type fix.
