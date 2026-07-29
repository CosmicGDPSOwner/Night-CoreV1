<?php

declare(strict_types=1);

namespace NightCore\Domain\Profiles;

use NightCore\Core\TableNames;
use PDO;

final class ProfileRepository
{
    private const USER_COLUMNS = 'userID, isRegistered, extID, userName, creatorPoints, stars, demons, coins, userCoins, diamonds, moons, icon, color1, color2, color3, iconType, special, accIcon, accShip, accBall, accBird, accDart, accRobot, accGlow, accSpider, accExplosion, accSwing, accJetpack, gameVersion, secret, IP, lastPlayed, isBanned, dinfo, sinfo, pinfo';

    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function findUserByAccountId(int $accountID): ?array
    {
        $query = $this->db->prepare(
            'SELECT ' . self::USER_COLUMNS . ' FROM ' . $this->tables->get('users') .
            ' WHERE extID = :accountID ORDER BY isRegistered DESC, userID ASC LIMIT 1'
        );
        $query->execute([':accountID' => (string) $accountID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function findUserById(int $userID): ?array
    {
        $query = $this->db->prepare(
            'SELECT ' . self::USER_COLUMNS . ' FROM ' . $this->tables->get('users') .
            ' WHERE userID = :userID LIMIT 1'
        );
        $query->execute([':userID' => $userID]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function findAccountSettings(int $accountID): array
    {
        $query = $this->db->prepare(
            'SELECT mS, frS, cS, youtubeurl, twitter, twitch, discord, instagram, tiktok, custom FROM ' .
            $this->tables->get('accounts') . ' WHERE accountID = :accountID LIMIT 1'
        );
        $query->execute([':accountID' => $accountID]);
        $row = $query->fetch();

        return $row === false ? [
            'mS' => 0,
            'frS' => 0,
            'cS' => 0,
            'youtubeurl' => '',
            'twitter' => '',
            'twitch' => '',
            'discord' => '',
            'instagram' => '',
            'tiktok' => '',
            'custom' => '',
        ] : $row;
    }

    /** @return list<array<string, mixed>> */
    public function search(string $term, int $offset, int $limit = 10): array
    {
        $numericUserID = ctype_digit($term) ? (int) $term : -1;
        $query = $this->db->prepare(
            'SELECT ' . self::USER_COLUMNS . ' FROM ' . $this->tables->get('users') .
            ' WHERE userID = :userID OR userName LIKE :term ORDER BY stars DESC, userID ASC LIMIT :limit OFFSET :offset'
        );
        $query->bindValue(':userID', $numericUserID, PDO::PARAM_INT);
        $query->bindValue(':term', '%' . $term . '%', PDO::PARAM_STR);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    public function countSearch(string $term): int
    {
        $numericUserID = ctype_digit($term) ? (int) $term : -1;
        $query = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->tables->get('users') .
            ' WHERE userID = :userID OR userName LIKE :term'
        );
        $query->execute([
            ':userID' => $numericUserID,
            ':term' => '%' . $term . '%',
        ]);
        return (int) $query->fetchColumn();
    }

    public function rankForStars(int $stars): int
    {
        $query = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->tables->get('users') . ' WHERE stars > :stars AND isBanned = 0'
        );
        $query->execute([':stars' => $stars]);
        return (int) $query->fetchColumn() + 1;
    }

    /** @param array<string, int|string> $data */
    public function updateStats(int $userID, array $data): void
    {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('users') . ' SET ' .
            'gameVersion=:gameVersion, userName=:userName, coins=:coins, secret=:secret, stars=:stars, demons=:demons, ' .
            'icon=:icon, color1=:color1, color2=:color2, color3=:color3, iconType=:iconType, userCoins=:userCoins, ' .
            'special=:special, accIcon=:accIcon, accShip=:accShip, accBall=:accBall, accBird=:accBird, accDart=:accDart, ' .
            'accRobot=:accRobot, accGlow=:accGlow, accSpider=:accSpider, accExplosion=:accExplosion, accSwing=:accSwing, ' .
            'accJetpack=:accJetpack, diamonds=:diamonds, moons=:moons, IP=:ip, lastPlayed=:lastPlayed WHERE userID=:userID'
        );
        $data['userID'] = $userID;
        $query->execute(array_combine(
            array_map(static fn (string $key): string => ':' . $key, array_keys($data)),
            array_values($data)
        ));
    }

    /** @param array<string, int|string> $data */
    public function updateAccountSettings(int $accountID, array $data): void
    {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('accounts') . ' SET ' .
            'mS=:mS, frS=:frS, cS=:cS, youtubeurl=:youtubeurl, twitter=:twitter, twitch=:twitch, ' .
            'discord=:discord, instagram=:instagram, tiktok=:tiktok, custom=:custom WHERE accountID=:accountID'
        );
        $data['accountID'] = $accountID;
        $query->execute(array_combine(
            array_map(static fn (string $key): string => ':' . $key, array_keys($data)),
            array_values($data)
        ));
    }
}
