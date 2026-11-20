<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\ReportSnapshot;
use App\Services\CharacterizationStateTransaction;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\Report\ArelleXhtmlIxbrlValidator;
use App\Services\Report\IxbrlViewerPlugin;
use App\Services\Report\DocxRenderer;
use App\Services\Report\EvidenceBundleBuilder;
use App\Jobs\WarmArelleCache;
use App\Services\Report\ArelleCommandLocator;
use App\Services\Report\ExternalTaxonomyManifestRepository;
use App\Services\Report\ExternalTaxonomyProvisioner;
use App\Services\Report\HtmlReportRenderer;
use App\Services\Report\IxbrlReportPackage;
use App\Services\Report\TaxonomyProfileRegistry;
use App\Services\Report\ReportIrBuilder;
use App\Services\Report\ReportScopeException;
use App\Services\Report\ReportScopeAssets;
use App\Services\Report\ReportDisplayProjection;
use App\Services\Report\ReportStalenessDetector;
use App\Services\Report\ReportingProfileException;
use App\Services\Report\ReportingProfileRepository;
use App\Services\Report\XhtmlIxbrlCandidateException;
use App\Services\Report\XhtmlIxbrlCandidateRenderer;
use DomainException;
use Illuminate\Http\Request;
use RuntimeException;
use App\Services\PlatformAdmin;

class GuidedReportController extends Controller
{
    public function __construct(
        private readonly ReportIrBuilder $irBuilder,
        private readonly EvidenceBundleBuilder $evidence,
        private readonly DocxRenderer $docx,
        private readonly HtmlReportRenderer $html,
        private readonly ReportController $reportController,
        private readonly ReportStalenessDetector $staleness,
        private readonly ReportingProfileRepository $profiles,
        private readonly ExternalTaxonomyManifestRepository $externalTaxonomyManifest,
        private readonly XhtmlIxbrlCandidateRenderer $xhtmlIxbrl,
        private readonly ArelleXhtmlIxbrlValidator $arelle,
        private readonly CharacterizationStateTransaction $stateTransactions,
        private readonly TaxonomyProfileRegistry $taxonomyProfiles,
        private readonly IxbrlReportPackage $reportPackage,
    ) {}

