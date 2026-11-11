<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Support\View;

/**
 * Écrans du module Diagnostic (docs/07-ux-ui.md §4.4) : accueil, questionnaire par étapes, résultats figés.
 */
final class DiagnosticPagesController extends Controller
{
    public function home(Request $request): Response
    {
        return $this->screen($request, 'diagnostic/home', 'diagnostic', 'Diagnostic', [
            'overview' => $this->app->diagnostics()->overview($this->org($request)),
        ]);
    }

    public function questionnaire(Request $request): Response
    {
        $view = $this->app->diagnostics()->draftView($this->org($request), (int) ($request->query['etape'] ?? 1));
        if ($view === null) {
            return Response::redirect('/app/diagnostic');
        }

        return $this->screen($request, 'diagnostic/questionnaire', 'diagnostic', 'Diagnostic · ' . $view['dimension']['label'], ['draft' => $view]);
    }

    public function results(Request $request): Response
    {
        $results = $this->app->diagnostics()->results($this->org($request), $request->param('id'));
        if ($results === null) {
            return Response::html(View::render('errors/error', [
                'title' => 'Page introuvable', 'message' => 'Ce diagnostic n\'existe pas ou n\'est pas accessible.', 'status' => 404, 'errorRef' => null,
            ], 'layouts/minimal'), 404);
        }

        return $this->screen($request, 'diagnostic/results', 'diagnostic', 'Diagnostic · Résultats', ['results' => $results, 'charts' => true]);
    }
}
