<?php

namespace App\Services\Report;

use DOMDocument;

/** DOM text nodes only: evidence stays plain text, never an active URL or fragment. */
final class ReportPresentationDocument
{
    public const NS = 'http://www.w3.org/1999/xhtml';

    public function build(array $ir, string $locale): DOMDocument
    {
        $display = new ReportDisplayProjection($locale);
        $events = $display->ordered($ir);
        $doc = new DOMDocument('1.0', 'UTF-8');
        // Inline XBRL 1.1 is validated against the XHTML 1.1 schema: no HTML5 `lang`, `meta charset`
        // or sectioning elements (Arelle reports them as lxml schema errors and blocks the candidate).
        $html = $doc->appendChild($doc->createElementNS(self::NS, 'html'));
        $html->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $display->locale);
        $head = $html->appendChild($doc->createElementNS(self::NS, 'head'));
        $title = $head->appendChild($doc->createElementNS(self::NS, 'title'));
        $title->appendChild($doc->createTextNode($events[0]['text']));
        $meta = $head->appendChild($doc->createElementNS(self::NS, 'meta'));
        $meta->setAttribute('http-equiv', 'Content-Type');
        $meta->setAttribute('content', 'text/html; charset=UTF-8');
        $style = $head->appendChild($doc->createElementNS(self::NS, 'style'));
        $style->setAttribute('type', 'text/css');
        $style->appendChild($doc->createTextNode(ReportXhtmlDocument::stylesheet()));
        $body = $html->appendChild($doc->createElementNS(self::NS, 'body'));
        $stack = [$body];
        foreach ($events as $event) {
            if ($event['kind'] === 'close') { array_pop($stack); continue; }
            $parent = $stack[array_key_last($stack)];
            if ($event['kind'] === 'open') {
                $parent = $parent->appendChild($doc->createElementNS(self::NS, 'div'));
                $parent->setAttribute('class', 'section');
                $stack[] = $parent;
                if ($event['text'] === null) continue;
            }
            $tag = match ($event['kind']) { 'title' => 'h1', 'open' => 'h'.$event['level'], default => 'p' };
            $node = $parent->appendChild($doc->createElementNS(self::NS, $tag));
            if ($event['kind'] === 'value') {
                // XHTML 1.1 has no data-* attributes and `id` must be unique: the claim travels as a class token.
                $node->setAttribute('class', 'fact '.$event['claim']['claim_id']);
                if ($event['numeric_text'] !== null) {
                    $span = $node->appendChild($doc->createElementNS(self::NS, 'span'));
                    $span->setAttribute('class', 'numeric-value');
                    $span->appendChild($doc->createTextNode($event['numeric_text']));
                    $node->appendChild($doc->createTextNode(substr($event['text'], strlen($event['numeric_text']))));
                    continue;
                }
            }
            $node->appendChild($doc->createTextNode($event['text']));
        }
        return $doc;
    }
}