    public function taxonomyStatus()
    {
        $profile = $this->profiles->load('esrs-2023-preparatory-v1');
        $taxonomy = $profile->taxonomy();

        // Provision the published EFRAG package when the installation has no
        // operator-managed manifest yet; failures fall through to the usual block reasons.
        $provisioning = app(ExternalTaxonomyProvisioner::class)->ensure($profile);

        try {
            // Admission only: discard internal metadata, including checksums.
            $this->externalTaxonomyManifest->loadForProfile($profile);
            $availability = ['state' => 'verified'];
            // The one-off online Arelle bootstrap takes about a minute: run it from the queue
            // so the first "Generar XBRL" click does not wait for it.
            if (ArelleCommandLocator::resolve() !== null && ! ArelleXhtmlIxbrlValidator::cacheWarm()) {
                WarmArelleCache::dispatch($profile->profileId());
            }
        } catch (RuntimeException $e) {
            $allowedReasons = [
                'external_taxonomy_manifest_missing',
                'external_taxonomy_manifest_malformed',
                'external_taxonomy_manifest_schema_mismatch',
                'external_taxonomy_profile_mismatch',
                'external_taxonomy_entrypoint_mismatch',
                'external_taxonomy_not_confirmed',
                'external_taxonomy_package_checksum_invalid',
                'external_taxonomy_package_missing',
                'external_taxonomy_package_path_forbidden',
                'external_taxonomy_package_checksum_mismatch',
            ];
            $availability = [
                'state' => 'blocked',
                'reason_code' => in_array($e->getMessage(), $allowedReasons, true)
                    ? $e->getMessage()
                    : 'external_taxonomy_unavailable',
            ];
        }

        return response()->json([
            'data' => [
                'type' => 'report_taxonomy_status',
                'version' => 'v1',
                'taxonomy' => [
                    'name' => $taxonomy['name'],
                    'version' => $taxonomy['version'],
                ],
                'reporting_profile' => $profile->profileId(),
                'availability' => $availability,
                'provisioning' => $provisioning,
                'validator' => ['state' => ArelleCommandLocator::resolve() === null ? 'missing' : 'available'],
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function docx(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        try {
            return $this->buildDocx($request, $datapoints);
        } catch (ReportScopeException $e) {
            return $this->blocked($e->getMessage());
        } catch (DomainException|RuntimeException|\TypeError) {
            return $this->blocked('approved_snapshot_invalid');
        }
    }

    private function buildDocx(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        [$characterization, $readiness] = $this->readyOrBlock($request, $datapoints);
        if ($readiness !== null) {
            return $readiness;
        }

        [$snapshot, $snapshotBlock] = $this->approvedFreshSnapshotOrBlock($request);
        if ($snapshotBlock !== null) {
            return $snapshotBlock;
        }

        try {
            $ir = $this->irBuilder->buildFromApprovedSnapshot($snapshot);
        } catch (ReportScopeException $e) {
            return $this->blocked($e->getMessage());
        } catch (DomainException) {
            return $this->blocked('approved_snapshot_invalid');
        }

        if (($ir['claims'] ?? []) === []) {
            return $this->blocked('no_claimable_report_content');
        }

        if (! ($ir['content_readiness']['ready'] ?? false)) {
            return $this->blocked($ir['content_readiness']['reasons'][0] ?? 'completed_datapoint_facts_missing');
        }

        $bytes = $this->docx->render($ir, app()->getLocale());
        if ($block = $this->finalPublicationSnapshotBlock($snapshot)) {
            return $block;
        }

        return response($bytes, 200)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->header('Content-Disposition', 'attachment; filename="'.(app()->getLocale() === 'en' ? 'esrs-draft-report.docx' : 'informe-neis-borrador.docx').'"');
    }

    public function evidenceBundle(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        try {
            return $this->buildEvidencebundle($request, $datapoints);
        } catch (ReportScopeException $e) {
            return $this->blocked($e->getMessage());
        } catch (DomainException|RuntimeException|\TypeError) {
            return $this->blocked('approved_snapshot_invalid');
        }
    }

    private function buildEvidencebundle(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        [$characterization, $readiness] = $this->readyOrBlock($request, $datapoints);
        if ($readiness !== null) {
            return $readiness;
        }

        [$snapshot, $snapshotBlock] = $this->approvedFreshSnapshotOrBlock($request);
        if ($snapshotBlock !== null) {
            return $snapshotBlock;
        }

        try {
            $ir = $this->irBuilder->buildFromApprovedSnapshot($snapshot);
        } catch (ReportScopeException $e) {
            return $this->blocked($e->getMessage());
        } catch (DomainException) {
            return $this->blocked('approved_snapshot_invalid');
        }

        if (($ir['claims'] ?? []) === []) {
            return $this->blocked('no_claimable_report_content');
        }

        if (! ($ir['content_readiness']['ready'] ?? false)) {
            return $this->blocked($ir['content_readiness']['reasons'][0] ?? 'completed_datapoint_facts_missing');
        }

        if (config('ixbrl.package_ui_enabled')) {
            try {
                $ir['taxonomy_profile'] = $this->taxonomyProfiles->describe($this->taxonomyProfiles->selectedId());
            } catch (XhtmlIxbrlCandidateException $e) {
                return $this->blocked($e->getMessage());
            }
        }
        $bundle = $this->evidence->build($ir, [], app()->getLocale());
        if (config('eu_taxonomy.enabled')) {
            $bundle['eu_taxonomy_eligibility'] = app(\App\Services\EuTaxonomyEligibility::class)->evidence($characterization);
        }
        if ($block = $this->finalPublicationSnapshotBlock($snapshot)) {
            return $block;
        }

        return response()->json(['data' => $bundle])
            ->header('Content-Disposition', 'attachment; filename="'.(app()->getLocale() === 'en' ? 'esrs-evidence-bundle.json' : 'paquete-evidencias-neis.json').'"');
    }

    public function html(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        try {
            return $this->buildHtml($request, $datapoints);
        } catch (ReportScopeException $e) {
            return $this->blocked($e->getMessage());
        } catch (DomainException|RuntimeException|\TypeError) {
            return $this->blocked('approved_snapshot_invalid');
        }
    }

    private function buildHtml(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        [$characterization, $readiness] = $this->readyOrBlock($request, $datapoints);
        if ($readiness !== null) {
            return $readiness;
        }

        [$snapshot, $snapshotBlock] = $this->approvedFreshSnapshotOrBlock($request);
        if ($snapshotBlock !== null) {
            return $snapshotBlock;
        }

        try {
            $ir = $this->irBuilder->buildFromApprovedSnapshot($snapshot);
        } catch (ReportScopeException $e) {
            return $this->blocked($e->getMessage());
        } catch (DomainException) {
            return $this->blocked('approved_snapshot_invalid');
        }

        if (($ir['claims'] ?? []) === []) {
            return $this->blocked('no_claimable_report_content');
        }

        if (! ($ir['content_readiness']['ready'] ?? false)) {
            return $this->blocked($ir['content_readiness']['reasons'][0] ?? 'completed_datapoint_facts_missing');
        }

        $html = $this->html->render($ir, app()->getLocale());
        if ($block = $this->finalPublicationSnapshotBlock($snapshot)) {
            return $block;
        }

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.(app()->getLocale() === 'en' ? 'esrs-draft-report.html' : 'informe-neis-borrador.html').'"');
    }

    public function xhtml(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        abort_unless(config('report_scope.enabled', false), 404);
        try {
            [, $readiness] = $this->readyOrBlock($request, $datapoints);
            if ($readiness !== null) return $readiness;
            [$snapshot, $block] = $this->approvedFreshSnapshotOrBlock($request);
            if ($block !== null) return $block;
            $ir = $this->irBuilder->buildFromApprovedSnapshot($snapshot);
            if (! ($ir['content_readiness']['ready'] ?? false)) return $this->blocked($ir['content_readiness']['reasons'][0] ?? 'no_claimable_report_content');
            $bytes = $this->xhtmlIxbrl->renderPresentation($ir, app()->getLocale());
            if ($block = $this->finalPublicationSnapshotBlock($snapshot)) return $block;
            return response($bytes, 200)
                ->header('Content-Type', 'application/xhtml+xml; charset=UTF-8')
                ->header('Content-Disposition', 'attachment; filename="'.(app()->getLocale() === 'en' ? 'esrs-report.xhtml' : 'informe-neis.xhtml').'"')
                ->header('X-Report-Publication-State', 'presentation_only');
        } catch (ReportScopeException $e) {
            return $this->blocked($e->getMessage());
        } catch (DomainException|RuntimeException|\TypeError) {
            return $this->blocked('approved_snapshot_invalid');
        }
    }

    public function ixbrlPackage(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        abort_unless(config('ixbrl.package_ui_enabled', false), 404);
        return $this->candidateResponse($request, $datapoints, true);
    }

    public function xhtmlIxbrlCandidate(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        return $this->candidateResponse($request, $datapoints, false);
    }

    private function candidateResponse(Request $request, EsrsDatapointCorpusBuilder $datapoints, bool $package)
    {
        try {
            return $this->buildCandidateResponse($request, $datapoints, $package);
        } catch (ReportScopeException $e) {
            return $this->blocked($e->getMessage(), 'xhtml_ixbrl_candidate_blocked');
        } catch (ReportingProfileException|DomainException) {
            return $this->blocked('approved_snapshot_invalid', 'xhtml_ixbrl_candidate_blocked');
        } catch (XhtmlIxbrlCandidateException $e) {
            return $this->blocked($this->allowedXhtmlIxbrlReason($e->getMessage()), 'xhtml_ixbrl_candidate_blocked');
        } catch (RuntimeException|\TypeError) {
            return $this->blocked('approved_snapshot_invalid', 'xhtml_ixbrl_candidate_blocked');
        }
    }

    private function buildCandidateResponse(Request $request, EsrsDatapointCorpusBuilder $datapoints, bool $package)
    {
        [, $readiness] = $this->readyOrBlock($request, $datapoints, 'xhtml_ixbrl_candidate_blocked');
        if ($readiness !== null) {
            return $readiness;
        }

        [$snapshot, $snapshotBlock] = $this->approvedFreshSnapshotOrBlock($request, 'xhtml_ixbrl_candidate_blocked');
        if ($snapshotBlock !== null) {
            return $snapshotBlock;
        }

        try {
            $profile = $this->profiles->load((string) $snapshot->profile_id);
        } catch (ReportingProfileException) {
            return $this->blocked('approved_snapshot_invalid', 'xhtml_ixbrl_candidate_blocked');
        }

        if (! hash_equals($profile->hash(), (string) $snapshot->profile_hash)) {
            return $this->blocked('approved_snapshot_invalid', 'xhtml_ixbrl_candidate_blocked');
        }

        $ir = null;
        if (ReportScopeAssets::required($snapshot)) {
            $ir = $this->irBuilder->buildFromApprovedSnapshot($snapshot);
            if (! ($ir['content_readiness']['ready'] ?? false)) return $this->blocked($ir['content_readiness']['reasons'][0] ?? 'no_claimable_report_content', 'xhtml_ixbrl_candidate_blocked');
            if (! $this->xhtmlIxbrl->hasTaggableClaims($ir)) return $this->blocked('xhtml_ixbrl_no_taggable_claims', 'xhtml_ixbrl_candidate_blocked');
        }

        $taxonomyId = $this->taxonomyProfiles->selectedId();
        $taxonomy = $this->taxonomyProfiles->candidate($taxonomyId, $profile);

        try {
            $manifest = $this->externalTaxonomyManifest->loadInternalForProfile($profile);
        } catch (RuntimeException $e) {
            return $this->blocked($e->getMessage(), 'xhtml_ixbrl_candidate_blocked');
        }

        try {
            $ir ??= $this->irBuilder->buildFromApprovedSnapshot($snapshot);
            if (! ($ir['content_readiness']['ready'] ?? false)) {
                return $this->blocked(
                    $ir['content_readiness']['reasons'][0] ?? 'completed_datapoint_facts_missing',
                    'xhtml_ixbrl_candidate_blocked',
                );
            }
            $ir['taxonomy_profile'] = $taxonomy;
            $bundle = $this->evidence->build($ir, [], app()->getLocale());
            if (ReportDisplayProjection::coherent($ir)) {
                $rendered = $this->xhtmlIxbrl->renderWithTaggingEvidence($ir, $profile, $manifest, app()->getLocale(), $taxonomyId);
                $xhtml = $rendered['xhtml'];
                $bundle['tagging'] = $rendered['tagging'];
                if ($bundle['tagging']['tagged_claim_ids'] === []) return $this->blocked('xhtml_ixbrl_no_taggable_claims', 'xhtml_ixbrl_candidate_blocked');
            } else {
                $xhtml = $this->xhtmlIxbrl->render($ir, $profile, $manifest, app()->getLocale(), $taxonomyId);
            }
            $bundle['candidate_validation'] = ['scope' => 'xbrl_2_1_inline_xbrl_report_package', 'publication_state' => 'validated_candidate', 'filing_ready' => false];
            $bundle['taxonomy_package_checksum'] = $manifest['taxonomy_package_checksum'];
            $bytes = $package ? $this->reportPackage->render($xhtml, $bundle) : $xhtml;
            // The browser view (`inline=1`) also asks the validation run for the iXBRL Viewer page;
            // `viewer=0` keeps the plain document. Downloads and packages never embed the viewer.
            $wantViewer = ! $package && $request->boolean('inline') && $request->query('viewer', '1') !== '0';
            $assertions = $this->arelle->validate($bytes, $profile, $manifest, $package, $wantViewer ? self::viewerScriptUrl() : null);
        } catch (DomainException) {
            return $this->blocked('approved_snapshot_invalid', 'xhtml_ixbrl_candidate_blocked');
        } catch (XhtmlIxbrlCandidateException $e) {
            return $this->blocked($this->allowedXhtmlIxbrlReason($e->getMessage()), 'xhtml_ixbrl_candidate_blocked');
        }

        if ($block = $this->finalPublicationSnapshotBlock($snapshot, 'xhtml_ixbrl_candidate_blocked')) {
            return $block;
        }

        // Taxonomy assertion messages (EFRAG completeness checks) never block; the counts travel in headers.
        $assertionSummary = 'errors='.$assertions['assertion_errors'].'; warnings='.$assertions['assertion_warnings'];
        if ($package) {
            return response($bytes, 200)
                ->header('Content-Type', 'application/zip')
                ->header('Content-Disposition', 'attachment; filename="candidato-ixbrl-provisional.zip"')
                ->header('X-Report-Publication-State', 'validated_candidate')
                ->header('X-Taxonomy-Assertions', $assertionSummary)
                ->header('X-Taxonomy-Profile', $taxonomyId);
        }
        // `inline=1` lets the browser show the report; the default stays a download.
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';
        $viewer = is_string($assertions['viewer_html'] ?? null) ? $assertions['viewer_html'] : null;

        return response($viewer ?? $xhtml, 200)
            ->header('X-Report-Viewer', $viewer !== null ? 'ixbrl-viewer' : 'none')
            ->header('X-Taxonomy-Assertions', $assertionSummary)
            ->header('Content-Type', 'application/xhtml+xml; charset=UTF-8')
            ->header('Content-Disposition', $disposition.'; filename="'.(app()->getLocale() === 'en' ? 'esrs-candidate-report.xhtml' : 'informe-neis-candidato.xhtml').'"')
            ->header('X-Report-Publication-State', 'validated_candidate');
    }

    /**
     * Serves the iXBRL Viewer script bundled with the Arelle plugin, referenced by the
     * viewer page. Same session rules as the report itself; 404 when the plugin is absent.
     */
    public function ixbrlViewerScript()
    {
        $script = IxbrlViewerPlugin::scriptPath();
        if ($script === null) {
            return response()->json(['data' => ['type' => 'ixbrl_viewer_unavailable']], 404);
        }

        return response()->file($script, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** The viewer looks for an optional `ixbrlviewer.config.json` next to its script; an empty one avoids a 404. */
    public function ixbrlViewerConfig()
    {
        return response()->json(new \stdClass, 200, ['Cache-Control' => 'private, max-age=86400']);
    }

    /**
     * Same-origin path of the viewer script, so the page served through the frontend proxy
     * finds it. A literal path, not route(): a deployment whose route cache predates the
     * script route must still serve the plain document instead of failing.
     */
    private static function viewerScriptUrl(): string
    {
        return '/api/report/ixbrl-viewer.js';
    }

    /** @return array{0: ?Characterization, 1: mixed} */
    private function readyOrBlock(
        Request $request,
        EsrsDatapointCorpusBuilder $datapoints,
        string $blockedType = 'guided_report_blocked'
    ): array
    {
        // Persist scope invalidation before live display projection: new corpus
        // labels need not exist in the display catalogue yet. V2 remains guarded
        // after disabling the feature, while legacy OFF-mode readiness is kept.
        $scopeRequired = (bool) config('report_scope.enabled', false)
            || ReportSnapshot::query()
                ->where('user_id', PlatformAdmin::viewedUserId($request))
                ->whereHas('approval')
                ->where('snapshot_json->schema_version', 'report_snapshot_v2')
                ->exists();
        if ($scopeRequired) {
            [, $scopeBlock] = $this->approvedFreshSnapshotOrBlock($request, $blockedType);
            if ($scopeBlock !== null) {
                return [null, $scopeBlock];
            }
        }

        $readiness = $this->reportController->exportReadiness($request, $datapoints);

        if ($readiness === null || ! ($readiness['workflow_complete'] ?? false)) {
            return [null, response()->json([
                'data' => ['type' => $blockedType, 'status' => $readiness['status'] ?? 'incomplete'],
            ], 409)];
        }

        return [Characterization::forUser(PlatformAdmin::viewedUserId($request))->first(), null];
    }

    /** @return array{0: ?ReportSnapshot, 1: mixed} */
    private function approvedFreshSnapshotOrBlock(Request $request, string $blockedType = 'guided_report_blocked'): array
    {
        $snapshots = ReportSnapshot::query()
            ->where('user_id', PlatformAdmin::viewedUserId($request))
            ->whereHas('approval')
            ->with('approval')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        if ($snapshots->isEmpty()) {
            return [null, $this->blocked('approved_snapshot_missing', $blockedType)];
        }

        $newestResult = null;
        foreach ($snapshots as $snapshot) {
            $result = $this->staleness->refreshState($snapshot);
            $newestResult ??= $result;
            if (! $result['is_stale']) {
                return [$snapshot->fresh(['approval']), null];
            }
        }

        return [null, $this->blocked($this->staleReason($newestResult), $blockedType)];
    }

    private function blocked(string $reasonCode, string $type = 'guided_report_blocked')
    {
        return response()->json([
            'data' => [
                'type' => $type,
                'reason_code' => $reasonCode,
                ...($reasonCode === 'xhtml_ixbrl_no_taggable_claims' ? ['next_step' => '/api/report/xhtml', 'message' => __('No hay datos numéricos etiquetables. Puedes descargar la presentación XHTML.')] : []),
            ],
        ], 409);
    }

    private function finalPublicationSnapshotBlock(
        ReportSnapshot $snapshot,
        string $blockedType = 'guided_report_blocked',
    ): mixed {
        return $this->stateTransactions->run(
            $snapshot->characterization_id,
            function (Characterization $locked) use ($snapshot, $blockedType): mixed {
                $current = ReportSnapshot::query()
                    ->whereKey($snapshot->id)
                    ->where('characterization_id', $locked->id)
                    ->where('user_id', $snapshot->user_id)
                    ->whereHas('approval')
                    ->lockForUpdate()
                    ->first();

                if (! $current) {
                    return $this->blocked('approved_snapshot_missing', $blockedType);
                }

                $result = $this->staleness->refreshState($current);

                return $result['is_stale']
                    ? $this->blocked($this->staleReason($result), $blockedType)
                    : null;
            },
        );
    }

    private function staleReason(array $result): string
    {
        foreach ($result['reasons'] as $reason) {
            if (in_array($reason, ['scope_identity_missing', 'ig3_corpus_changed', 'ar16_dr_mapping_changed', 'report_scope_changed', 'scope_assets_unavailable', 'approved_snapshot_invalid'], true)) return $reason;
        }
        return 'approved_snapshot_stale';
    }

    private function allowedXhtmlIxbrlReason(string $reasonCode): string
    {
        $allowed = [
            'xhtml_ixbrl_arelle_unavailable',
            'xhtml_ixbrl_arelle_busy',
            'xhtml_ixbrl_arelle_validation_failed',
            'xhtml_ixbrl_concept_unavailable',
            'xhtml_ixbrl_context_missing',
            'xhtml_ixbrl_decimals_invalid',
            'xhtml_ixbrl_dimensions_unsupported',
            'xhtml_ixbrl_no_renderable_claims',
            'xhtml_ixbrl_no_taggable_claims',
            'xhtml_ixbrl_render_failed',
            'xhtml_ixbrl_unit_invalid',
            'xhtml_ixbrl_datatype_invalid',
            'xhtml_ixbrl_duplicate_fact_conflict',
            'xhtml_ixbrl_taxonomy_profile_unknown',
            'xhtml_ixbrl_taxonomy_profile_disabled',
            'xhtml_ixbrl_taxonomy_mapping_unavailable',
            'xhtml_ixbrl_taxonomy_metadata_invalid',
            'xhtml_ixbrl_package_failed',
        ];

        return in_array($reasonCode, $allowed, true)
            ? $reasonCode
            : 'xhtml_ixbrl_arelle_validation_failed';
    }
}
