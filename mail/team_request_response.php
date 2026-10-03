<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var string $recipientName
 * @var string $siteName
 * @var string $teamName
 * @var string $fromName
 * @var string $link
 * @var bool $isInvite
 * @var bool $accepted
 */

if ($isInvite) {
    $subject = $accepted
        ? t('%s accepted your invitation to %s', $fromName, $teamName)
        : t('%s declined your invitation to %s', $fromName, $teamName);
} else {
    $subject = $accepted
        ? t('Your request to join %s was approved', $teamName)
        : t('Your request to join %s was denied', $teamName);
}

$body = t("
Hi %s,

%s

%s
", $recipientName, $subject, $link);
