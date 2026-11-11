<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Application\StrategyService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Écrans du module Stratégie (docs/07-ux-ui.md §4.7) : recommandations par onglet et plan d'action.
 */
final class StrategyPagesController extends Controller
{
    private const TABS = ['nouvelles' => 'new', 'acceptees' => 'accepted', 'reportees' => 'snoozed', 'ignorees' => 'dismissed', 'resolues' => 'resolved'];
    private const MODULES = ['pricing', 'recurring', 'diagnostic', 'strategy'];

    public function index(Request $request): Response
    {
        $tabKey = $request->query['onglet'] ?? 'nouvelles';
        $tabKey = array_key_exists($tabKey, self::TABS) ? $tabKey : 'nouvelles';
        $module = $request->query['module'] ?? null;
        $module = in_array($module, self::MODULES, true) ? $module : null;

        return $this->screen($request, 'strategy/index', 'strategie', 'Stratégie', [
            'list' => $this->app->strategy()->list($this->org($request), self::TABS[$tabKey], $module),
            'tabKey' => $tabKey,
            'tabs' => self::TABS,
            'module' => $module,
            'snoozeDays' => StrategyService::SNOOZE_DAYS,
        ]);
    }

    public function plan(Request $request): Response
    {
        return $this->screen($request, 'strategy/plan', 'strategie', 'Plan d\'action', ['plan' => $this->app->strategy()->plan($this->org($request))]);
    }
}
