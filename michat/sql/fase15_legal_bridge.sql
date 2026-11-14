-- Legal bridge: additive, explicit migration. DO NOT apply automatically.
-- Apply only after a verified migration-history and backup review.
CREATE TABLE IF NOT EXISTS LegalBridgeClients (
  credential_id varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id_ int NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 0,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (credential_id),
  CONSTRAINT fk_lbc_user FOREIGN KEY (user_id_) REFERENCES Users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS LegalBridgeNonces (
  credential_id varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  nonce char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at timestamp NOT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (credential_id, nonce),
  KEY idx_lbn_created (credential_id, created_at),
  KEY idx_lbn_expiry (expires_at),
  CONSTRAINT fk_lbn_client FOREIGN KEY (credential_id) REFERENCES LegalBridgeClients(credential_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS LegalBridgePrincipals (
  credential_id varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  external_subject varchar(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id_ int NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (credential_id, external_subject),
  UNIQUE KEY uq_legal_principal_user (credential_id,user_id_),
  CONSTRAINT fk_lbp_client FOREIGN KEY (credential_id) REFERENCES LegalBridgeClients(credential_id) ON DELETE RESTRICT,
  CONSTRAINT fk_lbp_user FOREIGN KEY (user_id_) REFERENCES Users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS LegalBridgeSessions (
  id_ bigint unsigned NOT NULL AUTO_INCREMENT,
  credential_id varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id_ int NOT NULL,
  external_system varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  external_document_type varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  external_document_id varchar(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  session_id_ int NOT NULL,
  current_revision char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_),
  UNIQUE KEY uq_legal_document (credential_id, user_id_, external_system, external_document_type, external_document_id),
  UNIQUE KEY uq_legal_session (session_id_),
  KEY idx_legal_owner (user_id_, session_id_),
  CONSTRAINT fk_lbs_client FOREIGN KEY (credential_id) REFERENCES LegalBridgeClients(credential_id) ON DELETE RESTRICT,
  CONSTRAINT fk_lbs_user FOREIGN KEY (user_id_) REFERENCES Users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_lbs_session FOREIGN KEY (session_id_) REFERENCES ChatSessions(id_) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Provisioning is deliberately NOT performed by the API:
-- Administrators must review and insert the exact authorized Users.id separately.
-- A signed external_subject maps to a preauthorized MiChat user via LegalBridgePrincipals.
-- Clean expired nonces with a trusted maintenance task AFTER deployment:
-- DELETE FROM LegalBridgeNonces WHERE expires_at < UTC_TIMESTAMP();

CREATE TABLE IF NOT EXISTS LegalBridgeDocuments (
  id_ bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id_ int NOT NULL,
  user_id_ int NOT NULL,
  revision_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  files3_id_ int NOT NULL,
  byte_size int unsigned NOT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_),
  UNIQUE KEY uq_legal_doc_revision (session_id_, revision_sha256),
  UNIQUE KEY uq_legal_doc_file (files3_id_),
  CONSTRAINT fk_lbd_session FOREIGN KEY (session_id_) REFERENCES ChatSessions(id_) ON DELETE RESTRICT,
  CONSTRAINT fk_lbd_user FOREIGN KEY (user_id_) REFERENCES Users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_lbd_file FOREIGN KEY (files3_id_) REFERENCES FileS3(id_) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
