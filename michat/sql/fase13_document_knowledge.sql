-- Additive central document index. No existing table or data is altered.
-- Activation requires MICHAT_DOCUMENT_KNOWLEDGE_ENABLED=1 and a CLI worker.
CREATE TABLE DocumentKnowledgeSources (
  id_ bigint unsigned NOT NULL AUTO_INCREMENT,
  files3_id int NOT NULL,
  user_id_ int NOT NULL,
  revision_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  s3_key varchar(1024) NOT NULL,
  source_etag varchar(255) NOT NULL,
  source_sha256 char(64) DEFAULT NULL,
  format varchar(32) NOT NULL,
  extractor varchar(120) DEFAULT NULL,
  state varchar(32) NOT NULL DEFAULT 'queued',
  coverage varchar(32) NOT NULL DEFAULT 'unknown',
  extracted_content mediumtext,
  extracted_chars int unsigned NOT NULL DEFAULT 0,
  text_sha256 char(64) DEFAULT NULL,
  chunks_total int unsigned NOT NULL DEFAULT 0,
  chunks_done int unsigned NOT NULL DEFAULT 0,
  embedding_model varchar(120) NOT NULL DEFAULT '',
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_),
  UNIQUE KEY uq_document_revision (files3_id,user_id_,revision_hash),
  KEY idx_document_owner (user_id_,files3_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE DocumentKnowledgeChunks (
  id_ bigint unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint unsigned NOT NULL,
  ordinal int unsigned NOT NULL,
  content mediumtext NOT NULL,
  content_sha256 char(64) NOT NULL,
  locator_json longtext NOT NULL,
  embedding_json longtext,
  embedding_model varchar(120) DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_),
  UNIQUE KEY uq_document_chunk (source_id,ordinal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE DocumentKnowledgeJobs (
  id_ bigint unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint unsigned NOT NULL,
  phase varchar(32) NOT NULL DEFAULT 'extract',
  status varchar(32) NOT NULL DEFAULT 'pending',
  cursor_ordinal int unsigned NOT NULL DEFAULT 0,
  failure_count int unsigned NOT NULL DEFAULT 0,
  claim_count int unsigned NOT NULL DEFAULT 0,
  lease_owner char(48) DEFAULT NULL,
  lease_until datetime DEFAULT NULL,
  available_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  error_message text,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_),
  UNIQUE KEY uq_document_job (source_id),
  KEY idx_document_claim (status,available_at,lease_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE DocumentKnowledgeEvents (
  id_ bigint unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint unsigned NOT NULL,
  job_id bigint unsigned NOT NULL,
  actor_user_id int NOT NULL,
  event_type varchar(64) NOT NULL,
  details_json longtext NOT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_),
  KEY idx_document_events (source_id,id_)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
