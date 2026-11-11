<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Diagnostic\DiagnosticResult;
use App\Domain\Diagnostic\DiagnosticScorer;
use App\Domain\Diagnostic\DimensionScore;
use App\Domain\Diagnostic\Questionnaire;
use App\Domain\Diagnostic\QuestionnaireRegistry;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Repositories\DiagnosticRepository;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Support\Decimal;
use LogicException;

/**
 * Cas d'usage du module Diagnostic (docs/05 §3.3, docs/07 §4.4) :
 * brouillon unique avec sauvegarde automatique et reprise, préremplissage depuis le profil,
 * clôture qui fige les scores, résultats, historique et comparaison avec le diagnostic précédent.
 */
final class DiagnosticService
{
    public function __construct(
        private readonly DiagnosticRepository $repository,
        private readonly OrganizationRepository $organizations,
        private readonly FinancialProfileService $profiles,
        private readonly DiagnosticScorer $scorer = new DiagnosticScorer(),
    ) {
    }

    /**
     * Reprend le brouillon existant ou en crée un. Avec $copyFrom (diagnostic terminé de la même version),
     * le nouveau brouillon reprend ses réponses (« Compléter ces réponses »).
     *
     * @return array{public_id: string, resumed: bool}
     */
    public function startOrResume(OrgId $org, ?int $userId, ?string $copyFrom = null): array
    {
        $draft = $this->repository->draft($org);
        if ($draft !== null) {
            return ['public_id' => $draft['public_id'], 'resumed' => true];
        }
        $questionnaire = QuestionnaireRegistry::current();
        $source = $copyFrom === null ? null : $this->repository->find($org, $copyFrom);
        if ($copyFrom !== null && ($source === null || $source['status'] !== 'completed')) {
            throw new ValidationException(['from' => 'Diagnostic introuvable.']);
        }

        $publicId = $this->repository->createDraft($org, $userId, $questionnaire->version, DiagnosticScorer::CALC_VERSION);
        if ($publicId === null) { // brouillon créé entre-temps (autre onglet)
            $existing = $this->repository->draft($org) ?? throw new LogicException('Brouillon introuvable.');

            return ['public_id' => $existing['public_id'], 'resumed' => true];
        }
        $draft = $this->repository->find($org, $publicId) ?? throw new LogicException('Brouillon introuvable.');

        if ($source !== null && $source['questionnaire_version'] === $questionnaire->version) {
            foreach ($this->repository->answers($org, $source['id']) as $code => $answer) {
                if ($questionnaire->isValidAnswer($code, $answer['option'])) {
                    $this->repository->saveAnswer($org, $draft['id'], $code, $answer['option'], false);
                }
            }
        }
        $this->prefill($org, $draft['id'], $questionnaire, $source === null ? [] : $this->repository->answers($org, $draft['id']));

        return ['public_id' => $publicId, 'resumed' => false];
    }

    /**
     * Enregistre une réponse (sauvegarde automatique) et renvoie la progression recalculée.
     *
     * @param array<string, mixed> $input {question: string, option: n0..n3|unknown}
     * @return array<string, mixed>
     */
    public function answer(OrgId $org, string $publicId, array $input): array
    {
        $draft = $this->requireDraft($org, $publicId);
        $questionnaire = QuestionnaireRegistry::get($draft['questionnaire_version']);
        $question = is_string($input['question'] ?? null) ? $input['question'] : '';
        $option = is_string($input['option'] ?? null) ? $input['option'] : '';
        if ($questionnaire->question($question) === null) {
            throw new ValidationException(['question' => 'Question inconnue.']);
        }
        if (!$questionnaire->isValidAnswer($question, $option)) {
            throw new ValidationException(['option' => 'Réponse invalide.']);
        }
        $this->repository->saveAnswer($org, $draft['id'], $question, $option, false);

        return $this->progress($org, $draft, $questionnaire);
    }

    /**
     * Clôture. Par défaut, toutes les questions applicables doivent avoir une réponse (« Je ne sais pas » compris).
     * Avec $acceptMissing (confirmation explicite de l'utilisateur), les questions sans réponse sont exclues du calcul,
     * comme une réponse inconnue : les dimensions trop incomplètes deviennent « Données insuffisantes » et le diagnostic
     * est « partiel ». Au moins une dimension doit rester calculable.
     *
     * @return array{public_id: string, completeness: string, missing: int}
     */
    public function complete(OrgId $org, string $publicId, bool $acceptMissing = false): array
    {
        $draft = $this->requireDraft($org, $publicId);
        $questionnaire = QuestionnaireRegistry::get($draft['questionnaire_version']);
        $result = $this->scorer->score($questionnaire, $this->options($org, $draft['id']), $this->context($org));
        if (!$result->isComplete() && !$acceptMissing) {
            throw new ValidationException(['answers' => sprintf('%d question(s) sans réponse.', count($result->missing))]);
        }
        if (count($result->unscorableDimensions()) === count($result->dimensions)) {
            throw new ValidationException(['answers' => 'Répondez au moins à une dimension complète pour obtenir un résultat.']);
        }
        if (!$this->repository->complete($org, $draft['id'], $result, DiagnosticScorer::CALC_VERSION)) {
            throw new ValidationException(['status' => 'Ce diagnostic est déjà terminé.']);
        }

        return ['public_id' => $publicId, 'completeness' => $result->completeness(), 'missing' => count($result->missing)];
    }

