<?php
defined('C5_EXECUTE') or die('Access Denied.');

use TeamManager\Entity\TeamPool;
use TeamManager\Entity\Team;

/**
 * @var \Concrete\Core\Block\View\BlockView $view
 * @var \Concrete\Core\Validation\CSRF\Token $token
 * @var \Concrete\Core\User\UserInfo|null $me
 * @var string $mode overview | pool | team
 * @var Team|null $team set in team mode
 * @var TeamPool|null $pool set in pool mode
 * @var TeamPool|null $joinPool pool joining is limited to
 * @var callable $canJoin (TeamPool|null): bool
 * @var Team[] $myCaptainTeams
 * @var \TeamManager\Entity\TeamRequest[] $myJoinRequests by team ID
 * @var \Concrete\Core\Page\Page|null $myTeamsPage
 * @var string $pageURL
 * @var array $flashMessages
 * @var int $onlyListJoinPool block option
 * overview:
 * @var array $pools list of ['pool' => TeamPool, 'teams' => int, 'singles' => int]
 * @var Team[] $teams
 * @var string $keywords
 * @var string $pagination
 * pool:
 * @var Team[] $poolTeams
 * @var int $poolTeamCount
 * @var \TeamManager\Entity\TeamPoolUser|null $mySingle
 * @var int|null $myPoolTeamID
 */

$userInfoRepository = app(\Concrete\Core\User\UserInfoRepository::class);
$dh = app('helper/date');
$returnQuery = $mode === 'team' ? 'team=' . $team->getID() : ($mode === 'pool' ? 'pool=' . $pool->getID() : '');

$logoURL = function (Team $team) {
    $logo = $team->getLogo();

    return $logo ? $logo->getApprovedVersion()->getThumbnailURL('file_manager_listing') : null;
};

/**
 * Opens a POST form for a block action, the caller closes it.
 */
$formStart = function (string $task, string $tokenAction, array $fields = [], string $class = 'd-inline') use ($view, $token, $returnQuery) {
    ?>
    <form method="post" class="<?= h($class) ?>" action="<?= h($view->action($task)) ?>">
        <?php $token->output($tokenAction) ?>
        <input type="hidden" name="returnQuery" value="<?= h($returnQuery) ?>">
        <?php foreach ($fields as $name => $value) { ?>
            <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
        <?php } ?>
    <?php
};

$poolStatus = function (TeamPool $pool) {
    if (!$pool->isOpen()) {
        return '<span class="badge badge-secondary bg-secondary">' . t('Closed') . '</span>';
    }

    return '<span class="badge badge-success bg-success">' . t('Open') . '</span>';
};

/**
 * Join / withdraw button for a team depending on the state of the current user.
 */
$joinControl = function (Team $team) use ($me, $myJoinRequests, $myTeamsPage, $canJoin, $formStart) {
    if (!$me) {
        return;
    }
    if ($team->hasMember((int) $me->getUserID())) {
        if ($myTeamsPage) {
            ?><a class="btn btn-sm btn-outline-primary" href="<?= h($myTeamsPage->getCollectionLink()) ?>"><?= t('Manage') ?></a><?php
        } else {
            ?><span class="badge badge-success bg-success"><?= t('Member') ?></span><?php
        }

        return;
    }
    $request = $myJoinRequests[$team->getID()] ?? null;
    if ($request) {
        $formStart('withdraw_join', 'team_withdraw', ['team' => $team->getID(), 'request' => $request->getID()]);
        ?><button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= t('Withdraw request') ?>"><i class="fa fa-hourglass-half"></i> <?= t('Requested') ?></button></form><?php

        return;
    }
    if ($canJoin($team->getPool())) {
        $formStart('request_join', 'team_join', ['team' => $team->getID()]);
        ?><button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-user-plus"></i> <?= t('Request to join') ?></button></form><?php
    }
};

/**
 * One line of a team list.
 */
