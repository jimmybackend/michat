-- Additive, private processing queue and attributed usage. No existing data changes.
CREATE TABLE AttachmentProcessingJobs (
 id_ bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id_ int NOT NULL, session_id_ int NOT NULL, files3_id int NOT NULL,
 revision_hash char(64) NOT NULL, source_etag varchar(255) NOT NULL,
 phase varchar(32) NOT NULL DEFAULT 'extract', status varchar(32) NOT NULL DEFAULT 'pending',
 checkpoint_json mediumtext, error_message text, attempts int NOT NULL DEFAULT 0,
 available_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
 trace_id varchar(36) NOT NULL DEFAULT '',
 created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_attachment_revision(user_id_,session_id_,files3_id,revision_hash),
 KEY idx_attachment_work(status,available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE AttachmentProcessingCosts (
 id_ bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 operation_key char(64) NOT NULL, job_id bigint unsigned NOT NULL, user_id_ int NOT NULL,
 session_id_ int NOT NULL, files3_id int NOT NULL, service varchar(64) NOT NULL,
 operation varchar(100) NOT NULL, units_json text NOT NULL,
 estimated_cost_usd decimal(20,10) DEFAULT NULL,
 pricing_state varchar(32) NOT NULL, price_source varchar(255) NOT NULL,
 outcome varchar(32) NOT NULL, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_attachment_operation(operation_key),
 KEY idx_attachment_cost_owner(user_id_,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