    public function discardDraft(OrgId $org, string $publicId): bool
    {
        $draft = $this->repository->find($org, $publicId);

        return $draft !== null && $draft['status'] === 'draft' && $this->repository->discardDraft($org, $draft['id']);
    }

    /**
     * Données de l'écran questionnaire pour une étape (une dimension par étape).
     *
     * @return array{public_id: string, version: string, step: int, steps: int, dimension: array{code: string, label: string, description: string}, questions: list<array<string, mixed>>, progress: array<string, mixed>, updated_at: string}|null null si aucun brouillon
     */
    public function draftView(OrgId $org, int $step): ?array
    {
        $draft = $this->repository->draft($org);
        if ($draft === null) {
            return null;
        }
        $questionnaire = QuestionnaireRegistry::get($draft['questionnaire_version']);
        $answers = $this->repository->answers($org, $draft['id']);
        $options = array_map(static fn (array $a): string => $a['option'], $answers);
        $applicable = array_flip($this->scorer->applicableQuestions($questionnaire, $options, $this->context($org)));
        $codes = $questionnaire->dimensionCodes();
        $step = max(1, min(count($codes), $step));
        $code = $codes[$step - 1];
        $dimension = $questionnaire->dimensions[$code];

        $questions = [];
        foreach ($dimension['questions'] as $questionCode => $question) {
            $questions[] = [
                'code' => $questionCode,
                'text' => $question['text'],
                'options' => $question['options'],
                'unknown_policy' => $question['unknown_policy'],
                'conditional' => $question['condition'] !== null,
                'visible' => isset($applicable[$questionCode]),
                'answer' => $answers[$questionCode]['option'] ?? null,
                'prefilled' => $answers[$questionCode]['prefilled'] ?? false,
            ];
        }

        return [
            'public_id' => $draft['public_id'],
            'version' => $questionnaire->version,
            'step' => $step,
            'steps' => count($codes),
            'dimension' => ['code' => $code, 'label' => $dimension['label'], 'description' => $dimension['description']],
            'questions' => $questions,
            'progress' => $this->progress($org, $draft, $questionnaire),
            'updated_at' => $draft['updated_at'],
        ];
    }

    /**
     * Progression du brouillon : questions applicables répondues, dimensions terminées, prêt à clôturer.
     *
     * @param array{id: int, public_id: string, questionnaire_version: string} $draft
     * @return array{answered: int, applicable: int, dimensions_done: int, dimensions: int, ready: bool, applicable_questions: list<string>, by_dimension: array<string, array{answered: int, applicable: int}>}
     */
    private function progress(OrgId $org, array $draft, Questionnaire $questionnaire): array
    {
        $options = $this->options($org, $draft['id']);
        $applicable = $this->scorer->applicableQuestions($questionnaire, $options, $this->context($org));
        $applicableSet = array_flip($applicable);
        $byDimension = [];
        $answered = 0;
        foreach ($questionnaire->dimensions as $dimensionCode => $dimension) {
            $counts = ['answered' => 0, 'applicable' => 0];
            foreach (array_keys($dimension['questions']) as $code) {
                if (!isset($applicableSet[$code])) {
                    continue;
                }
                $counts['applicable']++;
                if (isset($options[$code])) {
                    $counts['answered']++;
                    $answered++;
                }
            }
            $byDimension[$dimensionCode] = $counts;
        }
        $done = count(array_filter($byDimension, static fn (array $d): bool => $d['answered'] === $d['applicable']));

        return [
            'answered' => $answered,
            'applicable' => count($applicable),
            'dimensions_done' => $done,
            'dimensions' => count($byDimension),
            'ready' => $answered === count($applicable),
            'applicable_questions' => $applicable,
            'by_dimension' => $byDimension,
        ];
    }

