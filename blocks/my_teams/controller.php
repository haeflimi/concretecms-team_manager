<?php
namespace Concrete\Package\TeamManager\Block\MyTeams;

use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\UserInfo;
use Symfony\Component\HttpFoundation\JsonResponse;
use TeamManager\Block\AbstractTeamBlockController;
use TeamManager\Entity\TeamRequest;
use TeamManager\Team\TeamPoolRepository;
use TeamManager\Team\TeamRepository;
use TeamManager\Team\TeamRequestRepository;
use TeamManager\Team\TeamService;
use TeamManager\Team\UserSearch;

class Controller extends AbstractTeamBlockController
{
    protected $btDefaultSet = 'social';

    public function getBlockTypeName()
    {
        return t('My Teams');
    }

    public function getBlockTypeDescription()
    {
        return t('Lets users manage their teams, invite members and answer invitations.');
    }

    public function view()
    {
        parent::view();
        $this->requireAsset('team_manager/user-search');
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
     * User autocomplete for the invite form (js/user-search.js), without email addresses. The team gives each
     * user's state (already a member, in another team of the pool, ...), only for teams the current user captains.
     */
    public function action_search_users($bID = null)
    {
        $me = $this->getCurrentUserInfo();
        if (!$me) {
            return new JsonResponse([]);
        }
        $team = $this->app->make(TeamRepository::class)->getByID((int) $this->request->query->get('team'));
        if ($team && !$this->app->make(TeamService::class)->isCaptain($team, $me)) {
            $team = null;
        }

        return new JsonResponse($this->app->make(UserSearch::class)->search(
            (string) $this->request->query->get('q'),
            UserSearch::MODE_INVITE,
            $team
        ));
    }
}
