-- «Придёт на договор»: когда ждать клиента — текст, который пишет администратор («во вторник после 15:00»).
ALTER TABLE leads ADD COLUMN contract_note VARCHAR(200) NULL;
