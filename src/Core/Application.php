<?php

declare(strict_types=1);

namespace NightCore\Core;

use NightCore\Domain\Accounts\AccountRepository;
use NightCore\Domain\Accounts\AccountService;
use NightCore\Domain\Accounts\AuthRateLimiter;
use NightCore\Domain\Content\ContentRepository;
use NightCore\Domain\Content\ContentService;
use NightCore\Domain\Levels\LevelAccessPolicy;
use NightCore\Domain\Levels\LevelRepository;
use NightCore\Domain\Levels\LevelSearchBridge;
use NightCore\Domain\Levels\LevelService;
use NightCore\Domain\Levels\LevelStorage;
use NightCore\Domain\Moderation\ModerationRepository;
use NightCore\Domain\Moderation\ModerationService;
use NightCore\Domain\Profiles\ProfileContextRepository;
use NightCore\Domain\Profiles\ProfileRepository;
use NightCore\Domain\Profiles\ProfileService;
use NightCore\Domain\Progress\ListAudienceResolver;
use NightCore\Domain\Progress\ProgressRepository;
use NightCore\Domain\Progress\ProgressService;
use NightCore\Domain\Social\SocialRepository;
use NightCore\Domain\Social\SocialService;
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
    private ProfileContextRepository $profileContextRepository;
    private LevelRepository $levelRepository;
    private ContentRepository $contentRepository;
    private SocialRepository $socialRepository;
    private ProgressRepository $progressRepository;
    private ModerationRepository $moderationRepository;

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
        $this->profileContextRepository = new ProfileContextRepository($db, $tables);
        $this->levelRepository = new LevelRepository($db, $tables);
        $this->contentRepository = new ContentRepository($db, $tables);
        $this->socialRepository = new SocialRepository($db, $tables);
        $this->progressRepository = new ProgressRepository($db, $tables);
        $this->moderationRepository = new ModerationRepository($db, $tables);
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
            $this->authenticator(),
            $this->profileContextRepository,
            $this->adminAccountIDs()
        );
    }

    public function levels(): LevelService
    {
        $defaultStorage = dirname(__DIR__, 2) . '/data/levels';
        $storagePath = trim(Config::get('LEVEL_STORAGE_PATH', '') ?? '');
        if ($storagePath === '') {
            $storagePath = $defaultStorage;
        }

        $authenticator = $this->authenticator();
        return new LevelService(
            $this->levelRepository,
            new LevelStorage(
                $storagePath,
                max(1, Config::getInt('LEVEL_MAX_BYTES', 8388608))
            ),
            $this->accountRepository,
            $authenticator,
            new LevelAccessPolicy($this->socialRepository, $authenticator),
            max(0, Config::getInt('LEVEL_UPLOAD_COOLDOWN_SECONDS', 60))
        );
    }

    public function levelSearch(): LevelSearchBridge
    {
        return new LevelSearchBridge($this->db, $this->tables, $this->levels(), $this->authenticator());
    }

    public function content(): ContentService
    {
        return new ContentService(
            $this->contentRepository,
            $this->accountRepository,
            $this->authenticator(),
            $this->progressRepository
        );
    }

    public function social(): SocialService
    {
        return new SocialService($this->socialRepository, $this->accountRepository, $this->authenticator());
    }

    public function progress(): ProgressService
    {
        return new ProgressService(
            $this->progressRepository,
            $this->accountRepository,
            $this->authenticator(),
            new ListAudienceResolver($this->db, $this->tables),
            max(1024, Config::getInt('SAVE_MAX_BYTES', 16777216))
        );
    }

    public function moderation(): ModerationService
    {
        return new ModerationService(
            $this->moderationRepository,
            $this->authenticator(),
            $this->adminAccountIDs()
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

    /** @return array<int,int> */
    private function adminAccountIDs(): array
    {
        $raw = trim(Config::get('CORE_ADMIN_ACCOUNT_IDS', '') ?? '');
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }
        return array_values(array_unique($ids));
    }
}
