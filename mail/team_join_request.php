<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var string $recipientName
 * @var string $siteName
 * @var string $teamName
 * @var string $fromName
 * @var string $link
 */

$subject = t('%s wants to join %s', $fromName, $teamName);
$body = t("
Hi %s,

%s would like to join your team %s on %s.

Approve or deny the request here:
%s
", $recipientName, $fromName, $teamName, $siteName, $link);
