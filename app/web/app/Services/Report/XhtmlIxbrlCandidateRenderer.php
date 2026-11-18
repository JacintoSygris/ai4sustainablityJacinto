<?php

namespace App\Services\Report;

use DOMDocument;
use DOMElement;

class XhtmlIxbrlCandidateRenderer
{
    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';
    private const IX_NS = 'http://www.xbrl.org/2013/inlineXBRL';
    private const IXT_NS = 'http://www.xbrl.org/inlineXBRL/transformation/2020-02-12';
    private const XBRLI_NS = 'http://www.xbrl.org/2003/instance';
    private const LINK_NS = 'http://www.xbrl.org/2003/linkbase';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';
    private const DTR_NS = 'http://www.xbrl.org/dtr/type/2024-01-31';
    private const NUMERIC_VALUE_TYPES = ['decimal', 'integer', 'monetary', 'number'];

    public function __construct(
        private readonly XbrlConceptMap $conceptMap,
        private readonly TaxonomyConceptMetadata $metadata = new TaxonomyConceptMetadata,
        private readonly TaxonomyProfileRegistry $registry = new TaxonomyProfileRegistry,
    ) {}

    private const TEXT_ITEM_TYPES = ['stringItemType', 'textBlockItemType'];

    public function render(array $ir, ReportingProfile $profile, array $internalManifest, string $locale = 'es', ?string $taxonomyProfileId = null): string
    {
        if (ReportDisplayProjection::coherent($ir)) return $this->renderCoherent($ir, $profile, $internalManifest, $locale, $taxonomyProfileId);
        $taxonomy = $this->registry->candidate($taxonomyProfileId ?? $this->registry->selectedId(), $profile);
        $concepts = $this->metadata->load($taxonomy, $internalManifest);
        $display = new ReportDisplayProjection($locale);
        $context = $this->context($ir);
        $mapping = isset($ir['xbrl_mapping'])
            ? XbrlConceptMap::fromFrozen($ir['xbrl_mapping'])
            : $this->conceptMap->capture(! isset($ir['source']['report_snapshot_id']), $taxonomy['concept_mapping']);
        $facts = $this->facts($ir, $concepts, $mapping);

        if ($facts === []) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_no_renderable_claims');
        }
        // Claims lacking an id (synthetic IRs) get an internal key that never reaches the visible document.
        foreach ($ir['claims'] ?? [] as $index => $claim) {
            if (is_array($claim) && ! is_string($claim['claim_id'] ?? null)) $ir['claims'][$index]['claim_id'] = '_claim_'.$index;
        }
        $factsByClaim = [];
        foreach ($ir['claims'] ?? [] as $claim) {
            if (! is_array($claim) || ! in_array($claim['value_type'] ?? null, self::NUMERIC_VALUE_TYPES, true)) continue;
            foreach ($facts as $fact) {
                if ($fact['datapoint_id'] === (string) ($claim['datapoint_id'] ?? '') && ! isset($factsByClaim[$claim['claim_id']])) {
                    $factsByClaim[$claim['claim_id']] = $fact;
                    break;
                }
            }
        }

        // The report itself is the Inline XBRL document: same design as the other outputs.
        try {
            $built = (new ReportXhtmlDocument)->build($ir, $locale);
        } catch (\DomainException|\RuntimeException $e) {
            // Fail closed on XML-incompatible text or unknown dimensions, as the other visible paths do.
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_invalid_visible_content', previous: $e);
        }
        $doc = $built['doc'];
        $html = $doc->documentElement;
        $html->setAttribute('xml:lang', $display->locale);
        foreach ([
            'ix' => self::IX_NS, 'ixt' => self::IXT_NS, 'xbrli' => self::XBRLI_NS,
            'link' => self::LINK_NS, 'xlink' => self::XLINK_NS,
            'iso4217' => 'http://www.xbrl.org/2003/iso4217',
            'utr' => 'http://www.xbrl.org/2009/utr', 'esrs' => $taxonomy['namespace'],
        ] as $prefix => $namespace) $html->setAttribute('xmlns:'.$prefix, $namespace);

