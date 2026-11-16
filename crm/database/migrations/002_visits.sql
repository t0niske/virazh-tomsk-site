-- Заходы на сайт (docs/visits-api.md): посетители, заходы, просмотры страниц, действия.
-- Связь с заявкой — по visitor_id (cookie sd_vid) и visit_id (cookie sd_sid), которые форма шлёт вместе с заявкой.

CREATE TABLE visitors (
    id VARCHAR(40) NOT NULL PRIMARY KEY,
    first_seen DATETIME NOT NULL,
    last_seen DATETIME NOT NULL,
    visits_count INT NOT NULL DEFAULT 0
){{table}};

CREATE TABLE visits (
    id {{pk}},
    sid VARCHAR(40) NOT NULL,
    vid VARCHAR(40) NOT NULL,
    started_at DATETIME NOT NULL,
    last_at DATETIME NOT NULL,
    last_ping_at DATETIME NULL,
    number INT NOT NULL DEFAULT 1,
    source VARCHAR(120) NOT NULL DEFAULT 'Прямой заход',
    referrer VARCHAR(300) NULL,
    utm TEXT NULL,
    landing VARCHAR(300) NULL,
    pages INT NOT NULL DEFAULT 0,
    active_seconds INT NOT NULL DEFAULT 0,
    events_count INT NOT NULL DEFAULT 0,
    has_tel SMALLINT NOT NULL DEFAULT 0,
    has_leave SMALLINT NOT NULL DEFAULT 0,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    device VARCHAR(16) NULL,
    browser VARCHAR(40) NULL,
    os VARCHAR(40) NULL,
    screen VARCHAR(20) NULL,
    lang VARCHAR(20) NULL,
    tz VARCHAR(50) NULL,
    touch SMALLINT NOT NULL DEFAULT 0,
    webdriver SMALLINT NOT NULL DEFAULT 0,
    ym VARCHAR(30) NULL,
    kind VARCHAR(8) NOT NULL DEFAULT 'human',
    reasons VARCHAR(255) NULL,
    checked SMALLINT NOT NULL DEFAULT 0,
    lead_id INT NULL,
    FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL
){{table}};

CREATE UNIQUE INDEX visits_sid ON visits (sid);
CREATE INDEX visits_started ON visits (started_at);
CREATE INDEX visits_vid ON visits (vid, started_at);
CREATE INDEX visits_lead ON visits (lead_id);
CREATE INDEX visits_ping ON visits (last_ping_at);

CREATE TABLE visit_pages (
    id {{pk}},
    visit_id INT NOT NULL,
    pid VARCHAR(40) NOT NULL,
    path VARCHAR(300) NOT NULL,
    title VARCHAR(200) NULL,
    opened_at DATETIME NOT NULL,
    active_seconds INT NOT NULL DEFAULT 0,
    scroll SMALLINT NOT NULL DEFAULT 0,
    FOREIGN KEY (visit_id) REFERENCES visits (id) ON DELETE CASCADE
){{table}};

CREATE INDEX visit_pages_visit ON visit_pages (visit_id, pid);

CREATE TABLE visit_events (
    id {{pk}},
    visit_id INT NOT NULL,
    page_id INT NULL,
    created_at DATETIME NOT NULL,
    name VARCHAR(20) NOT NULL,
    data VARCHAR(200) NULL,
    FOREIGN KEY (visit_id) REFERENCES visits (id) ON DELETE CASCADE
){{table}};

CREATE INDEX visit_events_visit ON visit_events (visit_id, id);
CREATE INDEX visit_events_name ON visit_events (name, created_at);

ALTER TABLE leads ADD COLUMN visitor_id VARCHAR(40) NULL;
ALTER TABLE leads ADD COLUMN visit_id VARCHAR(40) NULL;
CREATE INDEX leads_visitor ON leads (visitor_id);
