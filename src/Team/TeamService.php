<?php
namespace TeamManager\Team;

use Concrete\Core\Application\Application;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\File\Import\FileImporter;
use Concrete\Core\File\Import\ImportOptions;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use TeamManager\Entity\Team;
use TeamManager\Entity\TeamMember;
use TeamManager\Entity\TeamPool;
use TeamManager\Entity\TeamPoolUser;
use TeamManager\Entity\TeamRequest;
use TeamManager\Team\Event\TeamEvent;

/**
 * All changes to teams go through this service. Every method validates permissions and throws a
 * UserMessageException with a message that can be shown to the user if something is not allowed.
 *
 * Teams live in the package tables only. Pools are core groups, their membership is kept in sync
 * by TeamPoolMembership after every change.
 */
class TeamService
{
    /** @var Application */
    protected $app;
    /** @var TeamConfig */
    protected $config;
    /** @var TeamRepository */
    protected $teams;
    /** @var TeamRequestRepository */
    protected $requests;
    /** @var TeamPoolRepository */
    protected $pools;
    /** @var TeamPoolMembership */
    protected $poolMembership;
    /** @var TeamNotifier */
    protected $notifier;
    /** @var EntityManagerInterface */
    protected $em;
    /** @var UserInfoRepository */
    protected $userInfoRepository;
    /** @var bool see asAdmin() */
    protected $adminMode = false;

    public function __construct(
        Application $app,
        TeamConfig $config,
        TeamRepository $teams,
        TeamRequestRepository $requests,
        TeamPoolRepository $pools,
        TeamPoolMembership $poolMembership,
        TeamNotifier $notifier,
        EntityManagerInterface $em,
        UserInfoRepository $userInfoRepository
    ) {
        $this->app = $app;
        $this->config = $config;
        $this->teams = $teams;
        $this->requests = $requests;
        $this->pools = $pools;
        $this->poolMembership = $poolMembership;
        $this->notifier = $notifier;
        $this->em = $em;
        $this->userInfoRepository = $userInfoRepository;
    }

    public function getNotifier(): TeamNotifier
    {
        return $this->notifier;
    }

    /**
     * Returns a service instance for administrators (dashboard): captain checks, the team size limit and the
     * pool settings (open, allow teams / singles, max teams) are skipped and teams are not deleted automatically
     * when their last member is removed. The one-team-per-pool rule still applies.
     * Access control is up to the caller, e.g. the dashboard page permissions.
     */
    public function asAdmin(): self
    {
        $service = clone $this;
        $service->adminMode = true;

        return $service;
    }

    public function isAdminMode(): bool
    {
        return $this->adminMode;
    }

    /**
     * @param bool $joinAsCaptain false creates an empty team (used by admins), members are added with addMember()
     * @param TeamPool|null $pool pool to create the team in
     */
    public function create(string $name, string $tag, string $description, UserInfo $creator, bool $joinAsCaptain = true, ?TeamPool $pool = null): Team
    {
        [$name, $tag] = $this->validateProfile($name, $tag, 0);
        if ($pool) {
            $this->assertPoolAcceptsTeam($pool);
            if ($joinAsCaptain) {
                $this->assertFreeInPool($pool, $creator);
            }
        }

        $team = new Team($name, (int) $creator->getUserID());
        $team->setTag($tag)->setDescription(trim($description))->setPool($pool);
        $this->em->persist($team);
        $this->em->flush();

        if ($joinAsCaptain) {
            $this->enterTeam($team, $creator, true);
        }
        $this->dispatch('on_team_create', $team, $creator, $creator);

        return $team;
    }

    public function update(Team $team, string $name, string $tag, string $description, UserInfo $actor, ?UploadedFile $logo = null): Team
    {
        $this->assertCaptain($team, $actor);
        [$name, $tag] = $this->validateProfile($name, $tag, $team->getID());

        $team->setName($name)->setTag($tag)->setDescription(trim($description));
        if ($logo) {
            $team->setLogoFileID((int) $this->importLogo($logo)->getFileID());
        }
        $this->em->flush();
        $this->dispatch('on_team_update', $team, null, $actor);

        return $team;
    }

    public function removeLogo(Team $team, UserInfo $actor): void
    {
        $this->assertCaptain($team, $actor);
        $team->setLogoFileID(null);
        $this->em->flush();
    }