        $head = $doc->getElementsByTagNameNS(self::XHTML_NS, 'head')->item(0);
        foreach (['taxonomy-profile' => $taxonomy['id'], 'taxonomy-status' => $taxonomy['status']] as $name => $value) {
            $meta = $head->appendChild($doc->createElementNS(self::XHTML_NS, 'meta'));
            $meta->setAttribute('name', $name);
            $meta->setAttribute('content', $value);
        }
        $body = $doc->getElementsByTagNameNS(self::XHTML_NS, 'body')->item(0);
        $hidden = $body->insertBefore($doc->createElementNS(self::XHTML_NS, 'div'), $body->firstChild);
        $hidden->setAttribute('style', 'display:none');
        $header = $hidden->appendChild($doc->createElementNS(self::IX_NS, 'ix:header'));
        $references = $header->appendChild($doc->createElementNS(self::IX_NS, 'ix:references'));
        $schemaRef = $references->appendChild($doc->createElementNS(self::LINK_NS, 'link:schemaRef'));
        $schemaRef->setAttributeNS(self::XLINK_NS, 'xlink:type', 'simple');
        $schemaRef->setAttributeNS(self::XLINK_NS, 'xlink:href', $taxonomy['entrypoint']);
        $resources = $header->appendChild($doc->createElementNS(self::IX_NS, 'ix:resources'));
        $textFacts = $this->textFacts($ir, $concepts, $mapping);
        $periodTypes = array_unique([...array_column($facts, 'period_type'), ...array_column($textFacts, 'period_type')]);
        sort($periodTypes);
        foreach ($periodTypes as $periodType) {
            $this->appendContext($doc, $resources, 'c_'.$periodType, $context, $periodType);
        }
        $units = [];
        foreach ($facts as $fact) $units[$fact['unit_id']] = $fact['unit_measure'];
        ksort($units);
        foreach ($units as $id => $measure) {
            $unit = $resources->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:unit'));
            $unit->setAttribute('id', $id);
            $this->text($doc, $unit, self::XBRLI_NS, 'xbrli:measure', $measure);
        }

        // Numbers: tag the visible, localised value in place; the unit stays in unitRef only.
        $tagged = 0;
        $seen = [];
        foreach ($built['numeric'] as $entry) {
            $fact = $factsByClaim[$entry['claim_id']] ?? null;
            if ($fact === null) continue;
            $key = $fact['concept'].'|'.$fact['period_type'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $span = $entry['span'];
            $value = $span->textContent;
            $prefix = str_starts_with($value, '-') || str_starts_with($value, '+') ? substr($value, 0, 1) : '';
            $node = $doc->createElementNS(self::IX_NS, 'ix:nonFraction');
            foreach ([
                'name' => $fact['concept'], 'contextRef' => 'c_'.$fact['period_type'],
                'unitRef' => $fact['unit_id'], 'decimals' => $fact['decimals'],
                'format' => $locale === 'en' ? 'ixt:num-dot-decimal' : 'ixt:num-comma-decimal', 'id' => 'f'.(++$tagged),
            ] as $key => $attribute) $node->setAttribute($key, $attribute);
            if ($fact['negative']) $node->setAttribute('sign', '-');
            if ($fact['scale'] !== null) $node->setAttribute('scale', $fact['scale']);
            $node->appendChild($doc->createTextNode($prefix === '' ? $value : substr($value, 1)));
            while ($span->firstChild) $span->removeChild($span->firstChild);
            if ($prefix !== '') $span->appendChild($doc->createTextNode($prefix));
            $span->appendChild($node);
        }
        if ($tagged === 0) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_render_failed');

        // Narratives, booleans, dates and years: tag the visible answer in place as ix:nonNumeric.
        $textSeen = [];
        foreach ($built['text'] as $entry) {
            $fact = $textFacts[$entry['claim_id']] ?? null;
            if ($fact === null || isset($textSeen[$fact['concept']])) continue;
            $textSeen[$fact['concept']] = true;
            $paragraph = $entry['node'];
            $node = $doc->createElementNS(self::IX_NS, 'ix:nonNumeric');
            $node->setAttribute('name', $fact['concept']);
            $node->setAttribute('contextRef', 'c_'.$fact['period_type']);
            if ($fact['format'] !== null) $node->setAttribute('format', $fact['format']);
            $node->setAttribute('id', 'f'.(++$tagged));
            while ($paragraph->firstChild) $node->appendChild($paragraph->firstChild);
            $paragraph->appendChild($node);
        }

        $xml = $doc->saveXML();
        if (! is_string($xml) || $xml === '') throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_render_failed');

        return $xml;
    }

