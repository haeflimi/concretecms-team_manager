<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var \Concrete\Core\Form\Service\Form $form
 * @var int $itemsPerPage
 * @var int $myTeamsPageID
 * @var int $joinPoolID
 * @var int $onlyListJoinPool
 * @var array $poolOptions
 */

$pageSelector = app('helper/form/page_selector');
?>
<div class="form-group">
    <?= $form->label('joinPoolID', t('Joining allowed in')) ?>
    <?= $form->select('joinPoolID', $poolOptions, (int) $joinPoolID) ?>
    <div class="help-block"><?= t('Limit joining teams, joining as single player and registering teams to one pool.') ?></div>
</div>
<div class="form-group">
    <div class="form-check">
        <?= $form->checkbox('onlyListJoinPool', 1, (bool) $onlyListJoinPool) ?>
        <?= $form->label('onlyListJoinPool', t('Only show this pool'), ['class' => 'form-check-label']) ?>
    </div>
    <div class="help-block"><?= t('Shows the selected pool directly instead of the overview of all pools and teams.') ?></div>
</div>
<div class="form-group">
    <?= $form->label('itemsPerPage', t('Teams per page')) ?>
    <?= $form->number('itemsPerPage', $itemsPerPage, ['min' => 1]) ?>
</div>
<div class="form-group">
    <?= $form->label('myTeamsPageID', t('My Teams page')) ?>
    <?= $pageSelector->selectPage('myTeamsPageID', $myTeamsPageID ?: null) ?>
    <div class="help-block"><?= t('Optional. A page with the My Teams block, linked so users can create and manage teams.') ?></div>
</div>
