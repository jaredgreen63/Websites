<?php
declare(strict_types=1);

/**
 * Database access.
 *
 * Targets MySQL, which is what cPanel provides. SQLite is supported too so the
 * same code can be exercised without a server — the two differ in exactly one
 * place, the auto-increment column, which `schema()` handles.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = config();

    if (($config['db_driver'] ?? 'mysql') === 'sqlite') {
        $dsn = 'sqlite:' . $config['db_path'];
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            (int) ($config['db_port'] ?? 3306),
            $config['db_name'],
        );
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements, so a parameter can never be parsed as SQL.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    migrate($pdo);
    return $pdo;
}

/** Create the table on first use, so there is no separate install step. */
function migrate(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $id = $driver === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $engine = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS appointments (
            id {$id},
            created_at        DATETIME     NOT NULL,
            updated_at        DATETIME     NULL,
            status            VARCHAR(16)  NOT NULL DEFAULT 'new',
            source            VARCHAR(10)  NOT NULL DEFAULT 'web',
            name              VARCHAR(120) NOT NULL,
            phone             VARCHAR(40)  NULL,
            email             VARCHAR(160) NULL,
            vehicle_id        VARCHAR(40)  NULL,
            vehicle_label     VARCHAR(200) NULL,
            preferred_day     VARCHAR(20)  NULL,
            preferred_day_label VARCHAR(40) NULL,
            preferred_time    VARCHAR(20)  NULL,
            trade_in          VARCHAR(60)  NULL,
            message           TEXT         NULL,
            vehicle_location  VARCHAR(200) NULL,
            submitted_from    VARCHAR(400) NULL,
            source_ip         VARCHAR(45)  NULL,
            user_agent        VARCHAR(300) NULL,
            admin_notes       TEXT         NULL
        ){$engine}
    ");

    // A visit counter, and room for any other single number worth keeping.
    // Separate from appointments because it is written on every visit and read
    // on every page, while that table is written rarely and read by one person.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS counters (
            name       VARCHAR(40) NOT NULL PRIMARY KEY,
            value      BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NULL
        ){$engine}
    ");

    // Columns added after the first release. A table created before they
    // existed gets them here; one created above already has them and the ALTER
    // fails harmlessly. Neither driver has a portable ADD COLUMN IF NOT EXISTS.
    $added = [
        'source' => "VARCHAR(10) NOT NULL DEFAULT 'web'",
    ];
    foreach ($added as $column => $definition) {
        try {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN {$column} {$definition}");
        } catch (PDOException) {
            // Already there.
        }
    }

    // Indexed because the admin list always sorts by one and filters by the other.
    foreach (['created_at', 'status'] as $column) {
        try {
            $pdo->exec("CREATE INDEX idx_appointments_{$column} ON appointments ({$column})");
        } catch (PDOException) {
            // Already exists. MySQL has no portable CREATE INDEX IF NOT EXISTS.
        }
    }
}

/** Valid workflow states, in the order a lead moves through them. */
function statuses(): array
{
    return ['new', 'contacted', 'scheduled', 'sold', 'closed'];
}

/**
 * Add one to a counter and return its new value.
 *
 * The increment happens inside the database, not by reading then writing, so
 * two visitors landing at the same moment cannot both read 41 and both write
 * 42. MySQL and SQLite spell the upsert differently; the names are literals
 * from this file, never from a request.
 */
function bump_counter(PDO $pdo, string $name): int
{
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'INSERT INTO counters (name, value, updated_at) VALUES (?, 1, ?)
           ON CONFLICT(name) DO UPDATE SET value = value + 1, updated_at = excluded.updated_at'
        : 'INSERT INTO counters (name, value, updated_at) VALUES (?, 1, ?)
           ON DUPLICATE KEY UPDATE value = value + 1, updated_at = VALUES(updated_at)';

    $pdo->prepare($sql)->execute([$name, $now]);
    return read_counter($pdo, $name);
}

/** The counter's current value, or 0 if it has never been written. */
function read_counter(PDO $pdo, string $name): int
{
    $statement = $pdo->prepare('SELECT value FROM counters WHERE name = ?');
    $statement->execute([$name]);
    return (int) $statement->fetchColumn();
}