    /**
     * Non-numeric claims that can be tagged in place, keyed by claim id: free text on
     * string/text-block concepts, booleans (ixt:fixed-true/false), ISO dates and years.
     *
     * @return array<string, array{concept: string, period_type: string, format: ?string}>
     */
    private function textFacts(array $ir, array $concepts, XbrlConceptMap $mapping): array
    {
        $facts = [];
        foreach ($ir['claims'] ?? [] as $claim) {
            if (! is_array($claim) || ($claim['nil'] ?? false) === true || ($claim['dimensions'] ?? []) !== []) continue;
            $valueType = $claim['value_type'] ?? null;
            if (! in_array($valueType, ['text', 'boolean', 'date'], true)) continue;
            $mapped = $mapping->conceptFor((string) ($claim['datapoint_id'] ?? ''));
            $concept = $mapped['concept_id'] ?? '';
            if (! is_array($mapped) || ($mapped['taggable_state'] ?? null) !== 'mapped' || ($mapped['required_context'] ?? []) !== []
                || ! is_string($concept) || ! preg_match('/\Aesrs:[A-Za-z_][A-Za-z0-9._-]*\z/', $concept)) continue;
            $metadata = $concepts[$concept] ?? null;
            if (! is_array($metadata) || ($metadata['abstract'] ?? false) || ! in_array($metadata['period_type'] ?? null, ['duration', 'instant'], true)) continue;
            $type = substr((string) $metadata['type'], (int) strrpos((string) $metadata['type'], '}') + 1);
            try {
                $value = ReportFactValue::scalar($claim);
            } catch (\RuntimeException) {
                continue;
            }
            $format = match (true) {
                $valueType === 'text' && in_array($type, self::TEXT_ITEM_TYPES, true) => null,
                $valueType === 'boolean' && $type === 'booleanItemType' && is_bool($value) => $value ? 'ixt:fixed-true' : 'ixt:fixed-false',
                $valueType === 'date' && $type === 'dateItemType' && $this->validDate($value) => null,
                $valueType === 'date' && $type === 'gYearItemType' && is_string($value) && preg_match('/\A\d{4}\z/', $value) === 1 => null,
                default => false,
            };
            if ($format === false) continue;
            $facts[(string) $claim['claim_id']] = ['concept' => $concept, 'period_type' => $metadata['period_type'], 'format' => $format];
        }

        return $facts;
    }

    /** Complete XHTML presentation without taxonomy, contexts, units or Arelle prerequisites. */
    public function renderPresentation(array $ir, string $locale = 'es'): string
    {
        return (new ReportPresentationDocument)->build($ir, $locale)->saveXML();
    }