    public function invite(Team $team, UserInfo $invitee, UserInfo $actor): TeamRequest
    {
        $this->assertCaptain($team, $actor);
        $this->assertCanJoin($team, $invitee);

        $request = new TeamRequest($team, (int) $invitee->getUserID(), TeamRequest::TYPE_INVITE, (int) $actor->getUserID());
        $this->em->persist($request);
        $this->em->flush();
        $this->notifier->invite($team, $invitee, $actor);

        return $request;
    }

    public function requestJoin(Team $team, UserInfo $user): TeamRequest
    {
        $this->assertCanJoin($team, $user);

        $request = new TeamRequest($team, (int) $user->getUserID(), TeamRequest::TYPE_JOIN, (int) $user->getUserID());
        $this->em->persist($request);
        $this->em->flush();
        $this->notifier->joinRequest($team, $user);

        return $request;
    }

    /**
     * Accept or decline an invitation (by the invitee) or a join request (by a captain).
     */
    public function respond(TeamRequest $request, bool $accept, UserInfo $actor): void
    {
        $team = $this->getRequestTeam($request);
        if ($request->isInvite() && (int) $actor->getUserID() !== $request->getUserID()) {
            throw new UserMessageException(t('This invitation is not addressed to you.'));
        }
        if ($request->isJoinRequest()) {
            $this->assertCaptain($team, $actor);
        }

        $user = $this->userInfoRepository->getByID($request->getUserID());
        if (!$user) {
            throw new UserMessageException(t('The user does not exist anymore.'));
        }
        if ($accept) {
            $this->assertCapacity($team);
            $this->enterTeam($team, $user, false);
        }
        $request->resolve($accept ? TeamRequest::STATUS_ACCEPTED : TeamRequest::STATUS_DECLINED, (int) $actor->getUserID());
        $this->em->flush();

        // inviting captain resp. joining user get to know the answer
        $creator = $this->userInfoRepository->getByID($request->getCreatedBy());
        if ($creator && $creator->getUserID() != $actor->getUserID()) {
            $this->notifier->requestAnswered($team, $creator, $actor, $request->isInvite(), $accept);
        }

        if ($accept) {
            // an accepted invite makes a pending join request of the same user obsolete and vice versa
            $this->requests->cancelPending($team->getID(), (int) $user->getUserID(), (int) $actor->getUserID());
            $this->dispatch('on_team_member_join', $team, $user, $actor);
        }
    }

    /**
     * Withdraw an invitation (captains) or a join request (the requester or captains).
     */
    public function cancel(TeamRequest $request, UserInfo $actor): void
    {
        $team = $this->getRequestTeam($request);
        $isOwnJoinRequest = $request->isJoinRequest() && (int) $actor->getUserID() === $request->getUserID();
        if (!$isOwnJoinRequest) {
            $this->assertCaptain($team, $actor);
        }
        $request->resolve(TeamRequest::STATUS_CANCELLED, (int) $actor->getUserID());
        $this->em->flush();
    }

    public function setCaptain(Team $team, int $uID, bool $captain, UserInfo $actor): void
    {
        $this->assertCaptain($team, $actor);
        $member = $team->getMember($uID);
        if (!$member) {
            throw new UserMessageException(t('This user is not a member of the team.'));
        }
        if (!$captain && $member->isCaptain() && count($team->getCaptains()) === 1) {
            throw new UserMessageException(t('A team needs at least one captain. Promote somebody else first.'));
        }
        $member->setCaptain($captain);
        $this->em->flush();
        $this->dispatch('on_team_role_change', $team, $member->getUserInfo(), $actor);
    }

    /**
     * Adds a user directly, without invitation. A team without captain gets the new member as captain.
     */
    public function addMember(Team $team, UserInfo $user, UserInfo $actor, bool $captain = false): void
    {
        $this->assertCaptain($team, $actor);
        if ($team->hasMember((int) $user->getUserID())) {
            throw new UserMessageException(t('%s is already a member of %s.', $user->getUserName(), $team->getName()));
        }
        $this->addMemberUnchecked($team, $user, $actor, $captain);
    }

