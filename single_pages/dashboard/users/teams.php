<?php
defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\Support\Facade\Url;

/**
 * @var \Concrete\Core\Page\View\PageView $view
 * @var \Concrete\Core\Validation\CSRF\Token $token
 * @var \Concrete\Core\Form\Service\Form $form
 * @var string $mode list | board | team | add
 * @var string $tokenAction
 * @var string $basePath
 * @var string $searchUsersURL
 * @var array $poolFilterOptions '' | 'none' | pool ID => label
 * @var string $poolFilter
 * list and board:
 * @var string $keywords
 * list:
 * @var \TeamManager\Entity\Team[] $teams
 * @var string $pagination
 * @var array $stats
 * board:
 * @var array $boardData
 * @var \TeamManager\Entity\TeamPool|null $boardPool
 * @var array $boardSingles
 * team / add:
 * @var array $poolOptions
 * @var int $selectedPoolID
 * pools:
 * @var array $pools list of ['pool' => TeamPool, 'teams' => int, 'singles' => int]
 * pool / pool_form:
 * @var \TeamManager\Entity\TeamPool|null $pool
 * @var \TeamManager\Entity\Team[] $poolTeams
 * team:
 * @var \TeamManager\Entity\Team $team
 * @var array $otherTeams
 * @var \TeamManager\Entity\TeamRequest[] $joinRequests
 * @var \TeamManager\Entity\TeamRequest[] $invites
 * @var \Concrete\Core\User\UserInfoRepository $userInfoRepository
 */

$dh = app('helper/date');
$url = function (...$parts) use ($basePath) {
    return (string) Url::to($basePath, ...$parts);
};
$currentPath = $basePath . [
    'list' => '',
    'board' => '/board',
    'team' => $mode === 'team' ? '/team/' . $team->getID() : '',
    'add' => '/add',
    'pools' => '/pools',
    'pool_form' => '/add_pool',
    'pool' => $mode === 'pool' ? '/pool/' . $pool->getID() : '',
][$mode] . ($poolFilter !== '' && in_array($mode, ['list', 'board'], true) ? '?pool=' . rawurlencode($poolFilter) : '');

$poolsButton = '<a href="' . h($url('pools')) . '" class="btn btn-secondary"><i class="fas fa-layer-group"></i> ' . t('Pools') . '</a>';

/**
 * List / board switch, both always shown, the active one highlighted. Keeps the pool filter.
 */
$viewSwitch = function () use ($mode, $url, $poolFilter) {
    $query = $poolFilter !== '' ? '?pool=' . rawurlencode($poolFilter) : '';
    $html = '<div class="btn-group" role="group" aria-label="' . h(t('View')) . '">';
    foreach (['list' => ['fa-list', t('List'), $url()], 'board' => ['fa-columns', t('Board'), $url('board')]] as $key => [$icon, $label, $href]) {
        $active = $mode === $key;
        $html .= '<a href="' . h($href . $query) . '" class="btn ' . ($active ? 'btn-primary active' : 'btn-secondary') . '"'
            . ($active ? ' aria-current="page"' : '') . '><i class="fas ' . $icon . '"></i> ' . $label . '</a>';
    }

    return $html . '</div>';
};

/**
 * GET form to filter list / board by pool.
 */
$poolFilterSelect = function () use ($poolFilterOptions, $poolFilter) {
    ?>
    <select name="pool" class="form-select w-auto" onchange="this.form.submit()" aria-label="<?= t('Pool') ?>">
        <?php foreach ($poolFilterOptions as $value => $label) { ?>
            <option value="<?= h($value) ?>" <?= (string) $value === $poolFilter ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php } ?>
    </select>
    <?php
};

/**
 * Small POST form with hidden fields and a single button.
 */
$button = function (string $task, array $fields, string $label, string $class, ?string $confirm = null) use ($url, $token, $tokenAction, $currentPath) {
    ?>
    <form method="post" action="<?= h($url($task)) ?>" class="d-inline"
        <?php if ($confirm) { ?>onsubmit="return confirm(<?= h(json_encode($confirm)) ?>)"<?php } ?>>
        <?php $token->output($tokenAction) ?>
        <input type="hidden" name="return" value="<?= h($currentPath) ?>">
        <?php foreach ($fields as $name => $value) { ?>
            <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
        <?php } ?>
        <button type="submit" class="btn btn-sm <?= h($class) ?>"><?= $label ?></button>
    </form>
    <?php
};
?>

<datalist id="team-manager-users"></datalist>

