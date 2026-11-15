<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Core;

class Session
{
    /**
     * Seconds during which an unchanged session (same id, player, account, IP, universe) is not written
     * to the database again: session row, users/accounts.onlinetime and the multi-IP check run at most
     * this often. All consumers of onlinetime work in minutes or more.
     */
    private const DB_WRITE_INTERVAL = 30;

    private $data = null;

    /** services, attached by SessionManager after create/restore (not part of the stored data) */
    private ?Database $db = null;
    private ?SessionManager $manager = null;
    /** set by delete(): a destroyed session is not written back */
    private bool $destroyed = false;

    /** @internal called by SessionManager */
    public function attach(SessionManager $manager, Database $db): static
    {
        $this->manager = $manager;
        $this->db = $db;

        return $this;
    }

    /**
     * The session data as stored in the Symfony session under 'obj' (a plain array, no serialized object).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data ?? [];
    }

    /**
     * Restores a session from the array written by toArray(); services are attached afterwards by SessionManager.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $session = new static();
        $session->data = $data;

        return $session;
    }

    public function __set($name, $value)
    {
        $this->data[$name]  = $value;
    }

    public function __get($name)
    {
        if (isset($this->data[$name])) {
            return $this->data[$name];
        } else {
            return null;
        }
    }

    public function __isset($name)
    {
        return isset($this->data[$name]);
    }

    /** @param bool $force write to the database even if it was written a moment ago (e.g. the session row is gone) */
    public function save(bool $force = false)
    {
        // do not save an empty or destroyed session
        if ($this->destroyed || $this->manager === null) {
            return;
        }
        $sessionId = $this->manager->id();
        if (empty($sessionId)) {
            return;
        }

        // sessions require an valid user.
        if ($this->manager->gameState()->runtime->mode != 'INSTALL' && $this->manager->gameState()->runtime->mode != 'UPGRADE') {
            if (empty($this->data['userId']) && empty($this->data['accountId'])) {
                $this->delete();
            }
        }

        $userIpAddress = $this->manager->getClientIp();

        if (
            !(
                isset($this->manager->gameState()->requestQuery['page'])
                && $this->manager->gameState()->requestQuery['page'] == "raport"
                && isset($this->manager->gameState()->requestQuery['raport'])
                && count($this->manager->gameState()->requestQuery) == 2
                && $this->manager->gameState()->runtime->mode === 'INGAME'
            )
        ) {
            if (empty($this->data['userId'])) {
                $this->data['userId'] = 0;
            }
            if (empty($this->data['accountId'])) {
                $this->data['accountId'] = 0;
            }

            $timestamp = $this->manager->gameState()->runtime->timestamp;
            $syncKey = implode('|', [$sessionId, $this->data['userId'], $this->data['accountId'], $userIpAddress, $this->data['universe'] ?? '']);
            $lastActivity = (int) ($this->data['lastActivity'] ?? 0);
            if (
                !$force
                && !$this->destroyed
                && ($this->data['syncKey'] ?? null) === $syncKey
                && $lastActivity <= $timestamp
                && $lastActivity > $timestamp - self::DB_WRITE_INTERVAL
            ) {
                // nothing changed and the database was written a moment ago: only keep the session data
                // (selected planet etc.) in the Symfony session
                $this->manager->symfonySession()->set('obj', $this->toArray());
                return;
            }

            $sql    = 'REPLACE INTO %%SESSION%% SET
                sessionID	= :sessionId,
                userID		= :userId,
                accountID	= :accountId,
                lastonline	= :lastActivity,
                created		= :created,
                userIP		= :userAddress;';

            $db     = $this->db;

            $sql_created = 'SELECT created FROM %%SESSION%% 
                WHERE sessionID = :sessionId 
                AND userID = :userId 
                AND accountID = :accountId;';

            $created = $db->selectSingle($sql_created, [
                ':sessionId'    => $this->manager->id(),
                ':userId'       => $this->data['userId'],
                ':accountId'    => $this->data['accountId'],
            ], 'created');

            if (empty($created)) {
                $created = time();
            }

            $db->replace($sql, [
                ':sessionId'    => $this->manager->id(),
                ':userId'       => $this->data['userId'],
                ':accountId'    => $this->data['accountId'],
                ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
                ':created'  => $created,
                ':userAddress'  => $userIpAddress,
            ]);



            if ($this->data['userId'] != 0) {
                $sql = 'UPDATE %%USERS%% SET
                onlinetime	= :lastActivity,
                user_lastip = :userAddress
                WHERE id = :userId;';

                $db->update($sql, [
                ':userAddress'   => $userIpAddress,
                ':lastActivity'  => $this->manager->gameState()->runtime->timestamp,
                ':userId'        => $this->data['userId'],
                ]);

                // Remove multisessions
                if (PREVENT_MULTISESSIONS == true) {
                    $sql    = 'DELETE FROM %%SESSION%% WHERE (userID = :userId AND sessionID != :sessionId);';
                    $db->delete($sql, [
                        ':userId'   => $this->data['userId'],
                        ':sessionId'    => $this->manager->id(),
                    ]);
                }

                // Remove old sessions
                if ($created + SESSION_LIFETIME < time()) {
                    $sql    = 'DELETE FROM %%SESSION%% WHERE (userID = :userId AND sessionID = :sessionId);';

                    $db->delete($sql, [
                        ':userId'       => $this->data['userId'],
                        ':sessionId'    => $this->manager->id(),
                    ]);
                }
                $this->data['requestPath']   = $this->getRequestPath();
            } else {
                $sql = 'UPDATE %%ACCOUNTS%% SET
                onlinetime	= :lastActivity
                WHERE id = :accountId;';

                $db->update($sql, [
                ':lastActivity'     => $this->manager->gameState()->runtime->timestamp,
                ':accountId'        => $this->data['accountId'],
                ]);

                // Remove multisessions
                if (PREVENT_MULTISESSIONS == true) {
                    $sql    = 'DELETE FROM %%SESSION%% WHERE (accountID = :accountId AND sessionID != :sessionId);';
                    $db->delete($sql, [
                        ':accountId'    => $this->data['accountId'],
                        ':sessionId'    => $this->manager->id(),
                    ]);
                }

                // Remove old sessions
                if ($created + SESSION_LIFETIME < time()) {
                    $sql    = 'DELETE FROM %%SESSION%% WHERE (accountID = :accountId AND sessionID = :sessionId);';

                    $db->delete($sql, [
                        ':accountId'    => $this->data['accountId'],
                        ':sessionId'    => $this->manager->id(),
                    ]);
                }
            }
            $this->checkMultiIP();

            $this->data['syncKey']       = $syncKey;
            $this->data['lastActivity']  = $this->manager->gameState()->runtime->timestamp;
            $this->data['sessionId']     = $this->manager->id();
            $this->data['userIpAddress'] = $userIpAddress;


            $this->manager->symfonySession()->set('obj', $this->toArray());
        }
    }

