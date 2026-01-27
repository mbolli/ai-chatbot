-- =====================================================
-- AI Chatbot Database Schema
-- Combined: PHP-Auth tables + Application tables
-- =====================================================

PRAGMA foreign_keys = OFF;

-- =====================================================
-- PHP-Auth tables (from delight-im/auth SQLite schema)
-- Source: https://github.com/delight-im/PHP-Auth/blob/master/Database/SQLite.sql
-- =====================================================

CREATE TABLE "users" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "email" TEXT NOT NULL COLLATE NOCASE CHECK (LENGTH("email") <= 249),
    "password" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("password") <= 255),
    "username" TEXT DEFAULT NULL COLLATE NOCASE CHECK (LENGTH("username") <= 100),
    "status" INTEGER NOT NULL CHECK ("status" >= 0) DEFAULT 0,
    "verified" INTEGER NOT NULL CHECK ("verified" >= 0 AND "verified" <= 1) DEFAULT 0,
    "resettable" INTEGER NOT NULL CHECK ("resettable" >= 0 AND "resettable" <= 1) DEFAULT 1,
    "roles_mask" INTEGER NOT NULL CHECK ("roles_mask" >= 0) DEFAULT 0,
    "registered" INTEGER NOT NULL CHECK ("registered" >= 0),
    "last_login" INTEGER CHECK ("last_login" >= 0) DEFAULT NULL,
    "force_logout" INTEGER NOT NULL CHECK ("force_logout" >= 0) DEFAULT 0,
    CONSTRAINT "users_email_uq" UNIQUE ("email")
);

CREATE TABLE "users_2fa" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "mechanism" INTEGER NOT NULL CHECK ("mechanism" >= 0),
    "seed" TEXT DEFAULT NULL COLLATE BINARY CHECK (LENGTH("seed") <= 255),
    "created_at" INTEGER NOT NULL CHECK ("created_at" >= 0),
    "expires_at" INTEGER CHECK ("expires_at" >= 0) DEFAULT NULL,
    CONSTRAINT "users_2fa_user_id_mechanism_uq" UNIQUE ("user_id", "mechanism")
);

CREATE TABLE "users_audit_log" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user_id" INTEGER DEFAULT NULL CHECK ("user_id" >= 0),
    "event_at" INTEGER NOT NULL CHECK ("event_at" >= 0),
    "event_type" TEXT NOT NULL COLLATE NOCASE CHECK (LENGTH("event_type") <= 128),
    "admin_id" INTEGER DEFAULT NULL CHECK ("admin_id" >= 0),
    "ip_address" TEXT DEFAULT NULL COLLATE NOCASE CHECK (LENGTH("ip_address") <= 49),
    "user_agent" TEXT DEFAULT NULL,
    "details_json" TEXT DEFAULT NULL
);
CREATE INDEX "users_audit_log_event_at_ix" ON "users_audit_log" ("event_at");
CREATE INDEX "users_audit_log_user_id_event_at_ix" ON "users_audit_log" ("user_id", "event_at");
CREATE INDEX "users_audit_log_user_id_event_type_event_at_ix" ON "users_audit_log" ("user_id", "event_type", "event_at");

CREATE TABLE "users_confirmations" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "email" TEXT NOT NULL COLLATE NOCASE CHECK (LENGTH("email") <= 249),
    "selector" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("selector") <= 16),
    "token" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("token") <= 255),
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0),
    CONSTRAINT "users_confirmations_selector_uq" UNIQUE ("selector")
);
CREATE INDEX "users_confirmations_email_expires_ix" ON "users_confirmations" ("email", "expires");
CREATE INDEX "users_confirmations_user_id_ix" ON "users_confirmations" ("user_id");

CREATE TABLE "users_otps" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "mechanism" INTEGER NOT NULL CHECK ("mechanism" >= 0),
    "single_factor" INTEGER NOT NULL CHECK ("single_factor" >= 0 AND "single_factor" <= 1) DEFAULT 0,
    "selector" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("selector") <= 24),
    "token" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("token") <= 255),
    "expires_at" INTEGER CHECK ("expires_at" >= 0) DEFAULT NULL
);
CREATE INDEX "users_otps_user_id_mechanism_ix" ON "users_otps" ("user_id", "mechanism");
CREATE INDEX "users_otps_selector_user_id_ix" ON "users_otps" ("selector", "user_id");

CREATE TABLE "users_remembered" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user" INTEGER NOT NULL CHECK ("user" >= 0),
    "selector" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("selector") <= 24),
    "token" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("token") <= 255),
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0),
    CONSTRAINT "users_remembered_selector_uq" UNIQUE ("selector")
);
CREATE INDEX "users_remembered_user_ix" ON "users_remembered" ("user");

CREATE TABLE "users_resets" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user" INTEGER NOT NULL CHECK ("user" >= 0),
    "selector" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("selector") <= 20),
    "token" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("token") <= 255),
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0),
    CONSTRAINT "users_resets_selector_uq" UNIQUE ("selector")
);
CREATE INDEX "users_resets_user_expires_ix" ON "users_resets" ("user", "expires");

