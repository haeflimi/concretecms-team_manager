<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var \Concrete\Core\Form\Service\Form $form
 * @var int $allowTeamCreation
 * @var int $poolID
 * @var array $poolOptions
 */
?>
<div class="form-group">
    <?= $form->label('poolID', t('Team Pool')) ?>
    <?= $form->select('poolID', $poolOptions, (int) $poolID) ?>
    <div class="help-block"><?= t('Limits the block to one pool: only teams, invitations and free agent entries of this pool are shown and can be changed, new teams are created in it. The pool settings (open, allow teams, max. teams) apply.') ?></div>
</div>
<div class="form-group">
    <div class="form-check">
        <?= $form->checkbox('allowTeamCreation', 1, (bool) $allowTeamCreation) ?>
        <?= $form->label('allowTeamCreation', t('Allow users to create teams'), ['class' => 'form-check-label']) ?>
    </div>
</div>
