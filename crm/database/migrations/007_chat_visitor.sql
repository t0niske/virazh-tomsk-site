-- Путь посетителя на странице чата (17.11.2026): с какого захода он писал — cookie счётчика sd_vid и sd_sid
-- (docs/visits-api.md). По ним /crm/chat/N показывает, откуда пришёл человек и какие страницы смотрел.
ALTER TABLE chats ADD COLUMN visitor_id VARCHAR(40) NULL;
ALTER TABLE chats ADD COLUMN visit_id VARCHAR(40) NULL;
CREATE INDEX chats_visitor ON chats (visitor_id);
