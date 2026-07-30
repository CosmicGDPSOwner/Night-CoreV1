<?php

declare(strict_types=1);

namespace NightCore\Core;

use NightCore\Domain\Accounts\AccountRepository;
use NightCore\Security\PasswordService;
use PDO;
use RuntimeException;

final class MediaAccountAccess
{
    private const LOGIN_WINDOW_SECONDS = 900;
    private const LOGIN_MAX_FAILURES = 5;

    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private SchemaInspector $schema,
        private AccountRepository $accounts,
        private PasswordService $passwords
    ) {
    }

    /** @return array<string,mixed> */
    public function login(string $username, string $password, string $ip): array
    {
        $this->requireTables();
        $ipHash = hash('sha256', $ip);
        $now = time();

        $blocked = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->tables->get('core_media_login_attempts')
            . ' WHERE ipHash = :ipHash AND success = 0 AND attemptedAt >= :since'
        );
        $blocked->execute([
            ':ipHash' => $ipHash,
            ':since' => $now - self::LOGIN_WINDOW_SECONDS,
        ]);
        if ((int) $blocked->fetchColumn() >= self::LOGIN_MAX_FAILURES) {
            throw new RuntimeException('Too many failed login attempts. Try again later.');
        }

        $account = $this->accounts->findByUsername($this->field($username, 64));
        $accountID = $account === null ? 0 : (int) $account['accountID'];
        $valid = $account !== null
            && $password !== ''
            && $this->passwords->verifyPassword($password, (string) $account['password'])
            && (int) ($account['isActive'] ?? 0) === 1
            && !$this->accounts->isAccountBanned($accountID)
            && !$this->accounts->isDeletionDue($accountID);

        $this->recordLogin($ipHash, $accountID, $valid, $now);
        if (!$valid || $account === null) {
            throw new RuntimeException('Invalid username or password, or this account is unavailable.');
        }

        $this->accounts->touchActivity($accountID, $now);
        return $account;
    }

    /** @param array<string,mixed> $result */
    public function recordUpload(
        int $accountID,
        string $mediaType,
        array $result,
        string $originalName,
        string $ip
    ): void {
        $this->requireTables();
        if ($accountID <= 0 || !in_array($mediaType, ['song', 'sfx'], true)) {
            throw new RuntimeException('Invalid authenticated media upload audit record.');
        }

        $mediaID = $mediaType === 'song'
            ? (int) ($result['songID'] ?? 0)
            : (int) ($result['sfxID'] ?? 0);
        if ($mediaID <= 0) {
            throw new RuntimeException('Uploaded media ID is missing.');
        }

        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_media_upload_audit')
            . ' (accountID, mediaType, mediaID, originalName, bytes, sha256, ipHash, createdAt)'
            . ' VALUES (:accountID, :mediaType, :mediaID, :originalName, :bytes, :sha256, :ipHash, :createdAt)'
        );
        $query->execute([
            ':accountID' => $accountID,
            ':mediaType' => $mediaType,
            ':mediaID' => $mediaID,
            ':originalName' => $this->field(basename($originalName), 255),
            ':bytes' => max(0, (int) ($result['bytes'] ?? 0)),
            ':sha256' => preg_match('/^[a-f0-9]{64}$/i', (string) ($result['sha256'] ?? '')) === 1
                ? strtolower((string) $result['sha256'])
                : '',
            ':ipHash' => hash('sha256', $ip),
            ':createdAt' => time(),
        ]);
    }

    private function recordLogin(string $ipHash, int $accountID, bool $success, int $now): void
    {
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_media_login_attempts')
            . ' (ipHash, accountID, success, attemptedAt)'
            . ' VALUES (:ipHash, :accountID, :success, :attemptedAt)'
        );
        $query->execute([
            ':ipHash' => $ipHash,
            ':accountID' => max(0, $accountID),
            ':success' => $success ? 1 : 0,
            ':attemptedAt' => $now,
        ]);

        $cleanup = $this->db->prepare(
            'DELETE FROM ' . $this->tables->get('core_media_login_attempts')
            . ' WHERE attemptedAt < :before'
        );
        $cleanup->execute([':before' => $now - 604800]);
    }

    private function requireTables(): void
    {
        if (!$this->schema->tableExists('core_media_login_attempts')
            || !$this->schema->tableExists('core_media_upload_audit')) {
            throw new RuntimeException('Apply migration 0019 before using authenticated media uploads.');
        }
    }

    private function field(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', trim($value)) ?? '';
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