    /**
     * Moves a member to another team. The member is added to the target first, so a failure leaves them where they were.
     */
    public function moveMember(Team $from, Team $to, int $uID, UserInfo $actor, bool $keepCaptain = false): void
    {
        if ($from->getID() === $to->getID()) {
            return;
        }
        $this->assertCaptain($from, $actor);
        $this->assertCaptain($to, $actor);
        $member = $from->getMember($uID);
        if (!$member || !$member->getUserInfo()) {
            throw new UserMessageException(t('This user is not a member of %s.', $from->getName()));
        }
        if ($to->hasMember($uID)) {
            throw new UserMessageException(t('%s is already a member of %s.', $member->getUserName(), $to->getName()));
        }
        // the member leaves $from, so being in $from doesn't count for the one-team-per-pool rule
        $this->addMemberUnchecked($to, $member->getUserInfo(), $actor, $keepCaptain && $member->isCaptain(), [$from->getID()]);
        $this->removeMember($from, $uID, $actor);
    }

    /**
     * Removes a member. Members can remove themselves (leave), captains can remove others (kick).
     */
    public function removeMember(Team $team, int $uID, UserInfo $actor): void
    {
        if ((int) $actor->getUserID() !== $uID) {
            $this->assertCaptain($team, $actor);
        }
        $member = $team->getMember($uID);
        if (!$member) {
            throw new UserMessageException(t('This user is not a member of the team.'));
        }

        $userInfo = $member->getUserInfo();
        $team->getMemberCollection()->removeElement($member);
        $this->em->remove($member);
        $this->em->flush();
        if ($team->getPool()) {
            $this->poolMembership->syncUser($team->getPool(), $uID);
        }
        $this->dispatch('on_team_member_leave', $team, $userInfo, $actor);

        $remaining = $team->getMembers();
        if (count($remaining) === 0) {
            // admins may want to refill a team, users leaving an empty team just leave it behind
            if (!$this->adminMode) {
                $this->deleteTeam($team, $actor);
            }
        } elseif (count($team->getCaptains()) === 0) {
            // the members are ordered by join date, the longest standing one takes over
            $remaining[0]->setCaptain(true);
            $this->em->flush();
            $this->dispatch('on_team_role_change', $team, $remaining[0]->getUserInfo(), $actor);
        }
    }

    public function disband(Team $team, UserInfo $actor): void
    {
        $this->assertCaptain($team, $actor);
        $this->deleteTeam($team, $actor);
    }

    /**
     * Removes the user from all teams and pools, used when a user is deleted.
     */
    public function removeUserEverywhere(int $uID): void
    {
        $admin = $this->asAdmin();
        $actor = $this->userInfoRepository->getByID($uID);
        foreach ($this->teams->getForUser($uID) as $team) {
            $member = $team->getMember($uID);
            $team->getMemberCollection()->removeElement($member);
            $this->em->remove($member);
            $this->em->flush();
            $remaining = $team->getMembers();
            if ($remaining && !$team->getCaptains()) {
                $remaining[0]->setCaptain(true);
                $this->em->flush();
            } elseif (!$remaining && $actor) {
                $admin->deleteTeam($team, $actor);
            }
        }
        foreach ($this->pools->getSinglesOfUser($uID) as $single) {
            $this->em->remove($single);
        }
        $this->requests->deleteForUser($uID);
        $this->em->flush();
    }

    /**
     * Puts a team into a pool or takes it out of its pool ($pool = null). Captains need an open pool that allows teams.
     */
    public function setTeamPool(Team $team, ?TeamPool $pool, UserInfo $actor): void
    {
        $this->assertCaptain($team, $actor);
        $current = $team->getPool();
        if (($current ? $current->getID() : 0) === ($pool ? $pool->getID() : 0)) {
            return;
        }
        if ($pool) {
            $this->assertPoolAcceptsTeam($pool);
            foreach ($team->getMembers() as $member) {
                if ($member->getUserInfo()) {
                    $this->assertFreeInPool($pool, $member->getUserInfo(), [$team->getID()]);
                }
            }
        }

        $memberIDs = array_map(function (TeamMember $member) {
            return $member->getUserID();
        }, $team->getMembers());
        $team->setPool($pool);
        if ($pool) {
            // members of the team are not looking for a team in this pool anymore
            foreach ($memberIDs as $uID) {
                $this->removeSingle($pool, $uID);
            }
        }
        $this->em->flush();

        foreach (array_filter([$current, $pool]) as $affected) {
            $this->poolMembership->syncUsers($affected, $memberIDs);
        }
        $this->dispatch('on_team_pool_change', $team, null, $actor);
    }