    /** Per-claim eligibility is independent of visibility. No fact values enter evidence. */
    public function taggingPlan(array $ir, ReportingProfile $profile, array $manifest, ?string $taxonomyId = null): array
    {
        $taxonomy = $this->registry->candidate($taxonomyId ?? $this->registry->selectedId(), $profile);
        $mapping = isset($ir['xbrl_mapping']) ? XbrlConceptMap::fromFrozen($ir['xbrl_mapping'])
            : $this->conceptMap->capture(! isset($ir['source']['report_snapshot_id']), $taxonomy['concept_mapping']);
        $concepts = null;
        $entries = [];
        $facts = [];
        foreach ($ir['claims'] ?? [] as $claim) {
            if (! is_array($claim) || ! is_string($claim['claim_id'] ?? null) || isset($entries[$claim['claim_id']])) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_render_failed');
            // Validate even untaggable values; malformed inputs are never silently excluded.
            ReportFactValue::scalar($claim);
            ReportVisiblePresentation::withVisibleDimensions('', $claim['dimensions'] ?? [], 'es', true);
            $reason = $this->exclusionReason($claim, $mapping);
            $fact = null;
            if ($reason === null) {
                $concepts ??= $this->metadata->load($taxonomy, $manifest);
                $fact = $this->facts(['claims' => [$claim]], $concepts, $mapping)[0];
                $key = $fact['concept'].'|'.$fact['period_type'];
                if (isset($facts[$key])) {
                    if ($facts[$key] !== $fact) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_duplicate_fact_conflict');
                    $reason = 'duplicate_machine_fact';
                } else {
                    $facts[$key] = $fact;
                }
            }
            $entries[$claim['claim_id']] = ['eligible' => $reason === null, 'reason' => $reason, 'fact' => $fact];
        }
        return ['taxonomy' => $taxonomy, 'claims' => $entries, 'facts' => array_values($facts)];
    }

    /** Claim paragraphs carry `class="fact <claim_id>"` (see ReportPresentationDocument). */
    private static function claimIdFromClass(string $class): string
    {
        foreach (preg_split('/\s+/', trim($class)) ?: [] as $token) {
            if (str_starts_with($token, 'claim_')) return $token;
        }

        return '';
    }

    private function exclusionReason(array $claim, XbrlConceptMap $mapping): ?string
    {
        if (($claim['nil'] ?? false) === true) return 'nil_unsupported';
        if (! in_array($claim['value_type'] ?? null, self::NUMERIC_VALUE_TYPES, true)) return 'non_numeric';
        if (($claim['dimensions'] ?? []) !== []) return 'dimensions_unsupported';
        $mapped = $mapping->conceptFor((string) ($claim['datapoint_id'] ?? ''));
        $required = $mapped['required_context'] ?? [];
        if (! is_array($required) || ! array_is_list($required) || array_filter($required, fn ($value) => ! is_string($value) || $value === '') !== []) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_concept_unavailable');
        if ($required !== []) return 'mapping_required_context';
        if ($mapped === null || ($mapped['taggable_state'] ?? null) !== 'mapped') return 'unmapped';
        return null;
    }

    /** Fast zero-tag gate also works without an external taxonomy package. */
    public function hasTaggableClaims(array $ir): bool
    {
        $mapping = isset($ir['xbrl_mapping']) ? XbrlConceptMap::fromFrozen($ir['xbrl_mapping']) : $this->conceptMap->capture(false);
        foreach ($ir['claims'] ?? [] as $claim) {
            if ($this->exclusionReason($claim, $mapping) === null) return true;
        }
        return false;
    }

    public function taggingEvidence(array $plan): array
    {
        $tagged = [];
        $untagged = [];
        foreach ($plan['claims'] as $id => $entry) {
            if ($entry['eligible']) $tagged[] = $id;
            else $untagged[] = ['claim_id' => $id, 'reason' => $entry['reason']];
        }
        return ['tagged_claim_ids' => $tagged, 'untagged_claims' => $untagged];
    }

    public function renderWithTaggingEvidence(array $ir, ReportingProfile $profile, array $manifest, string $locale = 'es', ?string $taxonomyId = null): array
    {
        $plan = $this->taggingPlan($ir, $profile, $manifest, $taxonomyId);
        return ['xhtml' => $this->renderCoherent($ir, $profile, $manifest, $locale, $taxonomyId, $plan), 'tagging' => $this->taggingEvidence($plan)];
    }

