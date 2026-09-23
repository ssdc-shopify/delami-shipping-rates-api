<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database as DatabaseConfig;

/**
 * Build a ready-to-use SQLite database from nothing.
 *
 *   php spark db:fresh-sqlite [--file delami.sqlite] [--force] [--stores]
 *
 * Creates the file, runs every migration against it, enables WAL, and seeds
 * an admin user to log in with. This is what a new server needs; there is no
 * other way to get a usable database now that the app no longer exports one
 * from MySQL.
 */
class DbFreshSqlite extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:fresh-sqlite';
    protected $description = 'Create a fresh SQLite database: schema, WAL, and an admin login.';
    protected $usage       = 'db:fresh-sqlite [--file delami.sqlite] [--force] [--stores]';
    protected $options     = [
        '--file'   => 'Filename under writable/database/ (default: DB_DATABASE, else delami.sqlite)',
        '--force'  => 'Replace the file if it already exists. Destroys its data.',
        '--stores' => 'Also seed the four legacy demo stores (not wanted on a real install).',
    ];

    public function run(array $params)
    {
        $file = trim((string) (CLI::getOption('file') ?: env('DB_DATABASE') ?: 'delami.sqlite'));
        $path = WRITEPATH . 'database/' . basename($file);

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true)) {
            CLI::error('Could not create ' . dirname($path));

            return EXIT_ERROR;
        }

        if (is_file($path)) {
            if (! CLI::getOption('force')) {
                CLI::error(basename($path) . ' already exists. Pass --force to replace it.');
                CLI::write('That deletes every order, waybill and store token in it.');

                return EXIT_ERROR;
            }

            // Confirm out loud in production. --force is easy to leave in a
            // shell history or a deploy script, and this is the one command
            // that can destroy the live database.
            if (ENVIRONMENT === 'production' && CLI::prompt('Replace the PRODUCTION database at ' . $path . '?', ['n', 'y']) !== 'y') {
                CLI::write('Aborted.');

                return EXIT_SUCCESS;
            }

            // WAL leaves siblings behind; a stale -wal against a new database
            // is a corrupt database.
            foreach ([$path, $path . '-wal', $path . '-shm'] as $stale) {
                if (is_file($stale)) {
                    unlink($stale);
                }
            }
        }

        CLI::write('Building ' . CLI::color(basename($path), 'yellow') . ' …');

        if (! $this->spark('migrate --all', $path, 'Migrations complete.')) {
            return EXIT_ERROR;
        }
        CLI::write('  schema      ' . CLI::color('ok', 'green'));

        if (! $this->spark('db:seed App\\\\Database\\\\Seeds\\\\AdminUserSeeder', $path, 'Admin user', $seedOutput)) {
            return EXIT_ERROR;
        }
        CLI::write('  admin user  ' . CLI::color('ok', 'green'));

        if (CLI::getOption('stores') && ! $this->spark('db:seed App\\\\Database\\\\Seeds\\\\StoreSeeder', $path, '')) {
            return EXIT_ERROR;
        }

        if (! $this->enableWal($path)) {
            return EXIT_ERROR;
        }
        CLI::write('  WAL         ' . CLI::color('ok', 'green'));

        CLI::write('');
        CLI::write(CLI::color('Ready: ' . $path, 'green'));

        // The generated password is printed once by the seeder and stored
        // only as a hash, so it is repeated here rather than lost in the
        // subprocess output.
        foreach (explode("\n", $seedOutput ?? '') as $line) {
            if (str_contains($line, 'password') || str_contains($line, 'Admin user')) {
                CLI::write('  ' . trim($line));
            }
        }

        CLI::write('');
        CLI::write('Set DB_CONNECTION = sqlite and DB_DATABASE = ' . basename($path) . ' in .env.');

        return EXIT_SUCCESS;
    }

    /**
     * Run a spark command against the new file in a separate process.
     *
     * A subprocess because this process already resolved its own connection
     * at boot; pointing the running instance at a different file mid-command
     * is not something the framework supports.
     *
     * @param string      $expect substring that must appear in the output for
     *                            the run to count as successful
     * @param string|null $output raw output, for the caller to mine
     */
    private function spark(string $command, string $path, string $expect, ?string &$output = null): bool
    {
        exec(sprintf(
            'DB_CONNECTION=sqlite DB_DATABASE=%s %s %s %s 2>&1',
            escapeshellarg($path),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(ROOTPATH . 'spark'),
            $command,
        ), $lines, $status);

        $output = implode("\n", $lines);

        // spark exits 0 even when a migration or a seeder throws, so the exit
        // code alone would report a half-built database as a success. The
        // expected line is the only trustworthy signal.
        if ($status === 0 && ($expect === '' || str_contains($output, $expect))) {
            return true;
        }

        CLI::error('Failed: spark ' . $command);
        CLI::write($output);

        return false;
    }

    /**
     * Turn on write-ahead logging and prove it took.
     *
     * Without WAL every reader blocks on the writer's exclusive lock, so one
     * AWB booking freezes the whole admin. The setting lives inside the
     * database file, so it survives being copied to the server and only has
     * to be set once.
     */
    private function enableWal(string $path): bool
    {
        $db = db_connect(['database' => $path] + config(DatabaseConfig::class)->sqlite, false);

        $db->query('PRAGMA journal_mode = WAL');
        $mode = $db->query('PRAGMA journal_mode')->getRowArray();

        if (strtolower($mode['journal_mode'] ?? '') !== 'wal') {
            CLI::error('Could not enable WAL (got "' . ($mode['journal_mode'] ?? '?') . '").');

            return false;
        }

        return true;
    }
}
