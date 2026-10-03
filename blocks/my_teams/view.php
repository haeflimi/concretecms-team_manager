<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var \Concrete\Core\Block\View\BlockView $view
 * @var \Concrete\Core\Validation\CSRF\Token $token
 * @var \Concrete\Core\User\UserInfo|null $me
 * @var \Concrete\Core\User\UserInfoRepository $userInfoRepository
 * @var \TeamManager\Team\TeamService $teamService
 * @var \TeamManager\Entity\Team[] $teams
 * @var array $invitations list of ['request' => TeamRequest, 'team' => Team]
 * @var \TeamManager\Entity\TeamRequest[][] $openJoinRequests by team ID, only for teams I captain
 * @var \TeamManager\Entity\TeamRequest[][] $openInvites by team ID, only for teams I captain
 * @var array $flashMessages
 * @var int $allowTeamCreation
 * @var \TeamManager\Entity\TeamPool|null $pool pool the block is limited to, null = all teams
 * @var bool $poolMissing the configured pool was deleted
 * @var string|null $creationBlockedReason why teams can't be created in the pool right now
 * @var \TeamManager\Entity\TeamPoolUser[] $mySingles pools I'm listed in as single player
 * @var int $bID
 */

$dh = app('helper/date');

/**
 * Renders a small POST form with a single button.
 */
$button = function (string $task, string $tokenAction, array $fields, string $label, string $class, ?string $confirm = null) use ($view, $token) {
    ?>
    <form method="post" action="<?= h($view->action($task)) ?>" class="d-inline"
        <?php if ($confirm) { ?>onsubmit="return confirm(<?= h(json_encode($confirm)) ?>)"<?php } ?>>
        <?php $token->output($tokenAction) ?>
        <?php foreach ($fields as $name => $value) { ?>
            <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
        <?php } ?>
        <button type="submit" class="btn btn-sm <?= h($class) ?>"><?= $label ?></button>
    </form>
    <?php
};

