<?php

namespace TeamManager\Figure;

use CommunityEventManager\Figure\CallbackFigure;
use CommunityEventManager\Figure\FigureContext;
use CommunityEventManager\Figure\FigureOptions as O;
use CommunityEventManager\Figure\FigureRegistry;
use CommunityEventManager\Figure\FigureValue;

/**
 * Figures for the block "Event Figure" (community_event_manager), registered on on_event_figures_register.
 */
class TeamFigures
{
    public static function register(FigureRegistry $registry): void
    {
        $group = t('Teams');
        $registry->register(CallbackFigure::make('teams.count', t('Teams'), $group, 'fas fa-shield-alt', [], static function (FigureContext $c, array $o) {
            return FigureValue::number($c->db->fetchOne('SELECT COUNT(*) FROM tmTeam'));
        }));

        $registry->register(CallbackFigure::make('teams.members', t('Team members'), $group, 'fas fa-user-friends', [], static function (FigureContext $c, array $o) {
            return FigureValue::number($c->db->fetchOne('SELECT COUNT(DISTINCT uID) FROM tmTeamMember'));
        }));
    }
}
