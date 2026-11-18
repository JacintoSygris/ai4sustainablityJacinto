<?php

namespace App\Services;

use App\Services\Report\XbrlConceptMap;
use App\Http\Controllers\Api\DoubleMaterialityGuideController;
use App\Http\Controllers\Api\EsrsDatapointController;
use App\Http\Controllers\Api\MaterialityConfirmationController;
use App\Http\Controllers\Api\MaterialityProposalController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReportSnapshotController;
use App\Models\Characterization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Builds a complete fictitious company (steps 1 to 6) through the same
 * controllers the browser uses, so every rule of the platform applies: the AI
 * proposal is real, the review keeps the AI/user split, the step 5 answers are
 * typed and the report version is approved. Profiles live in data/demo_companies.json.
 */
final class DemoCompanyBuilder
{
    public const DATA_FILE = 'data/demo_companies.json';

    private array $labels = [];

    /**
     * @return array<string, mixed>
     */
    public static function catalogue(): array
    {
        $payload = json_decode(file_get_contents(base_path(self::DATA_FILE)), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload['companies'] ?? null)) {
            throw new RuntimeException('demo_companies.json has no companies list');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $company
     * @param  array<string, array{value_type: string, unit: string, decimals: int}>  $typedFigures
     * @return array<string, mixed>  summary
     */
    public function build(array $company, array $typedFigures, string $password, bool $reset, ?callable $log = null): array
    {
        $log ??= static fn (string $line) => null;
        app()->setLocale('es');
        // The prediction job must finish inside this process, whatever the queue driver.
        config(['queue.default' => 'sync']);

        $user = $this->user($company, $password, $reset, $log);

        $characterization = $this->step1($user, $company, $log);
        $proposal = $this->step2($user, $company, $log);
        $this->step3($user, $company, $log);
        $confirmedTopicIds = $this->step4($user, $company, $proposal, $log);
        $answers = $this->step5($user, $company, $typedFigures, $log);
        $version = $this->step6($user, $log);

        $readiness = $this->call($user, ReportController::class, 'show', 'GET', '/api/report')['data'] ?? [];

        return [
            'email' => $user->email,
            'company' => $company['name'],
            'characterization_id' => $characterization->id,
            'ai_topics' => count($proposal['ai_topic_ids'] ?? []),
            'accepted' => $proposal['accepted'],
            'unsure' => $proposal['unsure'],
            'rejected' => $proposal['rejected'],
            'added' => $proposal['added'],
            'confirmed_topics' => count($confirmedTopicIds),
            'datapoints' => $answers['total'],
            'completed' => $answers['completed'],
            'typed' => $answers['typed'],
            'not_applicable' => $answers['not_applicable'],
            'version_approved' => $version['approved'],
            'report_status' => $readiness['status'] ?? null,
            'report_content_ready' => (bool) ($readiness['report_content_ready'] ?? false),
        ];
    }

    private function user(array $company, string $password, bool $reset, callable $log): User
    {
        $email = mb_strtolower(trim($company['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user === null) {
            $user = new User(['name' => $company['contact'] ?? $company['name'], 'email' => $email, 'password' => Hash::make($password)]);
            $user->email_verified_at = now();
            $user->save();
            $log("Cuenta creada: {$email}");
        } else {
            $user->forceFill(['name' => $company['contact'] ?? $company['name'], 'password' => Hash::make($password), 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            $log("Cuenta existente: {$email}");
        }

        $existing = Characterization::forUser($user->id)->first();
        if ($existing !== null) {
            if (! $reset) {
                throw new RuntimeException("La cuenta {$email} ya tiene datos; usa --reset para regenerarlos.");
            }
            // Facts, versions, documents and learning rows cascade from the characterization.
            $existing->delete();
            $log('Datos anteriores eliminados.');
        }

        return $user->fresh();
    }

    private function step1(User $user, array $company, callable $log): Characterization
    {
        $profile = $company['profile'];
        $payload = [
            'status' => Characterization::STATUS_SUBMITTED,
            'nace_code' => $company['nace_code'],
            'esrs_topic_ids' => [],
            'form_data' => [
                'company_profile' => [
                    'company_name' => $company['name'],
                    'headquarters_country' => $profile['headquarters_country'],
                    'reporting_year' => $profile['reporting_year'],
                    'reporting_scope' => $profile['reporting_scope'],
                    'num_subsidiaries_countries' => $profile['num_subsidiaries_countries'],
                    'stock_listed' => $profile['stock_listed'],
                    'reporting_currency' => $profile['reporting_currency'],
                    'product_service_type' => $profile['product_service_type'],
                    'entity_identifier' => $company['lei'],
                    'reporting_period_start' => $profile['reporting_period_start'],
                    'reporting_period_end' => $profile['reporting_period_end'],
                ],
                'operations' => $company['operations'],
                'activity_questions' => $company['activity_questions'],
                'notes' => null,
            ],
        ];

        $characterization = app(CharacterizationRecorder::class)->save($user, $payload);
        $deadline = time() + 600;
        while (! in_array($characterization->status, [Characterization::STATUS_COMPLETED, Characterization::STATUS_FAILED, Characterization::STATUS_TIMED_OUT], true) && time() < $deadline) {
            sleep(5);
            $characterization = $characterization->fresh();
        }
        if ($characterization->status !== Characterization::STATUS_COMPLETED) {
            throw new RuntimeException("La predicción de la IA no se completó (estado {$characterization->status}).");
        }
        $log("Paso 1: encuesta enviada y propuesta de la IA completada (#{$characterization->id}).");

        return $characterization;
    }

    /**
     * @return array<string, mixed>
     */
    private function step2(User $user, array $company, callable $log): array
    {
        $state = $this->call($user, MaterialityProposalController::class, 'show', 'GET', '/api/materiality-proposal')['data'];
        $aiTopicIds = array_map('intval', $state['ai_topic_ids'] ?? $state['proposal_topic_ids']);
        $decisions = $company['topics'];
        $unsure = array_values(array_intersect($aiTopicIds, $decisions['unsure'] ?? []));
        $rejected = array_values(array_intersect($aiTopicIds, $decisions['reject'] ?? []));
        // The AI decides what it proposes; when none of the configured doubts or
        // rejections were proposed, keep one of each anyway so the demo shows a
        // reviewed proposal rather than a blanket acceptance.
        $spare = array_values(array_diff($aiTopicIds, $unsure, $rejected, $decisions['add'] ?? []));
        if ($unsure === [] && count($spare) > 2) {
            $unsure = [array_pop($spare)];
        }
        if ($rejected === [] && count($spare) > 2) {
            $rejected = [array_pop($spare)];
        }
        $accepted = array_values(array_diff($aiTopicIds, $unsure, $rejected));
        $added = array_values(array_diff($decisions['add'] ?? [], $aiTopicIds));

        $actions = [];
        $reasons = [];
        $notes = [];
        foreach ($accepted as $id) {
            $actions[(string) $id] = 'accepted';
            $reasons[(string) $id] = ['sector_fit'];
        }
        foreach ($unsure as $id) {
            $actions[(string) $id] = 'unsure';
            $reasons[(string) $id] = ['needs_adm'];
        }
        foreach ($rejected as $id) {
            $actions[(string) $id] = 'rejected';
            $reasons[(string) $id] = ['not_relevant'];
        }
        foreach ($added as $id) {
            $actions[(string) $id] = 'accepted';
            $reasons[(string) $id] = ['stakeholder_input'];
        }
        foreach ($decisions['notes'] ?? [] as $id => $note) {
            if (isset($actions[(string) $id])) {
                $notes[(string) $id] = $note;
            }
        }
        foreach ($rejected as $id) {
            $notes[(string) $id] ??= 'Descartado tras la revisión interna: el tema no tiene incidencia relevante en la actividad ni en la cadena de valor de la empresa.';
        }
        foreach ($unsure as $id) {
            $notes[(string) $id] ??= 'Se resolverá en el análisis de doble importancia con los grupos de interés.';
        }

        $this->call($user, MaterialityProposalController::class, 'update', 'PUT', '/api/materiality-proposal', [
            'expected_revision' => (int) ($state['review']['revision'] ?? 0),
            'topic_actions' => $actions,
            'action_reasons' => $reasons,
            'action_notes' => $notes,
            'added_topic_ids' => $added,
        ]);
        $log(sprintf('Paso 2: %d temas de la IA (%d aceptados, %d en duda, %d rechazados) y %d añadidos por la empresa.', count($aiTopicIds), count($accepted), count($unsure), count($rejected), count($added)));

        return ['ai_topic_ids' => $aiTopicIds, 'accepted' => count($accepted), 'unsure' => count($unsure), 'rejected' => count($rejected), 'added' => count($added), 'confirmed' => array_values(array_unique(array_merge($accepted, $added)))];
    }

    private function step3(User $user, array $company, callable $log): void
    {
        $this->call($user, DoubleMaterialityGuideController::class, 'updateState', 'PUT', '/api/double-materiality-guide/state', [
            'checklist' => ['identified_stakeholders' => true, 'assessed_impacts' => true, 'assessed_financial_effects' => true, 'reached_conclusions' => true],
            'acta' => $company['acta'],
        ]);
        $log('Paso 3: acta del análisis de doble importancia registrada.');
    }

    /**
     * @return array<int, int>
     */
    private function step4(User $user, array $company, array $proposal, callable $log): array
    {
        $confirmed = $proposal['confirmed'];
        $aiTopicIds = $proposal['ai_topic_ids'];
        $reasons = [];
        foreach (array_diff($confirmed, $aiTopicIds) as $id) {
            $reasons[(string) $id] = ['stakeholders'];
        }
        $this->call($user, MaterialityConfirmationController::class, 'update', 'PUT', '/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => $confirmed,
            'change_reasons' => $reasons,
        ]);
        $log(sprintf('Paso 4: %d temas materiales confirmados.', count($confirmed)));

        return $confirmed;
    }

    /**
     * @param  array<string, array{value_type: string, unit: string, decimals: int}>  $typedFigures
     * @return array<string, int>
     */
    private function step5(User $user, array $company, array $typedFigures, callable $log): array
    {
        $characterization = Characterization::forUser($user->id)->firstOrFail();
        $corpus = app(EsrsDatapointCorpusBuilder::class)->build($characterization);
        $revision = app(EsrsDatapointResponseState::class)->revision($characterization);
        $responses = [];
        $counts = ['total' => 0, 'completed' => 0, 'typed' => 0, 'not_applicable' => 0];

        // Datapoints sharing one XBRL concept must carry one value, or the iXBRL
        // candidate refuses the report as a duplicate-fact conflict. Curated figures
        // claim their concept first; generated answers reuse them.
        $conceptValues = [];
        $conceptUses = [];
        foreach ($corpus['blocks'] ?? [] as $block) {
            foreach ($block['datapoints'] ?? [] as $datapoint) {
                $id = $datapoint['id'];
                $concept = $this->concept($id);
                if ($concept === null) {
                    continue;
                }
                $conceptUses[$concept] = ($conceptUses[$concept] ?? 0) + 1;
                if (isset($company['figures'][$id], $typedFigures[$id]) && ! isset($company['not_applicable'][$id])) {
                    $conceptValues[$concept] ??= ['value' => (string) $company['figures'][$id]] + $typedFigures[$id];
                }
            }
        }

        foreach ($corpus['blocks'] ?? [] as $block) {
            foreach ($block['datapoints'] ?? [] as $datapoint) {
                $id = $datapoint['id'];
                $counts['total']++;
                if (isset($company['not_applicable'][$id])) {
                    $responses[] = ['datapoint_id' => $id, 'status' => 'not_applicable', 'note' => $company['not_applicable'][$id], 'triage' => 'not_applicable_candidate'];
                    $counts['not_applicable']++;
                    continue;
                }
                if (isset($company['figures'][$id], $typedFigures[$id])) {
                    $typed = $typedFigures[$id];
                    $responses[] = [
                        'datapoint_id' => $id, 'status' => 'completed', 'triage' => 'have_it',
                        'value' => (string) $company['figures'][$id], 'value_type' => $typed['value_type'], 'unit' => $typed['unit'], 'decimals' => $typed['decimals'],
                        'evidence_reference' => $this->pick($company['documents'], $id, 0),
                        'note' => $this->figureNote($id, (string) $company['figures'][$id], $typed['unit'], $company),
                    ];
                    $counts['typed']++;
                    $counts['completed']++;
                    continue;
                }
                $typed = $this->typedAnswer($id, $company, $conceptValues, $conceptUses);
                if ($typed !== null) {
                    $responses[] = [
                        'datapoint_id' => $id, 'status' => 'completed', 'triage' => 'have_it',
                        'value' => $typed['value'], 'value_type' => $typed['value_type'], 'unit' => $typed['unit'], 'decimals' => $typed['decimals'],
                        'evidence_reference' => $this->pick($company['documents'], $id, 0),
                    ];
                    $counts['typed']++;
                    $counts['completed']++;
                    continue;
                }
                $responses[] = [
                    'datapoint_id' => $id, 'status' => 'completed', 'triage' => 'have_it', 'value_type' => 'text',
                    'value' => $this->narrative($datapoint, $company),
                    'evidence_reference' => $this->pick($company['documents'], $id, 1),
                ];
                $counts['completed']++;
            }
        }

        $this->call($user, EsrsDatapointController::class, 'updateResponses', 'PUT', '/api/esrs-datapoints/responses', [
            'expected_revision' => $revision,
            'responses' => $responses,
        ]);
        $log(sprintf('Paso 5: %d datos decididos (%d completados, de ellos %d cifras etiquetables; %d no aplican).', $counts['total'], $counts['completed'], $counts['typed'], $counts['not_applicable']));

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function step6(User $user, callable $log): array
    {
        $snapshot = $this->call($user, ReportSnapshotController::class, 'store', 'POST', '/api/report/snapshot')['data'];
        $approval = $this->call($user, ReportSnapshotController::class, 'approve', 'POST', "/api/report/snapshots/{$snapshot['id']}/approve", [
            'single_person_declaration' => 'Declaro que, como persona responsable, asumo la preparación, revisión y aprobación de esta versión del informe.',
        ], ['snapshot' => (int) $snapshot['id']]);
        $log("Paso 6: versión del informe #{$snapshot['id']} preparada y aprobada.");

        return ['snapshot_id' => $snapshot['id'], 'approved' => (bool) ($approval['data']['is_approved'] ?? $approval['data']['approval'] ?? true)];
    }

    /**
     * Runs a controller action with a JSON request on behalf of the user, as the
     * browser would, and returns the decoded JSON body. Non-2xx answers abort.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $routeParameters
     * @return array<string, mixed>
     */
    private function call(User $user, string $controller, string $method, string $verb, string $uri, array $payload = [], array $routeParameters = []): array
    {
        $request = Request::create($uri, $verb, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        // Binding the request fires the auth rebinding, which replaces the user
        // resolver with the guard's: sign the user into the guard and set the
        // resolver afterwards so both paths agree.
        app()->instance('request', $request);
        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);

        $response = app()->call([app($controller), $method], ['request' => $request] + $routeParameters);
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true) ?? [];
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('%s %s devolvió %d: %s', $verb, $uri, $status, mb_substr(json_encode($body, JSON_UNESCAPED_UNICODE), 0, 600)));
        }

        return is_array($body) ? $body : [];
    }

    private function label(array $datapoint): string
    {
        if ($this->labels === []) {
            $file = base_path('data/esrs_datapoint_labels_es_v1.json');
            $this->labels = is_file($file) ? (json_decode(file_get_contents($file), true)['labels'] ?? []) : [];
        }

        return $this->labels[$datapoint['id']] ?? (string) ($datapoint['name'] ?? $datapoint['id']);
    }

    private function pick(array $pool, string $seed, int $offset): string
    {
        return $pool[(crc32($seed) + $offset) % max(count($pool), 1)];
    }

    /** @var array<string, array{type: string, period_type: string, abstract: bool}> */
    private array $conceptTypes = [];

    private ?XbrlConceptMap $conceptMap = null;

    /** Official ESRS concept of a datapoint, when the mapping is one-to-one and needs no extra context. */
    private function concept(string $datapointId): ?string
    {
        $this->conceptMap ??= new XbrlConceptMap;
        $mapped = $this->conceptMap->conceptFor($datapointId);
        if (! is_array($mapped) || ($mapped['taggable_state'] ?? null) !== 'mapped' || ($mapped['required_context'] ?? []) !== []) {
            return null;
        }

        return is_string($mapped['concept_id'] ?? null) ? $mapped['concept_id'] : null;
    }

    /**
     * Answer a datapoint with the value type its XBRL concept expects (boolean, percent,
     * money, count, tonnes, dates...) so the iXBRL candidate can tag it. Narratives,
     * enumerations and unmapped datapoints keep their text answer.
     *
     * @param  array<string, array{value: string, value_type: string, unit: ?string, decimals: ?int}>  $conceptValues
     * @param  array<string, int>  $conceptUses
     * @return array{value: string, value_type: string, unit: ?string, decimals: ?int}|null
     */
    private function typedAnswer(string $datapointId, array $company, array &$conceptValues, array $conceptUses): ?array
    {
        $concept = $this->concept($datapointId);
        if ($concept === null) {
            return null;
        }
        if ($this->conceptTypes === []) {
            $this->conceptTypes = json_decode(file_get_contents(base_path('data/esrs_concept_types_v1.json')), true, 512, JSON_THROW_ON_ERROR)['concepts'];
        }
        $type = $this->conceptTypes[$concept]['type'] ?? null;
        $seed = crc32($company['email'].'|'.$concept);
        $year = (int) $company['profile']['reporting_year'];
        $revenue = (float) ($company['operations']['revenue'] ?? 1000000);
        $employees = (int) ($company['operations']['employee_count'] ?? 100);
        $n = fn (int $min, int $max): int => $min + ($seed % max($max - $min, 1));
        $quantity = fn (string $unit, int $min, int $max): array => ['value' => (string) $n($min, $max), 'value_type' => 'number', 'unit' => $unit, 'decimals' => 0];

        // The iXBRL candidate refuses two datapoints with numeric facts on one concept (current vs
        // future values look alike); shared numeric concepts stay narrative so the report renders.
        $numericTypes = ['percentItemType', 'monetaryItemType', 'integerItemType', 'decimalItemType', 'ghgEmissionsItemType', 'energyItemType', 'massItemType', 'volumeItemType', 'areaItemType'];
        if (in_array($type, $numericTypes, true) && ($conceptUses[$concept] ?? 1) > 1) {
            return null;
        }
        if (isset($conceptValues[$concept])) {
            return $conceptValues[$concept];
        }

        $answer = match ($type) {
            'booleanItemType' => ['value' => $seed % 5 === 0 ? 'false' : 'true', 'value_type' => 'boolean', 'unit' => null, 'decimals' => null],
            'percentItemType' => ['value' => $n(4, 96).'.'.(($seed >> 4) % 10), 'value_type' => 'number', 'unit' => '%', 'decimals' => 1],
            'monetaryItemType' => ['value' => (string) (int) (round($revenue * (($seed % 90) + 5) / 1000 / 1000) * 1000), 'value_type' => 'monetary', 'unit' => 'EUR', 'decimals' => 0],
            'integerItemType' => ['value' => (string) $n(1, max($employees, 10)), 'value_type' => 'integer', 'unit' => 'pure', 'decimals' => 0],
            'decimalItemType' => ['value' => number_format($n(1, 5000) / 10, 2, '.', ''), 'value_type' => 'number', 'unit' => 'pure', 'decimals' => 2],
            'ghgEmissionsItemType' => $quantity('tCO2e', 10, 4000),
            'energyItemType' => $quantity('MWh', 120, 9000),
            'massItemType' => $quantity('t', 2, 900),
            'volumeItemType' => $quantity('m3', 50, 60000),
            'areaItemType' => $quantity('ha', 1, 400),
            'dateItemType' => ['value' => sprintf('%04d-%02d-%02d', $year, 1 + $seed % 12, 1 + ($seed >> 4) % 28), 'value_type' => 'date', 'unit' => null, 'decimals' => null],
            'gYearItemType' => ['value' => (string) ($year - 2 - $seed % 4), 'value_type' => 'date', 'unit' => null, 'decimals' => null],
            default => null,
        };
        if ($answer !== null) {
            $conceptValues[$concept] = $answer;
        }

        return $answer;
    }

    private function figureNote(string $id, string $value, string $unit, array $company): string
    {
        $year = $company['profile']['reporting_year'];
        $display = $unit === '%' ? "{$value} %" : ($unit === 'pure' ? $value : "{$value} {$unit}");

        return "Dato {$year}: {$display}, tomado de los registros internos de {$company['name']}.";
    }

    /**
     * Plausible Spanish answer for a disclosure, varied per datapoint and company.
     */
    private function narrative(array $datapoint, array $company): string
    {
        $label = $this->label($datapoint);
        $year = (int) $company['profile']['reporting_year'];
        $name = $company['name'];
        $sector = $company['sector_phrase'];
        $type = strtolower((string) ($datapoint['data_type'] ?? ''));
        $seed = crc32($company['email'].'|'.$datapoint['id']);

        if (str_contains($type, 'table')) {
            return "{$label}: véase la tabla «{$label}» del anexo de datos {$year}, con desglose por centro de trabajo y periodo. Los datos proceden de los sistemas de gestión de {$name}.";
        }

        $figure = $this->pseudoFigure($type, $seed, (float) ($company['operations']['revenue'] ?? 1000000), (int) ($company['operations']['employee_count'] ?? 100));
        if ($figure !== null) {
            return "{$label}: {$figure} en {$year}. Cifra calculada por {$name} con la misma metodología que en el ejercicio anterior y contrastada con la documentación de soporte.";
        }

        $owners = ['la dirección general', 'el comité de sostenibilidad', 'la dirección de operaciones', 'el área de personas', 'la dirección financiera', 'el responsable de medio ambiente'];
        $results = [
            'todas las acciones previstas se completaron en plazo',
            'se registraron tres incidencias menores, ninguna con efectos significativos',
            'se alcanzó el objetivo intermedio fijado para el ejercicio',
            'la cobertura llegó al '.(82 + $seed % 17).' % de los centros de trabajo',
            'se formó al '.(61 + $seed % 35).' % de la plantilla afectada',
            'los indicadores mejoraron un '.(3 + $seed % 14).' % respecto al año anterior',
            'se identificaron '.(2 + $seed % 6).' áreas de mejora que entran en el plan de '.($year + 1),
        ];
        $templates = [
            "{$label}. {$name}, dedicada a {$sector}, dispone de un procedimiento documentado aprobado por {owner} y revisado en {$year}; en el ejercicio {result}.",
            "{$label}: la información se gestiona desde {owner} con revisión anual. En {$year} {result}, según la documentación de soporte indicada.",
            "{$label}. La empresa aplica criterios definidos en su política de sostenibilidad, con seguimiento trimestral por {owner}. Resultado {$year}: {result}.",
            "{$label}: {$name} describe aquí su enfoque para {$sector}, alineado con los requisitos NEIS. Durante {$year} {result}.",
            "{$label}. Responsable: {owner}. Alcance: operaciones propias y proveedores principales. En {$year} {result}; el detalle consta en la evidencia referenciada.",
        ];
        $template = $templates[$seed % count($templates)];

        return str_replace(['{owner}', '{result}'], [$owners[($seed >> 3) % count($owners)], $results[($seed >> 5) % count($results)]], $template);
    }

    private function pseudoFigure(string $type, int $seed, float $revenue, int $employees): ?string
    {
        $n = fn (int $min, int $max) => $min + ($seed % max($max - $min, 1));
        $money = fn (float $share) => number_format(round($revenue * $share / 1000) * 1000, 0, ',', '.').' EUR';

        return match (true) {
            str_contains($type, 'percent') => $n(4, 96).' %',
            str_contains($type, 'mone') => $money((($seed % 90) + 5) / 1000),
            str_contains($type, 'integer') => (string) $n(1, max($employees, 10)),
            str_contains($type, 'energy') => number_format($n(120, 9000), 0, ',', '.').' MWh',
            str_contains($type, 'ghg') => number_format($n(10, 4000), 0, ',', '.').' tCO2e',
            str_contains($type, 'mass') => number_format($n(2, 900), 0, ',', '.').' t',
            str_contains($type, 'volume') => number_format($n(50, 60000), 0, ',', '.').' m³',
            str_contains($type, 'area') => $n(1, 400).' ha',
            str_contains($type, 'intensity') => number_format($n(1, 900) / 100, 2, ',', '.').' por unidad de ingresos',
            str_contains($type, 'decimal') || str_contains($type, 'numerical') => number_format($n(1, 5000) / 10, 1, ',', '.'),
            str_contains($type, 'gyear') => (string) ($seed % 2 === 0 ? 2023 : 2024),
            default => null,
        };
    }
}
