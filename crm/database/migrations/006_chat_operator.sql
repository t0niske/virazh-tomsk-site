-- Оператор в чате (17.11.2026): администратор отвечает посетителю из /crm/chat/N, ответ приходит в окно чата на сайте.
-- chats.operator_at — последний ответ администратора: пока с него не прошло 30 минут, ИИ молчит и сообщения ждут человека.
-- chat_messages.staff_id — кто из сотрудников ответил (роль operator); посетитель видит только «Администратор».
-- Роли сообщений теперь: user, bot, system, operator, notice (служебное сообщение посетителю, например «администратор вышел»).
ALTER TABLE chats ADD COLUMN operator_at DATETIME NULL;
ALTER TABLE chat_messages ADD COLUMN staff_id INT NULL;
