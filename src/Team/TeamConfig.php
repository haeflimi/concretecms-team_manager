<?php
namespace TeamManager\Team;

use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Tree\Node\Node as TreeNode;
use Concrete\Core\Tree\Node\Type\FileFolder;
use Concrete\Core\Tree\Node\Type\GroupFolder;
use Concrete\Core\User\Group\GroupRole;
use Concrete\Core\User\Group\GroupType;

/**
 * Access to the package settings and the core objects (pool group type, roles, folders) created on install.
 */
class TeamConfig
{
    /** @var Repository */
    protected $config;

    public function __construct(PackageService $packageService)
    {
        $this->config = $packageService->getClass('team_manager')->getFileConfig();
    }

    public function get(string $key, $default = null)
    {
        return $this->config->get('settings.' . $key, $default);
    }

    public function save(string $key, $value): void
    {
        $this->config->save('settings.' . $key, $value);
    }

    public function getGroupType(): ?GroupType
    {
        $id = (int) $this->get('group_type_id');

        return $id ? (GroupType::getByID($id) ?: null) : null;
    }

    /**
     * Pool group role of users that play in a team of the pool (default role of the type).
     */
    public function getPlayerRole(): ?GroupRole
    {
        $id = (int) $this->get('player_role_id');

        return $id ? (GroupRole::getByID($id) ?: null) : null;
    }

    /**
     * Pool group role of users looking for a team in the pool.
     */
    public function getFreeAgentRole(): ?GroupRole
    {
        $id = (int) $this->get('free_agent_role_id');

        return $id ? (GroupRole::getByID($id) ?: null) : null;
    }

    /**
     * The "Team Pools" group folder holding all pool groups.
     */
    public function getGroupFolder(): ?GroupFolder
    {
        $node = TreeNode::getByID((int) $this->get('group_folder_id'));

        return $node instanceof GroupFolder ? $node : null;
    }

    public function getLogoFolder(): ?FileFolder
    {
        $node = TreeNode::getByID((int) $this->get('logo_folder_id'));

        return $node instanceof FileFolder ? $node : null;
    }

    public function getMaxTeamSize(): int
    {
        return (int) $this->get('max_team_size', 0);
    }

    public function getRequestExpiryDays(): int
    {
        return (int) $this->get('request_expiry_days', 0);
    }

    public function getTagMaxLength(): int
    {
        return (int) $this->get('tag_max_length', 8);
    }
}