    /**
     * A user joins a pool as free agent looking for a team.
     */
    public function joinPool(TeamPool $pool, UserInfo $user, string $note = ''): TeamPoolUser
    {
        if (!$this->adminMode && (!$pool->isOpen() || !$pool->allowsSingles())) {
            throw new UserMessageException(t('%s does not accept single players.', $pool->getName()));
        }
        if ($this->pools->findSingle($pool, (int) $user->getUserID())) {
            throw new UserMessageException(t('%s is already looking for a team in %s.', $user->getUserName(), $pool->getName()));
        }
        $this->assertFreeInPool($pool, $user);

        $single = new TeamPoolUser($pool, (int) $user->getUserID(), mb_substr(trim($note), 0, 255));
        $this->em->persist($single);
        $this->em->flush();
        $this->poolMembership->syncUser($pool, (int) $user->getUserID());

        return $single;
    }

    public function leavePool(TeamPoolUser $single, UserInfo $actor): void
    {
        if (!$this->adminMode && $single->getUserID() !== (int) $actor->getUserID()) {
            throw new UserMessageException(t('You can only remove yourself from a pool.'));
        }
        $pool = $single->getPool();
        $uID = $single->getUserID();
        $pool->getSingles()->removeElement($single);
        $this->em->remove($single);
        $this->em->flush();
        $this->poolMembership->syncUser($pool, $uID);
    }

    /**
     * Adds a free agent of a pool to a team of the same pool (captain of that team or admin).
     */
    public function assignSingle(TeamPoolUser $single, Team $team, UserInfo $actor): void
    {
        $this->assertCaptain($team, $actor);
        $pool = $team->getPool();
        if (!$pool || $pool->getID() !== $single->getPool()->getID()) {
            throw new UserMessageException(t('The team is not part of %s.', $single->getPool()->getName()));
        }
        $user = $this->userInfoRepository->getByID($single->getUserID());
        if (!$user) {
            throw new UserMessageException(t('The user does not exist anymore.'));
        }
        $this->addMember($team, $user, $actor);
    }

    public function isCaptain(Team $team, UserInfo $user): bool
    {
        return $this->adminMode || $team->isCaptain((int) $user->getUserID()) || $user->getUserObject()->isSuperUser();
    }

    protected function addMemberUnchecked(Team $team, UserInfo $user, UserInfo $actor, bool $captain, array $ignoreTeamIDs = []): void
    {
        if (!$this->adminMode) {
            $this->assertCapacity($team);
        }
        $this->enterTeam($team, $user, $captain || count($team->getCaptains()) === 0, $ignoreTeamIDs);
        $this->requests->cancelPending($team->getID(), (int) $user->getUserID(), (int) $actor->getUserID());
        $this->dispatch('on_team_member_join', $team, $user, $actor);
    }

    /**
     * The only place users enter a team: enforces one team per pool, clears their free agent entry in the pool
     * and updates the pool group.
     *
     * @param int[] $ignoreTeamIDs teams the user is leaving at the same time (moves)
     */
    protected function enterTeam(Team $team, UserInfo $user, bool $captain, array $ignoreTeamIDs = []): void
    {
        $uID = (int) $user->getUserID();
        $pool = $team->getPool();
        if ($pool) {
            $this->assertFreeInPool($pool, $user, $ignoreTeamIDs);
        }
        $member = new TeamMember($team, $uID, $captain);
        $team->getMemberCollection()->add($member);
        $this->em->persist($member);
        if ($pool) {
            $this->removeSingle($pool, $uID);
        }
        $this->em->flush();
        if ($pool) {
            $this->poolMembership->syncUser($pool, $uID);
        }
    }

    protected function deleteTeam(Team $team, UserInfo $actor): void
    {
        $this->dispatch('on_team_disband', $team, null, $actor);
        $pool = $team->getPool();
        $memberIDs = array_map(function (TeamMember $member) {
            return $member->getUserID();
        }, $team->getMembers());
        // members and requests are removed by the foreign keys / cascade
        $this->em->remove($team);
        $this->em->flush();
        if ($pool) {
            $this->poolMembership->syncUsers($pool, $memberIDs);
        }
    }

    protected function assertCaptain(Team $team, UserInfo $user): void
    {
        if (!$this->isCaptain($team, $user)) {
            throw new UserMessageException(t('Only team captains can do this.'));
        }
    }