    public function delete()
    {
        $sql    = 'DELETE FROM %%SESSION%% WHERE sessionID = :sessionId;';
        $db     = $this->db;

        $db->delete($sql, [':sessionId' => $this->manager->id()]);
        (new HTTP($this->manager->gameState()))->sendCookie("2Moons", "", time() - 3600);
        $this->manager->symfonySession()->invalidate();
        $this->destroyed = true;
    }

    public function isValidSession()
    {
        // if($this->compareIpAddress($this->data['userIpAddress'], $this->manager->getClientIp(), COMPARE_IP_BLOCKS) === false)
        // {
        // return false;
        // }

        if (
            isset($this->manager->gameState()->requestQuery['page'])
            && $this->manager->gameState()->requestQuery['page'] == "raport"
            && isset($this->manager->gameState()->requestQuery['raport'])
            && count($this->manager->gameState()->requestQuery) == 2
            && $this->manager->gameState()->runtime->mode === 'INGAME'
        ) {
            $this->data['lastActivity'] = time();
        } else {
            if (!$this->manager->symfonySession()->has('obj')) {
                return false;
            }
        }


        if ($this->data['lastActivity'] < $this->manager->gameState()->runtime->timestamp - SESSION_LIFETIME) {
            return false;
        }

        $sql = 'SELECT COUNT(*) as record FROM %%SESSION%% WHERE sessionID = :sessionId;';
        $db     = $this->db;

        $sessionCount = $db->selectSingle($sql, [':sessionId' => $this->manager->id()], 'record');

        if ($sessionCount == 0) {
            return false;
        }

        return true;
    }

    public function selectActivePlanet()
    {
        $httpData   = (new HTTP($this->manager->gameState()))->_GP('cp', 0);

        if (!empty($httpData)) {
            $sql    = 'SELECT id FROM %%PLANETS%% WHERE id = :planetId AND id_owner = :userId;';

            $db = $this->db;
            $planetId   = $db->selectSingle($sql, [
                ':userId'   => $this->data['userId'],
                ':planetId' => $httpData,
            ], 'id');

            if (!empty($planetId)) {
                $this->data['planetId'] = $planetId;
            }
        }
    }

    private function getRequestPath()
    {
        return $this->manager->gameState()->runtime->httpRoot . (!empty($this->manager->gameState()->requestServer['QUERY_STRING']) ? '?' . $this->manager->gameState()->requestServer['QUERY_STRING'] : '');
    }

    private function compareIpAddress($ip1, $ip2, $blockCount)
    {
        if (strpos($ip2, ':') !== false && strpos($ip1, ':') !== false) {
            $s_ip = $this->shortIpv6($ip1, $blockCount);
            $u_ip = $this->shortIpv6($ip2, $blockCount);
        } else {
            $s_ip = implode('.', array_slice(explode('.', $ip1), 0, $blockCount));
            $u_ip = implode('.', array_slice(explode('.', $ip2), 0, $blockCount));
        }

        return ($s_ip == $u_ip);
    }

