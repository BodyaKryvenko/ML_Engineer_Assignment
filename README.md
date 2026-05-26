# Transaction Monitoring Backend

Users transfer money to each other, and every transfer is monitored for
suspicious activity by two independent signals - a few explainable rules and a
logistic-regression risk score. Each transaction is also turned into features and
labelled, so the data to train/retrain a supervised fraud model falls out as a
by-product.

PHP 8.2, Slim 4, SQLite. No GUI or auth - users are identified by id, as allowed.

## Starting

```bash
composer install
php scripts/migrate.php          # creates var/database.sqlite and the schema if absent
php -S localhost:8080 -t public
vendor/bin/phpunit               # tests
```

`migrate.php` creates the SQLite file and tables when they don't exist (it uses
`CREATE TABLE IF NOT EXISTS`); it can add missing tables to an existing database
but won't clear existing data. So delete `var/database.sqlite` if you want a clean
schema. This file isn't committed.

## End to end

```bash
# create two users (balances in integer cents: 300000 = €3000.00)
curl -X POST localhost:8080/users -H 'Content-Type: application/json' -d '{"username":"alice","balance":300000}'
curl -X POST localhost:8080/users -H 'Content-Type: application/json' -d '{"username":"bob","balance":0}'

# transfer €30 (optional Idempotency-Key makes the call safe to retry)
curl -X POST localhost:8080/transactions -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: abc-123' \
  -d '{"sender_id":1,"receiver_id":2,"amount":3000,"currency":"EUR"}'

# a reviewer confirms a label, after that the training set picks it up
curl -X PATCH localhost:8080/transactions/1/label -H 'Content-Type: application/json' -d '{"label":"fraud"}'
curl localhost:8080/ml/training-dataset
```

## API

JSON in/out. Amounts are integer cents.

| Method | Path | Purpose                                |
|--------|------|----------------------------------------|
| `POST` | `/users` | Creates a user (`username`, `balance`) |
| `GET`  | `/users/{id}` | Gets a user                            |
| `POST` | `/transactions` | Creates a transfer.                    |
| `GET`  | `/transactions/{id}` | Transaction and monitoring result      |
| `GET`  | `/users/{id}/transactions` | User's history                         |
| `PATCH`| `/transactions/{id}/label` | Labels transaction `legit` / `fraud`   |
| `GET`  | `/ml/training-dataset` | Exports the labelled feature set       |
| `GET`  | `/health` | Liveness and DB check                  |

## Design decisions

- **Integer cents, not floats** - from experience, money arithmetic in floats can have rounding
  bugs; amounts are validated as integers at the boundary.
- **Atomic transfers with balance snapshots** - a transfer runs in one
  `BEGIN IMMEDIATE` transaction so the write lock is taken before balances are
  read (no concurrent double-spend), balances move via SQL deltas with a
  `CHECK (balance >= 0)` backstop, and the before/after balances are recorded on
  the row. After commit the live balances have changed, so the row is the only
  place the "before" values still exist - and they're trustworthy because the
  lock made the read authoritative.
- **Monitoring runs after commit, behind an event** - a `TransactionCreated`
  event triggers monitoring once the transfer is committed, so a slow or failing
  monitor never holds the write lock or undoes money movement. It's synchronous
  now; the event boundary keeps a future move to a queue contained (a
  queue-backed dispatcher plus a worker and idempotent retries) rather than
  rewiring the transfer path.
- **Rules and the model are independent signals** - rules are explainable hard
  logic (`HIGH_AMOUNT`, `ROUND_AMOUNT`); the model is a logistic regression over
  selected features (amount, sender velocity, 24h volume, new-recipient,
  night-time), not the rule thresholds, so it catches patterns the rules miss
  (e.g. a burst of transfers to a new recipient). Flagged if a rule fires or
  the score reaches or exceeds 0.7.
- **Point-in-time features** - the transaction's own amount and balances are
  legitimate inputs, but the historical aggregates (velocity, 24h volume,
  new-recipient) only count earlier transactions (`id < current`). This keeps
  the behavioural baseline separate from the transaction being evaluated and
  gives the exported training data the same feature meaning as live scoring.
- **Monitoring failure can't undo a transfer** - the money already moved, so the
  handler records `monitoring_status = 'failed'` rather than letting the error
  bubble up. The transfer still succeeds; the failed record can be reprocessed by
  a future retry mechanism.
- **Idempotency by key and request hash** - a repeated `Idempotency-Key` with the
  same body returns the original transaction; the same key with a different body
  is rejected (`409`), backed by a `UNIQUE` constraint.
- **Only confirmed labels are exported** - transactions start `unknown`; a
  reviewer confirms `legit`/`fraud`, and only those are exported, so the model is
  never trained on unreviewed data.

## The model

A logistic regression whose weights live in a versioned JSON artifact, served
in-process via an `InferenceClient` interface. **The weights are hand-tuned, not
fit on data** - they stand in for a trained model (as assignment allows mocking
inference). What's real is the serving path: swapping in a compatible logistic
model that uses the same features is just a new weights file, and a different
model or a remote model server is one new `InferenceClient`. Each transaction
records the `model_version` that scored it.

## Limitations / next steps

- SQLite is single-writer; for a larger deployment I'd move to Postgres. If
  SQLite stayed, WAL mode and a busy timeout would improve local concurrency.
- Monitoring is synchronous. The event boundary is there so it can move to a
  queue + worker, which would also need idempotent feature/label inserts and a
  retry path (there's no retry mechanism).
- Velocity features hit the DB per transaction; at volume I'd cache the counters.
- The model is hand-tuned, not trained.
- One currency (EUR), enforced by a `CHECK`; no FX.

## Layout

```
public/index.php     # Slim bootstrap, routes, wiring
scripts/migrate.php  # applies migrations.sql
resources/models/    # model weights (versioned JSON)
src/Database/        # PDO connection and schema
src/Repository/      # Transaction, Feature, Label data access
src/Event/           # TransactionCreated and synchronous dispatcher
src/Monitoring/      # FeatureExtractor, InferenceClient and mock, MonitoringHandler
tests/               # atomicity, feature extraction, monitoring handler
```
