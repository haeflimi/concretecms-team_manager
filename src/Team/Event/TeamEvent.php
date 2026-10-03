<?php
namespace TeamManager\Team\Event;

use Concrete\Core\User\UserInfo;
use TeamManager\Entity\Team;

/**
 * Dispatched as on_team_create, on_team_update, on_team_member_join, on_team_member_leave,
 * on_team_role_change and on_team_disband.
 */
class TeamEvent
{
    /** @var Team */
    protected $team;
    /** @var UserInfo|null the member the event is about */
    protected $user;
    /** @var UserInfo|null the user that triggered the change */
    protected $actor;

    public function __construct(Team $team, ?UserInfo $user = null, ?UserInfo $actor = null)
    {
        $this->team = $team;
        $this->user = $user;
        $this->actor = $actor;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getUser(): ?UserInfo
    {
        return $this->user;
    }

    public function getActor(): ?UserInfo
    {
        return $this->actor;
    }
}
