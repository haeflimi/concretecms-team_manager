<?php
namespace Concrete\Package\TeamManager\Block\MyTeams;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\UserInfo;
use Symfony\Component\HttpFoundation\JsonResponse;
use TeamManager\Block\AbstractTeamBlockController;
use TeamManager\Entity\Team;
use TeamManager\Entity\TeamPool;
use TeamManager\Entity\TeamRequest;
use TeamManager\Team\TeamPoolRepository;
use TeamManager\Team\TeamRepository;
use TeamManager\Team\TeamRequestRepository;
use TeamManager\Team\TeamService;

class Controller extends AbstractTeamBlockController
{
    protected $btTable = 'btTeamManagerMyTeams';
    protected $btInterfaceWidth = 500;
    protected $btInterfaceHeight = 350;
    protected $btDefaultSet = 'social';

    public $allowTeamCreation;
    /**
     * @var int pool the whole block is limited to (teams shown, invitations, new teams, free agent entries), 0 = all
     */
    public $poolID;

    public function getBlockTypeName()
    {
        return t('My Teams');
    }

    public function getBlockTypeDescription()
    {
        return t('Lets users create and manage their teams, invite members and answer invitations.');
    }

    public function add()
    {
        $this->set('allowTeamCreation', 1);
        $this->set('poolID', 0);
        $this->setPoolOptions();
    }

    public function edit()
    {
        $this->setPoolOptions();
    }

    protected function setPoolOptions(): void
    {
        $options = [0 => t('All teams (new teams without pool)')];
        foreach ($this->app->make(TeamPoolRepository::class)->getAll() as $pool) {
            $options[$pool->getID()] = $pool->getName();
        }
        $this->set('poolOptions', $options);
    }

    public function save($args)
    {
        parent::save([
            'allowTeamCreation' => empty($args['allowTeamCreation']) ? 0 : 1,
            'poolID' => (int) ($args['poolID'] ?? 0),
        ]);
    }

    public function view()
    {
        parent::view();
        $me = $this->getCurrentUserInfo();
        $pool = $this->getPool();
        $this->set('pool', $pool);
        // the pool the block was limited to has been deleted, the block stays inactive until it is edited
        $this->set('poolMissing', $this->poolID && !$pool);
        $teams = [];
        $invitations = [];
        $openJoinRequests = [];
        $openInvites = [];

        if ($me) {
            $teamRepository = $this->app->make(TeamRepository::class);
            $requestRepository = $this->app->make(TeamRequestRepository::class);
            $service = $this->app->make(TeamService::class);

            foreach ($requestRepository->getPendingForUser((int) $me->getUserID(), TeamRequest::TYPE_INVITE) as $request) {
                if ($this->inScope($request->getTeam())) {
                    $invitations[] = ['request' => $request, 'team' => $request->getTeam()];
                }
            }

            $teams = array_values(array_filter($teamRepository->getForUser((int) $me->getUserID()), [$this, 'inScope']));
            foreach ($teams as $team) {
                if ($service->isCaptain($team, $me)) {
                    $openJoinRequests[$team->getID()] = $requestRepository->getPendingForTeam($team->getID(), TeamRequest::TYPE_JOIN);
                    $openInvites[$team->getID()] = $requestRepository->getPendingForTeam($team->getID(), TeamRequest::TYPE_INVITE);
                }
            }
        }

        $this->set('teams', $teams);
        $this->set('invitations', $invitations);
        $this->set('openJoinRequests', $openJoinRequests);
        $this->set('openInvites', $openInvites);
        $this->set('userInfoRepository', $this->app->make(\Concrete\Core\User\UserInfoRepository::class));
        $mySingles = $me ? $this->app->make(TeamPoolRepository::class)->getSinglesOfUser((int) $me->getUserID()) : [];
        $this->set('mySingles', array_values(array_filter($mySingles, function ($single) use ($pool) {
            return !$this->poolID || ($pool && $single->getPool()->getID() === $pool->getID());
        })));
        $this->set('creationBlockedReason', $this->getCreationBlockedReason($pool));
    }

    public function action_create_team($bID = null)
    {
        return $this->handle('team_create', function (UserInfo $me, TeamService $service) {
            $this->assertActive();
            if (!$this->allowTeamCreation) {
                throw new UserMessageException(t('Creating teams is not allowed here.'));
            }
            // the pool settings (open, allow teams, max. teams) are checked by the service
            $team = $service->create(
                (string) $this->request->request->get('name'),
                (string) $this->request->request->get('tag'),
                (string) $this->request->request->get('description'),
                $me,
                true,
                $this->getPool()
            );

            return t('Team %s has been created. You are its captain.', $team->getName());
        });
    }

    public function action_update_team($bID = null)
    {
        return $this->handle('team_update', function (UserInfo $me, TeamService $service) {
            $team = $this->postedTeam();
            if ($this->request->request->get('removeLogo')) {
                $service->removeLogo($team, $me);
            }
            $team = $service->update(
                $team,
                (string) $this->request->request->get('name'),
                (string) $this->request->request->get('tag'),
                (string) $this->request->request->get('description'),
                $me,
                $this->request->files->get('logo')
            );

            return t('Team %s has been updated.', $team->getName());
        });
    }

