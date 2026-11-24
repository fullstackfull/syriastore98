<?php

namespace Tests\Support\Security;

/**
 * The oracle of the stored-XSS tests (HelpdeskStoredXssTest, SupportTicketStoredXssTest): what a browser would build from a response, asked of the DOM -- not a substring search.
 *
 * A page legitimately contains scripts of its own, and an escaped payload legitimately contains the characters of a handler, so the questions are about ELEMENTS and ATTRIBUTES:
 * an image with an `onerror`, an svg that loads, a frame with a script URL, a script that carries a PAYLOAD MARKER (`XSS-`), a link to `javascript:`, any attribute that starts with
 * `on` and carries a marker, a `style` with a script URL. The markers are what keeps the page's own scripts out of the answer, which is why every payload in these tests carries one.
 *
 * It is only worth anything if it can SEE the defect: each test that uses it has a premise case that feeds it the payload printed raw and shows that it is found.
 */
trait DetectsActiveMarkup
{
    /** @return list<string> what in $html would run, request or navigate; empty when nothing of the payload is active */
    private function activeMarkupIn(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);

        $found = [];
        $queries = [
            'an image with a handler'      => '//img[@onerror]',
            'an svg that loads'            => '//svg[@onload]',
            'a frame with a script URL'    => '//iframe[contains(@src, "javascript")]',
            'a script of the payload'      => '//script[contains(., "XSS-")]',
            'a script URL'                 => '//a[starts-with(translate(normalize-space(@href), "JAVSCRIPT", "javscript"), "javascript:")]',
            'a handler on any element'     => '//*[@*[starts-with(name(), "on") and contains(., "XSS-")]]',
            'a style with a script URL'    => '//*[contains(@style, "javascript")]',
        ];

        foreach ($queries as $what => $query) {
            if ($xpath->query($query)->length > 0) {
                $found[] = $what;
            }
        }

        return $found;
    }
}
