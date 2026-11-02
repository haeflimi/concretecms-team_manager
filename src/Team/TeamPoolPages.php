<?php

namespace TeamManager\Team;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\User\User;
use TeamManager\Entity\TeamPool;

/**
 * Pages and their team pool: the page attribute "team_pool" (e.g. on tournament pages), else the pool the page's
 * Team Directory block is limited to. Plus the pool status the frontend shows next to such a page.
 */
class TeamPoolPages
{
    public const ATTRIBUTE = 'team_pool';

    /** @var TeamPoolRepository */
    protected $pools;

    /** @var Connection */
    protected $db;

    public function __construct(TeamPoolRepository $pools, Connection $db)
    {
        $this->pools = $pools;
        $this->db = $db;
    }

    public function poolOfPage(Page $page): ?TeamPool
    {
        $gID = (int) (string) $page->getAttribute(self::ATTRIBUTE);
        if ($gID <= 0) {
            $gID = $this->directoryPoolID((int) $page->getCollectionID());
        }

        return $gID > 0 ? $this->pools->getByID($gID) : null;
    }

    /**
     * The pool a Team Directory block on the page's current version is limited to (0 = none).
     */
    public function directoryPoolID(int $cID): int
    {
        return (int) $this->db->fetchOne(
            'SELECT d.joinPoolID FROM btTeamManagerDirectory d
             JOIN CollectionVersionBlocks cvb ON cvb.bID = d.bID
             JOIN CollectionVersions cv ON cv.cID = cvb.cID AND cv.cvID = cvb.cvID AND cv.cvIsApproved = 1
             WHERE cvb.cID = ? AND d.joinPoolID > 0 AND d.onlyListJoinPool = 1
             ORDER BY d.bID LIMIT 1',
            [$cID]
        );
    }

    /**
     * What the frontend shows of a pool for a visitor.
     *
     * @return array{id: int, name: string, open: bool, singles: bool, teams: bool, teamCount: int, maxTeams: int,
     *               singleCount: int, full: bool, mine: string|null}
     */
    public function status(TeamPool $pool, ?User $user = null): array
    {
        $teamCount = $this->pools->countTeams($pool);
        $mine = null;
        $uID = $user && $user->isRegistered() ? (int) $user->getUserID() : 0;
        if ($uID) {
            if ($this->pools->getTeamOfUser($pool, $uID)) {
                $mine = 'team';
            } elseif ($this->pools->findSingle($pool, $uID)) {
                $mine = 'single';
            }
        }

        return [
            'id' => $pool->getID(),
            'name' => $pool->getName(),
            'open' => $pool->isOpen(),
            'singles' => $pool->allowsSingles(),
            'teams' => $pool->allowsTeams(),
            'teamCount' => $teamCount,
            'maxTeams' => $pool->getMaxTeams(),
            'singleCount' => count($this->pools->getSingles($pool)),
            'full' => $pool->getMaxTeams() > 0 && $teamCount >= $pool->getMaxTeams(),
            'mine' => $mine,
        ];
    }

    /**
     * Upgrade helper: pages whose Team Directory is limited to one pool get that pool as page attribute (when empty).
     *
     * @return int pages updated
     */
    public function fillFromDirectories(): int
    {
        if (!$this->db->fetchOne("SHOW TABLES LIKE 'btTeamManagerDirectory'")) {
            return 0;
        }
        $cIDs = $this->db->fetchFirstColumn(
            'SELECT DISTINCT cvb.cID FROM btTeamManagerDirectory d
             JOIN CollectionVersionBlocks cvb ON cvb.bID = d.bID
             JOIN CollectionVersions cv ON cv.cID = cvb.cID AND cv.cvID = cvb.cvID AND cv.cvIsApproved = 1
             WHERE d.joinPoolID > 0 AND d.onlyListJoinPool = 1'
        );
        $count = 0;
        foreach ($cIDs as $cID) {
            $page = Page::getByID((int) $cID);
            if (!$page || $page->isError() || (int) (string) $page->getAttribute(self::ATTRIBUTE) > 0) {
                continue;
            }
            $gID = $this->directoryPoolID((int) $cID);
            if ($gID > 0 && $this->pools->getByID($gID)) {
                $page->setAttribute(self::ATTRIBUTE, (string) $gID);
                ++$count;
            }
        }

        return $count;
    }
}