    /**
     * Synthèse pour l'accueil du module et la vue d'ensemble.
     *
     * @return array{draft: array{public_id: string, dimensions_done: int, dimensions: int, answered: int, applicable: int}|null, latest: array<string, mixed>|null, history: list<array<string, mixed>>}
     */
    public function overview(OrgId $org): array
    {
        $draft = $this->repository->draft($org);
        $draftSummary = null;
        if ($draft !== null) {
            $p = $this->progress($org, $draft, QuestionnaireRegistry::get($draft['questionnaire_version']));
            $draftSummary = ['public_id' => $draft['public_id'], 'dimensions_done' => $p['dimensions_done'], 'dimensions' => $p['dimensions'],
                'answered' => $p['answered'], 'applicable' => $p['applicable']];
        }
        $history = array_map(static function (array $h): array {
            $global = $h['global'] === null ? null : Decimal::of($h['global']);

            return $h + [
                'global_display' => $global?->round(0)->toFixed(0),
                'level' => DiagnosticResult::level($global),
                'reliability_label' => $h['reliability'] === null ? null : DiagnosticResult::reliabilityLabel(Decimal::of($h['reliability'])),
            ];
        }, $this->repository->history($org));

        return ['draft' => $draftSummary, 'latest' => $history[0] ?? null, 'history' => $history];
    }

    /**
     * Résultats figés d'un diagnostic terminé, interprétés avec SA version de questionnaire.
     *
     * @return array<string, mixed>|null
     */
    public function results(OrgId $org, string $publicId): ?array
    {
        $diagnostic = $this->repository->find($org, $publicId);
        if ($diagnostic === null || $diagnostic['status'] !== 'completed' || $diagnostic['completed_at'] === null) {
            return null;
        }
        $questionnaire = QuestionnaireRegistry::get($diagnostic['questionnaire_version']);
        $scores = $this->repository->scores($org, $diagnostic['id']);
        $result = $this->frozenResult($questionnaire, $scores);
        $answers = $this->options($org, $diagnostic['id']);
        $applicable = array_flip($this->scorer->applicableQuestions($questionnaire, $answers, $this->context($org)));

        $dimensions = [];
        foreach ($questionnaire->dimensions as $code => $dimension) {
            $s = $scores[$code] ?? null;
            if ($s === null) {
                continue;
            }
            $unknownQuestions = [];
            foreach ($dimension['questions'] as $questionCode => $question) {
                $answer = $answers[$questionCode] ?? null;
                if ($answer === null && isset($applicable[$questionCode])) {
                    $unknownQuestions[] = ['code' => $questionCode, 'text' => $question['text'], 'reason' => 'unanswered'];
                } elseif ($answer === Questionnaire::UNKNOWN && $question['unknown_policy'] === 'unknown') {
                    $unknownQuestions[] = ['code' => $questionCode, 'text' => $question['text'], 'reason' => 'unknown'];
                }
            }
            $score = $s['score'] === null ? null : Decimal::of($s['score']);
            $dimensions[$code] = [
                'code' => $code,
                'label' => $dimension['label'],
                'description' => $dimension['description'],
                'score' => $s['score'],
                'score_display' => $score?->round(0)->toFixed(0),
                'level' => DiagnosticResult::level($score),
                'scorable' => $s['is_scorable'],
                'applicable' => $s['applicable_count'],
                'scored' => $s['scored_count'],
                'unknown_excluded' => $s['unknown_excluded_count'],
                'unknown_evaluable' => $s['unknown_evaluable_count'],
                'unanswered' => $s['applicable_count'] - $s['scored_count'] - $s['unknown_excluded_count'],
                'reliability' => $s['reliability'],
                'unknown_questions' => $unknownQuestions,
            ];
        }
        $global = $scores['global'] ?? null;
        $globalScore = $global === null || $global['score'] === null ? null : Decimal::of($global['score']);
        $globalReliability = Decimal::of($global['reliability'] ?? '0');

        return [
            'public_id' => $diagnostic['public_id'],
            'version' => $diagnostic['questionnaire_version'],
            'calc_version' => $diagnostic['calc_version'],
            'completed_at' => $diagnostic['completed_at'],
            'completeness' => $diagnostic['completeness'],
            'global' => $global['score'] ?? null,
            'global_display' => $globalScore?->round(0)->toFixed(0),
            'level' => DiagnosticResult::level($globalScore),
            'reliability' => $globalReliability->toFixed(4),
            'reliability_label' => DiagnosticResult::reliabilityLabel($globalReliability),
            'unknown_excluded' => $global['unknown_excluded_count'] ?? 0,
            'unknown_evaluable' => $global['unknown_evaluable_count'] ?? 0,
            'scorable_count' => count(array_filter($dimensions, static fn (array $d): bool => $d['scorable'])),
            'dimensions' => $dimensions,
            'strengths' => $result->strengths(),
            'weaknesses' => $result->weaknesses(),
            'comparison' => $this->comparison($org, $diagnostic, $scores),
            'can_copy' => $diagnostic['questionnaire_version'] === QuestionnaireRegistry::CURRENT,
        ];
    }

