<?php

namespace App\Services\Report;

use DOMDocument;
use DOMElement;

/**
 * Styled XHTML 1.1 report used as the body of the Inline XBRL document: cover,
 * chapters, disclosure requirements with their code, and one card per datapoint
 * with code, question and answer. Numeric answers wrap their number in
 * `span.numeric-value` so the iXBRL renderer can tag them in place; evidence
 * references stay inside the platform and are never printed.
 */
final class ReportXhtmlDocument
{
    public const NS = 'http://www.w3.org/1999/xhtml';

    private const NUMERIC_TYPES = ['number', 'monetary', 'integer', 'decimal'];

    private DOMDocument $doc;

    private ReportDisplayProjection $display;

    /** @var array<int, array{span: DOMElement, claim_id: string}> */
    private array $numericSpans = [];

    /** @var array<int, array{node: DOMElement, claim_id: string}> */
    private array $textNodes = [];

    /**
     * @return array{doc: DOMDocument, numeric: array<int, array{span: DOMElement, claim_id: string}>, text: array<int, array{node: DOMElement, claim_id: string}>}
     */
    public function build(array $ir, string $locale = 'es', string $title = 'Informe de sostenibilidad NEIS'): array
    {
        $this->display = new ReportDisplayProjection($locale);
        $this->numericSpans = [];
        $this->textNodes = [];
        $display = $this->display;
        $company = $ir['company'] ?? [];
        $claims = $this->claimsById($ir);

        $this->doc = $doc = new DOMDocument('1.0', 'UTF-8');
        $html = $doc->appendChild($doc->createElementNS(self::NS, 'html'));
        $html->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $display->locale);
        $head = $html->appendChild($doc->createElementNS(self::NS, 'head'));
        $this->text($head, 'title', ($company['name'] ?? $display->text('Informe NEIS')).' — '.$display->text($title));
        $meta = $head->appendChild($doc->createElementNS(self::NS, 'meta'));
        $meta->setAttribute('http-equiv', 'Content-Type');
        $meta->setAttribute('content', 'text/html; charset=UTF-8');
        $style = $head->appendChild($doc->createElementNS(self::NS, 'style'));
        $style->setAttribute('type', 'text/css');
        $style->appendChild($doc->createTextNode(self::stylesheet()));

        $body = $html->appendChild($doc->createElementNS(self::NS, 'body'));
        $page = $this->div($body, 'page');

        // Cover
        $cover = $this->div($page, 'cover');
        $this->text($cover, 'p', $display->text($title), 'kicker');
        $this->text($cover, 'h1', (string) ($company['name'] ?? $display->text('Informe NEIS')));
        $metaLine = $this->div($cover, 'meta');
        $this->metaItem($metaLine, $display->text('Ejercicio'), (string) ($company['reporting_year'] ?? '-'));
        if (! empty($company['reporting_period_start']) && ! empty($company['reporting_period_end'])) {
            $this->metaItem($metaLine, $display->text('Periodo'), $company['reporting_period_start'].' – '.$company['reporting_period_end']);
        }
        if (! empty($company['entity_identifier'])) {
            $this->metaItem($metaLine, $display->text('Identificador de entidad (LEI)'), (string) $company['entity_identifier']);
        }
        $narrative = $display->narrative($ir);
        $notice = $this->div($cover, 'notice');
        $this->text($notice, 'p', $display->text('Informe generado desde una versión aprobada'), 'notice-title');
        foreach (array_values(array_unique($narrative['disclaimers'] ?? [])) as $disclaimer) {
            if (is_string($disclaimer) && trim($disclaimer) !== '') {
                $this->text($notice, 'p', ReportVisiblePresentation::controlledNarrative($disclaimer));
            }
        }

        $iroLines = IroContextPresentation::lines($ir['iro_context_v1'] ?? [], $display->locale);
        if ($iroLines !== []) {
            $section = $this->div($page, 'chapter');
            $this->text($section, 'h2', array_shift($iroLines));
            foreach ($iroLines as $line) $this->text($section, 'p', $line);
        }

        $placed = [];
        foreach ($ir['chapters'] ?? [] as $chapter) {
            if (! is_array($chapter)) continue;
            $chapterNode = $this->div($page, 'chapter');
            $this->text($chapterNode, 'h2', $display->chapterTitle($chapter['block_key'] ?? null));
            $isE1 = ($chapter['block_key'] ?? null) === 'e1_not_material_explanation';
            foreach ($chapter['sections'] ?? [] as $section) {
                if (! is_array($section)) continue;
                $sectionNode = $this->div($chapterNode, 'requirement');
                if (! $isE1) {
                    $heading = $sectionNode->appendChild($doc->createElementNS(self::NS, 'h3'));
                    $this->text($heading, 'span', (string) ($section['dr_key'] ?? ''), 'code');
                    $heading->appendChild($doc->createTextNode(' '.$display->sectionTitle($section['dr_key'] ?? null)));
                }
                foreach ($section['blocks'] ?? [] as $block) {
                    if (! is_array($block)) continue;
                    if ($isE1 || ($block['datapoint_id'] ?? null) === 'e1_not_material_explanation') {
                        foreach ($block['assertions'] ?? [] as $explanation) {
                            if (is_string($explanation) && trim($explanation) !== '') $this->text($sectionNode, 'p', $explanation, 'explanation');
                        }
                        continue;
                    }
                    $this->datapointCard($sectionNode, (string) ($block['datapoint_id'] ?? ''), $block['claims'] ?? [], $claims, $placed);
                }
            }
        }

