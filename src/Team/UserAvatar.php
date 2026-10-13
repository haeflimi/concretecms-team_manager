<?php
namespace TeamManager\Team;

use Concrete\Core\User\UserInfo;

/**
 * Small avatar in front of a username in the frontend lists: the member's own avatar, the user icon if there is none.
 */
class UserAvatar
{
    public static function html(?UserInfo $userInfo): string
    {
        if ($userInfo && $userInfo->hasAvatar()) {
            return '<img src="' . h((string) $userInfo->getUserAvatar()->getPath()) . '" alt="" class="team-manager-avatar" loading="lazy">';
        }

        return '<i class="fa fa-user"></i>';
    }
}
