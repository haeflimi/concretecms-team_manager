<?php
namespace TeamManager\Team;

use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use TeamManager\Entity\Team;
use TeamManager\Entity\TeamMember;
use TeamManager\Entity\TeamPool;

/**
 * Admin tools to shuffle the players of a pool into random teams with random names (TeamNameGenerator).
 * Everything runs in one transaction, if something fails the pool stays as it was.
 */
class TeamRandomizer
{
    /** all players of the pool: the teams of the pool are deleted, everybody is put into new teams */
    const MODE_ALL = 'all';
    /** only the players looking for a team are put into new teams, existing teams stay */
    const MODE_SINGLES = 'singles';
    /** the teams of the pool get new random names */
    const MODE_NAMES = 'names';

    /** @var TeamService */
    protected $service;
    /** @var TeamConfig */
    protected $config;
    /** @var TeamRepository */
    protected $teams;
    /** @var TeamPoolRepository */
    protected $pools;
    /** @var TeamNameGenerator */
    protected $names;
    /** @var UserInfoRepository */
    protected $userInfoRepository;
    /** @var EntityManagerInterface */
    protected $em;

    public function __construct(
        TeamService $service,
        TeamConfig $config,
        TeamRepository $teams,
        TeamPoolRepository $pools,
        TeamNameGenerator $names,
        UserInfoRepository $userInfoRepository,
        EntityManagerInterface $em
    ) {
        $this->service = $service->asAdmin();
        $this->config = $config;
        $this->teams = $teams;
        $this->pools = $pools;
        $this->names = $names;
        $this->userInfoRepository = $userInfoRepository;
        $this->em = $em;
    }

    /**
     * Max. size of the pool's teams: the pool's max. team size, else the global one, 0 = unlimited.
     */
    public function getTeamSizeLimit(TeamPool $pool): int
    {
        return $pool->getMaxTeamSize() ?: $this->config->getMaxTeamSize();
    }

    /**
     * Team size suggested in the dialog: the size limit, 2 without limit.
     */
    public function getDefaultTeamSize(TeamPool $pool): int
    {
        return $this->getTeamSizeLimit($pool) ?: 2;
    }

    /**
     * @return Team[] the new teams (MODE_ALL, MODE_SINGLES) or the renamed teams (MODE_NAMES)
     */
    public function randomize(TeamPool $pool, string $mode, int $teamSize, UserInfo $actor): array
    {
        if (!in_array($mode, [self::MODE_ALL, self::MODE_SINGLES, self::MODE_NAMES], true)) {
            throw new UserMessageException(t('Unknown randomize mode.'));
        }
        if ($mode !== self::MODE_NAMES) {
            $max = $this->getTeamSizeLimit($pool);
            if ($teamSize < 1 || ($max > 0 && $teamSize > $max)) {
                throw new UserMessageException($max > 0
                    ? t('The team size must be between 1 and %s.', $max)
                    : t('The team size must be at least 1.'));
            }
        }

        $this->em->beginTransaction();
        try {
            if ($mode === self::MODE_NAMES) {
                $result = $this->renameTeams($pool, $actor);
            } else {
                $result = $this->shuffle($pool, $mode === self::MODE_ALL, $teamSize, $actor);
            }
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            // entities may be out of sync with the database after the rollback
            $this->em->clear();
            throw $e;
        }

        return $result;
    }

    /**
     * @return Team[] new teams
     */
    protected function shuffle(TeamPool $pool, bool $all, int $teamSize, UserInfo $actor): array
    {
        $uIDs = [];
        foreach ($this->pools->getSingles($pool) as $single) {
            $uIDs[] = $single->getUserID();
        }
        if ($all) {
            foreach ($this->teams->findAll($pool) as $team) {
                foreach ($team->getMembers() as $member) {
                    /** @var TeamMember $member */
                    $uIDs[] = $member->getUserID();
                }
                $this->service->disband($team, $actor);
            }
        }
        $users = array_values(array_filter(array_map([$this->userInfoRepository, 'getByID'], array_unique($uIDs))));
        if (!$users) {
            throw new UserMessageException($all
                ? t('There are no players in %s.', $pool->getName())
                : t('Nobody is looking for a team in %s.', $pool->getName()));
        }
        shuffle($users);

        // as few teams as the size allows, filled evenly (sizes differ by one at most)
        $teamCount = (int) ceil(count($users) / $teamSize);
        $groups = array_fill(0, $teamCount, []);
        foreach ($users as $i => $user) {
            $groups[$i % $teamCount][] = $user;
        }

        $created = [];
        foreach ($groups as $group) {
            $team = $this->service->create($this->newName(), '', '', $actor, false, $pool);
            // the first member added becomes captain
            foreach ($group as $user) {
                $this->service->addMember($team, $user, $actor);
            }
            $created[] = $team;
        }

        return $created;
    }

    /**
     * @return Team[]
     */
    protected function renameTeams(TeamPool $pool, UserInfo $actor): array
    {
        $teams = $this->teams->findAll($pool);
        if (!$teams) {
            throw new UserMessageException(t('There are no teams in %s.', $pool->getName()));
        }
        foreach ($teams as $team) {
            $this->service->update($team, $this->newName(), (string) $team->getTag(), $team->getDescription(), $actor);
        }

        return $teams;
    }

    protected function newName(): string
    {
        $name = $this->names->generate();
        if ($name === '') {
            throw new UserMessageException(t('No free random team name found, add more words to team_name_adjectives / team_name_nouns.'));
        }

        return $name;
    }
}