        // Claims without a layout (legacy or synthetic IRs) stay visible in a generic chapter.
        $orphans = array_diff(array_keys($claims), array_keys($placed));
        if ($orphans !== []) {
            $chapterNode = $this->div($page, 'chapter');
            $this->text($chapterNode, 'h2', $display->text('Datos informados'));
            $sectionNode = $this->div($chapterNode, 'requirement');
            foreach ($orphans as $claimId) {
                $this->datapointCard($sectionNode, (string) ($claims[$claimId]['datapoint_id'] ?? ''), [$claimId], $claims, $placed);
            }
        }

        $this->appendDecisions($page, $ir);
        $this->appendOmissions($page, $narrative['omission_section'] ?? null);

        $footer = $this->div($page, 'footer');
        $this->text($footer, 'p', $display->text('Candidato iXBRL provisional (no es una presentación ESEF/ESAP oficial)'));

        return ['doc' => $doc, 'numeric' => $this->numericSpans, 'text' => $this->textNodes];
    }

    /**
     * @param  array<int, string>  $claimIds
     * @param  array<string, array<string, mixed>>  $claims
     * @param  array<string, bool>  $placed
     */
    private function datapointCard(DOMElement $parent, string $datapointId, array $claimIds, array $claims, array &$placed): void
    {
        $display = $this->display;
        $card = $this->div($parent, 'datapoint');
        $header = $this->div($card, 'datapoint-head');
        $this->text($header, 'span', $datapointId, 'code');
        $this->text($header, 'p', $display->claimLabel($datapointId), 'question');
        $answers = 0;
        foreach ($claimIds as $claimId) {
            if (! is_string($claimId) || ! isset($claims[$claimId])) continue;
            $claim = $claims[$claimId];
            $placed[$claimId] = true;
            $value = $display->claimValue($claim);
            if ($value === null) continue;
            $answers++;
            $p = $this->div($card, 'answer', 'p');
            if (in_array($claim['value_type'] ?? null, self::NUMERIC_TYPES, true)) {
                $numericText = $this->numericText($claim);
                if ($numericText !== null && str_starts_with($value, $numericText)) {
                    $span = $p->appendChild($this->doc->createElementNS(self::NS, 'span'));
                    $span->setAttribute('class', 'numeric-value');
                    $span->appendChild($this->doc->createTextNode($numericText));
                    $this->numericSpans[] = ['span' => $span, 'claim_id' => $claimId];
                    $rest = substr($value, strlen($numericText));
                    if ($rest !== '') $p->appendChild($this->doc->createTextNode($rest));
                    continue;
                }
            }
            $p->appendChild($this->doc->createTextNode($value));
            if (($claim['value_type'] ?? null) === 'text') {
                $this->textNodes[] = ['node' => $p, 'claim_id' => $claimId];
            }
        }
        if ($answers === 0) {
            $this->text($card, 'p', $display->text('Sin valor'), 'answer muted');
        }
    }

    /** Localised number without unit, exactly as the visible value starts. */
    private function numericText(array $claim): ?string
    {
        $calendar = in_array($claim['unit'] ?? null, ['year', 'año'], true) ? $claim['unit'] : null;
        $number = array_replace($claim, ['unit' => $calendar, 'dimensions' => []]);
        if (in_array($number['value_type'] ?? null, ['monetary', 'decimal'], true)) $number['value_type'] = 'number';

        return $this->display->claimValue($number);
    }

    private function appendDecisions(DOMElement $page, array $ir): void
    {
        $display = $this->display;
        $decisions = array_filter($ir['fact_decisions'] ?? [], fn ($fact): bool => is_array($fact)
            && ($fact['output_section'] ?? null) !== 'body'
            && in_array($fact['approval_status'] ?? null, ['reviewed', 'approved'], true));
        if ($decisions === []) return;
        $chapter = $this->div($page, 'chapter appendix');
        $this->text($chapter, 'h2', $display->text('Anexo: decisiones revisadas fuera del contenido afirmativo'));
        foreach ($decisions as $fact) {
            $card = $this->div($chapter, 'datapoint');
            $header = $this->div($card, 'datapoint-head');
            $this->text($header, 'span', (string) ($fact['datapoint_id'] ?? ''), 'code');
            $this->text($header, 'p', $display->claimLabel($fact['datapoint_id'] ?? null), 'question');
            $applicability = match ($fact['applicability'] ?? null) {
                'applicable' => 'Aplicable', 'not_applicable' => 'No aplicable', 'unavailable' => 'No disponible',
                'pending' => 'Pendiente', 'blocked' => 'Bloqueado', default => 'No disponible',
            };
            $this->text($card, 'p', $display->text('Aplicabilidad').': '.$display->text($applicability), 'answer muted');
            if (is_string($fact['nil_reason'] ?? null) && trim($fact['nil_reason']) !== '') {
                $this->text($card, 'p', $display->text('Motivo sin valor').': '.$fact['nil_reason'], 'answer');
            }
        }
    }

    private function appendOmissions(DOMElement $page, ?array $omission): void
    {
        if ($omission === null) return;
        $statements = is_array($omission['statements'] ?? null) ? $omission['statements'] : [];
        $hasContent = $statements !== [] || ! empty($omission['declaration']) || ! empty($omission['limitation']);
        if (! $hasContent) return;
        $chapter = $this->div($page, 'chapter appendix');
        $this->text($chapter, 'h2', $this->display->text('Anexo: ').ReportVisiblePresentation::controlledNarrative($omission['title'] ?? 'Omisiones'));
        foreach ([$omission['declaration'] ?? null, ...$statements, $omission['limitation'] ?? null] as $text) {
            if (is_string($text) && trim($text) !== '') $this->text($chapter, 'p', ReportVisiblePresentation::controlledNarrative($text));
        }
    }

    private function metaItem(DOMElement $parent, string $label, string $value): void
    {
        $item = $this->div($parent, 'meta-item');
        $this->text($item, 'span', $label, 'meta-label');
        $this->text($item, 'span', $value, 'meta-value');
    }

    private function div(DOMElement $parent, string $class, string $tag = 'div'): DOMElement
    {
        $node = $parent->appendChild($this->doc->createElementNS(self::NS, $tag));
        $node->setAttribute('class', $class);

        return $node;
    }

    private function text(DOMElement $parent, string $tag, string $text, ?string $class = null): DOMElement
    {
        ReportDisplayProjection::assertXmlText($text);
        $node = $parent->appendChild($this->doc->createElementNS(self::NS, $tag));
        if ($class !== null) $node->setAttribute('class', $class);
        $node->appendChild($this->doc->createTextNode($text));

        return $node;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function claimsById(array $ir): array
    {
        $claims = [];
        foreach ($ir['claims'] ?? [] as $claim) {
            if (is_array($claim) && is_string($claim['claim_id'] ?? null) && $claim['claim_id'] !== '') $claims[$claim['claim_id']] = $claim;
        }

        return $claims;
    }

    public static function stylesheet(): string
    {
        return implode("\n", [
            'body{margin:0;background:#f4f4f7;color:#1f2333;font-family:"Segoe UI",Arial,Helvetica,sans-serif;line-height:1.5}',
            '.page{max-width:960px;margin:0 auto;padding:32px 24px 48px}',
            '.cover{background:#4838bf;color:#fff;border-radius:12px;padding:32px 36px;margin-bottom:28px}',
            '.cover h1{margin:4px 0 16px;font-size:30px;line-height:1.2}',
            '.kicker{margin:0;font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.85}',
            '.meta{display:block;margin:0 0 18px}',
            '.meta-item{display:inline-block;margin:0 24px 8px 0}',
            '.meta-label{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;opacity:.8}',
            '.meta-value{display:block;font-size:15px;font-weight:600}',
            '.notice{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.35);border-radius:8px;padding:12px 16px;font-size:13px}',
            '.notice p{margin:0 0 6px}.notice p:last-child{margin-bottom:0}.notice-title{font-weight:600}',
            '.chapter{background:#fff;border:1px solid #e3e4ea;border-radius:12px;padding:24px 28px;margin-bottom:24px}',
            '.chapter h2{margin:0 0 16px;font-size:22px;color:#2b2470;border-bottom:2px solid #e9e6fb;padding-bottom:8px}',
            '.requirement{margin:18px 0 0}',
            '.requirement h3{margin:0 0 10px;font-size:16px;color:#1f2333}',
            '.code{display:inline-block;font-family:Consolas,"Courier New",monospace;font-size:12px;background:#ece9fb;color:#3b2fa0;border-radius:6px;padding:2px 8px;margin-right:8px;vertical-align:middle}',
            '.datapoint{border:1px solid #e3e4ea;border-left:4px solid #4838bf;border-radius:8px;padding:12px 16px;margin:10px 0;background:#fbfbfd}',
            '.datapoint-head p{display:inline;margin:0}',
            '.question{font-weight:600;color:#2b2470}',
            '.answer{margin:8px 0 0;padding:8px 12px;background:#fff;border:1px solid #ececf2;border-radius:6px}',
            '.numeric-value{font-weight:700;font-size:17px;color:#1f2333}',
            '.muted{color:#6b6f80}',
            '.explanation{margin:8px 0}',
            '.appendix h2{color:#6b6f80;border-bottom-color:#e3e4ea}',
            '.footer{text-align:center;color:#6b6f80;font-size:12px;margin-top:24px}',
            '@media print{body{background:#fff}.page{padding:0}.chapter,.datapoint{break-inside:avoid}}',
        ]);
    }
}
