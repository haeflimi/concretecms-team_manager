<?php
namespace Concrete\Package\TeamManager\Block\TeamDirectory;

use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfo;
use TeamManager\Block\AbstractTeamBlockController;
use TeamManager\Entity\TeamPool;
use TeamManager\Entity\TeamRequest;
use TeamManager\Entity\Team;
use TeamManager\Team\Pagination;
use TeamManager\Team\TeamPoolRepository;
use TeamManager\Team\TeamRepository;
use TeamManager\Team\TeamRequestRepository;
use TeamManager\Team\TeamService;

class Controller extends AbstractTeamBlockController
{
    protected $btTable = 'btTeamManagerDirectory';
    protected $btInterfaceWidth = 500;
    protected $btInterfaceHeight = 450;
    protected $btDefaultSet = 'social';

    public $itemsPerPage;
    public $myTeamsPageID;
    /** @var int pool joining is limited to, 0 = all pools and teams without pool */
    public $joinPoolID;
    /** @var int only show the pool selected in joinPoolID */
    public $onlyListJoinPool;

    public function getBlockTypeName()
    {
        return t('Team Directory');
    }

    public function getBlockTypeDescription()
    {
        return t('Lists team pools and teams, lets users join pools as single player and request to join teams.');
    }

    public function add()
    {
        $this->set('itemsPerPage', 20);
        $this->set('myTeamsPageID', 0);
        $this->set('joinPoolID', 0);
        $this->set('onlyListJoinPool', 0);
        $this->setPoolOptions();
    }

    public function edit()
    {
        $this->setPoolOptions();
    }

    public function save($args)
    {
        $joinPoolID = (int) ($args['joinPoolID'] ?? 0);
        parent::save([
            'itemsPerPage' => max(1, (int) ($args['itemsPerPage'] ?? 20)),
            'myTeamsPageID' => (int) ($args['myTeamsPageID'] ?? 0),
            'joinPoolID' => $joinPoolID,
            'onlyListJoinPool' => $joinPoolID && !empty($args['onlyListJoinPool']) ? 1 : 0,
        ]);
    }

    public function view()
    {
        parent::view();
        $me = $this->getCurrentUserInfo();
        $teams = $this->app->make(TeamRepository::class);
        $pools = $this->app->make(TeamPoolRepository::class);
        $service = $this->app->make(TeamService::class);
        $joinPool = $this->getJoinPool();

        // join requests I sent, keyed by team, to show their state in the lists
        $myJoinRequests = [];
        // teams I captain, to register them in pools and invite single players
        $myCaptainTeams = [];
        if ($me) {
            $requests = $this->app->make(TeamRequestRepository::class)->getPendingForUser((int) $me->getUserID(), TeamRequest::TYPE_JOIN);
            foreach ($requests as $request) {
                $myJoinRequests[$request->getTeamID()] = $request;
            }
            foreach ($teams->getForUser((int) $me->getUserID()) as $team) {
                if ($service->isCaptain($team, $me)) {
                    $myCaptainTeams[] = $team;
                }
            }
        }

        $myTeamsPage = $this->myTeamsPageID ? Page::getByID($this->myTeamsPageID) : null;
        $this->set('myTeamsPage', $myTeamsPage && !$myTeamsPage->isError() ? $myTeamsPage : null);
        $this->set('pageURL', (string) $this->getPageURL());
        $this->set('myJoinRequests', $myJoinRequests);
        $this->set('myCaptainTeams', $myCaptainTeams);
        $this->set('joinPool', $joinPool);
        $this->set('canJoin', function (?TeamPool $pool) {
            return $this->canJoinIn($pool);
        });
        $this->set('team', null);
        $this->set('pool', null);

        $team = $teams->getByID((int) $this->request->query->get('team'));
        if ($team && $this->isListed($team->getPool())) {
            $this->set('mode', 'team');
            $this->set('team', $team);

            return;
        }

        $pool = $this->onlyListJoinPool ? $joinPool : $pools->getByID((int) $this->request->query->get('pool'));
        if ($pool) {
            $this->set('mode', 'pool');
            $this->set('pool', $pool);
            $this->set('poolTeams', $teams->findAll($pool));
            $this->set('poolTeamCount', $pools->countTeams($pool));
            $this->set('mySingle', $me ? $pools->findSingle($pool, (int) $me->getUserID()) : null);
            $myPoolTeam = $me ? $pools->getTeamOfUser($pool, (int) $me->getUserID()) : null;
            $this->set('myPoolTeamID', $myPoolTeam ? $myPoolTeam->getID() : null);

            return;
        }

        // overview: pools, then teams without pool (or all matching teams when searching)
        $keywords = trim((string) $this->request->query->get('keywords'));
        $page = $teams->paginate($keywords === '' ? false : null, $keywords, Pagination::getCurrentPage(), (int) $this->itemsPerPage ?: 20);

        $poolList = [];
        foreach ($pools->getAll() as $item) {
            $poolList[] = [
                'pool' => $item,
                'teams' => $pools->countTeams($item),
                'singles' => $item->getSingles()->count(),
            ];
        }

        $this->set('mode', 'overview');
        $this->set('pools', $poolList);
        $this->set('keywords', $keywords);
        $this->set('teams', $page['items']);
        $this->set('pagination', Pagination::render($page, (string) $this->getPageURL(), ['keywords' => $keywords]));
    }