    private function renderCoherent(array $ir, ReportingProfile $profile, array $manifest, string $locale, ?string $taxonomyId, ?array $plan = null): string
    {
        $doc = (new ReportPresentationDocument)->build($ir, $locale);
        $plan ??= $this->taggingPlan($ir, $profile, $manifest, $taxonomyId);
        if ($plan['facts'] === []) return $doc->saveXML();
        $context = $this->context($ir);
        $html = $doc->documentElement;
        $taxonomy = $plan['taxonomy'];
        foreach (['ix' => self::IX_NS, 'ixt' => self::IXT_NS, 'xbrli' => self::XBRLI_NS, 'link' => self::LINK_NS,
            'xlink' => self::XLINK_NS, 'iso4217' => 'http://www.xbrl.org/2003/iso4217', 'utr' => 'http://www.xbrl.org/2009/utr', 'esrs' => $taxonomy['namespace']] as $prefix => $ns) $html->setAttribute('xmlns:'.$prefix, $ns);
        $body = $doc->getElementsByTagNameNS(self::XHTML_NS, 'body')->item(0);
        $hidden = $body->insertBefore($doc->createElementNS(self::XHTML_NS, 'div'), $body->firstChild);
        $hidden->setAttribute('style', 'display:none');
        $header = $hidden->appendChild($doc->createElementNS(self::IX_NS, 'ix:header'));
        $references = $header->appendChild($doc->createElementNS(self::IX_NS, 'ix:references'));
        $schema = $references->appendChild($doc->createElementNS(self::LINK_NS, 'link:schemaRef'));
        $schema->setAttributeNS(self::XLINK_NS, 'xlink:type', 'simple');
        $schema->setAttributeNS(self::XLINK_NS, 'xlink:href', $taxonomy['entrypoint']);
        $resources = $header->appendChild($doc->createElementNS(self::IX_NS, 'ix:resources'));
        foreach (array_unique(array_column($plan['facts'], 'period_type')) as $type) $this->appendContext($doc, $resources, 'c_'.$type, $context, $type);
        $units = [];
        foreach ($plan['facts'] as $fact) $units[$fact['unit_id']] = $fact['unit_measure'];
        ksort($units);
        foreach ($units as $id => $measure) {
            $unit = $resources->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:unit'));
            $unit->setAttribute('id', $id);
            $this->text($doc, $unit, self::XBRLI_NS, 'xbrli:measure', $measure);
        }
        $used = [];
        foreach ($doc->getElementsByTagNameNS(self::XHTML_NS, 'p') as $p) {
            $id = self::claimIdFromClass($p->getAttribute('class'));
            $entry = $plan['claims'][$id] ?? null;
            if (! ($entry['eligible'] ?? false) || isset($used[$id])) continue;
            $span = $p->getElementsByTagNameNS(self::XHTML_NS, 'span')->item(0);
            if (! $span || $span->getAttribute('class') !== 'numeric-value') throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_render_failed');
            $fact = $entry['fact'];
            $value = $span->textContent;
            $prefix = str_starts_with($value, '-') || str_starts_with($value, '+') ? substr($value, 0, 1) : '';
            $node = $doc->createElementNS(self::IX_NS, 'ix:nonFraction');
            foreach (['name' => $fact['concept'], 'contextRef' => 'c_'.$fact['period_type'], 'unitRef' => $fact['unit_id'],
                'decimals' => $fact['decimals'], 'format' => $locale === 'en' ? 'ixt:num-dot-decimal' : 'ixt:num-comma-decimal',
                'id' => 'f'.(count($used) + 1)] as $key => $valueAttr) $node->setAttribute($key, $valueAttr);
            if ($fact['negative']) $node->setAttribute('sign', '-');
            if ($fact['scale'] !== null) $node->setAttribute('scale', $fact['scale']);
            $node->appendChild($doc->createTextNode($prefix === '' ? $value : substr($value, 1)));
            while ($span->firstChild) $span->removeChild($span->firstChild);
            if ($prefix !== '') $span->appendChild($doc->createTextNode($prefix));
            $span->appendChild($node);
            $used[$id] = true;
        }
        foreach ($plan['claims'] as $id => $entry) {
            if ($entry['eligible'] && ! isset($used[$id])) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_render_failed');
        }
        $meta = $doc->getElementsByTagNameNS(self::XHTML_NS, 'head')->item(0)->appendChild($doc->createElementNS(self::XHTML_NS, 'meta'));
        $meta->setAttribute('name', 'report-tagging-evidence');
        $meta->setAttribute('content', json_encode($this->taggingEvidence($plan), JSON_THROW_ON_ERROR));
        return $doc->saveXML();
    }

