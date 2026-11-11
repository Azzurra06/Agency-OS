<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Écrans de l'application : vue d'ensemble, onboarding, paramètres. Les modules pricing, récurrent
 * et stratégie arrivent aux lots suivants (page d'attente).
 */
final class AppPagesController extends Controller
{
    private const MODULES = [
        'pricing' => ['Pricing', 'tag', 3, 'Vérifiez que vos prix couvrent vos coûts et votre marge cible.'],
        'recurrent' => ['Revenus récurrents', 'repeat', 4, 'Concevez vos offres de maintenance et simulez votre MRR.'],
        'strategie' => ['Stratégie', 'target', 5, 'Recevez des recommandations priorisées et suivez votre plan d\'action.'],
    ];

    public function dashboard(Request $request): Response
    {
        $org = $this->org($request);
        $identity = $this->app->organizations()->get($org);
        if (!$identity['onboarding_completed'] && !$identity['is_demo']) {
            return Response::redirect('/app/bienvenue');
        }

        return $this->screen($request, 'app/dashboard', 'vue', 'Vue d\'ensemble', [
            'derived' => $this->app->financialProfiles()->derived($org),
            'services' => $this->app->serviceRepository()->all($org, true),
            'diagnostic' => $this->app->diagnostics()->overview($org),
        ]);
    }

    public function onboarding(Request $request): Response
    {
        $org = $this->org($request);
        $step = (int) ($request->query['etape'] ?? 1);
        $step = max(1, min(3, $step));

        return $this->screen($request, 'app/onboarding', 'vue', 'Bienvenue', [
            'step' => $step,
            'values' => $this->app->financialProfiles()->formValues($org),
            'services' => $this->app->serviceRepository()->all($org),
        ]);
    }

    public function settingsAgency(Request $request): Response
    {
        return $this->screen($request, 'app/settings-agency', 'parametres', 'Paramètres · Agence', ['tab' => 'agence']);
    }

    public function settingsFinance(Request $request): Response
    {
        $org = $this->org($request);

        return $this->screen($request, 'app/settings-finance', 'parametres', 'Paramètres · Finances', [
            'tab' => 'finances',
            'values' => $this->app->financialProfiles()->formValues($org),
            'derived' => $this->app->financialProfiles()->derived($org),
        ]);
    }

    public function settingsServices(Request $request): Response
    {
        return $this->screen($request, 'app/settings-services', 'parametres', 'Paramètres · Prestations', [
            'tab' => 'prestations',
            'services' => $this->app->serviceRepository()->all($this->org($request)),
        ]);
    }

    public function module(Request $request): Response
    {
        $key = trim(substr($request->path, strlen('/app/')), '/');
        [$label, $icon, $lot, $promise] = self::MODULES[$key] ?? self::MODULES['pricing'];

        return $this->screen($request, 'app/module-soon', $key, $label, ['moduleLabel' => $label, 'moduleIcon' => $icon, 'lot' => $lot, 'promise' => $promise]);
    }
}
