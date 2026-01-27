-- =====================================================
-- AI Chatbot Database Schema
-- Simplified schema with session-based auth
-- =====================================================

PRAGMA foreign_keys = OFF;

-- =====================================================
-- Users table (simplified for session-based auth)
-- =====================================================

CREATE TABLE "users" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "email" TEXT NOT NULL COLLATE NOCASE CHECK (LENGTH("email") <= 249),
    "password_hash" TEXT NOT NULL DEFAULT '' COLLATE BINARY CHECK (LENGTH("password_hash") <= 255),
    "is_guest" INTEGER NOT NULL DEFAULT 0 CHECK ("is_guest" >= 0 AND "is_guest" <= 1),
    "created_at" TEXT NOT NULL DEFAULT (datetime('now')),
    CONSTRAINT "users_email_uq" UNIQUE ("email")
);
CREATE INDEX "users_email_ix" ON "users" ("email");

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

-- Rate limiting
CREATE TABLE "rate_limits" (
    "user_id" INTEGER NOT NULL,
    "date" TEXT NOT NULL,  -- YYYY-MM-DD format
    "message_count" INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY ("user_id", "date"),
    FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE
);

PRAGMA foreign_keys = ON;