    protected function assertCanJoin(Team $team, UserInfo $user): void
    {
        if ($team->getPool()) {
            $this->assertFreeInPool($team->getPool(), $user);
        }
        if ($team->hasMember((int) $user->getUserID())) {
            throw new UserMessageException(t('%s is already a member of %s.', $user->getUserName(), $team->getName()));
        }
        if ($this->requests->findPending($team->getID(), (int) $user->getUserID())) {
            throw new UserMessageException(t('There already is an open invitation or join request for %s.', $user->getUserName()));
        }
        $this->assertCapacity($team);
    }

    protected function assertCapacity(Team $team): void
    {
        $max = $this->config->getMaxTeamSize();
        if ($max > 0 && $team->getMemberCount() >= $max) {
            throw new UserMessageException(t('The team is full (%s members max).', $max));
        }
    }

    /**
     * Users can be in only one team per pool.
     *
     * @param int[] $ignoreTeamIDs
     */
    protected function assertFreeInPool(TeamPool $pool, UserInfo $user, array $ignoreTeamIDs = []): void
    {
        $other = $this->pools->getTeamOfUser($pool, (int) $user->getUserID(), $ignoreTeamIDs);
        if ($other) {
            throw new UserMessageException(t(
                '%s is already in the team %s of %s. Users can only be in one team per pool.',
                $user->getUserName(),
                $other->getName(),
                $pool->getName()
            ));
        }
    }

    protected function assertPoolAcceptsTeam(TeamPool $pool): void
    {
        if ($this->adminMode) {
            return;
        }
        if (!$pool->isOpen() || !$pool->allowsTeams()) {
            throw new UserMessageException(t('%s does not accept new teams.', $pool->getName()));
        }
        if ($pool->getMaxTeams() > 0 && $this->pools->countTeams($pool) >= $pool->getMaxTeams()) {
            throw new UserMessageException(t('%s is full (%s teams max).', $pool->getName(), $pool->getMaxTeams()));
        }
    }

    /**
     * Removes the free agent entry, the caller flushes and syncs the pool group.
     */
    protected function removeSingle(TeamPool $pool, int $uID): void
    {
        $single = $this->pools->findSingle($pool, $uID);
        if ($single) {
            $pool->getSingles()->removeElement($single);
            $this->em->remove($single);
        }
    }

    protected function getRequestTeam(TeamRequest $request): Team
    {
        if (!$this->requests->isActive($request)) {
            throw new UserMessageException(t('This request is no longer open.'));
        }

        return $request->getTeam();
    }

    /**
     * @return string[] normalized [name, tag]
     */
    protected function validateProfile(string $name, string $tag, int $teamID): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $tag = trim($tag);
        $maxTag = $this->config->getTagMaxLength();

        if ($name === '') {
            throw new UserMessageException(t('Please enter a team name.'));
        }
        if (mb_strlen($name) > 64) {
            throw new UserMessageException(t('The team name can have at most %s characters.', 64));
        }
        if ($this->teams->nameExists($name, $teamID)) {
            throw new UserMessageException(t('The name "%s" is already taken.', $name));
        }
        if ($tag !== '') {
            if (mb_strlen($tag) > $maxTag || !preg_match('/^[\p{L}\p{N}_\-.!]+$/u', $tag)) {
                throw new UserMessageException(t('The tag can have at most %s letters, digits or _ - . !', $maxTag));
            }
            if ($this->teams->tagExists($tag, $teamID)) {
                throw new UserMessageException(t('The tag "%s" is already taken.', $tag));
            }
        }

        return [$name, $tag];
    }

    protected function importLogo(UploadedFile $upload)
    {
        if (!in_array(strtolower($upload->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            throw new UserMessageException(t('The logo must be a JPG, PNG, GIF or WEBP image.'));
        }
        $options = $this->app->make(ImportOptions::class);
        $folder = $this->config->getLogoFolder();
        if ($folder) {
            $options->setImportToFolder($folder);
        }
        try {
            return $this->app->make(FileImporter::class)->importUploadedFile($upload, '', $options)->getFile();
        } catch (Exception $e) {
            throw new UserMessageException(t('The logo could not be uploaded: %s', $e->getMessage()));
        }
    }

    protected function dispatch(string $event, Team $team, ?UserInfo $user, ?UserInfo $actor): void
    {
        $this->app->make('director')->dispatch($event, new TeamEvent($team, $user, $actor));
    }
}
