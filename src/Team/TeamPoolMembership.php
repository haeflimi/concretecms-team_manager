<?php
namespace TeamManager\Team;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\User\UserInfoRepository;
use TeamManager\Entity\TeamPool;

/**
 * Keeps the core group of a pool in sync with the package tables, which are the source of truth:
 * players of the pool's teams get the "Team Player" role, free agents the "Free Agent" role,
 * everybody else is removed from the group.
 */
class TeamPoolMembership
{
    /** @var TeamPoolRepository */
    protected $pools;
    /** @var TeamConfig */
    protected $config;
    /** @var Connection */
    protected $db;
    /** @var UserInfoRepository */
    protected $userInfoRepository;

    public function __construct(TeamPoolRepository $pools, TeamConfig $config, Connection $db, UserInfoRepository $userInfoRepository)
    {
        $this->pools = $pools;
        $this->config = $config;
        $this->db = $db;
        $this->userInfoRepository = $userInfoRepository;
    }

    public function syncUser(TeamPool $pool, int $uID): void
    {
        $group = $pool->getGroup();
        $userInfo = $this->userInfoRepository->getByID($uID);
        if (!$group || !$userInfo) {
            return;
        }

        $role = null;
        if ($this->pools->getTeamOfUser($pool, $uID)) {
            $role = $this->config->getPlayerRole();
        } elseif ($this->pools->findSingle($pool, $uID)) {
            $role = $this->config->getFreeAgentRole();
        }

        $user = $userInfo->getUserObject();
        if ($role === null) {
            if ($user->inExactGroup($group)) {
                $user->exitGroup($group);
            }

            return;
        }
        if (!$user->inExactGroup($group)) {
            $user->enterGroup($group);
        }
        $this->db->update('UserGroups', ['grID' => $role->getId()], ['gID' => $group->getGroupID(), 'uID' => $uID]);
    }

    /**
     * @param int[] $uIDs
     */
    public function syncUsers(TeamPool $pool, array $uIDs): void
    {
        foreach (array_unique($uIDs) as $uID) {
            $this->syncUser($pool, (int) $uID);
        }
    }

    /**
     * Rebuilds the whole group, e.g. after members were changed in Dashboard > Groups.
     */
    public function syncAll(TeamPool $pool): void
    {
        $group = $pool->getGroup();
        if (!$group) {
            return;
        }
        $current = array_map('intval', $this->db->fetchFirstColumn('select uID from UserGroups where gID = ?', [$group->getGroupID()]));
        $this->syncUsers($pool, array_merge($current, $this->pools->getParticipantIDs($pool)));
    }
}