    public function action_request_join($bID = null)
    {
        return $this->handle('team_join', function (UserInfo $me, TeamService $service) {
            $team = $this->postedTeam();
            $this->assertCanJoinIn($team->getPool());
            $service->requestJoin($team, $me);

            return t('Your request to join %s has been sent to the captains.', $team->getName());
        }, $this->getReturnQuery());
    }

    public function action_withdraw_join($bID = null)
    {
        return $this->handle('team_withdraw', function (UserInfo $me, TeamService $service) {
            $service->cancel($this->postedRequest(), $me);

            return t('Your join request has been withdrawn.');
        }, $this->getReturnQuery());
    }

    public function action_join_pool($bID = null)
    {
        return $this->handle('team_pool_join', function (UserInfo $me, TeamService $service) {
            $pool = $this->postedPool();
            $this->assertCanJoinIn($pool);
            $service->joinPool($pool, $me, (string) $this->request->request->get('note'));

            return t('You are now listed in %s as looking for a team.', $pool->getName());
        }, $this->getReturnQuery());
    }

    public function action_leave_pool($bID = null)
    {
        return $this->handle('team_pool_leave', function (UserInfo $me, TeamService $service) {
            $pool = $this->postedPool();
            $single = $this->app->make(TeamPoolRepository::class)->findSingle($pool, (int) $me->getUserID());
            if ($single) {
                $service->leavePool($single, $me);
            }

            return t('You are no longer listed in %s.', $pool->getName());
        }, $this->getReturnQuery());
    }

    public function action_register_team($bID = null)
    {
        return $this->handle('team_pool_register', function (UserInfo $me, TeamService $service) {
            $pool = $this->postedPool();
            $team = $this->postedTeam();
            $this->assertCanJoinIn($pool);
            $service->setTeamPool($team, $pool, $me);

            return t('%s is now part of %s.', $team->getName(), $pool->getName());
        }, $this->getReturnQuery());
    }

    public function action_invite_single($bID = null)
    {
        return $this->handle('team_pool_invite', function (UserInfo $me, TeamService $service) {
            $pools = $this->app->make(TeamPoolRepository::class);
            $single = $pools->getSingle((int) $this->request->request->get('single'));
            $team = $this->postedTeam();
            if (!$single || !$team->getPool() || $team->getPool()->getID() !== $single->getPool()->getID()) {
                throw new UserMessageException(t('This player is not looking for a team in the pool of %s anymore.', $team->getName()));
            }
            $invitee = $this->app->make(\Concrete\Core\User\UserInfoRepository::class)->getByID($single->getUserID());
            if (!$invitee) {
                throw new UserMessageException(t('The user does not exist anymore.'));
            }
            $service->invite($team, $invitee, $me);

            return t('%s has been invited to %s.', $invitee->getUserName(), $team->getName());
        }, $this->getReturnQuery());
    }

    protected function getJoinPool(): ?TeamPool
    {
        return $this->joinPoolID ? $this->app->make(TeamPoolRepository::class)->getByID((int) $this->joinPoolID) : null;
    }

    /**
     * Joining (teams, pools) can be limited to one pool in the block options.
     */
    protected function canJoinIn(?TeamPool $pool): bool
    {
        if (!$this->joinPoolID) {
            return true;
        }

        return $pool !== null && $pool->getID() === (int) $this->joinPoolID;
    }

    protected function assertCanJoinIn(?TeamPool $pool): void
    {
        if (!$this->canJoinIn($pool)) {
            $joinPool = $this->getJoinPool();
            throw new UserMessageException($joinPool
                ? t('Here you can only join %s.', $joinPool->getName())
                : t('Joining is not possible here.'));
        }
    }

    /**
     * With "only list the selected pool", teams outside of it aren't shown either.
     */
    protected function isListed(?TeamPool $pool): bool
    {
        return !$this->onlyListJoinPool || $this->canJoinIn($pool);
    }

    protected function postedPool(): TeamPool
    {
        $pool = $this->app->make(TeamPoolRepository::class)->getByID((int) $this->request->request->get('pool'));
        if (!$pool) {
            throw new UserMessageException(t('The pool does not exist.'));
        }

        return $pool;
    }

    /**
     * Forms post the view they were sent from (team / pool detail) to return there.
     */
    protected function getReturnQuery(): array
    {
        parse_str((string) $this->request->request->get('returnQuery'), $query);

        return array_filter(array_intersect_key(array_map('intval', $query), ['team' => 0, 'pool' => 0]));
    }

    protected function setPoolOptions(): void
    {
        $options = [0 => t('All pools and teams without pool')];
        foreach ($this->app->make(TeamPoolRepository::class)->getAll() as $pool) {
            $options[$pool->getID()] = $pool->getName();
        }
        $this->set('poolOptions', $options);
    }
}
