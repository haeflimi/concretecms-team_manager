<?php
namespace TeamManager\Team;

use Concrete\Core\Database\Connection\Connection;
use Doctrine\ORM\EntityManagerInterface;
use TeamManager\Entity\Team;
use TeamManager\Entity\TeamMember;
use TeamManager\Entity\TeamPool;
use TeamManager\Entity\TeamPoolUser;

class TeamPoolRepository
{
    /** @var EntityManagerInterface */
    protected $em;
    /** @var Connection */
    protected $db;

    public function __construct(EntityManagerInterface $em, Connection $db)
    {
        $this->em = $em;
        $this->db = $db;
    }

    /**
     * @param int $id ID of the pool's core group
     */
    public function getByID(int $id): ?TeamPool
    {
        return $id ? $this->em->find(TeamPool::class, $id) : null;
    }

    /**
     * @return TeamPool[] sorted by name
     */
    public function getAll(bool $onlyOpen = false): array
    {
        $pools = $this->em->getRepository(TeamPool::class)->findBy($onlyOpen ? ['open' => true] : []);
        // the name lives on the core group
        usort($pools, function (TeamPool $a, TeamPool $b) {
            return strcasecmp($a->getName(), $b->getName());
        });

        return $pools;
    }

    /**
     * Pool names are group names, they must not clash with any other group.
     */
    public function nameExists(string $name, int $exceptID = 0): bool
    {
        return (bool) $this->db->fetchOne('select gID from `Groups` where gName = ? and gID <> ?', [$name, $exceptID]);
    }

    public function countTeams(TeamPool $pool): int
    {
        return (int) $this->em->createQuery('select count(t.id) from ' . Team::class . ' t where t.pool = :pool')
            ->setParameter('pool', $pool)
            ->getSingleScalarResult();
    }

    /**
     * The team of the pool the user plays in, if any (users can only be in one team per pool).
     *
     * @param int[] $ignoreTeamIDs
     */
    public function getTeamOfUser(TeamPool $pool, int $uID, array $ignoreTeamIDs = []): ?Team
    {
        $qb = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Team::class, 't')
            ->innerJoin('t.members', 'm', 'WITH', 'm.uID = :uID')
            ->where('t.pool = :pool')
            ->setParameter('uID', $uID)
            ->setParameter('pool', $pool)
            ->setMaxResults(1);
        if ($ignoreTeamIDs) {
            $qb->andWhere('t.id not in (:ignore)')->setParameter('ignore', array_map('intval', $ignoreTeamIDs));
        }
        $result = $qb->getQuery()->getResult();

        return $result[0] ?? null;
    }

    /**
     * All users that belong into the pool group: players of its teams and free agents.
     *
     * @return int[]
     */
    public function getParticipantIDs(TeamPool $pool): array
    {
        $players = array_column($this->em->createQuery(
            'select distinct m.uID from ' . TeamMember::class . ' m join m.team t where t.pool = :pool'
        )->setParameter('pool', $pool)->getScalarResult(), 'uID');
        $singles = array_map(function (TeamPoolUser $single) {
            return $single->getUserID();
        }, $this->getSingles($pool));

        return array_values(array_unique(array_map('intval', array_merge($players, $singles))));
    }

    public function findSingle(TeamPool $pool, int $uID): ?TeamPoolUser
    {
        return $this->em->getRepository(TeamPoolUser::class)->findOneBy(['pool' => $pool, 'uID' => $uID]);
    }

    public function getSingle(int $id): ?TeamPoolUser
    {
        return $id ? $this->em->find(TeamPoolUser::class, $id) : null;
    }

    /**
     * Queried instead of using TeamPool::getSingles(), so entries removed in this request are not included.
     *
     * @return TeamPoolUser[]
     */
    public function getSingles(TeamPool $pool): array
    {
        return $this->em->getRepository(TeamPoolUser::class)->findBy(['pool' => $pool], ['joinedAt' => 'ASC']);
    }

    /**
     * Single players of all pools.
     *
     * @return TeamPoolUser[]
     */
    public function getAllSingles(): array
    {
        return $this->em->getRepository(TeamPoolUser::class)->findBy([], ['joinedAt' => 'ASC']);
    }

    /**
     * @return TeamPoolUser[]
     */
    public function getSinglesOfUser(int $uID): array
    {
        return $this->em->getRepository(TeamPoolUser::class)->findBy(['uID' => $uID], ['joinedAt' => 'ASC']);
    }

    public function countSingles(): int
    {
        return (int) $this->db->fetchOne('select count(distinct uID) from tmTeamPoolUser');
    }
}
