<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var array<int|string, string> $options
 * @var string $fieldName
 * @var string $selected
 */
$form = Core::make('helper/form');
echo $form->select($fieldName, $options, $selected);
