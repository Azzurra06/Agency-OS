<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Support\View;

/**
 * Écrans du module Revenus récurrents (docs/07-ux-ui.md §4.6) : synthèse (réel / prévisionnel), formules, scénarios.
 */
final class RecurringPagesController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $this->org($request);
        $service = $this->app->recurring();

        return $this->screen($request, 'recurring/index', 'recurrent', 'Revenus récurrents', [
            'summary' => $service->summary($org),
            'offers' => $service->offers($org),
            'scenarios' => $service->scenarios($org),
        ]);
    }

    public function newOffer(Request $request): Response
    {
        return $this->screen($request, 'recurring/offer', 'recurrent', 'Nouvelle formule', ['offer' => null]);
    }

    public function offer(Request $request): Response
    {
        $offer = $this->app->recurring()->offer($this->org($request), $request->param('id'));
        if ($offer === null) {
            return $this->notFoundPage('Cette formule n\'existe pas ou n\'est pas accessible.');
        }

        return $this->screen($request, 'recurring/offer', 'recurrent', 'Formule · ' . (is_string($offer['name']) ? $offer['name'] : ''), ['offer' => $offer]);
    }

    public function scenario(Request $request): Response
    {
        $view = $this->app->recurring()->scenarioView($this->org($request), $request->param('id'));
        if ($view === null) {
            return $this->notFoundPage('Ce scénario n\'existe pas ou n\'est pas accessible.');
        }

        return $this->screen($request, 'recurring/scenario', 'recurrent', 'Scénario récurrent', ['view' => $view, 'charts' => true]);
    }

    private function notFoundPage(string $message): Response
    {
        return Response::html(View::render('errors/error', ['title' => 'Page introuvable', 'message' => $message, 'status' => 404, 'errorRef' => null], 'layouts/minimal'), 404);
    }
}