    private function shortIpv6($ip, $length)
    {
        if ($length < 1) {
            return '';
        }

        $blocks = substr_count($ip, ':') + 1;
        if ($blocks < 9) {
            $ip = str_replace('::', ':' . str_repeat('0000:', 9 - $blocks), $ip);
        }
        if ($ip[0] == ':') {
            $ip = '0000' . $ip;
        }
        if ($length < 4) {
            $ip = implode(':', array_slice(explode(':', $ip), 0, 1 + $length));
        }

        return $ip;
    }

    private function checkMultiIP()
    {

        $db     = $this->db;
        $userIpAddress = $this->manager->getClientIp();
        if (isset($this->data['userId']) && $this->data['userId'] != 0 && isset($this->data['universe'])) {
            // Check fpr MultiIP
            $sql = "SELECT `id` FROM %%USERS%%
                WHERE `onlinetime` > :lastActivity
                AND `user_lastip` = :userAddress
                AND `id` != :userId
                AND `universe` = :universe;";
            $multis = $db->select($sql, [
                ':userAddress'  => $userIpAddress,
                ':lastActivity' => $this->manager->gameState()->runtime->timestamp - TIME_24_HOURS,
                ':userId'       => $this->data['userId'],
                ':universe'     => $this->data['universe'],
            ]);

            if (!empty($multis)) {
                $multis[] = ['id' => $this->data['userId']];

                $sql = "SELECT * FROM %%MULTI%%
                    WHERE `multi_ip` = :userAddress
                    AND `universe` = :universe;";
                $multiEntry = $db->selectSingle($sql, [
                    ':userAddress'  => $userIpAddress,
                    ':universe'     => $this->data['universe'],
                ]);

                if (empty($multiEntry)) {
                    $sql = "SELECT mu.`multiID`, count(DISTINCT muc.`userID`) AS anz
                        FROM `%%MULTI_TO_USERS%%` AS mu
                        JOIN `%%MULTI_TO_USERS%%` AS muc ON muc.`multiID` = mu.`multiID`
                        WHERE mu.`userID` IN ( ". implode(', ', array_column($multis, 'id')) ." )
                        GROUP BY mu.`multiID`
                        HAVING count(DISTINCT mu.`userID`) >= :usersSize
                        ORDER BY anz ASC;";
                    $multiID = $db->selectSingle($sql, [
                        ':usersSize' => count($multis),
                    ], 'multiID');

                    $sql = "SELECT * FROM %%MULTI%%
                        WHERE `multiID` = :multiID;";
                    $multiEntry = $db->selectSingle($sql, [
                        ':multiID'  => $multiID,
                    ]);
                }

                if (empty($multiEntry)) {
                    $sql = "INSERT INTO %%MULTI%% SET
                        `multi_ip` = :userAddress,
                        `lastActivity` = :lastActivity,
                        `universe` = :universe,
                        `allowed` = 0;";
                    $db->insert($sql, [
                        ':userAddress' => $userIpAddress,
                        ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
                        ':universe' => $this->data['universe'],
                    ]);
                    $multiID = $db->lastInsertId();

                    $sql = "INSERT INTO %%MULTI_TO_USERS%% SET
                        `multiID` = :multiID,
                        `userID` = :userID,
                        `lastActivity` = :lastActivity,
                        `allowed` = 0;";

                    foreach ($multis as $multi) {
                        $db->insert($sql, [
                            ':multiID' => $multiID,
                            ':userID' => $multi['id'],
                            ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
                        ]);
                    }
                } else {
                    $multiID = $multiEntry['multiID'];
                    $sql = "UPDATE %%MULTI%% SET
                        `multi_ip` = :userAddress,
                        `lastActivity` = :lastActivity
                        WHERE `multiID` = :multiID;";
                    $db->update($sql, [
                        ':userAddress'  => $userIpAddress,
                        ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
                        ':multiID' => $multiID,
                    ]);

                    $sqlSelect = "SELECT * FROM %%MULTI_TO_USERS%%
                        WHERE `multiID` = :multiID AND `userID` = :userID;";
                    $sqlInsert = "INSERT INTO %%MULTI_TO_USERS%% SET
                        `multiID` = :multiID,
                        `userID` = :userID,
                        `lastActivity` = :lastActivity,
                        `allowed` = 0;";
                    $sqlUpdate = "UPDATE %%MULTI_TO_USERS%% SET
                        `lastActivity` = :lastActivity
                        WHERE `multiID` = :multiID AND `userID` = :userID;";

                    foreach ($multis as $multi) {
                        $result = $db->selectSingle($sqlSelect, [
                            ':multiID' => $multiID,
                            ':userID' => $multi['id'],
                        ]);

                        if (empty($result)) {
                            $db->insert($sqlInsert, [
                                ':multiID' => $multiID,
                                ':userID' => $multi['id'],
                                ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
                            ]);
                        } elseif ($multi['id'] == $this->data['userId']) {
                            $db->update($sqlUpdate, [
                                ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
                                ':multiID' => $multiID,
                                ':userID' => $multi['id'],
                            ]);
                        }
                    }
                }
            }
        }
    }
}
