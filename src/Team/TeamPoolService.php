<?php
namespace TeamManager\Team;

use Concrete\Core\Application\Application;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Package\PackageService;
use Concrete\Core\User\Group\Command\AddGroupCommand;
use Concrete\Core\User\Group\Command\DeleteGroupCommand;
use Concrete\Core\User\Group\Group;
use Doctrine\ORM\EntityManagerInterface;
use TeamManager\Entity\TeamPool;

/**
 * Creating, changing and deleting pools. Only used by the dashboard, access control is up to the page permissions.
 * A pool is a core group of the "Team Pool" type in the "Team Pools" folder plus its TeamPool settings.
 * Teams and free agents are added to pools through TeamService.
 */
class TeamPoolService
{
    /** @var Application */
    protected $app;
    /** @var TeamConfig */
    protected $config;
    /** @var TeamPoolRepository */
    protected $pools;
    /** @var EntityManagerInterface */
    protected $em;

    public function __construct(Application $app, TeamConfig $config, TeamPoolRepository $pools, EntityManagerInterface $em)
    {
        $this->app = $app;
        $this->config = $config;
        $this->pools = $pools;
        $this->em = $em;
    }

    public function create(array $data): TeamPool
    {
        $name = $this->validateName((string) ($data['name'] ?? ''), 0);
        $folder = $this->config->getGroupFolder();
        $type = $this->config->getGroupType();
        if (!$folder || !$type) {
            throw new UserMessageException(t('The team manager is not installed correctly.'));
        }

        $command = new AddGroupCommand();
        $command->setName($name);
        $command->setDescription(trim((string) ($data['description'] ?? '')));
        $command->setParentNodeID((int) $folder->getTreeNodeID());
        $command->setPackageID((int) $this->app->make(PackageService::class)->getByHandle('team_manager')->getPackageID());
        /** @var Group $group */
        $group = $this->app->executeCommand($command);
        $group->setGroupType($type);

        $pool = new TeamPool((int) $group->getGroupID());
        $this->apply($pool, $data);
        $this->em->persist($pool);
        $this->em->flush();

        return $pool;
    }

    public function update(TeamPool $pool, array $data): TeamPool
    {
        $name = $this->validateName((string) ($data['name'] ?? ''), $pool->getID());
        $group = $pool->getGroup();
        if ($group) {
            $group->update($name, trim((string) ($data['description'] ?? '')));
        }
        $this->apply($pool, $data);
        $this->em->flush();

        return $pool;
    }

    /**
     * Deletes the pool group and settings. Its teams are kept and have no pool afterwards.
     */
    public function delete(TeamPool $pool): void
    {
        $gID = $pool->getID();
        foreach ($pool->getTeams() as $team) {
            $team->setPool(null);
        }
        $this->em->remove($pool);
        $this->em->flush();
        // removes the group memberships too
        $this->app->executeCommand(new DeleteGroupCommand($gID));
    }

    protected function apply(TeamPool $pool, array $data): void
    {
        $pool->setOpen(!empty($data['open']));
        $pool->setAllowTeams(!empty($data['allowTeams']));
        $pool->setAllowTeamCreation(!empty($data['allowTeamCreation']));
        $pool->setAllowSingles(!empty($data['allowSingles']));
        $pool->setMaxTeams((int) ($data['maxTeams'] ?? 0));
    }

    protected function validateName(string $name, int $poolID): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            throw new UserMessageException(t('Please enter a pool name.'));
        }
        if (mb_strlen($name) > 128) {
            throw new UserMessageException(t('The pool name can have at most %s characters.', 128));
        }
        if (strpos($name, '/') !== false) {
            throw new UserMessageException(t('The pool name must not contain slashes.'));
        }
        if ($this->pools->nameExists($name, $poolID)) {
            throw new UserMessageException(t('There already is a group called "%s".', $name));
        }

        return $name;
    }
}
