<?php

declare(strict_types=1);

namespace NightCore\Web\Security;

interface AccountStateProvider
{
    /** @return array<string,mixed>|null */
    public function find(int $accountID): ?array;

    /** @param array<string,mixed> $account */
    public function isAllowed(int $accountID, array $account, int $now): bool;
}
