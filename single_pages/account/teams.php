<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var \Concrete\Core\Page\Page $c
 */

// the My Teams block is added to this area on install / upgrade
$area = new Area('Main');
$area->display($c);
