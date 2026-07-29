<?php

declare(strict_types=1);

namespace NightCore\Domain\Progress;

use NightCore\Core\TableNames;
use PDO;

final class ListAudienceResolver
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    /** @return array<int,int> */
    public function friendAccountIDs(int $accountID): array
    {
        if ($accountID <= 0) {
            return [];
        }
        $query = $this->db->prepare(
            'SELECT CASE WHEN accountLow = :meCase THEN accountHigh ELSE accountLow END AS accountID FROM ' .
            $this->tables->get('core_friendships') . ' WHERE accountLow = :meLow OR accountHigh = :meHigh'
        );
        $query->execute([':meCase' => $accountID, ':meLow' => $accountID, ':meHigh' => $accountID]);
        return array_values(array_unique(array_map('intval', array_column($query->fetchAll(), 'accountID'))));
    }
}