$teamItem = function (Team $item, bool $showPool) use ($logoURL, $pageURL, $joinControl) {
    ?>
    <li class="list-group-item d-flex align-items-center tm-gap">
        <?php if ($url = $logoURL($item)) { ?>
            <img src="<?= h($url) ?>" alt="" class="team-manager-logo">
        <?php } ?>
        <span class="flex-grow-1">
            <a href="<?= h($pageURL . '?team=' . $item->getID()) ?>"><?= h($item->getDisplayName()) ?></a>
            <?php if ($showPool && $item->getPool()) { ?>
                <small class="text-muted">· <?= h($item->getPool()->getName()) ?></small>
            <?php } ?>
        </span>
        <span class="badge badge-secondary bg-secondary" title="<?= t('Members') ?>"><i class="fa fa-users"></i> <?= $item->getMemberCount() ?></span>
        <?php $joinControl($item) ?>
    </li>
    <?php
};
?>
<div class="team-manager team-manager-directory">

    <?php foreach ($flashMessages as $message) { ?>
        <div class="alert alert-<?= h($message['type']) ?>" role="alert"><?= h($message['text']) ?></div>
    <?php } ?>

    <?php if ($mode === 'team') {
        $teamPool = $team->getPool();
        ?>
        <p>
            <a href="<?= h($teamPool ? $pageURL . '?pool=' . $teamPool->getID() : $pageURL) ?>">
                <i class="fa fa-arrow-left"></i> <?= $teamPool ? h($teamPool->getName()) : t('All teams') ?>
            </a>
        </p>
        <div class="d-flex align-items-center tm-gap mb-3">
            <?php if ($url = $logoURL($team)) { ?>
                <img src="<?= h($url) ?>" alt="" class="team-manager-logo team-manager-logo-lg">
            <?php } ?>
            <h3 class="mb-0 flex-grow-1"><?= h($team->getDisplayName()) ?></h3>
            <?php $joinControl($team) ?>
        </div>
        <?php if ($team->getDescription()) { ?>
            <p><?= nl2br(h($team->getDescription())) ?></p>
        <?php } ?>
        <h5><?= t('Members (%s)', $team->getMemberCount()) ?></h5>
        <ul class="list-group">
            <?php foreach ($team->getMembers() as $member) {
                $profileURL = ($member->getUserInfo() ? $member->getUserInfo()->getUserPublicProfileURL() : null);
                ?>
                <li class="list-group-item">
                    <i class="fa fa-user"></i>
                    <?php if ($profileURL) { ?>
                        <a href="<?= h($profileURL) ?>"><?= h($member->getUserName()) ?></a>
                    <?php } else { ?>
                        <?= h($member->getUserName()) ?>
                    <?php } ?>
                    <?php if ($member->isCaptain()) { ?>
                        <span class="badge badge-primary bg-primary"><?= t('Captain') ?></span>
                    <?php } ?>
                </li>
            <?php } ?>
        </ul>

    <?php } elseif ($mode === 'pool') {
        $joinable = $canJoin($pool) && $pool->isOpen();
        // my teams that could be registered: captain, not in this pool yet
        $registrable = array_filter($myCaptainTeams, function (Team $t) use ($pool) {
            return !$t->getPool() || $t->getPool()->getID() !== $pool->getID();
        });
        // my teams in this pool, for inviting single players
        $myPoolTeams = array_filter($myCaptainTeams, function (Team $t) use ($pool) {
            return $t->getPool() && $t->getPool()->getID() === $pool->getID();
        });
        ?>
        <?php if (empty($onlyListJoinPool)) { ?>
            <p><a href="<?= h($pageURL) ?>"><i class="fa fa-arrow-left"></i> <?= t('All pools') ?></a></p>
        <?php } ?>
        <div class="d-flex align-items-center tm-gap mb-2">
            <h3 class="mb-0 flex-grow-1"><?= h($pool->getName()) ?></h3>
            <?= $poolStatus($pool) ?>
        </div>
        <?php if ($pool->getDescription()) { ?>
            <p><?= nl2br(h($pool->getDescription())) ?></p>
        <?php } ?>

        <?php if ($me && $joinable) { ?>
            <div class="team-manager-pool-actions mb-4">
                <?php if ($pool->allowsSingles() && !$myPoolTeamID) { ?>
                    <?php if ($mySingle) {
                        $formStart('leave_pool', 'team_pool_leave', ['pool' => $pool->getID()]);
                        ?>
                        <span class="mr-2 me-2"><i class="fa fa-check"></i> <?= t('You are listed as looking for a team.') ?></span>
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><?= t('Remove me') ?></button>
                        </form>
                    <?php } else {
                        $formStart('join_pool', 'team_pool_join', ['pool' => $pool->getID()], 'mb-2');
                        ?>
                        <div class="input-group">
                            <input type="text" name="note" class="form-control" maxlength="255" placeholder="<?= t('Note for captains (optional), e.g. preferred role') ?>">
                            <div class="input-group-append"><button type="submit" class="btn btn-primary"><i class="fa fa-user"></i> <?= t('Join as single player') ?></button></div>
                        </div>
                        </form>
                    <?php } ?>
                <?php } ?>

                <?php if ($pool->allowsTeams() && $registrable && ($pool->getMaxTeams() === 0 || $poolTeamCount < $pool->getMaxTeams())) {
                    $formStart('register_team', 'team_pool_register', ['pool' => $pool->getID()], 'mt-2');
                    ?>
                    <div class="input-group">
                        <select name="team" class="form-control form-select" required aria-label="<?= t('Team') ?>">
                            <?php foreach ($registrable as $t) { ?>
                                <option value="<?= $t->getID() ?>"><?= h($t->getDisplayName()) ?><?= $t->getPool() ? ' (' . h($t->getPool()->getName()) . ')' : '' ?></option>
                            <?php } ?>
                        </select>
                        <div class="input-group-append"><button type="submit" class="btn btn-outline-primary"><i class="fa fa-users"></i> <?= t('Register team') ?></button></div>
                    </div>
                    </form>
                <?php } ?>
            </div>
        <?php } ?>

        <h5>
            <?= t('Teams') ?>
            <small class="text-muted"><?= $pool->getMaxTeams() ? t('%s of %s', $poolTeamCount, $pool->getMaxTeams()) : $poolTeamCount ?></small>
        </h5>
        <?php if ($poolTeams) { ?>
            <ul class="list-group mb-4">
                <?php foreach ($poolTeams as $item) {
                    $teamItem($item, false);
                } ?>
            </ul>
        <?php } else { ?>
            <p class="text-muted"><?= t('No teams yet.') ?></p>
        <?php } ?>

        <?php if ($pool->allowsSingles() || $pool->getSingles()->count()) { ?>
            <h5><?= t('Looking for a team') ?> <small class="text-muted"><?= $pool->getSingles()->count() ?></small></h5>
            <?php if (!$pool->getSingles()->count()) { ?>
                <p class="text-muted"><?= t('Nobody is looking for a team right now.') ?></p>
            <?php } else { ?>
                <ul class="list-group">
                    <?php foreach ($pool->getSingles() as $single) {
                        $ui = $userInfoRepository->getByID($single->getUserID());
                        if (!$ui) {
                            continue;
                        }
                        $profileURL = $ui->getUserPublicProfileURL();
                        ?>
                        <li class="list-group-item d-flex align-items-center flex-wrap tm-gap">
                            <span class="flex-grow-1">
                                <i class="fa fa-user"></i>
                                <?php if ($profileURL) { ?>
                                    <a href="<?= h($profileURL) ?>"><?= h($ui->getUserName()) ?></a>
                                <?php } else { ?>
                                    <?= h($ui->getUserName()) ?>
                                <?php } ?>
                                <?php if ($single->getNote()) { ?>
                                    <small class="text-muted">– <?= h($single->getNote()) ?></small>
                                <?php } ?>
                                <small class="text-muted"><?= t('since %s', $dh->formatDate($single->getJoinedAt())) ?></small>
                            </span>
                            <?php if ($myPoolTeams && $canJoin($pool)) {
                                $formStart('invite_single', 'team_pool_invite', ['single' => $single->getID()]);
                                ?>
                                <div class="input-group input-group-sm">
                                    <select name="team" class="form-control form-select" aria-label="<?= t('Team') ?>">
                                        <?php foreach ($myPoolTeams as $t) { ?>
                                            <option value="<?= $t->getID() ?>"><?= h($t->getDisplayName()) ?></option>
                                        <?php } ?>
                                    </select>
                                    <div class="input-group-append"><button type="submit" class="btn btn-outline-primary"><?= t('Invite') ?></button></div>
                                </div>
                                </form>
                            <?php } ?>
                        </li>
                    <?php } ?>
                </ul>
            <?php } ?>
        <?php } ?>

    <?php } else { ?>

        <?php if ($pools && $keywords === '') { ?>
            <h4><?= t('Team Pools') ?></h4>
            <div class="list-group mb-4">
                <?php foreach ($pools as $entry) {
                    /** @var TeamPool $item */
                    $item = $entry['pool'];
                    ?>
                    <a href="<?= h($pageURL . '?pool=' . $item->getID()) ?>" class="list-group-item list-group-item-action d-flex align-items-center tm-gap">
                        <span class="flex-grow-1">
                            <strong><?= h($item->getName()) ?></strong>
                            <?php if ($joinPool && $joinPool->getID() === $item->getID()) { ?>
                                <span class="badge badge-primary bg-primary"><?= t('Join here') ?></span>
                            <?php } ?>
                            <?php if ($item->getDescription()) { ?>
                                <br><small class="text-muted"><?= h(mb_strimwidth($item->getDescription(), 0, 140, '…')) ?></small>
                            <?php } ?>
                        </span>
                        <span class="badge badge-secondary bg-secondary" title="<?= t('Teams') ?>"><i class="fa fa-users"></i> <?= $item->getMaxTeams() ? $entry['teams'] . '/' . $item->getMaxTeams() : $entry['teams'] ?></span>
                        <span class="badge badge-secondary bg-secondary" title="<?= t('Looking for a team') ?>"><i class="fa fa-user"></i> <?= $entry['singles'] ?></span>
                        <?= $poolStatus($item) ?>
                    </a>
                <?php } ?>
            </div>
        <?php } ?>

        <h4><?= $keywords !== '' ? t('Teams') : ($pools ? t('Teams without pool') : t('Teams')) ?></h4>
        <form method="get" action="<?= h($pageURL) ?>" class="mb-3">
            <div class="input-group">
                <input type="search" name="keywords" class="form-control" value="<?= h($keywords) ?>" placeholder="<?= t('Search all teams') ?>" aria-label="<?= t('Search all teams') ?>">
                <div class="input-group-append"><button type="submit" class="btn btn-outline-secondary"><i class="fa fa-search"></i></button></div>
            </div>
        </form>

        <?php if (!$teams) { ?>
            <p class="text-muted"><?= $keywords !== '' ? t('No teams match your search.') : t('There are no teams without pool.') ?></p>
        <?php } else { ?>
            <ul class="list-group mb-3">
                <?php foreach ($teams as $item) {
                    $teamItem($item, $keywords !== '');
                } ?>
            </ul>
            <?= $pagination ?>
        <?php } ?>
    <?php } ?>

    <?php if ($me && $myTeamsPage) { ?>
        <p class="mt-3"><a href="<?= h($myTeamsPage->getCollectionLink()) ?>"><?= t('Create or manage your teams') ?></a></p>
    <?php } ?>
</div>
