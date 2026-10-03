<?php
namespace Concrete\Package\TeamManager\Block\MyTeams;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\UserInfo;
use Symfony\Component\HttpFoundation\JsonResponse;
use TeamManager\Block\AbstractTeamBlockController;
use TeamManager\Entity\TeamRequest;
use TeamManager\Team\TeamPoolRepository;
use TeamManager\Team\TeamRepository;
use TeamManager\Team\TeamRequestRepository;
use TeamManager\Team\TeamService;

class Controller extends AbstractTeamBlockController
{
    protected $btDefaultSet = 'social';

    public function getBlockTypeName()
    {
        return t('My Teams');
    }

    public function getBlockTypeDescription()
    {
        return t('Lets users create and manage their teams, invite members and answer invitations.');
    }

    public function view()
    {
        parent::view();
        $me = $this->getCurrentUserInfo();
        $teams = [];
        $invitations = [];
        $openJoinRequests = [];
        $openInvites = [];

        if ($me) {
            $teamRepository = $this->app->make(TeamRepository::class);
            $requestRepository = $this->app->make(TeamRequestRepository::class);
            $service = $this->app->make(TeamService::class);

            foreach ($requestRepository->getPendingForUser((int) $me->getUserID(), TeamRequest::TYPE_INVITE) as $request) {
                $invitations[] = ['request' => $request, 'team' => $request->getTeam()];
            }

            $teams = $teamRepository->getForUser((int) $me->getUserID());
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
        $this->set('mySingles', $me ? $this->app->make(TeamPoolRepository::class)->getSinglesOfUser((int) $me->getUserID()) : []);
        // the pool settings decide where users can create teams
        $this->set('creationPools', $me ? $this->app->make(TeamService::class)->getPoolsForTeamCreation($me) : []);
    }

    public function action_create_team($bID = null)
    {
        return $this->handle('team_create', function (UserInfo $me, TeamService $service) {
            // the pool settings (open, allow team creation, max. teams) are checked by the service
            $team = $service->create(
                (string) $this->request->request->get('name'),
                (string) $this->request->request->get('tag'),
                (string) $this->request->request->get('description'),
                $me,
                true,
                $this->app->make(TeamPoolRepository::class)->getByID((int) $this->request->request->get('pool'))
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
            if (!$single) {
                throw new UserMessageException(t('You are not listed in this pool anymore.'));
            }
            $service->leavePool($single, $me);

            return t('You are no longer listed in %s.', $single->getPool()->getName());
        });
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