$userName = function (int $uID) use ($userInfoRepository) {
    $ui = $userInfoRepository->getByID($uID);

    return $ui ? $ui->getUserName() : t('Deleted user');
};
?>
<div class="team-manager team-manager-my-teams" data-search-url="<?= h($view->action('search_users')) ?>">

    <?php foreach ($flashMessages as $message) { ?>
        <div class="alert alert-<?= h($message['type']) ?>" role="alert">
            <?= h($message['text']) ?>
        </div>
    <?php } ?>

    <?php if ($poolMissing) { ?>
        <p class="text-muted"><?= t('The team pool of this block does not exist anymore.') ?></p>
    <?php } elseif (!$me) { ?>
        <p class="text-muted"><?= t('Please log in to manage your teams.') ?></p>
    <?php } else { ?>

        <?php if ($pool) { ?>
            <p class="team-manager-pool-name text-muted"><i class="fa fa-users"></i> <?= h($pool->getName()) ?></p>
        <?php } ?>

        <?php if ($invitations) { ?>
            <section class="team-manager-invitations mb-4">
                <h4><?= t('Invitations') ?></h4>
                <ul class="list-group">
                    <?php foreach ($invitations as $invitation) { ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap tm-gap">
                            <span>
                                <?= t('%s invited you to %s', '<strong>' . h($userName($invitation['request']->getCreatedBy())) . '</strong>', '<strong>' . h($invitation['team']->getDisplayName()) . '</strong>') ?>
                                <small class="text-muted"><?= $dh->formatDate($invitation['request']->getCreatedAt()) ?></small>
                            </span>
                            <span>
                                <?php $button('respond_request', 'team_respond', ['request' => $invitation['request']->getID(), 'accept' => 1], '<i class="fa fa-check"></i> ' . t('Accept'), 'btn-success') ?>
                                <?php $button('respond_request', 'team_respond', ['request' => $invitation['request']->getID(), 'accept' => 0], '<i class="fa fa-times"></i> ' . t('Decline'), 'btn-outline-secondary') ?>
                            </span>
                        </li>
                    <?php } ?>
                </ul>
            </section>
        <?php } ?>

        <?php if ($mySingles) { ?>
            <section class="team-manager-singles mb-4">
                <h4><?= t('Looking for a team') ?></h4>
                <ul class="list-group">
                    <?php foreach ($mySingles as $single) { ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap tm-gap">
                            <span>
                                <?= t('You are listed in %s', '<strong>' . h($single->getPool()->getName()) . '</strong>') ?>
                                <small class="text-muted"><?= $dh->formatDate($single->getJoinedAt()) ?></small>
                            </span>
                            <?php $button('leave_pool', 'team_pool_leave', ['single' => $single->getID()], t('Remove me'), 'btn-outline-secondary') ?>
                        </li>
                    <?php } ?>
                </ul>
            </section>
        <?php } ?>

        <section class="team-manager-teams mb-4">
            <h4><?= t('My Teams') ?></h4>
            <?php if (!$teams) { ?>
                <p class="text-muted"><?= $pool ? t('You are not in a team of %s yet.', h($pool->getName())) : t('You are not in a team yet.') ?></p>
            <?php } ?>

            <?php foreach ($teams as $team) {
                $teamID = $team->getID();
                $isCaptain = $teamService->isCaptain($team, $me);
                $logo = $team->getLogo();
                ?>
                <details class="card team-manager-team mb-3" id="team-<?= $teamID ?>"<?= count($teams) === 1 ? ' open' : '' ?>>
                    <summary class="card-header d-flex align-items-center tm-gap">
                        <?php if ($logo) { ?>
                            <img src="<?= h($logo->getApprovedVersion()->getThumbnailURL('file_manager_listing')) ?>" alt="" class="team-manager-logo">
                        <?php } ?>
                        <span class="h5 mb-0 flex-grow-1">
                            <?= h($team->getDisplayName()) ?>
                            <?php if (!$pool && $team->getPool()) { ?>
                                <small class="text-muted">· <?= h($team->getPool()->getName()) ?></small>
                            <?php } ?>
                        </span>
                        <?php if ($team->isCaptain((int) $me->getUserID())) { ?>
                            <span class="badge badge-primary bg-primary"><?= t('Captain') ?></span>
                        <?php } ?>
                        <span class="badge badge-secondary bg-secondary" title="<?= t('Members') ?>"><i class="fa fa-users"></i> <?= $team->getMemberCount() ?></span>
                    </summary>
                    <div class="card-body">
                            <?php if ($team->getDescription()) { ?>
                                <p><?= nl2br(h($team->getDescription())) ?></p>
                            <?php } ?>

                            <h6><?= t('Members') ?></h6>
                            <ul class="list-group mb-3">
                                <?php foreach ($team->getMembers() as $member) {
                                    $profileURL = ($member->getUserInfo() ? $member->getUserInfo()->getUserPublicProfileURL() : null);
                                    $isMe = $member->getUserID() === (int) $me->getUserID();
                                    ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap tm-gap">
                                        <span>
                                            <i class="fa fa-user"></i>
                                            <?php if ($profileURL) { ?>
                                                <a href="<?= h($profileURL) ?>"><?= h($member->getUserName()) ?></a>
                                            <?php } else { ?>
                                                <?= h($member->getUserName()) ?>
                                            <?php } ?>
                                            <?php if ($member->isCaptain()) { ?>
                                                <span class="badge badge-primary bg-primary"><?= t('Captain') ?></span>
                                            <?php } ?>
                                        </span>
                                        <span>
                                            <?php if ($isCaptain && !$isMe) { ?>
                                                <?php if ($member->isCaptain()) {
                                                    $button('set_captain', 'team_role', ['team' => $teamID, 'user' => $member->getUserID(), 'captain' => 0], t('Make member'), 'btn-outline-secondary');
                                                } else {
                                                    $button('set_captain', 'team_role', ['team' => $teamID, 'user' => $member->getUserID(), 'captain' => 1], t('Make captain'), 'btn-outline-primary');
                                                } ?>
                                                <?php $button('remove_member', 'team_remove', ['team' => $teamID, 'user' => $member->getUserID()], '<i class="fa fa-user-minus"></i>', 'btn-outline-danger', t('Remove %s from the team?', $member->getUserName())) ?>
                                            <?php } ?>
                                            <?php if ($isMe) { ?>
                                                <?php $button('remove_member', 'team_remove', ['team' => $teamID, 'user' => $member->getUserID()], t('Leave'), 'btn-outline-danger', t('Do you really want to leave %s?', $team->getName())) ?>
                                            <?php } ?>
                                        </span>
                                    </li>
                                <?php } ?>
                            </ul>

                            <?php if ($isCaptain) { ?>
                                <form method="post" action="<?= h($view->action('invite_member')) ?>" class="team-manager-invite-form mb-3">
                                    <?php $token->output('team_invite') ?>
                                    <input type="hidden" name="team" value="<?= $teamID ?>">
                                    <div class="input-group">
                                        <input type="text" name="user" class="form-control" required autocomplete="off"
                                               list="team-manager-users-<?= $bID ?>" placeholder="<?= t('Username to invite') ?>">
                                        <div class="input-group-append"><button type="submit" class="btn btn-primary"><i class="fa fa-user-plus"></i> <?= t('Invite') ?></button></div>
                                    </div>
                                </form>

                                <?php if (!empty($openJoinRequests[$teamID])) { ?>
                                    <h6><?= t('Join requests') ?></h6>
                                    <ul class="list-group mb-3">
                                        <?php foreach ($openJoinRequests[$teamID] as $request) { ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap tm-gap">
                                                <span><?= h($userName($request->getUserID())) ?> <small class="text-muted"><?= $dh->formatDate($request->getCreatedAt()) ?></small></span>
                                                <span>
                                                    <?php $button('respond_request', 'team_respond', ['request' => $request->getID(), 'accept' => 1], t('Approve'), 'btn-success') ?>
                                                    <?php $button('respond_request', 'team_respond', ['request' => $request->getID(), 'accept' => 0], t('Deny'), 'btn-outline-secondary') ?>
                                                </span>
                                            </li>
                                        <?php } ?>
                                    </ul>
                                <?php } ?>

                                <?php if (!empty($openInvites[$teamID])) { ?>
                                    <h6><?= t('Pending invitations') ?></h6>
                                    <ul class="list-group mb-3">
                                        <?php foreach ($openInvites[$teamID] as $request) { ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><?= h($userName($request->getUserID())) ?> <small class="text-muted"><?= $dh->formatDate($request->getCreatedAt()) ?></small></span>
                                                <?php $button('cancel_request', 'team_cancel', ['request' => $request->getID()], t('Withdraw'), 'btn-outline-secondary') ?>
                                            </li>
                                        <?php } ?>
                                    </ul>
                                <?php } ?>

                                <details class="team-manager-edit mb-3">
                                    <summary><?= t('Edit team') ?></summary>
                                    <form method="post" enctype="multipart/form-data" action="<?= h($view->action('update_team')) ?>" class="mt-2">
                                        <?php $token->output('team_update') ?>
                                        <input type="hidden" name="team" value="<?= $teamID ?>">
                                        <div class="form-row row g-2 mb-2">
                                            <div class="col-sm-8">
                                                <label class="form-label"><?= t('Name') ?></label>
                                                <input type="text" name="name" class="form-control" required maxlength="64" value="<?= h($team->getName()) ?>">
                                            </div>
                                            <div class="col-sm-4">
                                                <label class="form-label"><?= t('Tag') ?></label>
                                                <input type="text" name="tag" class="form-control" maxlength="16" value="<?= h((string) $team->getTag()) ?>">
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label"><?= t('Description') ?></label>
                                            <textarea name="description" class="form-control" rows="3"><?= h($team->getDescription()) ?></textarea>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label"><?= t('Logo') ?></label>
                                            <input type="file" name="logo" class="form-control" accept="image/*">
                                            <?php if ($logo) { ?>
                                                <div class="form-check mt-1">
                                                    <input type="checkbox" name="removeLogo" value="1" class="form-check-input" id="team-remove-logo-<?= $bID ?>-<?= $teamID ?>">
                                                    <label class="form-check-label" for="team-remove-logo-<?= $bID ?>-<?= $teamID ?>"><?= t('Remove current logo') ?></label>
                                                </div>
                                            <?php } ?>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm"><?= t('Save') ?></button>
                                    </form>
                                </details>

                                <?php $button('disband_team', 'team_disband', ['team' => $teamID], '<i class="fa fa-trash"></i> ' . t('Disband team'), 'btn-danger', t('Disband %s? This removes the team for all members.', $team->getName())) ?>
                            <?php } ?>
                    </div>
                </details>
            <?php } ?>
        </section>

        <?php if ($allowTeamCreation) { ?>
            <section class="team-manager-create">
                <h4><?= t('Create a Team') ?></h4>
                <?php if ($creationBlockedReason) { ?>
                    <p class="text-muted"><?= h($creationBlockedReason) ?></p>
                <?php } else { ?>
                <form method="post" action="<?= h($view->action('create_team')) ?>">
                    <?php $token->output('team_create') ?>
                    <div class="form-row row g-2 mb-2">
                        <div class="col-sm-8">
                            <input type="text" name="name" class="form-control" required maxlength="64" placeholder="<?= t('Team name') ?>">
                        </div>
                        <div class="col-sm-4">
                            <input type="text" name="tag" class="form-control" maxlength="16" placeholder="<?= t('Tag (optional)') ?>">
                        </div>
                    </div>
                    <div class="mb-2">
                        <textarea name="description" class="form-control" rows="2" placeholder="<?= t('Description (optional)') ?>"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-plus-circle"></i> <?= t('Create Team') ?></button>
                </form>
                <?php } ?>
            </section>
        <?php } ?>

        <datalist id="team-manager-users-<?= $bID ?>"></datalist>
    <?php } ?>
</div>
