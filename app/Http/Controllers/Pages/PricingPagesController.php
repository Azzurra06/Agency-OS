<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Support\View;

/**
 * Écrans du module Pricing (docs/07-ux-ui.md §4.5) : liste, simulateur (brouillon) et version figée.
 */
final class PricingPagesController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $this->org($request);

        return $this->screen($request, 'pricing/index', 'pricing', 'Pricing', [
            'simulations' => $this->app->pricing()->index($org),
            'services' => $this->app->serviceRepository()->all($org, true),
            'filter' => in_array($request->query['filtre'] ?? '', ['actives', 'brouillons', 'archivees'], true) ? $request->query['filtre'] : 'toutes',
        ]);
    }

    public function show(Request $request): Response
    {
        $org = $this->org($request);
        $view = $this->app->pricing()->view($org, $request->param('id'), $this->app->organizations()->priceRounding($org));
        if ($view === null) {
            return Response::html(View::render('errors/error', [
                'title' => 'Page introuvable', 'message' => 'Cette simulation n\'existe pas ou n\'est pas accessible.', 'status' => 404, 'errorRef' => null,
            ], 'layouts/minimal'), 404);
        }
        /** @var array{name: string} $simulation */
        $simulation = $view['simulation'];

        return $this->screen($request, 'pricing/show', 'pricing', 'Pricing · ' . $simulation['name'], [
            'view' => $view,
            'scenarioId' => $request->query['scenario'] ?? null,
        ]);
    }
}
