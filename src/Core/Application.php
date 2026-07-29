<?php

declare(strict_types=1);

namespace NightCore\Core;

use NightCore\Domain\Accounts\AccountRepository;
use NightCore\Domain\Accounts\AccountService;
use NightCore\Domain\Accounts\AuthRateLimiter;
use NightCore\Domain\Profiles\ProfileRepository;
use NightCore\Domain\Profiles\ProfileService;
use NightCore\Security\AccountAuthenticator;
use NightCore\Security\PasswordService;
use PDO;

final class Application
{
    private SchemaInspector $schema;
    private PasswordService $passwords;
    private AccountRepository $accountRepository;
    private AuthRateLimiter $rateLimiter;
    private ProfileRepository $profileRepository;

    public function __construct(private PDO $db, private TableNames $tables)
    {
        $this->schema = new SchemaInspector($db, $tables);
        $this->passwords = new PasswordService();
        $this->accountRepository = new AccountRepository($db, $tables, $this->schema);
        $this->rateLimiter = new AuthRateLimiter(
            $db,
            $tables,
            $this->schema,
            Config::getInt('AUTH_MAX_ATTEMPTS', 8),
            Config::getInt('AUTH_WINDOW_SECONDS', 3600)
        );
        $this->profileRepository = new ProfileRepository($db, $tables);
    }

    public static function boot(): self
    {
        $db = Database::connect(Config::database());
        $tables = new TableNames(Config::get('CORE_TABLE_PREFIX', '') ?? '');
        return new self($db, $tables);
    }

    public function db(): PDO
    {
        return $this->db;
    }

    public function tables(): TableNames
    {
        return $this->tables;
    }

    public function schema(): SchemaInspector
    {
        return $this->schema;
    }

    public function accounts(): AccountService
    {
        return new AccountService(
            $this->accountRepository,
            $this->rateLimiter,
            $this->passwords,
            Config::getBool('ACCOUNT_PREACTIVATE', true),
            Config::getBool('LEGACY_MIGRATE_UDID_LEVELS', true)
        );
    }

    public function authenticator(): AccountAuthenticator
    {
        return new AccountAuthenticator($this->accountRepository, $this->rateLimiter, $this->passwords);
    }

    public function profiles(): ProfileService
    {
        return new ProfileService(
            $this->profileRepository,
            $this->accountRepository,
            $this->authenticator()
        );
    }

    public function serverName(): string
    {
        return Config::get('SERVER_NAME', 'GDPS') ?? 'GDPS';
    }

    public function profile(): string
    {
        return Config::get('CORE_PROFILE', 'cvolton') ?? 'cvolton';
    }
}