CREATE TABLE "users_throttling" (
    "bucket" TEXT PRIMARY KEY NOT NULL COLLATE BINARY CHECK (LENGTH("bucket") <= 44),
    "tokens" REAL NOT NULL CHECK ("tokens" >= 0),
    "replenished_at" INTEGER NOT NULL CHECK ("replenished_at" >= 0),
    "expires_at" INTEGER NOT NULL CHECK ("expires_at" >= 0)
);
CREATE INDEX "users_throttling_expires_at_ix" ON "users_throttling" ("expires_at");

-- =====================================================
-- Application tables
-- =====================================================

-- Chats (conversations)
CREATE TABLE "chats" (
    "id" TEXT PRIMARY KEY NOT NULL,
    "user_id" INTEGER NOT NULL,
    "title" TEXT DEFAULT NULL,
    "model" TEXT NOT NULL DEFAULT 'claude-3-5-sonnet',
    "visibility" TEXT NOT NULL DEFAULT 'private' CHECK ("visibility" IN ('private', 'public')),
    "created_at" INTEGER NOT NULL,
    "updated_at" INTEGER NOT NULL,
    FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE
);
CREATE INDEX "chats_user_id_updated_at_ix" ON "chats" ("user_id", "updated_at" DESC);

-- Messages within chats
CREATE TABLE "messages" (
    "id" TEXT PRIMARY KEY NOT NULL,
    "chat_id" TEXT NOT NULL,
    "role" TEXT NOT NULL CHECK ("role" IN ('user', 'assistant', 'system')),
    "content" TEXT NOT NULL,
    "parts" TEXT DEFAULT NULL,  -- JSON array of message parts (text, tool calls, etc.)
    "created_at" INTEGER NOT NULL,
    FOREIGN KEY ("chat_id") REFERENCES "chats" ("id") ON DELETE CASCADE
);
CREATE INDEX "messages_chat_id_created_at_ix" ON "messages" ("chat_id", "created_at");

-- Documents (artifacts) with versioning
CREATE TABLE "documents" (
    "id" TEXT PRIMARY KEY NOT NULL,
    "chat_id" TEXT NOT NULL,
    "message_id" TEXT DEFAULT NULL,
    "kind" TEXT NOT NULL CHECK ("kind" IN ('text', 'code', 'sheet', 'image')),
    "title" TEXT NOT NULL,
    "language" TEXT DEFAULT NULL,  -- For code: 'python', 'javascript', etc.
    "created_at" INTEGER NOT NULL,
    "updated_at" INTEGER NOT NULL,
    FOREIGN KEY ("chat_id") REFERENCES "chats" ("id") ON DELETE CASCADE,
    FOREIGN KEY ("message_id") REFERENCES "messages" ("id") ON DELETE SET NULL
);
CREATE INDEX "documents_chat_id_ix" ON "documents" ("chat_id");

-- Document versions (for undo/redo)
CREATE TABLE "document_versions" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "document_id" TEXT NOT NULL,
    "content" TEXT NOT NULL,
    "version" INTEGER NOT NULL DEFAULT 1,
    "created_at" INTEGER NOT NULL,
    FOREIGN KEY ("document_id") REFERENCES "documents" ("id") ON DELETE CASCADE
);
CREATE INDEX "document_versions_document_id_version_ix" ON "document_versions" ("document_id", "version" DESC);

-- Votes on messages
CREATE TABLE "votes" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "chat_id" TEXT NOT NULL,
    "message_id" TEXT NOT NULL,
    "user_id" INTEGER NOT NULL,
    "is_upvote" INTEGER NOT NULL CHECK ("is_upvote" >= 0 AND "is_upvote" <= 1),
    "created_at" INTEGER NOT NULL,
    CONSTRAINT "votes_message_user_uq" UNIQUE ("message_id", "user_id"),
    FOREIGN KEY ("chat_id") REFERENCES "chats" ("id") ON DELETE CASCADE,
    FOREIGN KEY ("message_id") REFERENCES "messages" ("id") ON DELETE CASCADE,
    FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE
);

-- Suggestions (AI-generated action suggestions)
CREATE TABLE "suggestions" (
    "id" TEXT PRIMARY KEY NOT NULL,
    "document_id" TEXT NOT NULL,
    "content" TEXT NOT NULL,  -- JSON with suggestion details
    "status" TEXT NOT NULL DEFAULT 'pending' CHECK ("status" IN ('pending', 'accepted', 'rejected')),
    "created_at" INTEGER NOT NULL,
    FOREIGN KEY ("document_id") REFERENCES "documents" ("id") ON DELETE CASCADE
);
CREATE INDEX "suggestions_document_id_status_ix" ON "suggestions" ("document_id", "status");

-- Rate limiting (application-level, supplements PHP-Auth throttling)
CREATE TABLE "rate_limits" (
    "user_id" INTEGER NOT NULL,
    "date" TEXT NOT NULL,  -- YYYY-MM-DD format
    "message_count" INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY ("user_id", "date"),
    FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE
);

PRAGMA foreign_keys = ON;
