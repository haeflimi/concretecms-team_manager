<?php
namespace TeamManager\Team;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use TeamManager\Entity\Team;
use TeamManager\Entity\TeamPool;

class TeamRepository
{
    /** @var EntityManagerInterface */
    protected $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getByID(int $id): ?Team
    {
        return $id ? $this->em->find(Team::class, $id) : null;
    }

    /**
     * @return Team[]
     */
    public function getForUser(int $uID): array
    {
        return $this->em->createQueryBuilder()
            ->select('t')
            ->from(Team::class, 't')
            ->innerJoin('t.members', 'm', 'WITH', 'm.uID = :uID')
            ->setParameter('uID', $uID)
            ->orderBy('t.name', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @param TeamPool|false|null $pool null = all teams, false = only teams without pool, TeamPool = teams of that pool
     *
     * @return Team[]
     */
    public function findAll($pool = null, string $keywords = ''): array
    {
        return $this->createQuery($pool, $keywords)->getQuery()->getResult();
    }

    /**
     * @param TeamPool|false|null $pool see findAll()
     *
     * @return array{items: Team[], page: int, pages: int, total: int}
     */
    public function paginate($pool, string $keywords, int $page, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $paginator = new Paginator($this->createQuery($pool, $keywords)->getQuery(), false);
        $total = count($paginator);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $paginator->getQuery()->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        return [
            'items' => iterator_to_array($paginator->getIterator(), false),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    public function nameExists(string $name, int $exceptID = 0): bool
    {
        return $this->exists('name', $name, $exceptID);
    }

    public function tagExists(string $tag, int $exceptID = 0): bool
    {
        return $this->exists('tag', $tag, $exceptID);
    }

    public function countAll(): int
    {
        return (int) $this->em->createQuery('select count(t.id) from ' . Team::class . ' t')->getSingleScalarResult();
    }

    public function countEmpty(): int
    {
        return (int) $this->em->createQuery('select count(t.id) from ' . Team::class . ' t where t.members is empty')->getSingleScalarResult();
    }

    public function countMembers(): int
    {
        return (int) $this->em->createQuery('select count(distinct m.uID) from ' . Team::class . ' t join t.members m')->getSingleScalarResult();
    }

    /**
     * @param TeamPool|false|null $pool see findAll()
     */
    protected function createQuery($pool, string $keywords): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Team::class, 't')
            ->orderBy('t.name', 'ASC');
        if ($pool instanceof TeamPool) {
            $qb->andWhere('t.pool = :pool')->setParameter('pool', $pool);
        } elseif ($pool === false) {
            $qb->andWhere('t.pool is null');
        }
        if ($keywords !== '') {
            $qb->andWhere('t.name like :keywords or t.tag like :keywords or t.description like :keywords')
                ->setParameter('keywords', '%' . addcslashes($keywords, '%_\\') . '%');
        }

        return $qb;
    }

    protected function exists(string $field, string $value, int $exceptID): bool
    {
        return (bool) $this->em->createQueryBuilder()
            ->select('count(t.id)')
            ->from(Team::class, 't')
            ->where('t.' . $field . ' = :value')
            ->andWhere('t.id <> :id')
            ->setParameter('value', $value)
            ->setParameter('id', $exceptID)
            ->getQuery()->getSingleScalarResult();
    }
}
