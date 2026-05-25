CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    balance INTEGER NOT NULL CHECK (balance >= 0),
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id INTEGER NOT NULL,
    receiver_id INTEGER NOT NULL,
    amount INTEGER NOT NULL CHECK (amount > 0),
    currency TEXT NOT NULL DEFAULT 'EUR' CHECK (currency = 'EUR'),
    status TEXT NOT NULL DEFAULT 'completed' CHECK (status = 'completed'),
    sender_balance_before INTEGER NOT NULL CHECK (sender_balance_before >= 0),
    sender_balance_after INTEGER NOT NULL CHECK (sender_balance_after >= 0),
    receiver_balance_before INTEGER NOT NULL CHECK (receiver_balance_before >= 0),
    receiver_balance_after INTEGER NOT NULL CHECK (receiver_balance_after >= 0),
    monitoring_status TEXT NOT NULL DEFAULT 'pending' CHECK (monitoring_status IN ('pending', 'completed', 'failed')),
    monitoring_error TEXT,
    is_suspicious INTEGER CHECK (is_suspicious IN (0, 1)),
    risk_score REAL CHECK (risk_score >= 0 AND risk_score <= 1),
    model_version TEXT,
    rule_hits TEXT,
    idempotency_key TEXT UNIQUE,
    request_hash TEXT,
    created_at TEXT NOT NULL,
    CHECK (sender_id <> receiver_id),
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (receiver_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_transactions_sender_created_at
    ON transactions (sender_id, created_at);

CREATE INDEX IF NOT EXISTS idx_transactions_receiver_created_at
    ON transactions (receiver_id, created_at);

CREATE TABLE IF NOT EXISTS transaction_features (
    transaction_id INTEGER PRIMARY KEY,
    amount INTEGER NOT NULL,
    hour_of_day INTEGER NOT NULL,
    sender_balance_before INTEGER NOT NULL,
    sender_balance_after INTEGER NOT NULL,
    receiver_balance_before INTEGER NOT NULL,
    sender_tx_count_last_hour INTEGER NOT NULL,
    sender_tx_sum_last_24h INTEGER NOT NULL,
    is_new_receiver INTEGER NOT NULL CHECK (is_new_receiver IN (0, 1)),
    created_at TEXT NOT NULL,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id)
);
