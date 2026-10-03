<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var string $recipientName
 * @var string $siteName
 * @var string $teamName
 * @var string $fromName
 * @var string $link
 */

$subject = t('%s invited you to join %s', $fromName, $teamName);
$body = t("
Hi %s,

%s invited you to join the team %s on %s.

Accept or decline the invitation here:
%s
", $recipientName, $fromName, $teamName, $siteName, $link);