    private function context(array $ir): array
    {
        $company = $ir['company'] ?? [];
        $name = $company['name'] ?? null;
        $start = $company['reporting_period_start'] ?? null;
        $end = $company['reporting_period_end'] ?? null;
        $identifier = $company['entity_identifier'] ?? null;
        // Read old immutable snapshots without rewriting them. Always emit the canonical HTTP scheme.
        $scheme = $company['entity_identifier_scheme'] ?? null;
        if (! is_string($name) || trim($name) === '' || ! $this->validDate($start) || ! $this->validDate($end) || $start > $end
            || ! self::validLei($identifier)
            || ! in_array($scheme, ['http://standards.iso.org/iso/17442', 'https://standards.iso.org/iso/17442'], true)) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_context_missing');
        }

        return ['entity_name' => $name, 'entity_identifier' => $identifier, 'start' => $start, 'end' => $end];
    }

    public static function validLei(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A[A-Z0-9]{18}[0-9]{2}\z/', $value) !== 1) return false;
        $remainder = 0;
        foreach (str_split($value) as $char) {
            $digits = ctype_digit($char) ? $char : (string) (ord($char) - 55);
            foreach (str_split($digits) as $digit) $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }

    private function validDate(mixed $date): bool
    {
        if (! is_string($date) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts)) return false;
        return (int) $parts[1] >= 2000 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function facts(array $ir, array $concepts, XbrlConceptMap $mapping): array
    {
        $facts = [];
        foreach ($ir['claims'] ?? [] as $claim) {
            if (! is_array($claim)) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_no_renderable_claims');
            if (! in_array($claim['value_type'] ?? null, self::NUMERIC_VALUE_TYPES, true)) continue;
            if (($claim['dimensions'] ?? []) !== []) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_dimensions_unsupported');
            $datapoint = (string) ($claim['datapoint_id'] ?? '');
            $mapped = $mapping->conceptFor($datapoint);
            // QName/periodType alone cannot express a future period or a dimensional breakdown.
            // Reject even a single fact until all mapping-required aspects can be represented.
            if (($mapped['required_context'] ?? []) !== []) {
                throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_dimensions_unsupported');
            }
            $concept = $mapped['concept_id'] ?? '';
            if (! is_array($mapped) || ($mapped['taggable_state'] ?? null) !== 'mapped'
                || ! is_string($concept) || ! preg_match('/\Aesrs:[A-Za-z_][A-Za-z0-9._-]*\z/', $concept)) {
                throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_concept_unavailable');
            }
            $metadata = $concepts[$concept] ?? null;
            if (! is_array($metadata) || $metadata['abstract'] || ! in_array($metadata['period_type'], ['duration', 'instant'], true)) {
                throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_concept_unavailable');
            }
            try {
                $value = $this->numericValue(ReportFactValue::scalar($claim));
            } catch (\RuntimeException) {
                throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_no_renderable_claims');
            }
            $decimals = $this->decimals($claim['decimals'] ?? null);
            [$unitId, $measure, $scale] = $this->unit((string) ($claim['unit'] ?? ''), $metadata['type']);
            if ($metadata['type'] === '{'.self::XBRLI_NS.'}integerItemType' && str_contains($value, '.')) {
                throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_datatype_invalid');
            }
            $fact = [
                'datapoint_id' => $datapoint, 'concept' => $concept, 'period_type' => $metadata['period_type'],
                'negative' => str_starts_with($value, '-'), 'value' => ltrim($value, '+-'),
                'unit_id' => $unitId, 'unit_measure' => $measure, 'display_unit' => (string) $claim['unit'],
                'scale' => $scale, 'decimals' => $scale === '-2' && $decimals !== 'INF' ? (string) ((int) $decimals + 2) : $decimals,
            ];
            // Different datapoints sharing a QName can mean current vs future values: never collapse those.
            $key = $concept.'|'.$metadata['period_type'];
            if (isset($facts[$key]) && $facts[$key] !== $fact) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_duplicate_fact_conflict');
            $facts[$key] = $fact;
        }
        ksort($facts);
        return array_values($facts);
    }

    private function numericValue(mixed $value): string
    {
        if (is_int($value)) return (string) $value;
        // No fixed precision truncation. Exponential floats must be supplied as exact decimal strings.
        if (is_float($value) && is_finite($value)) $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if (! is_string($value) || ! preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)\z/', $value)) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_no_renderable_claims');
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        if (str_starts_with($value, '.')) $value = '0'.$value;
        $value = rtrim($value, '.');
        return ($negative ? '-' : '').$value;
    }

    private function decimals(mixed $decimals): string
    {
        if ($decimals === 'INF') return 'INF';
        if ((is_int($decimals) || (is_string($decimals) && preg_match('/\A-?\d{1,3}\z/', $decimals)))
            && (int) $decimals >= -100 && (int) $decimals <= 100) return (string) (int) $decimals;
        throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_decimals_invalid');
    }

    private function unit(string $unit, string $type): array
    {
        if ($type === '{'.self::XBRLI_NS.'}monetaryItemType' && array_key_exists($unit, \App\Support\CharacterizationOptions::reportingCurrencies())) return ['u_'.$unit, 'iso4217:'.$unit, null];
        if (in_array($type, ['{'.self::XBRLI_NS.'}integerItemType', '{'.self::XBRLI_NS.'}decimalItemType'], true) && $unit === 'pure') return ['u_pure', 'xbrli:pure', null];
        if ($type === '{'.self::DTR_NS.'}percentItemType' && in_array($unit, ['%', 'ratio'], true)) return ['u_pure', 'xbrli:pure', $unit === '%' ? '-2' : null];
        $utr = ['tCO2e' => 'ghgEmissionsItemType', 'MWh' => 'energyItemType', 'kg' => 'massItemType', 't' => 'massItemType', 'm3' => 'volumeItemType', 'ha' => 'areaItemType'];
        if (isset($utr[$unit]) && $type === '{'.self::DTR_NS.'}'.$utr[$unit]) return ['u_'.$unit, 'utr:'.$unit, null];
        throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_unit_invalid');
    }

    private function appendContext(DOMDocument $doc, DOMElement $resources, string $id, array $context, string $periodType): void
    {
        $node = $resources->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:context'));
        $node->setAttribute('id', $id);
        $entity = $node->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:entity'));
        $identifier = $this->text($doc, $entity, self::XBRLI_NS, 'xbrli:identifier', $context['entity_identifier']);
        $identifier->setAttribute('scheme', 'http://standards.iso.org/iso/17442');
        $period = $node->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:period'));
        if ($periodType === 'instant') {
            $this->text($doc, $period, self::XBRLI_NS, 'xbrli:instant', $context['end']);
        } else {
            $this->text($doc, $period, self::XBRLI_NS, 'xbrli:startDate', $context['start']);
            $this->text($doc, $period, self::XBRLI_NS, 'xbrli:endDate', $context['end']);
        }
    }

    private function text(DOMDocument $doc, DOMElement $parent, string $namespace, string $name, string $text): DOMElement
    {
        if (preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $text) !== 0) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_render_failed');
        }
        $node = $parent->appendChild($doc->createElementNS($namespace, $name));
        $node->appendChild($doc->createTextNode($text));
        return $node;
    }
}