    /**
     * Comparaison par dimension avec le diagnostic terminé précédent (même version uniquement).
     *
     * @param array{id: int, questionnaire_version: string, completed_at: string|null} $diagnostic
     * @param array<string, array{score: string|null}> $scores
     * @return array{public_id: string, completed_at: string, same_method: bool, deltas: array<string, string|null>}|null
     */
    private function comparison(OrgId $org, array $diagnostic, array $scores): ?array
    {
        $previous = $diagnostic['completed_at'] === null ? null : $this->repository->previousCompleted($org, $diagnostic['id'], $diagnostic['completed_at']);
        if ($previous === null || $previous['completed_at'] === null) {
            return null;
        }
        $same = $previous['questionnaire_version'] === $diagnostic['questionnaire_version'];
        $deltas = [];
        if ($same) {
            $before = $this->repository->scores($org, $previous['id']);
            foreach ($scores as $code => $s) {
                $old = $before[$code]['score'] ?? null;
                $deltas[$code] = $s['score'] === null || $old === null ? null : Decimal::of($s['score'])->round(0)->sub(Decimal::of($old)->round(0))->toFixed(0);
            }
        }

        return ['public_id' => $previous['public_id'], 'completed_at' => $previous['completed_at'], 'same_method' => $same, 'deltas' => $deltas];
    }

    /**
     * Reconstruit un résultat à partir des scores figés (aucun recalcul des scores).
     *
     * @param array<string, array{dimension_code: string, score: string|null, is_scorable: bool, applicable_count: int, scored_count: int, unknown_excluded_count: int, unknown_evaluable_count: int, reliability: string}> $scores
     */
    private function frozenResult(Questionnaire $questionnaire, array $scores): DiagnosticResult
    {
        $dimensions = [];
        foreach ($questionnaire->dimensions as $code => $dimension) {
            $s = $scores[$code] ?? null;
            if ($s === null) {
                continue;
            }
            $dimensions[$code] = new DimensionScore(
                $code, $dimension['label'], $s['applicable_count'], $s['scored_count'], $s['unknown_excluded_count'], $s['unknown_evaluable_count'], 0,
                $s['score'] === null ? null : Decimal::of($s['score']), $s['is_scorable'], Decimal::of($s['reliability']),
            );
        }
        $global = $scores['global'] ?? null;

        return new DiagnosticResult(
            $questionnaire->version, $dimensions, $global === null || $global['score'] === null ? null : Decimal::of($global['score']),
            Decimal::of($global['reliability'] ?? '0'), [], [],
        );
    }

    /**
     * Préremplissage depuis le profil (suggestion modifiable) : coût horaire calculé → niveau 2, saisi → niveau 1.
     *
     * @param array<string, array{option: string, prefilled: bool}> $existing
     */
    private function prefill(OrgId $org, int $diagnosticId, Questionnaire $questionnaire, array $existing): void
    {
        foreach ($questionnaire->dimensions as $dimension) {
            foreach ($dimension['questions'] as $code => $question) {
                if ($question['prefill'] !== 'hourly_cost' || isset($existing[$code])) {
                    continue;
                }
                $hourly = $this->profiles->derived($org)['hourly_cost'] ?? null;
                if (!is_array($hourly) || ($hourly['value'] ?? null) === null) {
                    continue;
                }
                $option = match ($hourly['nature'] ?? null) {
                    'calculated' => 'n2',
                    'assumption' => 'n1',
                    default => null,
                };
                if ($option !== null) {
                    $this->repository->saveAnswer($org, $diagnosticId, $code, $option, true);
                }
            }
        }
    }

    /**
     * @return array{id: int, public_id: string, questionnaire_version: string, status: string, updated_at: string}
     */
    private function requireDraft(OrgId $org, string $publicId): array
    {
        $draft = $this->repository->find($org, $publicId);
        if ($draft === null) {
            throw new NotFoundException();
        }
        if ($draft['status'] !== 'draft') {
            throw new ValidationException(['status' => 'Ce diagnostic est terminé : ses réponses sont figées.']);
        }

        return $draft;
    }

    /** @return array<string, string> */
    private function options(OrgId $org, int $diagnosticId): array
    {
        return array_map(static fn (array $a): string => $a['option'], $this->repository->answers($org, $diagnosticId));
    }

    /** @return array{team_size: int|null} */
    private function context(OrgId $org): array
    {
        return ['team_size' => $this->organizations->get($org)['team_size']];
    }
}
