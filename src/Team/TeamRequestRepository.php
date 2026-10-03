<?php
namespace TeamManager\Team;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use TeamManager\Entity\TeamRequest;

class TeamRequestRepository
{
    /** @var EntityManagerInterface */
    protected $em;
    /** @var TeamConfig */
    protected $config;

    public function __construct(EntityManagerInterface $em, TeamConfig $config)
    {
        $this->em = $em;
        $this->config = $config;
    }

    public function getByID(int $id): ?TeamRequest
    {
        $request = $this->em->find(TeamRequest::class, $id);

        return $request && $this->isActive($request) ? $request : null;
    }

    public function findPending(int $teamID, int $uID): ?TeamRequest
    {
        $result = $this->pending()
            ->andWhere('r.team = :team')->setParameter('team', $teamID)
            ->andWhere('r.uID = :uID')->setParameter('uID', $uID)
            ->setMaxResults(1)
            ->getQuery()->getResult();

        return $result[0] ?? null;
    }

    /**
     * @return TeamRequest[]
     */
    public function getPendingForUser(int $uID, string $type): array
    {
        return $this->pending()
            ->andWhere('r.uID = :uID')->setParameter('uID', $uID)
            ->andWhere('r.type = :type')->setParameter('type', $type)
            ->getQuery()->getResult();
    }

    /**
     * @return TeamRequest[]
     */
    public function getPendingForTeam(int $teamID, string $type): array
    {
        return $this->pending()
            ->andWhere('r.team = :team')->setParameter('team', $teamID)
            ->andWhere('r.type = :type')->setParameter('type', $type)
            ->getQuery()->getResult();
    }

    /**
     * Cancels all pending requests of a team, or of a single user in that team.
     */
    public function cancelPending(int $teamID, ?int $uID, int $actorID): void
    {
        $qb = $this->pending()->andWhere('r.team = :team')->setParameter('team', $teamID);
        if ($uID !== null) {
            $qb->andWhere('r.uID = :uID')->setParameter('uID', $uID);
        }
        foreach ($qb->getQuery()->getResult() as $request) {
            $request->resolve(TeamRequest::STATUS_CANCELLED, $actorID);
        }
        $this->em->flush();
    }

    public function deleteForUser(int $uID): void
    {
        $this->em->createQuery('delete from ' . TeamRequest::class . ' r where r.uID = :uID')
            ->setParameter('uID', $uID)
            ->execute();
    }

    public function isActive(TeamRequest $request): bool
    {
        $cutoff = $this->getExpiryCutoff();

        return $request->isPending() && ($cutoff === null || $request->getCreatedAt() >= $cutoff);
    }

    protected function pending(): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->select('r')
            ->from(TeamRequest::class, 'r')
            ->where('r.status = :status')->setParameter('status', TeamRequest::STATUS_PENDING)
            ->orderBy('r.createdAt', 'asc');
        $cutoff = $this->getExpiryCutoff();
        if ($cutoff) {
            $qb->andWhere('r.createdAt >= :cutoff')->setParameter('cutoff', $cutoff);
        }

        return $qb;
    }

    protected function getExpiryCutoff(): ?DateTime
    {
        $days = $this->config->getRequestExpiryDays();

        return $days > 0 ? new DateTime('-' . $days . ' days') : null;
    }
}