    public function action_invite_member($bID = null)
    {
        return $this->handle('team_invite', function (UserInfo $me, TeamService $service) {
            $team = $this->postedTeam();
            $invitee = $this->postedUser();
            $service->invite($team, $invitee, $me);

            return t('%s has been invited to %s.', $invitee->getUserName(), $team->getName());
        });
    }

    public function action_respond_request($bID = null)
    {
        return $this->handle('team_respond', function (UserInfo $me, TeamService $service) {
            $accept = (bool) $this->request->request->get('accept');
            $service->respond($this->postedRequest(), $accept, $me);

            return $accept ? t('Request accepted.') : t('Request declined.');
        });
    }

    public function action_cancel_request($bID = null)
    {
        return $this->handle('team_cancel', function (UserInfo $me, TeamService $service) {
            $service->cancel($this->postedRequest(), $me);

            return t('The request has been withdrawn.');
        });
    }

    public function action_set_captain($bID = null)
    {
        return $this->handle('team_role', function (UserInfo $me, TeamService $service) {
            $captain = (bool) $this->request->request->get('captain');
            $service->setCaptain($this->postedTeam(), (int) $this->request->request->get('user'), $captain, $me);

            return $captain ? t('The member is now a captain.') : t('The captain is now a regular member.');
        });
    }

    public function action_remove_member($bID = null)
    {
        return $this->handle('team_remove', function (UserInfo $me, TeamService $service) {
            $team = $this->postedTeam();
            $uID = (int) $this->request->request->get('user');
            $service->removeMember($team, $uID, $me);

            return $uID === (int) $me->getUserID()
                ? t('You have left %s.', $team->getName())
                : t('The member has been removed from %s.', $team->getName());
        });
    }

    public function action_disband_team($bID = null)
    {
        return $this->handle('team_disband', function (UserInfo $me, TeamService $service) {
            $team = $this->postedTeam();
            $service->disband($team, $me);

            return t('Team %s has been disbanded.', $team->getName());
        });
    }

    public function action_leave_pool($bID = null)
    {
        return $this->handle('team_pool_leave', function (UserInfo $me, TeamService $service) {
            $single = $this->app->make(TeamPoolRepository::class)->getSingle((int) $this->request->request->get('single'));
            if (!$single || ($this->poolID && $single->getPool()->getID() !== (int) $this->poolID)) {
                throw new UserMessageException(t('You are not listed in this pool anymore.'));
            }
            $service->leavePool($single, $me);

            return t('You are no longer listed in %s.', $single->getPool()->getName());
        });
    }

    /**
     * Only teams of the configured pool can be changed through this block.
     */
    protected function postedTeam(): Team
    {
        $team = parent::postedTeam();
        $this->assertInScope($team);

        return $team;
    }

    protected function postedRequest(): TeamRequest
    {
        $request = parent::postedRequest();
        $this->assertInScope($request->getTeam());

        return $request;
    }

    protected function getPool(): ?TeamPool
    {
        return $this->poolID ? $this->app->make(TeamPoolRepository::class)->getByID((int) $this->poolID) : null;
    }

    /**
     * @param Team $team
     */
    public function inScope($team): bool
    {
        if (!$this->poolID) {
            return true;
        }

        return $team->getPool() !== null && $team->getPool()->getID() === (int) $this->poolID;
    }

    protected function assertActive(): void
    {
        if ($this->poolID && !$this->getPool()) {
            throw new UserMessageException(t('The pool of this block does not exist anymore.'));
        }
    }

    protected function assertInScope(Team $team): void
    {
        $this->assertActive();
        if (!$this->inScope($team)) {
            throw new UserMessageException(t('%s is not part of %s.', $team->getName(), $this->getPool()->getName()));
        }
    }

    /**
     * Why users can't create a team in the block's pool right now, null if they can.
     */
    protected function getCreationBlockedReason(?TeamPool $pool): ?string
    {
        if (!$pool) {
            return null;
        }
        if (!$pool->isOpen() || !$pool->allowsTeams()) {
            return t('%s does not accept new teams at the moment.', $pool->getName());
        }
        if ($pool->getMaxTeams() > 0 && $this->app->make(TeamPoolRepository::class)->countTeams($pool) >= $pool->getMaxTeams()) {
            return t('%s is full (%s teams max).', $pool->getName(), $pool->getMaxTeams());
        }

        return null;
    }

    /**
     * Username autocomplete for the invite form.
     */
    public function action_search_users($bID = null)
    {
        $keywords = trim((string) $this->request->query->get('q'));
        if (!$this->getCurrentUserInfo() || mb_strlen($keywords) < 2) {
            return new JsonResponse([]);
        }
        $names = $this->app->make(Connection::class)->fetchFirstColumn(
            'select uName from Users where uIsActive = 1 and uName like ? order by uName limit 10',
            [addcslashes($keywords, '%_\\') . '%']
        );

        return new JsonResponse($names);
    }
}
