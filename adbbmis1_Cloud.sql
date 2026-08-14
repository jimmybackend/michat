-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 14-08-2026 a las 10:17:13
-- Versión del servidor: 8.0.46-37
-- Versión de PHP: 8.4.24

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `adbbmis1_Cloud`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `AccessControl`
--

CREATE TABLE `AccessControl` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `date_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `action` enum('Inicio de Sesión','Cierre de Sesión','Cambio de Contraseña','Otro') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `action_details` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ChatMessages`
--

CREATE TABLE `ChatMessages` (
  `id_` int NOT NULL,
  `session_id_` int NOT NULL,
  `user_id_` int NOT NULL,
  `role` enum('system','user','assistant','tool') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `content_type` enum('text','image','video','audio','file') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'text',
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `s3_key` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `mime_type` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `size_bytes` bigint DEFAULT NULL,
  `thumb_s3_key` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `duration_ms` int DEFAULT NULL,
  `model_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `stop_reason` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `prompt_tokens` int DEFAULT NULL,
  `completion_tokens` int DEFAULT NULL,
  `latency_ms` int DEFAULT NULL,
  `meta` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `is_primordial` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = El usuario marcó esta respuesta como verdad absoluta/primordial',
  `phase` enum('compile','respond','lint_fix','embedding','classify','scout','plan','rag','edit','summarize','review') NOT NULL DEFAULT 'respond' COMMENT 'Fase del pipeline en la que se generó este mensaje',
  `parent_msg_id` int DEFAULT NULL COMMENT 'Para rastrear ediciones o reintentos de un mensaje anterior',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ChatSessions`
--

CREATE TABLE `ChatSessions` (
  `id_` int NOT NULL,
  `user_id_` int NOT NULL,
  `project_id_` int DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `model_id` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `provider` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `status` enum('open','archived','closed') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'open',
  `meta` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `context_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `context_embedding` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `context_level` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT '0: crudo, 1: resumen x5, 2: macro x20, 3: épico x80',
  `last_compressed_at` timestamp NULL DEFAULT NULL COMMENT 'Última vez que se ejecutó la compresión de contexto',
  `pending_summary` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Bandera anti-ciclo: 1 = hay bloques level_0 nuevos (> RECENT_WINDOW) esperando el resumen del cron; el cron la vuelve a 0 tras procesar',
  `memory_summary_updated_at` timestamp NULL DEFAULT NULL COMMENT 'Última vez que Nova Micro reescribió el context_summary'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ChunkEmbeddings`
--

CREATE TABLE `ChunkEmbeddings` (
  `id_` bigint UNSIGNED NOT NULL,
  `chunk_id_` bigint UNSIGNED NOT NULL,
  `model_id` varchar(120) NOT NULL COMMENT 'ej. amazon.titan-embed-text-v2:0',
  `dimensions` smallint UNSIGNED NOT NULL DEFAULT '1024' COMMENT 'dimensión del vector',
  `embedding` blob NOT NULL COMMENT 'vector binario (float32 little-endian)',
  `embedding_json` json DEFAULT NULL COMMENT 'fallback legible: [0.012, -0.034, ...]',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `EmbeddingJobs`
--

CREATE TABLE `EmbeddingJobs` (
  `id_` bigint UNSIGNED NOT NULL,
  `target_type` enum('session_block','source_chunk','project_context') NOT NULL,
  `target_id` bigint UNSIGNED NOT NULL COMMENT 'ID de la tabla objetivo (ej. id_ de SessionContextBlocks)',
  `model_id` varchar(120) NOT NULL DEFAULT 'amazon.titan-embed-text-v2:0',
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `error_message` text,
  `attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `FileS3`
--

CREATE TABLE `FileS3` (
  `id_` int NOT NULL,
  `Nombre` varchar(255) NOT NULL,
  `Encriptado` varchar(255) NOT NULL,
  `Tamano` bigint NOT NULL,
  `Metadatos` mediumtext,
  `Ruta` varchar(256) NOT NULL,
  `Found` tinyint(1) NOT NULL DEFAULT '0',
  `AccessType` enum('normal','secure','unlocked') NOT NULL DEFAULT 'normal',
  `PasswordHash` varchar(255) DEFAULT NULL,
  `SecureHint` varchar(255) DEFAULT NULL,
  `SecureUpdatedAt` timestamp NULL DEFAULT NULL,
  `Fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id_` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `FileVersions`
--

CREATE TABLE `FileVersions` (
  `id_` bigint UNSIGNED NOT NULL,
  `project_id_` int NOT NULL,
  `session_id_` int DEFAULT NULL,
  `message_id_` int DEFAULT NULL COMMENT 'El mensaje de ChatMessages que generó esta versión',
  `original_filename` varchar(255) NOT NULL COMMENT 'Nombre con el que se descarga (ej. AuthService.php)',
  `version` varchar(50) NOT NULL COMMENT 'ej. 1, 1.1, 1.2.1',
  `s3_path` varchar(1024) NOT NULL COMMENT 'Ruta al archivo completo en S3',
  `diff_summary` text COMMENT 'Resumen de cambios (ej. "Se agregó validación de token")',
  `is_stable` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = El usuario la marcó como versión estable/consolidada',
  `status` enum('draft','committed','failed','rolled_back') NOT NULL DEFAULT 'draft',
  `sha256_before` char(64) DEFAULT NULL,
  `sha256_after` char(64) DEFAULT NULL,
  `bytes_before` bigint DEFAULT NULL,
  `bytes_after` bigint DEFAULT NULL,
  `model_used` varchar(120) DEFAULT NULL,
  `error_message` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `LintAttempts`
--

CREATE TABLE `LintAttempts` (
  `id_` bigint UNSIGNED NOT NULL,
  `file_version_id_` bigint UNSIGNED NOT NULL,
  `attempt_number` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT 'Número de intento en la escalera',
  `model_used` varchar(120) NOT NULL COMMENT 'ej. anthropic.claude-3-haiku-20240307-v1:0',
  `error_message` text COMMENT 'Salida de php -l, eslint, etc.',
  `is_success` tinyint(1) NOT NULL DEFAULT '0',
  `duration_ms` int DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `PhaseCache`
--

CREATE TABLE `PhaseCache` (
  `id_` bigint UNSIGNED NOT NULL,
  `cache_key` char(64) NOT NULL,
  `project_id_` int NOT NULL,
  `phase` varchar(32) NOT NULL,
  `payload` json NOT NULL COMMENT 'Resultado cacheado de la fase',
  `hit_count` int UNSIGNED NOT NULL DEFAULT '0',
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ProjectContext`
--

CREATE TABLE `ProjectContext` (
  `id_` int NOT NULL,
  `project_id_` int NOT NULL,
  `type` enum('rule','decision','fact','style','todo','note') NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `source_chunk_id` bigint UNSIGNED DEFAULT NULL,
  `embedding` longtext,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Projects`
--

CREATE TABLE `Projects` (
  `id_` int NOT NULL,
  `user_id_` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `description` text,
  `language` varchar(50) DEFAULT NULL,
  `framework` varchar(100) DEFAULT NULL,
  `root_prefix` varchar(1024) NOT NULL,
  `status` enum('active','archived','deleted') NOT NULL DEFAULT 'active',
  `budget_usd_monthly` decimal(10,4) NOT NULL DEFAULT '25.0000' COMMENT 'Tope de gasto en modelos por mes; 0 = sin límite',
  `budget_usd_per_edit` decimal(10,6) NOT NULL DEFAULT '0.250000' COMMENT 'Tope por operación individual de edición',
  `index_gen` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Generación del índice. +1 en cada escritura a SourceChunks. Invalida el caché RAG. Fuera de meta a propósito: projects.php sobrescribe meta con JSON del cliente.',
  `meta` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ProjectSources`
--

CREATE TABLE `ProjectSources` (
  `id_` bigint UNSIGNED NOT NULL,
  `project_id_` int NOT NULL,
  `files3_id_` int DEFAULT NULL,
  `s3_key` varchar(1024) NOT NULL,
  `s3_key_hash` char(64) GENERATED ALWAYS AS (sha2(`s3_key`,256)) STORED,
  `filename` varchar(255) NOT NULL,
  `mime_type` varchar(128) DEFAULT NULL,
  `size_bytes` bigint DEFAULT '0',
  `language` varchar(50) DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `status` enum('pending','indexed','stale','error') NOT NULL DEFAULT 'pending',
  `indexed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ProjectTestCommands`
--

CREATE TABLE `ProjectTestCommands` (
  `id_` int NOT NULL,
  `project_id_` int NOT NULL,
  `label` varchar(64) NOT NULL COMMENT 'Unico identificador que el cliente puede enviar',
  `bin` varchar(512) NOT NULL COMMENT 'Ruta ABSOLUTA al binario. Nunca del PATH.',
  `args` json NOT NULL COMMENT 'Array de argumentos fijos. proc_open en forma de array, sin shell.',
  `cwd` varchar(1024) DEFAULT NULL COMMENT 'Directorio de trabajo. NULL = el del proyecto.',
  `timeout_sec` smallint UNSIGNED NOT NULL DEFAULT '120',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_by_user_id_` int DEFAULT NULL COMMENT 'Humano que autorizo este comando. SET NULL al borrar el usuario: se pierde el autor, no la fila.',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `PromptCompilations`
--

CREATE TABLE `PromptCompilations` (
  `id_` bigint UNSIGNED NOT NULL,
  `session_id_` int NOT NULL,
  `user_msg_id` int NOT NULL COMMENT 'El mensaje original del usuario que desencadenó esto',
  `compiled_prompt` mediumtext NOT NULL COMMENT 'El prompt fusionado en inglés generado por Haiku',
  `used_context_ids` json DEFAULT NULL COMMENT '["q12_a12", "q17_a17"] para trazabilidad',
  `used_code_refs` json DEFAULT NULL COMMENT '["auth/service.py:login()"]',
  `notes_for_user` text COMMENT 'Advertencias de la IA compiladora (ej. conflictos)',
  `was_edited_by_user` tinyint(1) NOT NULL DEFAULT '0',
  `edited_diff` text COMMENT 'Diferencia entre el prompt compilado y lo que el usuario aprobó',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `S3Folders`
--

CREATE TABLE `S3Folders` (
  `id_` bigint UNSIGNED NOT NULL,
  `user_id_` int NOT NULL DEFAULT '0',
  `Prefix` varchar(1024) NOT NULL,
  `Nombre` varchar(255) NOT NULL,
  `ParentPrefix` varchar(1024) DEFAULT NULL,
  `Found` tinyint(1) NOT NULL DEFAULT '0',
  `AccessType` enum('normal','secure') NOT NULL DEFAULT 'normal',
  `PasswordHash` varchar(255) DEFAULT NULL,
  `SecureHint` varchar(255) DEFAULT NULL,
  `SecureUpdatedAt` timestamp NULL DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `PrefixHash` binary(32) GENERATED ALWAYS AS (unhex(sha2(`Prefix`,256))) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `SessionContextBlocks`
--

CREATE TABLE `SessionContextBlocks` (
  `id_` bigint UNSIGNED NOT NULL,
  `session_id_` int NOT NULL,
  `block_type` enum('primordial','level_0','level_1','level_2','level_3','file','file_chunk') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'level_0',
  `question_msg_id` int DEFAULT NULL COMMENT 'ID del mensaje de pregunta en ChatMessages',
  `answer_msg_id` int DEFAULT NULL COMMENT 'ID del mensaje de respuesta en ChatMessages',
  `content_preview` mediumtext COMMENT 'Contexto completo del bloque para que la memoria no se corte y sea lógica',
  `s3_path` varchar(1024) DEFAULT NULL COMMENT 'Ruta al contenido completo en S3 (JSON o TXT)',
  `is_locked` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = No comprimir automáticamente (ej. primordial)',
  `source_ids` json DEFAULT NULL COMMENT 'IDs de bloques originales que formaron este resumen (trazabilidad)',
  `token_count` int DEFAULT '0',
  `embedding` blob COMMENT 'Vector binario float32 little-endian',
  `embedding_json` json DEFAULT NULL COMMENT 'Fallback legible: [0.012, -0.034, ...]',
  `embedding_model` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_memory_summary` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = Resumen generado por memoria selectiva',
  `memory_hits` int NOT NULL DEFAULT '0' COMMENT 'Veces que se consultó este resumen',
  `last_memory_used_at` timestamp NULL DEFAULT NULL COMMENT 'Última vez que se usó como memoria'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `SourceChunks`
--

CREATE TABLE `SourceChunks` (
  `id_` bigint UNSIGNED NOT NULL,
  `source_id_` bigint UNSIGNED NOT NULL,
  `project_id_` int NOT NULL,
  `chunk_type` enum('file','namespace','class','trait','interface','function','method','block','comment','docstring','import','other') NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `parent_name` varchar(255) DEFAULT NULL,
  `signature` text,
  `content` mediumtext NOT NULL,
  `start_line` int NOT NULL DEFAULT '0',
  `end_line` int NOT NULL DEFAULT '0',
  `token_count` int DEFAULT '0',
  `checksum` char(64) DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `TokenUsage`
--

CREATE TABLE `TokenUsage` (
  `id_` bigint UNSIGNED NOT NULL,
  `session_id_` int NOT NULL,
  `message_id_` int DEFAULT NULL,
  `phase` enum('compile','respond','lint_fix','embedding','classify','scout','plan','rag','edit','summarize','review') NOT NULL,
  `model_id` varchar(120) NOT NULL COMMENT 'ej. amazon.titan-embed-text-v2:0 o claude-3-opus',
  `input_tokens` int NOT NULL DEFAULT '0',
  `output_tokens` int NOT NULL DEFAULT '0',
  `estimated_cost_usd` decimal(10,6) NOT NULL DEFAULT '0.000000',
  `duration_ms` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ToolCalls`
--

CREATE TABLE `ToolCalls` (
  `id_` bigint UNSIGNED NOT NULL,
  `session_id_` int NOT NULL,
  `project_id_` int DEFAULT NULL,
  `message_id_` int DEFAULT NULL,
  `tool` enum('grep','view','search','str_replace','list_dir','read_chunk','run_shell','create_file','write_file','delete_file','move_file','lint','run_tests','preview_diff','restore_version') NOT NULL,
  `params` json NOT NULL,
  `target_path` varchar(1024) DEFAULT NULL COMMENT 'Archivo o prefijo sobre el que operó la herramienta',
  `params_hash` char(64) GENERATED ALWAYS AS (sha2(cast(`params` as char charset utf8mb4),256)) VIRTUAL COMMENT 'Hash de params para detectar llamadas idénticas repetidas',
  `result` mediumtext,
  `status` enum('ok','error','timeout') NOT NULL DEFAULT 'ok',
  `duration_ms` int DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `UserAIAgentConfigs`
--

CREATE TABLE `UserAIAgentConfigs` (
  `id_` bigint UNSIGNED NOT NULL,
  `user_id_` int NOT NULL,
  `agent_key` varchar(100) NOT NULL,
  `agent_group` varchar(50) NOT NULL DEFAULT 'general',
  `display_name` varchar(180) NOT NULL,
  `description` text,
  `model_id` varchar(180) NOT NULL,
  `fallback_model_id` varchar(180) DEFAULT NULL,
  `model_ladder_json` json DEFAULT NULL,
  `system_instruction` longtext,
  `user_prompt_template` longtext,
  `temperature` decimal(3,2) DEFAULT NULL,
  `max_tokens_prompt` int UNSIGNED DEFAULT NULL COMMENT 'Máximo de tokens cuando la IA genera un prompt compilado',
  `max_tokens_output` int UNSIGNED DEFAULT NULL COMMENT 'Máximo de tokens cuando la IA genera respuesta final',
  `top_p` decimal(4,3) DEFAULT NULL,
  `seed` int UNSIGNED DEFAULT '0',
  `max_attempts` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `extra_config` json DEFAULT NULL,
  `token_usage_phase` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Volcado de datos para la tabla `UserAIAgentConfigs`
--

INSERT INTO `UserAIAgentConfigs` (`id_`, `user_id_`, `agent_key`, `agent_group`, `display_name`, `description`, `model_id`, `fallback_model_id`, `model_ladder_json`, `system_instruction`, `user_prompt_template`, `temperature`, `max_tokens_prompt`, `max_tokens_output`, `top_p`, `seed`, `max_attempts`, `extra_config`, `token_usage_phase`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, 'prompt_compiler', 'chat', 'Compilador de prompts', 'IA que compila, corrige y enriquece el prompt del usuario.', 'amazon.nova-micro-v1:0', NULL, NULL, 'Eres un Ingeniero de Prompts experto. Tu ÚNICA tarea es transformar la entrada del usuario en una instrucción perfecta, clara y enriquecida para un modelo de IA avanzado.\r\nREGLAS OBLIGATORIAS:\r\n1. NUNCA repitas la pregunta del usuario tal cual. Debes REESCRIBIRLA como una instrucción directa a la IA.\r\n2. Corrige automáticamente CUALQUIER error ortográfico, gramatical o de tipeo en nombres o conceptos.\r\n3. Añade contexto profesional para garantizar la mejor respuesta posible.\r\n4. Devuelve ÚNICAMENTE el texto de la instrucción optimizada. Sin markdown, sin comillas.\r\n5. PROHIBIDO mencionar \'la sesión\', \'el contexto de la sesión\', \'esta conversación\' o \'lo que hemos hablado\' en el prompt generado. NUNCA agregues frases como \'en el contexto de la sesión actual\'. El prompt debe ser una instrucción limpia y directa.\r\n6. PREGUNTAS META-COGNITIVAS: SOLO si el usuario pregunta EXPLÍCITAMENTE sobre la conversación misma (ej: \'¿qué te he preguntado?\', \'¿de qué hemos hablado?\', \'resume la sesión\'), genera un prompt que pida un resumen de los temas tratados. Para CUALQUIER otra pregunta (historia, ciencia, programación, seguimiento de un tema), genera una instrucción de conocimiento general normal.\r\n7. INTENCIÓN ORIGINAL: Respeta SIEMPRE la intención del usuario. Si pregunta sobre Colón, genera un prompt sobre Colón. Si pregunta sobre código, genera un prompt sobre código. NUNCA cambies el tipo de respuesta que el usuario espera.', '{{compiler_context}}\r\n\r\nEntrada del usuario: \"{{user_text}}\"\r\nTarea: Transforma esta entrada en una instrucción experta, corregida y enriquecida para una IA, siguiendo estrictamente las reglas. Si la entrada es una pregunta sobre la conversación misma (meta-cognitiva), genera una instrucción que pida resumir los temas tratados, NO una pregunta enciclopédica.', 0.00, 200, NULL, 0.100, 42, 1, '{}', 'compile', 1, 10, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(2, 1, 'prompt_compiler_context_project_template', 'text_block', 'Contexto del proyecto para compilador', 'Plantilla para el contexto del proyecto enviada al compilador.', 'none', NULL, NULL, 'Contexto del proyecto: {{project_instructions}}', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, NULL, 1, 20, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(3, 1, 'prompt_compiler_context_project_none', 'text_block', 'Texto cuando no hay proyecto', 'Texto usado cuando no hay instrucciones de proyecto.', 'none', NULL, NULL, 'Ninguno', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, NULL, 1, 30, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(4, 1, 'prompt_compiler_context_recent_header', 'text_block', 'Encabezado de últimos mensajes', 'Encabezado para los últimos mensajes enviados al compilador.', 'none', NULL, NULL, 'ÚLTIMOS MENSAJES DE LA CONVERSACIÓN (para entender el contexto):', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, NULL, 1, 40, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(5, 1, 'prompt_compiler_context_recent_item_template', 'text_block', 'Plantilla de mensaje reciente', 'Plantilla para cada mensaje reciente enviado al compilador.', 'none', NULL, NULL, '[{{role_label}}]: {{content}}', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, NULL, 1, 50, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(6, 1, 'prompt_compiler_context_recent_user_label', 'text_block', 'Etiqueta usuario', 'Etiqueta para mensajes de usuario.', 'none', NULL, NULL, 'USUARIO', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, NULL, 1, 60, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(7, 1, 'prompt_compiler_context_recent_assistant_label', 'text_block', 'Etiqueta asistente', 'Etiqueta para mensajes del asistente.', 'none', NULL, NULL, 'ASISTENTE', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, NULL, 1, 70, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(8, 1, 'prompt_compiler_context_session_template', 'text_block', 'Plantilla memoria de sesión', 'Plantilla para la memoria de sesión enviada al compilador.', 'none', NULL, NULL, 'Memoria de sesión: {{session_memory}}', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, NULL, 1, 80, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(9, 1, 'prompt_compiler_fallback_template', 'text_block', 'Fallback del compilador', 'Plantilla usada si el compilador devuelve algo demasiado parecido o falla.', 'none', NULL, NULL, 'Actúa como un experto en la materia. Proporciona una respuesta muy detallada, estructurada y completa sobre: \"{{user_text}}\". Asegúrate de corregir cualquier error ortográfico o de tipeo en la consulta original y añade todo el contexto necesario.', NULL, NULL, NULL, NULL, NULL, 0, 1, NULL, NULL, 1, 90, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(10, 1, 'chat_main', 'chat', 'IA principal de respuesta', 'Plantilla principal del system prompt. Solo usa placeholders.', 'amazon.nova-micro-v1:0', NULL, NULL, '{{base_instruction}}\r\n\r\n{{procedural_memory_block}}\r\n\r\n{{session_memory_block}}\r\n\r\n{{attachment_context_block}}\r\n\r\n{{question_memory_block}}\r\n\r\n{{project_instructions_block}}\r\n\r\n{{tool_rules_block}}\r\n\r\n{{primordial_rules_block}}\r\n\r\n{{rag_context_block}}\r\n\r\n{{behavior_rules_block}}', NULL, 0.70, NULL, 1000, 0.900, 42, 1, '{\"max_rounds\": 5, \"default_max_tokens_fallback\": 1200, \"tools_enabled_only_with_project\": true}', 'respond', 1, 100, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(11, 1, 'chat_main_base', 'text_block', 'Instrucción base del chat principal', 'Instrucción base del asistente principal.', 'none', NULL, NULL, 'Eres un asistente de IA experto en programación y conocimiento general. Responde de manera directa, útil y precisa en español.', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 110, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(12, 1, 'chat_main_tool_rules', 'text_block', 'Reglas críticas de herramientas', 'Reglas obligatorias sobre uso de herramientas.', 'none', NULL, NULL, '[REGLA CRÍTICA DE HERRAMIENTAS - OBLIGATORIA]\r\nCuando el usuario solicite CREAR, MODIFICAR, EDITAR o GUARDAR un archivo de código en el proyecto, DEBES usar OBLIGATORIAMENTE la herramienta \'code_edit\' con action=\'write\' (o sin \'action\', es el valor por defecto).\r\nCuando el usuario pida VER, LEER o mostrar el contenido REAL/actual de un archivo del proyecto (no lo que tú recuerdes), usa \'code_edit\' con action=\'read\'.\r\nCuando el usuario pida ELIMINAR, BORRAR o quitar un archivo del proyecto, usa \'code_edit\' con action=\'delete\'.\r\nNUNCA respondas con el código directamente en el chat si la instrucción implica crear, modificar, leer o eliminar un archivo real del proyecto: siempre usa la herramienta.\r\nParámetros requeridos: project_id, session_id, target_filename (y \'instruction\' solo cuando action=\'write\').', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 120, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(13, 1, 'chat_main_behavior_rules', 'text_block', 'Reglas estrictas de comportamiento', 'Reglas finales de comportamiento del asistente.', 'none', NULL, NULL, '[REGLAS DE COMPORTAMIENTO ESTRICTAS]:\r\n1. CONOCIMIENTO GENERAL (PRIORIDAD MÁXIMA): Si la pregunta es sobre historia, ciencia, religión, geografía, cultura, programación o CUALQUIER tema de conocimiento, responde SIEMPRE directamente con tu conocimiento interno. NUNCA digas \'no hemos tratado este tema\' o \'no tengo información en esta sesión\'. Eso es FALSO: tienes conocimiento de entrenamiento sobre todos estos temas. Simplemente RESPONDE.\r\n2. PREGUNTAS DE SEGUIMIENTO: Si el usuario hace una pregunta que continúa o profundiza un tema ya discutido (ej: preguntó sobre Colón y ahora pregunta \'¿a dónde llegó?\' o \'¿qué idioma hablaban?\'), es una pregunta de CONOCIMIENTO GENERAL. Responde normalmente. NO es una pregunta meta-cognitiva. NO consultes la memoria de sesión para esto.\r\n3. EDICIÓN DE CÓDIGO: Para modificar un archivo, PRIMERO usa \'grep\' para obtener el código exacto. Al usar \'str_replace\', el \'old_text\' debe ser una copia CARBÓN del original, incluyendo TODOS los espacios y saltos de línea.\r\n4. PROHIBIDO PARROTEAR Y EXPLICAR MECÁNICAS: NUNCA repitas las instrucciones de este sistema. NUNCA menciones \'la memoria de esta sesión\', \'el contexto de la sesión\', \'los bloques\', \'los temas listados arriba\' ni ninguna mecánica interna. Si sabes la respuesta, simplemente RESPONDE como si siempre la hubieras sabido. No digas \'según la memoria\' ni \'aunque no esté en la sesión\'. Habla con naturalidad.\r\n5. RESPUESTA FINAL: Después de usar cualquier herramienta, explica el resultado en lenguaje natural.\r\n6. FORMATO DE ARCHIVOS: SOLO rutas de texto plano, sin botones HTML ni enlaces.\r\n7. PREGUNTAS META-COGNITIVAS (ÚNICAMENTE estas): SOLO si el usuario usa frases EXPLÍCITAS como \'¿qué te he preguntado?\', \'¿de qué hemos hablado?\', \'resume lo que hablamos en esta sesión\', \'¿qué temas tratamos aquí?\', entonces y SOLO entonces, responde con los temas de [MEMORIA DE ESTA SESIÓN]. Para TODO lo demás, responde con tu conocimiento normal.\r\n8. ANTI-ALUCINACIÓN DE SESIÓN: NUNCA inventes preguntas o temas que no estén en [MEMORIA DE ESTA SESIÓN] cuando respondas preguntas meta-cognitivas. Pero SÍ puedes y DEBES responder preguntas de conocimiento general con tu entrenamiento, aunque el tema no esté en la memoria.', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 130, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(14, 1, 'chat_main_procedural_template', 'text_block', 'Bloque memoria procedural', 'Plantilla del bloque de memoria procedural.', 'none', NULL, NULL, '[PATRONES Y PREFERENCIAS DEL USUARIO - MEMORIA PROCEDURAL]\r\nEl usuario ha establecido estos patrones a lo largo de sus conversaciones. DEBES seguirlos en TODAS tus respuestas:\r\n{{items}}\r\nEstos patrones tienen prioridad sobre tu comportamiento por defecto.', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 140, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(15, 1, 'chat_main_procedural_item_template', 'text_block', 'Item memoria procedural', 'Plantilla de cada item de memoria procedural.', 'none', NULL, NULL, '{{index}}. [{{type_label}}] {{content}}', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 150, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(16, 1, 'chat_main_procedural_labels', 'text_block', 'Etiquetas memoria procedural', 'Etiquetas para tipos de memoria procedural.', 'none', NULL, NULL, '', NULL, NULL, NULL, NULL, NULL, 0, 1, '{\"type_labels\": {\"rule\": \"REGLA\", \"pattern\": \"PATRÓN\", \"workflow\": \"FLUJO DE TRABAJO\", \"correction\": \"CORRECCIÓN\", \"preference\": \"PREFERENCIA\"}}', NULL, 1, 160, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(17, 1, 'chat_main_session_memory_template', 'text_block', 'Bloque memoria de sesión', 'Plantilla del bloque de memoria de sesión.', 'none', NULL, NULL, '[MEMORIA DE ESTA SESIÓN - SOLO ESTA, NO OTRAS]\r\nSi pregunta \'¿qué he preguntado?\' o \'¿de qué hemos hablado?\', responde EXCLUSIVAMENTE basándote en esta memoria. No inventes temas:\r\n{{session_memory}}', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 170, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(18, 1, 'chat_main_attachment_template', 'text_block', 'Bloque adjuntos', 'Plantilla del bloque de adjuntos relevantes.', 'none', NULL, NULL, '[ARCHIVOS ADJUNTOS DE ESTA SESIÓN]\r\nEl siguiente contenido proviene de archivos adjuntos reales de esta conversación. Úsalo solo si es relevante para la pregunta actual.\r\n{{attachment_context}}', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 180, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(19, 1, 'chat_main_question_memory_template', 'text_block', 'Bloque memoria selectiva', 'Plantilla del bloque de memoria selectiva de preguntas anteriores.', 'none', NULL, NULL, '[MEMORIA SELECTIVA DE PREGUNTAS ANTERIORES]\r\nLa siguiente información proviene de preguntas y respuestas anteriores del usuario.\r\nÚsala como contexto para mantener continuidad y precisión.\r\nLos fragmentos exactos contienen datos reales de respuestas previas.\r\n{{question_memory_context}}', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 190, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(20, 1, 'chat_main_project_instructions_template', 'text_block', 'Bloque instrucciones del proyecto', 'Plantilla del bloque de instrucciones del proyecto.', 'none', NULL, NULL, '[INSTRUCCIONES OBLIGATORIAS DEL PROYECTO]\r\n{{project_instructions}}\r\nDebes seguir estas reglas estrictamente en tu respuesta. Si el usuario pide código, usa el lenguaje y versiones especificadas aquí.', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 200, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(21, 1, 'chat_main_primordial_rules_template', 'text_block', 'Bloque reglas primordiales', 'Plantilla del bloque de reglas primordiales.', 'none', NULL, NULL, '[REGLAS PRIMORDIALES DEL PROYECTO (VERDAD ABSOLUTA)]\r\nEl usuario ha establecido estas reglas en sesiones anteriores. DEBES obedecerlas estrictamente por encima de cualquier otra lógica o conocimiento general:\r\n{{primordial_rules}}', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 210, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(22, 1, 'chat_main_primordial_rule_item_template', 'text_block', 'Item regla primordial', 'Plantilla de cada regla primordial.', 'none', NULL, NULL, '- [{{date}}] {{content}}', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 220, '2026-08-14 16:01:39', '2026-08-14 16:01:39'),
(23, 1, 'chat_main_rag_context_template', 'text_block', 'Bloque contexto RAG', 'Plantilla del bloque de contexto RAG de archivos indexados.', 'none', NULL, NULL, '[CONTEXTO DE ARCHIVOS]: El usuario ha proporcionado fragmentos de código. Prioriza esta información. Si la respuesta está en los fragmentos, CITA el nombre del archivo y usa ese contenido exacto.\r\n{{rag_context}}', NULL, NULL, NULL, NULL, NULL, 0, 1, '{}', NULL, 1, 230, '2026-08-14 16:01:39', '2026-08-14 16:01:39');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `UserProceduralMemory`
--

CREATE TABLE `UserProceduralMemory` (
  `id_` bigint UNSIGNED NOT NULL,
  `user_id_` int NOT NULL,
  `memory_type` enum('preference','rule','pattern','correction','workflow') NOT NULL DEFAULT 'rule',
  `content` text NOT NULL COMMENT 'La regla o patrón detectado',
  `source_session_id` int DEFAULT NULL COMMENT 'Sesión donde se detectó',
  `confidence` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT 'Veces que se ha observado este patrón',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Users`
--

CREATE TABLE `Users` (
  `id` int NOT NULL,
  `firstname` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `lastname` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `curp` varchar(18) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `gender` enum('Masculino','Femenino','Otro') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `birthdate` date DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `address` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `neighborhood` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `postalcode` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `state` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `country` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `homephone` varchar(15) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `mobilephone` varchar(15) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `role` enum('Alumno','Docente','Administración','Finanzas','Recursos Humanos','Ventas','Marketing','Soporte','Servicio Social','Otros') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `registrationdate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `profilepicture` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `chat` tinyint NOT NULL,
  `userstatus` enum('Activo','Inactivo') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `AccessControl`
--
ALTER TABLE `AccessControl`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indices de la tabla `ChatMessages`
--
ALTER TABLE `ChatMessages`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_msgs_session` (`session_id_`),
  ADD KEY `idx_msgs_user` (`user_id_`);

--
-- Indices de la tabla `ChatSessions`
--
ALTER TABLE `ChatSessions`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_chats_user` (`user_id_`),
  ADD KEY `idx_chats_updated` (`updated_at`),
  ADD KEY `idx_sessions_project` (`project_id_`),
  ADD KEY `idx_pending_summary` (`pending_summary`,`last_compressed_at`);

--
-- Indices de la tabla `ChunkEmbeddings`
--
ALTER TABLE `ChunkEmbeddings`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_ce_chunk` (`chunk_id_`),
  ADD KEY `idx_ce_model` (`model_id`);

--
-- Indices de la tabla `EmbeddingJobs`
--
ALTER TABLE `EmbeddingJobs`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `uq_embedding_target` (`target_type`,`target_id`,`model_id`),
  ADD KEY `idx_ej_status` (`status`);

--
-- Indices de la tabla `FileS3`
--
ALTER TABLE `FileS3`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `uq_files3_user_key` (`user_id_`,`Encriptado`),
  ADD KEY `user_id_` (`user_id_`),
  ADD KEY `idx_FileS3_Ruta` (`Ruta`(191)),
  ADD KEY `idx_FileS3_Found` (`Found`),
  ADD KEY `idx_FileS3_Access` (`AccessType`),
  ADD KEY `idx_FileS3_RutaFoundAccess` (`Ruta`(191),`Found`,`AccessType`),
  ADD KEY `idx_FileS3_UserRuta` (`user_id_`,`Ruta`(191),`Found`),
  ADD KEY `idx_files_user_found` (`user_id_`,`Found`),
  ADD KEY `idx_files_user_ruta` (`user_id_`,`Ruta`(191)),
  ADD KEY `idx_files_user_access_found` (`user_id_`,`AccessType`,`Found`);

--
-- Indices de la tabla `FileVersions`
--
ALTER TABLE `FileVersions`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `uq_file_version` (`project_id_`,`original_filename`,`version`),
  ADD KEY `idx_fv_project` (`project_id_`),
  ADD KEY `idx_fv_session` (`session_id_`),
  ADD KEY `fk_fv_message` (`message_id_`),
  ADD KEY `idx_fv_project_file_id` (`project_id_`,`original_filename`,`id_`),
  ADD KEY `idx_fv_status` (`status`);

--
-- Indices de la tabla `LintAttempts`
--
ALTER TABLE `LintAttempts`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_la_file` (`file_version_id_`),
  ADD KEY `idx_la_success` (`is_success`);

--
-- Indices de la tabla `PhaseCache`
--
ALTER TABLE `PhaseCache`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `uq_phase_cache` (`cache_key`),
  ADD KEY `idx_pcache_project` (`project_id_`),
  ADD KEY `idx_pcache_expires` (`expires_at`);

--
-- Indices de la tabla `ProjectContext`
--
ALTER TABLE `ProjectContext`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_pc_project` (`project_id_`);

--
-- Indices de la tabla `Projects`
--
ALTER TABLE `Projects`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `uq_projects_user_slug` (`user_id_`,`slug`),
  ADD UNIQUE KEY `uq_projects_user_rootprefix` (`user_id_`,`root_prefix`(255)),
  ADD KEY `idx_projects_user` (`user_id_`);

--
-- Indices de la tabla `ProjectSources`
--
ALTER TABLE `ProjectSources`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `uq_source_project_key` (`project_id_`,`s3_key_hash`),
  ADD KEY `idx_ps_s3key_prefix` (`s3_key`(255)),
  ADD KEY `idx_ps_project` (`project_id_`),
  ADD KEY `idx_ps_status` (`status`),
  ADD KEY `fk_ps_files3` (`files3_id_`),
  ADD KEY `idx_ps_project_filename` (`project_id_`,`filename`);

--
-- Indices de la tabla `ProjectTestCommands`
--
ALTER TABLE `ProjectTestCommands`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `uq_ptc_project_label` (`project_id_`,`label`),
  ADD KEY `idx_ptc_project_enabled` (`project_id_`,`enabled`),
  ADD KEY `fk_ptc_user` (`created_by_user_id_`);

--
-- Indices de la tabla `PromptCompilations`
--
ALTER TABLE `PromptCompilations`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_pc_session` (`session_id_`),
  ADD KEY `idx_pc_status` (`status`),
  ADD KEY `fk_pc_user_msg` (`user_msg_id`);

--
-- Indices de la tabla `S3Folders`
--
ALTER TABLE `S3Folders`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `uniq_user_prefix` (`user_id_`,`Prefix`(191)),
  ADD UNIQUE KEY `uq_s3folders_user_prefixhash` (`user_id_`,`PrefixHash`),
  ADD KEY `idx_user_parent` (`user_id_`,`ParentPrefix`(191)),
  ADD KEY `idx_user_found` (`user_id_`,`Found`),
  ADD KEY `idx_user_access` (`user_id_`,`AccessType`),
  ADD KEY `idx_user_parent_found_access` (`user_id_`,`ParentPrefix`(191),`Found`,`AccessType`),
  ADD KEY `idx_folders_user_found` (`user_id_`,`Found`);

--
-- Indices de la tabla `SessionContextBlocks`
--
ALTER TABLE `SessionContextBlocks`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_scb_session` (`session_id_`),
  ADD KEY `idx_scb_type_locked` (`block_type`,`is_locked`),
  ADD KEY `fk_scb_a_msg` (`answer_msg_id`),
  ADD KEY `idx_scb_has_embedding` (`embedding_model`),
  ADD KEY `idx_scb_memory_qa` (`question_msg_id`,`answer_msg_id`,`is_memory_summary`);

--
-- Indices de la tabla `SourceChunks`
--
ALTER TABLE `SourceChunks`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_chunks_source` (`source_id_`),
  ADD KEY `idx_chunks_project_type` (`project_id_`,`chunk_type`),
  ADD KEY `idx_chunks_name` (`name`(191)),
  ADD KEY `idx_chunks_project_name` (`project_id_`,`name`(191));

--
-- Indices de la tabla `TokenUsage`
--
ALTER TABLE `TokenUsage`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_tu_session` (`session_id_`),
  ADD KEY `idx_tu_phase` (`phase`),
  ADD KEY `fk_tu_message` (`message_id_`);

--
-- Indices de la tabla `ToolCalls`
--
ALTER TABLE `ToolCalls`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_tc_session` (`session_id_`),
  ADD KEY `idx_tc_tool` (`tool`),
  ADD KEY `idx_tc_project` (`project_id_`),
  ADD KEY `idx_tc_loop_detect` (`session_id_`,`tool`,`params_hash`,`created_at`);

--
-- Indices de la tabla `UserAIAgentConfigs`
--
ALTER TABLE `UserAIAgentConfigs`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `uq_user_ai_agent` (`user_id_`,`agent_key`),
  ADD KEY `idx_uac_user_active` (`user_id_`,`is_active`),
  ADD KEY `idx_uac_group` (`agent_group`),
  ADD KEY `idx_uac_agent_key` (`agent_key`);

--
-- Indices de la tabla `UserProceduralMemory`
--
ALTER TABLE `UserProceduralMemory`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_upm_user` (`user_id_`),
  ADD KEY `idx_upm_type_active` (`memory_type`,`is_active`),
  ADD KEY `fk_upm_session` (`source_session_id`);

--
-- Indices de la tabla `Users`
--
ALTER TABLE `Users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `AccessControl`
--
ALTER TABLE `AccessControl`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ChatMessages`
--
ALTER TABLE `ChatMessages`
  MODIFY `id_` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ChatSessions`
--
ALTER TABLE `ChatSessions`
  MODIFY `id_` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ChunkEmbeddings`
--
ALTER TABLE `ChunkEmbeddings`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `EmbeddingJobs`
--
ALTER TABLE `EmbeddingJobs`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `FileS3`
--
ALTER TABLE `FileS3`
  MODIFY `id_` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `FileVersions`
--
ALTER TABLE `FileVersions`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `LintAttempts`
--
ALTER TABLE `LintAttempts`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `PhaseCache`
--
ALTER TABLE `PhaseCache`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ProjectContext`
--
ALTER TABLE `ProjectContext`
  MODIFY `id_` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Projects`
--
ALTER TABLE `Projects`
  MODIFY `id_` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ProjectSources`
--
ALTER TABLE `ProjectSources`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ProjectTestCommands`
--
ALTER TABLE `ProjectTestCommands`
  MODIFY `id_` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `PromptCompilations`
--
ALTER TABLE `PromptCompilations`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `S3Folders`
--
ALTER TABLE `S3Folders`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `SessionContextBlocks`
--
ALTER TABLE `SessionContextBlocks`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `SourceChunks`
--
ALTER TABLE `SourceChunks`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `TokenUsage`
--
ALTER TABLE `TokenUsage`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ToolCalls`
--
ALTER TABLE `ToolCalls`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `UserAIAgentConfigs`
--
ALTER TABLE `UserAIAgentConfigs`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT de la tabla `UserProceduralMemory`
--
ALTER TABLE `UserProceduralMemory`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Users`
--
ALTER TABLE `Users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `AccessControl`
--
ALTER TABLE `AccessControl`
  ADD CONSTRAINT `AccessControl_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`);

--
-- Filtros para la tabla `ChatMessages`
--
ALTER TABLE `ChatMessages`
  ADD CONSTRAINT `fk_msgs_session` FOREIGN KEY (`session_id_`) REFERENCES `ChatSessions` (`id_`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_msgs_user` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `ChatSessions`
--
ALTER TABLE `ChatSessions`
  ADD CONSTRAINT `fk_chats_user` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sessions_project` FOREIGN KEY (`project_id_`) REFERENCES `Projects` (`id_`) ON DELETE SET NULL;

--
-- Filtros para la tabla `ChunkEmbeddings`
--
ALTER TABLE `ChunkEmbeddings`
  ADD CONSTRAINT `fk_ce_chunk` FOREIGN KEY (`chunk_id_`) REFERENCES `SourceChunks` (`id_`) ON DELETE CASCADE;

--
-- Filtros para la tabla `FileVersions`
--
ALTER TABLE `FileVersions`
  ADD CONSTRAINT `fk_fv_message` FOREIGN KEY (`message_id_`) REFERENCES `ChatMessages` (`id_`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_fv_project` FOREIGN KEY (`project_id_`) REFERENCES `Projects` (`id_`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fv_session` FOREIGN KEY (`session_id_`) REFERENCES `ChatSessions` (`id_`) ON DELETE SET NULL;

--
-- Filtros para la tabla `LintAttempts`
--
ALTER TABLE `LintAttempts`
  ADD CONSTRAINT `fk_la_file_version` FOREIGN KEY (`file_version_id_`) REFERENCES `FileVersions` (`id_`) ON DELETE CASCADE;

--
-- Filtros para la tabla `PhaseCache`
--
ALTER TABLE `PhaseCache`
  ADD CONSTRAINT `fk_pcache_project` FOREIGN KEY (`project_id_`) REFERENCES `Projects` (`id_`) ON DELETE CASCADE;

--
-- Filtros para la tabla `ProjectContext`
--
ALTER TABLE `ProjectContext`
  ADD CONSTRAINT `fk_pc_project` FOREIGN KEY (`project_id_`) REFERENCES `Projects` (`id_`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Projects`
--
ALTER TABLE `Projects`
  ADD CONSTRAINT `fk_projects_user` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `ProjectSources`
--
ALTER TABLE `ProjectSources`
  ADD CONSTRAINT `fk_ps_files3` FOREIGN KEY (`files3_id_`) REFERENCES `FileS3` (`id_`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ps_project` FOREIGN KEY (`project_id_`) REFERENCES `Projects` (`id_`) ON DELETE CASCADE;

--
-- Filtros para la tabla `ProjectTestCommands`
--
ALTER TABLE `ProjectTestCommands`
  ADD CONSTRAINT `fk_ptc_project` FOREIGN KEY (`project_id_`) REFERENCES `Projects` (`id_`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ptc_user` FOREIGN KEY (`created_by_user_id_`) REFERENCES `Users` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `PromptCompilations`
--
ALTER TABLE `PromptCompilations`
  ADD CONSTRAINT `fk_pc_session` FOREIGN KEY (`session_id_`) REFERENCES `ChatSessions` (`id_`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pc_user_msg` FOREIGN KEY (`user_msg_id`) REFERENCES `ChatMessages` (`id_`) ON DELETE CASCADE;

--
-- Filtros para la tabla `SessionContextBlocks`
--
ALTER TABLE `SessionContextBlocks`
  ADD CONSTRAINT `fk_scb_a_msg` FOREIGN KEY (`answer_msg_id`) REFERENCES `ChatMessages` (`id_`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_scb_q_msg` FOREIGN KEY (`question_msg_id`) REFERENCES `ChatMessages` (`id_`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_scb_session` FOREIGN KEY (`session_id_`) REFERENCES `ChatSessions` (`id_`) ON DELETE CASCADE;

--
-- Filtros para la tabla `SourceChunks`
--
ALTER TABLE `SourceChunks`
  ADD CONSTRAINT `fk_chunks_project` FOREIGN KEY (`project_id_`) REFERENCES `Projects` (`id_`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_chunks_source` FOREIGN KEY (`source_id_`) REFERENCES `ProjectSources` (`id_`) ON DELETE CASCADE;

--
-- Filtros para la tabla `TokenUsage`
--
ALTER TABLE `TokenUsage`
  ADD CONSTRAINT `fk_tu_message` FOREIGN KEY (`message_id_`) REFERENCES `ChatMessages` (`id_`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_tu_session` FOREIGN KEY (`session_id_`) REFERENCES `ChatSessions` (`id_`) ON DELETE CASCADE;

--
-- Filtros para la tabla `ToolCalls`
--
ALTER TABLE `ToolCalls`
  ADD CONSTRAINT `fk_tc_project` FOREIGN KEY (`project_id_`) REFERENCES `Projects` (`id_`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tc_session` FOREIGN KEY (`session_id_`) REFERENCES `ChatSessions` (`id_`) ON DELETE CASCADE;

--
-- Filtros para la tabla `UserAIAgentConfigs`
--
ALTER TABLE `UserAIAgentConfigs`
  ADD CONSTRAINT `fk_uac_user` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `UserProceduralMemory`
--
ALTER TABLE `UserProceduralMemory`
  ADD CONSTRAINT `fk_upm_session` FOREIGN KEY (`source_session_id`) REFERENCES `ChatSessions` (`id_`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_upm_user` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
