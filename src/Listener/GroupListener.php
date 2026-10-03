<?php
namespace TeamManager\Listener;

use Doctrine\ORM\EntityManagerInterface;
use TeamManager\Team\TeamPoolRepository;
use TeamManager\Team\TeamService;

/**
 * Keeps team data in sync when pool groups or users are changed through core (e.g. the dashboard).
 */
class GroupListener
{
    /** @var EntityManagerInterface */
    protected $em;
    /** @var TeamPoolRepository */
    protected $pools;
    /** @var TeamService */
    protected $service;

    public function __construct(EntityManagerInterface $em, TeamPoolRepository $pools, TeamService $service)
    {
        $this->em = $em;
        $this->pools = $pools;
        $this->service = $service;
    }

    /**
     * A pool group deleted in Dashboard > Groups: drop the pool settings, its teams are kept without pool.
     *
     * @param \Concrete\Core\User\Group\DeleteEvent $event
     */
    public function onGroupDelete($event)
    {
        $pool = $this->pools->getByID((int) $event->getGroupObject()->getGroupID());
        if ($pool) {
            foreach ($pool->getTeams() as $team) {
                $team->setPool(null);
            }
            $this->em->remove($pool);
            $this->em->flush();
        }
    }

    /**
     * @param \Concrete\Core\User\Event\DeleteUser $event
     */
    public function onUserDelete($event)
    {
        $this->service->removeUserEverywhere((int) $event->getUserInfoObject()->getUserID());
    }
}
