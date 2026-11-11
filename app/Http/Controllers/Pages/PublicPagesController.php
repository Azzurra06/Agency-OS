<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\App;
use App\Domain\Diagnostic\QuestionnaireRegistry;
use App\Http\Request;
use App\Http\Response;
use App\Support\View;

/**
 * Pages publiques : accueil, démonstration expirée, méthodologie, mentions légales, confidentialité.
 */
final class PublicPagesController
{
    public function __construct(private readonly App $app)
    {
    }

    public function home(Request $request): Response
    {
        return Response::html(View::render('pages/home', [
            'title' => 'Agency OS',
            'isPortfolio' => $this->app->config->isPortfolio(),
        ], 'layouts/minimal'));
    }

    public function demoExpired(Request $request): Response
    {
        return Response::html(View::render('pages/demo-expired', ['title' => 'Démonstration expirée'], 'layouts/minimal'));
    }

    public function methodology(Request $request): Response
    {
        return Response::html(View::render('pages/methodology', ['title' => 'Méthodologie', 'wide' => true, 'questionnaire' => QuestionnaireRegistry::current()], 'layouts/minimal'));
    }

    public function legal(Request $request): Response
    {
        return Response::html(View::render('pages/legal', ['title' => 'Mentions légales', 'config' => $this->app->config], 'layouts/minimal'));
    }

    public function privacy(Request $request): Response
    {
        return Response::html(View::render('pages/privacy', ['title' => 'Confidentialité', 'config' => $this->app->config], 'layouts/minimal'));
    }
}
