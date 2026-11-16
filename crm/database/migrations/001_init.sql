-- Система заявок: начальная схема.
-- Пишем SQL, общий для SQLite и MySQL. Мигратор подставляет:
--   {{pk}}    — автоинкрементный первичный ключ;
--   {{table}} — параметры таблицы (для MySQL — InnoDB и utf8mb4).
-- Время хранится строкой 'Y-m-d H:i:s' в часовом поясе автошколы (Asia/Tomsk).

CREATE TABLE staff (
    id {{pk}},
    login VARCHAR(64) NOT NULL,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(16) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    max_user_id VARCHAR(32) NULL,
    active SMALLINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL
){{table}};

CREATE UNIQUE INDEX staff_login ON staff (login);

CREATE TABLE leads (
    id {{pk}},
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_request_at DATETIME NOT NULL,
    request_count INT NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(16) NOT NULL,
    phone_raw VARCHAR(64) NULL,
    service VARCHAR(150) NULL,
    page_url VARCHAR(500) NULL,
    contact_time VARCHAR(16) NOT NULL DEFAULT 'now',
    channel VARCHAR(8) NOT NULL DEFAULT 'call',
    utm_source VARCHAR(150) NULL,
    utm_medium VARCHAR(150) NULL,
    utm_campaign VARCHAR(150) NULL,
    utm_content VARCHAR(150) NULL,
    utm_term VARCHAR(150) NULL,
    yclid VARCHAR(64) NULL,
    source VARCHAR(64) NOT NULL DEFAULT 'Сайт',
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    search VARCHAR(255) NOT NULL DEFAULT '',
    off_hours SMALLINT NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'new',
    assigned_to INT NULL,
    attempt INT NOT NULL DEFAULT 0,
    due_at DATETIME NOT NULL,
    next_action VARCHAR(16) NULL,
    next_action_at DATETIME NULL,
    notified_at DATETIME NULL,
    reminded_level SMALLINT NOT NULL DEFAULT 0,
    taken_at DATETIME NULL,
    first_action_at DATETIME NULL,
    contacted_at DATETIME NULL,
    contacted_via VARCHAR(8) NULL,
    closed_at DATETIME NULL,
    refusal_reason VARCHAR(150) NULL,
    max_user_id VARCHAR(32) NULL,
    max_chat_id VARCHAR(32) NULL,
    max_started_at DATETIME NULL,
    card_mid VARCHAR(64) NULL,
    FOREIGN KEY (assigned_to) REFERENCES staff (id) ON DELETE SET NULL
){{table}};

CREATE INDEX leads_phone ON leads (phone, last_request_at);
CREATE INDEX leads_status ON leads (status, next_action_at);
CREATE INDEX leads_created ON leads (created_at);
CREATE INDEX leads_max_user ON leads (max_user_id);

-- История заявки: создана, SMS, взята, звонок, сообщения MAX, статусы, комментарии.
CREATE TABLE lead_events (
    id {{pk}},
    lead_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    type VARCHAR(24) NOT NULL,
    staff_id INT NULL,
    actor VARCHAR(100) NULL,
    text TEXT NULL,
    data TEXT NULL,
    FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE SET NULL
){{table}};

CREATE INDEX lead_events_lead ON lead_events (lead_id, id);
CREATE INDEX lead_events_type ON lead_events (type, created_at);

-- Все SMS: для лимита «одно SMS о приёме заявки на номер в сутки» и отчётов.
CREATE TABLE sms_messages (
    id {{pk}},
    created_at DATETIME NOT NULL,
    lead_id INT NULL,
    phone VARCHAR(16) NOT NULL,
    kind VARCHAR(24) NOT NULL,
    text VARCHAR(500) NOT NULL,
    status VARCHAR(16) NOT NULL,
    provider_id VARCHAR(64) NULL,
    error VARCHAR(255) NULL,
    FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL
){{table}};

CREATE INDEX sms_messages_phone ON sms_messages (phone, kind, created_at);

-- Какое сообщение MAX к какой заявке относится: ответ на него в чате уходит клиенту.
CREATE TABLE max_messages (
    mid VARCHAR(64) NOT NULL PRIMARY KEY,
    lead_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE
){{table}};

CREATE TABLE settings (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    value TEXT NULL,
    updated_at DATETIME NOT NULL
){{table}};

-- Журнал действий в админке и системы.
CREATE TABLE audit_log (
    id {{pk}},
    created_at DATETIME NOT NULL,
    staff_id INT NULL,
    actor VARCHAR(100) NULL,
    action VARCHAR(32) NOT NULL,
    lead_id INT NULL,
    details VARCHAR(1000) NULL,
    ip VARCHAR(45) NULL
){{table}};

CREATE INDEX audit_log_created ON audit_log (created_at);

-- Счётчики для лимитов частоты (форма, вход в админку).
CREATE TABLE rate_hits (
    id {{pk}},
    bucket VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL
){{table}};

CREATE INDEX rate_hits_bucket ON rate_hits (bucket, created_at);
