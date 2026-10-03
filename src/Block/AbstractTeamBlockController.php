<?php
namespace TeamManager\Block;

use Concrete\Core\Block\BlockController;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Page\Page;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Symfony\Component\HttpFoundation\Response;
use TeamManager\Entity\TeamRequest;
use TeamManager\Entity\Team;
use TeamManager\Team\TeamRepository;
use TeamManager\Team\TeamRequestRepository;
use TeamManager\Team\TeamService;

/**
 * Shared plumbing for the team blocks: every action is a token protected POST that redirects
 * back to the page (post/redirect/get) and reports its outcome through flash messages.
 */
abstract class AbstractTeamBlockController extends BlockController
{
    protected $btCacheBlockRecord = true;
    protected $btCacheBlockOutput = false;
    protected $btCacheBlockOutputOnPost = false;
    protected $btCacheBlockOutputForRegisteredUsers = false;

    const FLASH_KEY = 'team_manager';

    public function view()
    {
        $this->set('me', $this->getCurrentUserInfo());
        $this->set('teamService', $this->app->make(TeamService::class));
        $this->set('flashMessages', $this->app->make('session')->getFlashBag()->get(self::FLASH_KEY . '.' . $this->bID));
        $this->set('token', $this->app->make('token'));
    }

    protected function getCurrentUserInfo(): ?UserInfo
    {
        $user = $this->app->make(User::class);

        return $user->isRegistered() ? $user->getUserInfoObject() : null;
    }

    /**
     * Runs a team action and redirects back to the page.
     *
     * @param string $action token action, the form must post a token generated for this action
     * @param callable $callback receives the current UserInfo and returns a success message
     * @param array $query query string for the redirect, e.g. to stay on a team detail view
     */
    protected function handle(string $action, callable $callback, array $query = []): Response
    {
        $type = 'danger';
        try {
            if (!$this->request->isMethod('POST')) {
                throw new UserMessageException(t('Invalid request method.'));
            }
            if (!$this->app->make('token')->validate($action)) {
                throw new UserMessageException($this->app->make('token')->getErrorMessage());
            }
            $me = $this->getCurrentUserInfo();
            if (!$me) {
                throw new UserMessageException(t('You must be logged in.'));
            }
            $service = $this->app->make(TeamService::class);
            $service->getNotifier()->setLink((string) $this->getPageURL());
            $message = $callback($me, $service);
            $type = 'success';
        } catch (UserMessageException $e) {
            $message = $e->getMessage();
        }
        if ($message) {
            $this->app->make('session')->getFlashBag()->add(self::FLASH_KEY . '.' . $this->bID, ['type' => $type, 'text' => $message]);
        }

        $url = (string) $this->getPageURL();
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        return $this->buildRedirect($url);
    }

    protected function getPageURL()
    {
        return $this->app->make('url/resolver/page')->resolve([Page::getCurrentPage()]);
    }

    protected function postedTeam(): Team
    {
        $team = $this->app->make(TeamRepository::class)->getByID((int) $this->request->request->get('team'));
        if (!$team) {
            throw new UserMessageException(t('The team does not exist.'));
        }

        return $team;
    }

    protected function postedRequest(): TeamRequest
    {
        $request = $this->app->make(TeamRequestRepository::class)->getByID((int) $this->request->request->get('request'));
        if (!$request) {
            throw new UserMessageException(t('This request is no longer open.'));
        }

        return $request;
    }

    protected function postedUser(string $field = 'user'): UserInfo
    {
        $name = trim((string) $this->request->request->get($field));
        $userInfo = $name === '' ? null : $this->app->make(UserInfoRepository::class)->getByName($name);
        if (!$userInfo || !$userInfo->isActive()) {
            throw new UserMessageException(t('User "%s" not found.', $name));
        }

        return $userInfo;
    }
}