<?php if ($mode === 'list') { ?>

    <div class="ccm-dashboard-header-buttons">
        <?= $poolsButton ?>
        <?= $viewSwitch() ?>
        <a href="<?= h($url('add')) ?>" class="btn btn-primary"><i class="fas fa-plus"></i> <?= t('Add Team') ?></a>
    </div>

    <div class="row row-cols-2 row-cols-md-5 g-3 mb-4">
        <div class="col">
            <div class="card"><div class="card-body">
                <div class="text-muted"><?= t('Teams') ?></div>
                <div class="h3 mb-0"><?= $stats['teams'] ?></div>
            </div></div>
        </div>
        <div class="col">
            <div class="card"><div class="card-body">
                <div class="text-muted"><?= t('Users in teams') ?></div>
                <div class="h3 mb-0"><?= $stats['members'] ?></div>
            </div></div>
        </div>
        <div class="col">
            <div class="card"><div class="card-body">
                <div class="text-muted"><?= t('Empty teams') ?></div>
                <div class="h3 mb-0"><?= $stats['empty'] ?></div>
            </div></div>
        </div>
        <div class="col">
            <div class="card"><div class="card-body">
                <div class="text-muted"><?= t('Pools') ?></div>
                <div class="h3 mb-0"><?= $stats['pools'] ?></div>
            </div></div>
        </div>
        <div class="col">
            <div class="card"><div class="card-body">
                <div class="text-muted"><?= t('Looking for a team') ?></div>
                <div class="h3 mb-0"><?= $stats['singles'] ?></div>
            </div></div>
        </div>
    </div>

    <form method="get" action="<?= h($url()) ?>" class="d-flex gap-2 mb-3">
        <?php $poolFilterSelect() ?>
        <div class="input-group">
            <input type="search" name="keywords" class="form-control" value="<?= h($keywords) ?>" placeholder="<?= t('Search teams') ?>" aria-label="<?= t('Search teams') ?>">
            <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i></button>
        </div>
    </form>

    <?php if (!$teams) { ?>
        <p class="text-muted"><?= $keywords !== '' ? t('No teams match your search.') : t('There are no teams yet.') ?></p>
    <?php } else { ?>
        <table class="table table-striped align-middle">
            <thead>
            <tr>
                <th><?= t('Team') ?></th>
                <th><?= t('Pool') ?></th>
                <th><?= t('Captains') ?></th>
                <th class="text-center"><?= t('Members') ?></th>
                <th><?= t('Created') ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($teams as $team) { ?>
                <tr>
                    <td><a href="<?= h($url('team', $team->getID())) ?>"><?= h($team->getDisplayName()) ?></a></td>
                    <td>
                        <?php if ($team->getPool()) { ?>
                            <a href="<?= h($url('pool', $team->getPool()->getID())) ?>"><?= h($team->getPool()->getName()) ?></a>
                        <?php } else { ?>
                            <span class="text-muted">–</span>
                        <?php } ?>
                    </td>
                    <td>
                        <?php $captains = array_map(function ($m) { return h($m->getUserName()); }, $team->getCaptains()); ?>
                        <?= $captains ? implode(', ', $captains) : '<span class="badge bg-warning text-dark">' . t('No captain') . '</span>' ?>
                    </td>
                    <td class="text-center"><?= $team->getMemberCount() ?></td>
                    <td><?= $dh->formatDate($team->getCreatedAt()) ?></td>
                    <td class="text-end"><a href="<?= h($url('team', $team->getID())) ?>" class="btn btn-sm btn-secondary"><?= t('Manage') ?></a></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?= $pagination ?>
    <?php } ?>

<?php } elseif ($mode === 'board') { ?>

    <div class="ccm-dashboard-header-buttons">
        <?= $poolsButton ?>
        <?= $viewSwitch() ?>
        <a href="<?= h($url('add') . ($boardPool ? '?pool=' . $boardPool->getID() : '')) ?>" class="btn btn-primary"><i class="fas fa-plus"></i> <?= t('Add Team') ?></a>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <form method="get" action="<?= h($url('board')) ?>"><?php $poolFilterSelect() ?></form>
        <input type="search" class="form-control w-auto" id="team-board-filter" placeholder="<?= t('Filter teams and members') ?>" aria-label="<?= t('Filter teams and members') ?>">
        <div class="form-check mb-0">
            <input type="checkbox" class="form-check-input" id="team-board-keep-captain">
            <label class="form-check-label" for="team-board-keep-captain"><?= t('Captains stay captains when moved') ?></label>
        </div>
        <span class="text-muted small"><i class="fas fa-info-circle"></i>
            <?= $boardPool ? t('Drag members onto another team to move them, drag players looking for a team onto a team to add them.') : t('Drag members onto another team to move them.') ?>
        </span>
    </div>

    <?php if ($boardPool) { ?>
        <div class="card mb-3" id="team-board-singles-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold"><?= t('Looking for a team in %s', h($boardPool->getName())) ?></span>
                <a href="<?= h($url('pool', $boardPool->getID())) ?>" class="small"><?= t('Manage pool') ?></a>
            </div>
            <div class="card-body d-flex flex-wrap gap-2" id="team-board-singles"></div>
        </div>
    <?php } ?>

    <div id="team-board" class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3"
         data-token="<?= h($token->generate($tokenAction)) ?>"
         data-move-url="<?= h($url('move_member')) ?>"
         data-add-url="<?= h($url('add_member')) ?>"
         data-remove-url="<?= h($url('remove_member')) ?>"
         data-captain-url="<?= h($url('set_captain')) ?>"
         data-assign-url="<?= h($url('assign_single')) ?>"
         data-remove-single-url="<?= h($url('remove_single')) ?>"
         data-search-url="<?= h($searchUsersURL) ?>"></div>
    <?php if (!$boardData) { ?>
        <p class="text-muted"><?= t('There are no teams yet.') ?></p>
    <?php } ?>

    <script type="application/json" id="team-board-data"><?= json_encode($boardData, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script type="application/json" id="team-board-singles-data"><?= json_encode($boardSingles, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script type="application/json" id="team-board-i18n"><?= json_encode([
        'captain' => t('Captain'),
        'makeCaptain' => t('Make captain'),
        'makeMember' => t('Make regular member'),
        'remove' => t('Remove from team'),
        'confirmRemove' => t('Remove %s from %s?'),
        'add' => t('Add'),
        'username' => t('Username'),
        'empty' => t('No members'),
        'manage' => t('Manage'),
        'noSingles' => t('Nobody is looking for a team.'),
        'removeSingle' => t('Remove from pool'),
        'confirmRemoveSingle' => t('Remove %s from the pool?'),
    ], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<?php } elseif ($mode === 'team') {
    $logo = $team->getLogo();
    ?>

    <div class="ccm-dashboard-header-buttons">
        <?= $poolsButton ?>
        <a href="<?= h($url()) ?>" class="btn btn-secondary"><i class="fas fa-list"></i> <?= t('All Teams') ?></a>
        <a href="<?= h($url('board') . ($team->getPool() ? '?pool=' . $team->getPool()->getID() : '')) ?>" class="btn btn-secondary"><i class="fas fa-columns"></i> <?= t('Board') ?></a>
    </div>

    <div class="row">
        <div class="col-lg-7 mb-4">
            <h4><?= t('Members (%s)', $team->getMemberCount()) ?></h4>
            <?php if (!$team->getMembers()) { ?>
                <p class="text-muted"><?= t('This team has no members.') ?></p>
            <?php } else { ?>
                <table class="table align-middle">
                    <tbody>
                    <?php foreach ($team->getMembers() as $member) { ?>
                        <tr>
                            <td>
                                <a href="<?= h(Url::to('/dashboard/users/search/edit', $member->getUserID())) ?>"><?= h($member->getUserName()) ?></a>
                                <?php if ($member->isCaptain()) { ?>
                                    <span class="badge bg-primary"><?= t('Captain') ?></span>
                                <?php } ?>
                                <?php if ($member->getJoinedAt()) { ?>
                                    <div class="small text-muted"><?= t('Joined %s', $dh->formatDate($member->getJoinedAt())) ?></div>
                                <?php } ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if ($member->isCaptain()) {
                                    $button('set_captain', ['team' => $team->getID(), 'user' => $member->getUserID(), 'captain' => 0], t('Make member'), 'btn-outline-secondary');
                                } else {
                                    $button('set_captain', ['team' => $team->getID(), 'user' => $member->getUserID(), 'captain' => 1], t('Make captain'), 'btn-outline-primary');
                                } ?>
                                <?php if ($otherTeams) { ?>
                                    <form method="post" action="<?= h($url('move_member')) ?>" class="d-inline-flex">
                                        <?php $token->output($tokenAction) ?>
                                        <input type="hidden" name="return" value="<?= h($currentPath) ?>">
                                        <input type="hidden" name="team" value="<?= $team->getID() ?>">
                                        <input type="hidden" name="user" value="<?= $member->getUserID() ?>">
                                        <select name="target" class="form-select form-select-sm" required aria-label="<?= t('Move to team') ?>">
                                            <option value=""><?= t('Move to…') ?></option>
                                            <?php foreach ($otherTeams as $id => $name) { ?>
                                                <option value="<?= $id ?>"><?= h($name) ?></option>
                                            <?php } ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary ms-1" title="<?= t('Move') ?>"><i class="fas fa-exchange-alt"></i></button>
                                    </form>
                                <?php } ?>
                                <?php $button('remove_member', ['team' => $team->getID(), 'user' => $member->getUserID()], '<i class="fas fa-user-minus"></i>', 'btn-outline-danger', t('Remove %s from %s?', $member->getUserName(), $team->getName())) ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            <?php } ?>

            <form method="post" action="<?= h($url('add_member')) ?>" class="mb-4">
                <?php $token->output($tokenAction) ?>
                <input type="hidden" name="return" value="<?= h($currentPath) ?>">
                <input type="hidden" name="team" value="<?= $team->getID() ?>">
                <div class="input-group">
                    <input type="text" name="user" class="form-control team-manager-user-input" list="team-manager-users" required autocomplete="off" placeholder="<?= t('Username') ?>">
                    <div class="input-group-text">
                        <input type="checkbox" name="captain" value="1" class="form-check-input mt-0 me-1" id="add-as-captain">
                        <label for="add-as-captain" class="mb-0"><?= t('Captain') ?></label>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> <?= t('Add member') ?></button>
                </div>
            </form>

            <?php foreach (['joinRequests' => t('Open join requests'), 'invites' => t('Open invitations')] as $var => $title) {
                if (!$$var) {
                    continue;
                } ?>
                <h5><?= $title ?></h5>
                <table class="table table-sm align-middle mb-4">
                    <tbody>
                    <?php foreach ($$var as $request) {
                        $user = $userInfoRepository->getByID($request->getUserID());
                        $creator = $userInfoRepository->getByID($request->getCreatedBy());
                        ?>
                        <tr>
                            <td>
                                <?= h($user ? $user->getUserName() : t('Deleted user')) ?>
                                <?php if ($request->isInvite() && $creator) { ?>
                                    <span class="small text-muted"><?= t('invited by %s', h($creator->getUserName())) ?></span>
                                <?php } ?>
                            </td>
                            <td class="text-muted small"><?= $dh->formatDateTime($request->getCreatedAt()) ?></td>
                            <td class="text-end">
                                <?php if ($user && $request->isJoinRequest()) {
                                    $button('add_member', ['team' => $team->getID(), 'user' => $user->getUserName()], t('Add to team'), 'btn-outline-primary');
                                } ?>
                                <?php $button('cancel_request', ['request' => $request->getID()], t('Cancel'), 'btn-outline-secondary') ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </div>

        <div class="col-lg-5">
            <h4><?= t('Pool') ?></h4>
            <form method="post" action="<?= h($url('set_team_pool')) ?>" class="mb-4">
                <?php $token->output($tokenAction) ?>
                <input type="hidden" name="return" value="<?= h($currentPath) ?>">
                <input type="hidden" name="team" value="<?= $team->getID() ?>">
                <div class="input-group">
                    <?= $form->select('pool', $poolOptions, $team->getPool() ? $team->getPool()->getID() : 0) ?>
                    <button type="submit" class="btn btn-secondary"><?= t('Change pool') ?></button>
                </div>
                <div class="form-text"><?= t('Members can only be in one team per pool, the change is rejected otherwise.') ?></div>
            </form>

            <h4><?= t('Profile') ?></h4>
            <form method="post" enctype="multipart/form-data" action="<?= h($url('update_team')) ?>" class="mb-4">
                <?php $token->output($tokenAction) ?>
                <input type="hidden" name="return" value="<?= h($currentPath) ?>">
                <input type="hidden" name="team" value="<?= $team->getID() ?>">
                <div class="mb-3">
                    <?= $form->label('name', t('Name')) ?>
                    <?= $form->text('name', $team->getName(), ['required' => 'required', 'maxlength' => 64]) ?>
                </div>
                <div class="mb-3">
                    <?= $form->label('tag', t('Tag')) ?>
                    <?= $form->text('tag', (string) $team->getTag(), ['maxlength' => 16]) ?>
                </div>
                <div class="mb-3">
                    <?= $form->label('description', t('Description')) ?>
                    <?= $form->textarea('description', $team->getDescription(), ['rows' => 4]) ?>
                </div>
                <div class="mb-3">
                    <?= $form->label('logo', t('Logo')) ?>
                    <?php if ($logo) { ?>
                        <div class="mb-2"><img src="<?= h($logo->getApprovedVersion()->getThumbnailURL('file_manager_listing')) ?>" alt="" style="max-width: 80px"></div>
                    <?php } ?>
                    <input type="file" name="logo" id="logo" class="form-control" accept="image/*">
                    <?php if ($logo) { ?>
                        <div class="form-check mt-1">
                            <?= $form->checkbox('removeLogo', 1, false) ?>
                            <?= $form->label('removeLogo', t('Remove current logo'), ['class' => 'form-check-label']) ?>
                        </div>
                    <?php } ?>
                </div>
                <button type="submit" class="btn btn-primary"><?= t('Save') ?></button>
            </form>

            <h4 class="text-danger"><?= t('Delete team') ?></h4>
            <p class="text-muted small"><?= t('Removes the team and all memberships. This cannot be undone.') ?></p>
            <?php $button('disband_team', ['team' => $team->getID()], '<i class="fas fa-trash"></i> ' . t('Delete team'), 'btn-danger', t('Delete %s for all %s members?', $team->getName(), $team->getMemberCount())) ?>
        </div>
    </div>

<?php } elseif ($mode === 'add') { ?>

    <form method="post" action="<?= h($url('create_team')) ?>" style="max-width: 640px">
        <?php $token->output($tokenAction) ?>
        <input type="hidden" name="return" value="<?= h($basePath . '/add') ?>">
        <div class="mb-3">
            <?= $form->label('name', t('Name')) ?>
            <?= $form->text('name', '', ['required' => 'required', 'maxlength' => 64]) ?>
        </div>
        <div class="mb-3">
            <?= $form->label('tag', t('Tag')) ?>
            <?= $form->text('tag', '', ['maxlength' => 16]) ?>
        </div>
        <div class="mb-3">
            <?= $form->label('description', t('Description')) ?>
            <?= $form->textarea('description', '', ['rows' => 3]) ?>
        </div>
        <div class="mb-3">
            <?= $form->label('captain', t('Captain')) ?>
            <input type="text" name="captain" id="captain" class="form-control team-manager-user-input" list="team-manager-users" autocomplete="off" placeholder="<?= t('Username (optional)') ?>">
            <div class="form-text"><?= t('Leave empty to create an empty team. The first member added becomes captain.') ?></div>
        </div>
        <div class="mb-3">
            <?= $form->label('pool', t('Pool')) ?>
            <?= $form->select('pool', $poolOptions, $selectedPoolID) ?>
        </div>
        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <a href="<?= h($url()) ?>" class="btn btn-secondary float-start"><?= t('Cancel') ?></a>
                <button type="submit" class="btn btn-primary float-end"><?= t('Create Team') ?></button>
            </div>
        </div>
    </form>

<?php } elseif ($mode === 'pools') { ?>

    <div class="ccm-dashboard-header-buttons">
        <a href="<?= h($url()) ?>" class="btn btn-secondary"><i class="fas fa-list"></i> <?= t('All Teams') ?></a>
        <a href="<?= h($url('add_pool')) ?>" class="btn btn-primary"><i class="fas fa-plus"></i> <?= t('Add Pool') ?></a>
    </div>

    <p class="text-muted"><?= t('A pool holds teams and single players looking for a team. Users can only be in one team per pool. Each pool is a group of the type "Team Pool" whose members are all its participants, so it can be used for permissions.') ?></p>

    <?php if (!$pools) { ?>
        <p class="text-muted"><?= t('There are no pools yet.') ?></p>
    <?php } else { ?>
        <table class="table table-striped align-middle">
            <thead>
            <tr>
                <th><?= t('Pool') ?></th>
                <th class="text-center"><?= t('Teams') ?></th>
                <th class="text-center"><?= t('Looking for a team') ?></th>
                <th><?= t('Joining') ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pools as $entry) {
                /** @var \TeamManager\Entity\TeamPool $item */
                $item = $entry['pool'];
                ?>
                <tr>
                    <td><a href="<?= h($url('pool', $item->getID())) ?>"><?= h($item->getName()) ?></a></td>
                    <td class="text-center"><?= $item->getMaxTeams() ? $entry['teams'] . ' / ' . $item->getMaxTeams() : $entry['teams'] ?></td>
                    <td class="text-center"><?= $entry['singles'] ?></td>
                    <td>
                        <?php if (!$item->isOpen()) { ?>
                            <span class="badge bg-secondary"><?= t('Closed') ?></span>
                        <?php } else { ?>
                            <span class="badge bg-success"><?= t('Open') ?></span>
                            <?php if ($item->allowsTeams()) { ?><span class="badge bg-light text-dark"><?= t('Teams') ?></span><?php } ?>
                            <?php if ($item->allowsTeamCreation()) { ?><span class="badge bg-light text-dark"><?= t('Team creation') ?></span><?php } ?>
                            <?php if ($item->allowsSingles()) { ?><span class="badge bg-light text-dark"><?= t('Single players') ?></span><?php } ?>
                        <?php } ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="<?= h($url('board') . '?pool=' . $item->getID()) ?>" class="btn btn-sm btn-secondary"><i class="fas fa-columns"></i> <?= t('Board') ?></a>
                        <a href="<?= h($url('pool', $item->getID())) ?>" class="btn btn-sm btn-secondary"><?= t('Manage') ?></a>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>

<?php } elseif ($mode === 'pool_form' || $mode === 'pool') {
    $isNew = $mode === 'pool_form';
    ?>

    <div class="ccm-dashboard-header-buttons">
        <?= $poolsButton ?>
        <?php if (!$isNew) { ?>
            <a href="<?= h($url('board') . '?pool=' . $pool->getID()) ?>" class="btn btn-secondary"><i class="fas fa-columns"></i> <?= t('Board') ?></a>
            <a href="<?= h($url('add') . '?pool=' . $pool->getID()) ?>" class="btn btn-primary"><i class="fas fa-plus"></i> <?= t('Add Team') ?></a>
        <?php } ?>
    </div>

    <div class="row">
        <?php if (!$isNew) { ?>
            <div class="col-lg-7 mb-4">
                <h4><?= t('Teams (%s)', count($poolTeams)) ?><?= $pool->getMaxTeams() ? ' <small class="text-muted">' . t('max. %s', $pool->getMaxTeams()) . '</small>' : '' ?><?= $pool->getMaxTeamSize() ? ' <small class="text-muted">· ' . t('max. %s members per team', $pool->getMaxTeamSize()) . '</small>' : '' ?></h4>
                <?php if (!$poolTeams) { ?>
                    <p class="text-muted"><?= t('No teams in this pool yet.') ?></p>
                <?php } else { ?>
                    <table class="table align-middle">
                        <tbody>
                        <?php foreach ($poolTeams as $item) { ?>
                            <tr>
                                <td><a href="<?= h($url('team', $item->getID())) ?>"><?= h($item->getDisplayName()) ?></a></td>
                                <td class="text-center"><i class="fas fa-users text-muted"></i> <?= $item->getMemberCount() ?></td>
                                <td class="text-end">
                                    <?php $button('set_team_pool', ['team' => $item->getID(), 'pool' => 0], t('Remove from pool'), 'btn-outline-secondary') ?>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                <?php } ?>
                <?php if ($otherTeams) { ?>
                    <form method="post" action="<?= h($url('set_team_pool')) ?>" class="mb-4">
                        <?php $token->output($tokenAction) ?>
                        <input type="hidden" name="return" value="<?= h($currentPath) ?>">
                        <input type="hidden" name="pool" value="<?= $pool->getID() ?>">
                        <div class="input-group">
                            <?= $form->select('team', $otherTeams) ?>
                            <button type="submit" class="btn btn-secondary"><i class="fas fa-plus"></i> <?= t('Add team to pool') ?></button>
                        </div>
                    </form>
                <?php } ?>

                <h4><?= t('Looking for a team (%s)', $pool->getSingles()->count()) ?></h4>
                <?php if (!$pool->getSingles()->count()) { ?>
                    <p class="text-muted"><?= t('Nobody is looking for a team in this pool.') ?></p>
                <?php } else { ?>
                    <table class="table align-middle">
                        <tbody>
                        <?php foreach ($pool->getSingles() as $single) {
                            $ui = $userInfoRepository->getByID($single->getUserID());
                            ?>
                            <tr>
                                <td>
                                    <?= h($ui ? $ui->getUserName() : t('Deleted user')) ?>
                                    <?php if ($single->getNote()) { ?>
                                        <div class="small text-muted"><?= h($single->getNote()) ?></div>
                                    <?php } ?>
                                </td>
                                <td class="small text-muted"><?= $dh->formatDate($single->getJoinedAt()) ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($poolTeams) { ?>
                                        <form method="post" action="<?= h($url('assign_single')) ?>" class="d-inline-flex">
                                            <?php $token->output($tokenAction) ?>
                                            <input type="hidden" name="return" value="<?= h($currentPath) ?>">
                                            <input type="hidden" name="single" value="<?= $single->getID() ?>">
                                            <select name="team" class="form-select form-select-sm" required aria-label="<?= t('Team') ?>">
                                                <option value=""><?= t('Add to team…') ?></option>
                                                <?php foreach ($poolTeams as $item) { ?>
                                                    <option value="<?= $item->getID() ?>"><?= h($item->getDisplayName()) ?></option>
                                                <?php } ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-primary ms-1" title="<?= t('Add to team') ?>"><i class="fas fa-user-plus"></i></button>
                                        </form>
                                    <?php } ?>
                                    <?php $button('remove_single', ['single' => $single->getID()], '<i class="fas fa-times"></i>', 'btn-outline-danger', t('Remove %s from the pool?', $ui ? $ui->getUserName() : '')) ?>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                <?php } ?>
                <form method="post" action="<?= h($url('add_single')) ?>">
                    <?php $token->output($tokenAction) ?>
                    <input type="hidden" name="return" value="<?= h($currentPath) ?>">
                    <input type="hidden" name="pool" value="<?= $pool->getID() ?>">
                    <div class="input-group">
                        <input type="text" name="user" class="form-control team-manager-user-input" list="team-manager-users" required autocomplete="off" placeholder="<?= t('Username') ?>" aria-label="<?= t('Username') ?>">
                        <input type="text" name="note" class="form-control" maxlength="255" placeholder="<?= t('Note (optional)') ?>" aria-label="<?= t('Note') ?>">
                        <button type="submit" class="btn btn-secondary"><i class="fas fa-user-plus"></i> <?= t('Add player') ?></button>
                    </div>
                </form>
            </div>
        <?php } ?>

        <div class="<?= $isNew ? 'col-lg-8' : 'col-lg-5' ?>">
            <h4><?= t('Settings') ?></h4>
            <form method="post" action="<?= h($url('save_pool')) ?>" class="mb-4">
                <?php $token->output($tokenAction) ?>
                <input type="hidden" name="return" value="<?= h($currentPath) ?>">
                <input type="hidden" name="pool" value="<?= $pool ? $pool->getID() : 0 ?>">
                <div class="mb-3">
                    <?= $form->label('name', t('Name')) ?>
                    <?= $form->text('name', $pool ? $pool->getName() : '', ['required' => 'required', 'maxlength' => 128]) ?>
                </div>
                <div class="mb-3">
                    <?= $form->label('description', t('Description')) ?>
                    <?= $form->textarea('description', $pool ? $pool->getDescription() : '', ['rows' => 3]) ?>
                </div>
                <div class="mb-3">
                    <div class="form-check">
                        <?= $form->checkbox('open', 1, $pool ? $pool->isOpen() : true) ?>
                        <?= $form->label('open', t('Open for joining'), ['class' => 'form-check-label']) ?>
                    </div>
                    <div class="form-check">
                        <?= $form->checkbox('allowTeams', 1, $pool ? $pool->allowsTeams() : true) ?>
                        <?= $form->label('allowTeams', t('Captains can register their teams'), ['class' => 'form-check-label']) ?>
                    </div>
                    <div class="form-check">
                        <?= $form->checkbox('allowTeamCreation', 1, $pool ? $pool->allowsTeamCreation() : false) ?>
                        <?= $form->label('allowTeamCreation', t('Users can create new teams in this pool'), ['class' => 'form-check-label']) ?>
                    </div>
                    <div class="form-check">
                        <?= $form->checkbox('allowSingles', 1, $pool ? $pool->allowsSingles() : true) ?>
                        <?= $form->label('allowSingles', t('Users can join as single player looking for a team'), ['class' => 'form-check-label']) ?>
                    </div>
                    <div class="form-text"><?= t('These settings apply to users. Admins can always add teams and players here.') ?></div>
                </div>
                <div class="mb-3">
                    <?= $form->label('maxTeams', t('Maximum number of teams')) ?>
                    <?= $form->number('maxTeams', $pool ? $pool->getMaxTeams() : 0, ['min' => 0]) ?>
                    <div class="form-text"><?= t('0 = unlimited') ?></div>
                </div>
                <div class="mb-3">
                    <?= $form->label('maxTeamSize', t('Maximum team size')) ?>
                    <?= $form->number('maxTeamSize', $pool ? $pool->getMaxTeamSize() : 0, ['min' => 0]) ?>
                    <div class="form-text"><?= t('Members per team, applies to admins too. 0 = the global setting applies.') ?></div>
                </div>
                <button type="submit" class="btn btn-primary"><?= $isNew ? t('Create Pool') : t('Save') ?></button>
            </form>

            <?php if (!$isNew) { ?>
                <h4><?= t('Core group') ?></h4>
                <p class="text-muted small">
                    <?= t('The pool is the group %s. Team players get the role "Team Player", free agents the role "Free Agent". You can use the group for permissions, e.g. to show a page to the pool\'s participants only. Memberships are managed here, changes made in Dashboard › Groups are overwritten on the next change or resync.', '<a href="' . h(Url::to('/dashboard/users/groups/edit', $pool->getID())) . '">' . h($pool->getName()) . '</a>') ?>
                </p>
                <div class="mb-4">
                    <?php $button('resync_pool', ['pool' => $pool->getID()], '<i class="fas fa-sync"></i> ' . t('Resync group members'), 'btn-secondary') ?>
                </div>

                <h4 class="text-danger"><?= t('Delete pool') ?></h4>
                <p class="text-muted small"><?= t('Deletes the pool group. The teams are kept and have no pool afterwards, the list of players looking for a team is deleted.') ?></p>
                <?php $button('delete_pool', ['pool' => $pool->getID()], '<i class="fas fa-trash"></i> ' . t('Delete pool'), 'btn-danger', t('Delete the pool %s?', $pool->getName())) ?>
            <?php } ?>
        </div>
    </div>

<?php } ?>

<script>
(function () {
    // username autocomplete for all inputs bound to the shared datalist
    var searchURL = <?= json_encode($searchUsersURL) ?>;
    var datalist = document.getElementById('team-manager-users');
    var timer = null;
    document.addEventListener('input', function (e) {
        if (!e.target.matches('.team-manager-user-input')) {
            return;
        }
        var q = e.target.value.trim();
        clearTimeout(timer);
        if (q.length < 2) {
            return;
        }
        timer = setTimeout(function () {
            fetch(searchURL + '?q=' + encodeURIComponent(q), {credentials: 'same-origin'})
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (names) {
                    datalist.innerHTML = '';
                    names.forEach(function (name) {
                        var option = document.createElement('option');
                        option.value = name;
                        datalist.appendChild(option);
                    });
                });
        }, 250);
    });
})();
</script>

<?php if ($mode === 'board') { ?>
<style>
    #team-board .team-board-members { min-height: 3rem; }
    #team-board .team-board-members.drag-over { background: rgba(13, 110, 253, .08); outline: 2px dashed #0d6efd; }
    #team-board .team-board-member, .team-board-single { cursor: grab; }
    .team-board-single { font-size: .9rem; padding: .4rem .6rem; }
    .team-board-single.dragging { opacity: .4; }
    #team-board .team-board-member.dragging { opacity: .4; }
    #team-board .team-board-member .team-board-actions { visibility: hidden; }
    #team-board .team-board-member:hover .team-board-actions { visibility: visible; }
    #team-board .card.busy { opacity: .6; pointer-events: none; }
</style>
<script>
(function () {
    var board = document.getElementById('team-board');
    var teams = JSON.parse(document.getElementById('team-board-data').textContent);
    var i18n = JSON.parse(document.getElementById('team-board-i18n').textContent);
    var keepCaptain = document.getElementById('team-board-keep-captain');
    var filter = document.getElementById('team-board-filter');
    var dragged = null;
    var singlesBox = document.getElementById('team-board-singles');
    var singles = JSON.parse(document.getElementById('team-board-singles-data').textContent);

    // players looking for a team, only shown when the board is filtered by a pool
    function renderSingles(list) {
        if (!singlesBox) return;
        singlesBox.innerHTML = '';
        if (!list.length) {
            singlesBox.appendChild(el('span', 'text-muted small', i18n.noSingles));
        }
        list.forEach(function (single) {
            var chip = el('span', 'badge bg-light text-dark border d-inline-flex align-items-center team-board-single');
            chip.draggable = true;
            chip.title = single.note || '';
            chip.appendChild(el('i', 'fas fa-user me-1'));
            chip.appendChild(document.createTextNode(single.name));
            chip.appendChild(iconButton('fa-times', i18n.removeSingle, 'text-danger', function () {
                if (confirm(i18n.confirmRemoveSingle.replace('%s', single.name))) {
                    post(board.dataset.removeSingleUrl, {single: single.id}, []);
                }
            }));
            chip.addEventListener('dragstart', function (e) {
                dragged = {single: single.id};
                chip.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', String(single.id));
            });
            chip.addEventListener('dragend', function () {
                chip.classList.remove('dragging');
                dragged = null;
            });
            singlesBox.appendChild(chip);
        });
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function iconButton(icon, title, className, onClick) {
        var btn = el('button', 'btn btn-sm btn-link p-0 ms-2 ' + className);
        btn.type = 'button';
        btn.title = title;
        btn.appendChild(el('i', 'fas ' + icon));
        btn.addEventListener('click', onClick);
        return btn;
    }

    function renderCard(team) {
        var col = el('div', 'col');
        col.dataset.teamId = team.id;
        var card = el('div', 'card h-100');
        var header = el('div', 'card-header d-flex justify-content-between align-items-center');
        var title = el('a', 'fw-bold text-decoration-none', team.name);
        title.href = team.url;
        title.title = i18n.manage;
        var titleWrap = el('span');
        titleWrap.appendChild(title);
        if (team.pool && !singlesBox) {
            titleWrap.appendChild(el('div', 'small text-muted', team.pool));
        }
        header.appendChild(titleWrap);
        header.appendChild(el('span', 'badge bg-secondary', String(team.members.length)));
        card.appendChild(header);

        var list = el('ul', 'list-group list-group-flush team-board-members');
        list.dataset.teamId = team.id;
        if (!team.members.length) {
            list.appendChild(el('li', 'list-group-item text-muted small', i18n.empty));
        }
        team.members.forEach(function (member) {
            var item = el('li', 'list-group-item d-flex align-items-center team-board-member');
            item.draggable = true;
            item.dataset.userId = member.id;
            item.dataset.name = member.name.toLowerCase();
            var name = el('span', 'flex-grow-1', member.name);
            if (member.captain) {
                name.appendChild(document.createTextNode(' '));
                name.appendChild(el('span', 'badge bg-primary', i18n.captain));
            }
            item.appendChild(name);
            var actions = el('span', 'team-board-actions text-nowrap');
            actions.appendChild(iconButton(member.captain ? 'fa-user' : 'fa-star', member.captain ? i18n.makeMember : i18n.makeCaptain, 'text-secondary', function () {
                post(board.dataset.captainUrl, {team: team.id, user: member.id, captain: member.captain ? 0 : 1}, [col]);
            }));
            actions.appendChild(iconButton('fa-times', i18n.remove, 'text-danger', function () {
                if (confirm(i18n.confirmRemove.replace('%s', member.name).replace('%s', team.name))) {
                    post(board.dataset.removeUrl, {team: team.id, user: member.id}, [col]);
                }
            }));
            item.appendChild(actions);
            item.addEventListener('dragstart', function (e) {
                dragged = {user: member.id, team: team.id};
                item.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', String(member.id));
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('dragging');
                dragged = null;
            });
            list.appendChild(item);
        });

        list.addEventListener('dragover', function (e) {
            if (dragged && (dragged.single || dragged.team !== team.id)) {
                e.preventDefault();
                list.classList.add('drag-over');
            }
        });
        list.addEventListener('dragleave', function () {
            list.classList.remove('drag-over');
        });
        list.addEventListener('drop', function (e) {
            e.preventDefault();
            list.classList.remove('drag-over');
            if (dragged && dragged.single) {
                post(board.dataset.assignUrl, {single: dragged.single, team: team.id}, [col]);
                return;
            }
            if (!dragged || dragged.team === team.id) return;
            var source = board.querySelector('.col[data-team-id="' + dragged.team + '"]');
            post(board.dataset.moveUrl, {
                team: dragged.team,
                target: team.id,
                user: dragged.user,
                keepCaptain: keepCaptain.checked ? 1 : 0
            }, [col, source]);
        });
        card.appendChild(list);

        var footer = el('form', 'card-footer');
        var group = el('div', 'input-group input-group-sm');
        var input = el('input', 'form-control team-manager-user-input');
        input.name = 'user';
        input.required = true;
        input.autocomplete = 'off';
        input.placeholder = i18n.username;
        input.setAttribute('list', 'team-manager-users');
        var add = el('button', 'btn btn-outline-primary');
        add.type = 'submit';
        add.title = i18n.add;
        add.appendChild(el('i', 'fas fa-user-plus'));
        group.appendChild(input);
        group.appendChild(add);
        footer.appendChild(group);
        footer.addEventListener('submit', function (e) {
            e.preventDefault();
            post(board.dataset.addUrl, {team: team.id, user: input.value}, [col]);
        });
        card.appendChild(footer);

        col.appendChild(card);
        return col;
    }

    function replaceTeam(team) {
        var existing = board.querySelector('.col[data-team-id="' + team.id + '"]');
        if (team.deleted) {
            if (existing) existing.remove();
            return;
        }
        var fresh = renderCard(team);
        if (existing) {
            board.replaceChild(fresh, existing);
        } else {
            board.appendChild(fresh);
        }
        applyFilter();
    }

    function notify(type, message) {
        if (window.ConcreteAlert) {
            type === 'success' ? ConcreteAlert.notify({message: message}) : ConcreteAlert.error({message: message});
        } else if (type !== 'success') {
            alert(message);
        }
    }

    function post(url, data, cols) {
        var body = new FormData();
        body.append('ccm_token', board.dataset.token);
        Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
        cols.forEach(function (col) { if (col) col.querySelector('.card').classList.add('busy'); });

        fetch(url, {method: 'POST', body: body, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success) {
                    result.teams.forEach(replaceTeam);
                    if (result.singles) {
                        renderSingles(result.singles);
                    }
                    notify('success', result.message);
                } else {
                    notify('error', result.message);
                }
            })
            .catch(function () { notify('error', 'Request failed.'); })
            .finally(function () {
                cols.forEach(function (col) { if (col && col.isConnected) col.querySelector('.card').classList.remove('busy'); });
            });
    }

    function applyFilter() {
        var q = filter.value.trim().toLowerCase();
        board.querySelectorAll('.col[data-team-id]').forEach(function (col) {
            var title = col.querySelector('.card-header a').textContent.toLowerCase();
            var memberMatch = Array.prototype.some.call(col.querySelectorAll('.team-board-member'), function (item) {
                return item.dataset.name.indexOf(q) !== -1;
            });
            col.style.display = !q || title.indexOf(q) !== -1 || memberMatch ? '' : 'none';
        });
    }

    teams.forEach(function (team) { board.appendChild(renderCard(team)); });
    renderSingles(singles);
    filter.addEventListener('input', applyFilter);
})();
</script>
<?php } ?>
