<?php
namespace TeamManager\Install;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\File\Filesystem;
use Concrete\Core\Tree\Node\Type\GroupFolder;
use Concrete\Core\Tree\Type\Group as GroupTree;
use Concrete\Core\User\Group\GroupRole;
use Concrete\Core\User\Group\GroupType;
use TeamManager\Team\TeamConfig;

/**
 * Creates the "Team Pool" group type, its roles and the folders pools and logos live in.
 * Every step checks for existing objects first so it can run on each upgrade.
 */
class TeamInstaller
{
    /** @var TeamConfig */
    protected $config;
    /** @var Connection */
    protected $db;
    /** @var Filesystem */
    protected $filesystem;

    public function __construct(TeamConfig $config, Connection $db, Filesystem $filesystem)
    {
        $this->config = $config;
        $this->db = $db;
        $this->filesystem = $filesystem;
    }

    public function install(): void
    {
        $type = $this->installGroupType();
        $this->installRoles($type);
        $this->installGroupFolder($type);
        $this->installLogoFolder();
        $this->dropObsoleteTables();
    }

    public function uninstall(): void
    {
        // deleting the type moves all pool groups back to the default group type, groups and members are kept
        $type = $this->config->getGroupType();
        if ($type) {
            $type->delete();
        }
    }

    protected function installGroupType(): GroupType
    {
        $type = $this->config->getGroupType();
        if ($type) {
            // up to 2.2 the type was called "Team" and teams were groups, now only pools are groups
            $type->setName(t('Team Pool'));
        } else {
            // no petition for public entry: that enables the core join request flow, which is broken in 9.4.
            $type = GroupType::add(t('Team Pool'), false);
            $this->config->save('group_type_id', (int) $type->getId());
        }

        return $type;
    }

    protected function installRoles(GroupType $type): void
    {
        $player = $this->config->getPlayerRole();
        if (!$player) {
            $player = GroupRole::add(t('Team Player'), false);
            $this->config->save('player_role_id', (int) $player->getId());
        }
        $freeAgent = $this->config->getFreeAgentRole();
        if (!$freeAgent) {
            $freeAgent = GroupRole::add(t('Free Agent'), false);
            $this->config->save('free_agent_role_id', (int) $freeAgent->getId());
        }

        // roles of older versions (Captain / Member), captains are a team thing now
        foreach (['captain_role_id', 'member_role_id'] as $key) {
            $legacy = (int) $this->config->get($key);
            if ($legacy) {
                $role = GroupRole::getByID($legacy);
                if ($role) {
                    $role->delete();
                }
                $this->config->save($key, null);
            }
        }

        $assigned = array_map(function (GroupRole $role) {
            return (int) $role->getId();
        }, array_filter($type->getRoles()));
        foreach ([$player, $freeAgent] as $role) {
            if (!in_array((int) $role->getId(), $assigned, true)) {
                $type->addRole($role);
            }
        }
        $type->setDefaultRole($player);
    }

    protected function installGroupFolder(GroupType $type): void
    {
        $folder = $this->config->getGroupFolder();
        if ($folder) {
            $folder->setTreeNodeName(t('Team Pools'));
        } else {
            $root = GroupTree::get()->getRootTreeNodeObject();
            $folder = GroupFolder::add(t('Team Pools'), $root, GroupFolder::CONTAINS_SPECIFIC_GROUPS, [$type]);
            $this->config->save('group_folder_id', (int) $folder->getTreeNodeID());
        }
    }

    protected function installLogoFolder(): void
    {
        if (!$this->config->getLogoFolder()) {
            $folder = $this->filesystem->addFolder($this->filesystem->getRootFolder(), t('Team Logos'));
            $this->config->save('logo_folder_id', (int) $folder->getTreeNodeID());
        }
    }

    protected function dropObsoleteTables(): void
    {
        // profiles of the group based teams (up to 2.2), teams have their own table now
        $this->db->executeStatement('DROP TABLE IF EXISTS tmTeamProfile');
    }
}
