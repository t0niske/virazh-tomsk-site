-- Чат-бот поддержки на сайте (docs/chat-api.md). Текст хранится уже без телефонов, почты, ссылок и номеров.

CREATE TABLE chats (
    id {{pk}},
    chat_id VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    last_at DATETIME NOT NULL,
    messages INT NOT NULL DEFAULT 0,
    operator SMALLINT NOT NULL DEFAULT 0,
    first_text VARCHAR(200) NULL,
    lead_id INT NULL,
    FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL
){{table}};

CREATE UNIQUE INDEX chats_chat_id ON chats (chat_id);
CREATE INDEX chats_created ON chats (created_at);
CREATE INDEX chats_lead ON chats (lead_id);

-- role: user — посетитель, bot — ответ ИИ, system — служебный ответ (ошибка, лимит), без обращения к ИИ.
CREATE TABLE chat_messages (
    id {{pk}},
    chat_ref INT NOT NULL,
    created_at DATETIME NOT NULL,
    role VARCHAR(10) NOT NULL,
    text TEXT NOT NULL,
    operator SMALLINT NOT NULL DEFAULT 0,
    FOREIGN KEY (chat_ref) REFERENCES chats (id) ON DELETE CASCADE
){{table}};

CREATE INDEX chat_messages_chat ON chat_messages (chat_ref, id);
CREATE INDEX chat_messages_role ON chat_messages (role, created_at);

ALTER TABLE leads ADD COLUMN chat_id VARCHAR(40) NULL;
CREATE INDEX leads_chat ON leads (chat_id);
