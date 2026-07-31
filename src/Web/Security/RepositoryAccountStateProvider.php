<?php

declare(strict_types=1);

namespace NightCore\Web\Security;

use NightCore\Domain\Accounts\AccountRepository;

final class RepositoryAccountStateProvider implements AccountStateProvider
{
    public function __construct(private AccountRepository $accounts)
    {
    }

    public function find(int $accountID): ?array
    {
        return $accountID > 0 ? $this->accounts->findById($accountID) : null;
    }

    public function isAllowed(int $accountID, array $account, int $now): bool
    {
        return $accountID > 0
            && (int) ($account['isActive'] ?? 0) === 1
            && !$this->accounts->isAccountBanned($accountID)
            && !$this->accounts->isDeletionDue($accountID, $now);
    }
}
