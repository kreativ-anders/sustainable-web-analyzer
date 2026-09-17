<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

use DOMDocument;
use DOMElement;

final class HtmlInspector
{
    /** Elements whose resources are downloaded, and the attribute holding the URL. */
    private const RESOURCE_ATTRIBUTES = ['script' => 'src', 'link' => 'href', 'img' => 'src'];

    /**
     * rel values that do not cause a download of the referenced URL.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Attributes/rel
     */
    private const IGNORED_REL = [
        'dns-prefetch', 'preconnect', 'canonical', 'prefetch', 'alternate', 'search', 'manifest', 'author',
        'bookmark', 'external', 'help', 'icon', 'license', 'me', 'next', 'nofollow', 'modulepreload', 'noopener',
        'noreferrer', 'opener', 'pingback', 'prerender', 'prev', 'tag',
    ];

    /** URL fragments of cookie consent managers – their presence implies cookies. */
    private const CONSENT_MARKERS = [
        'borlabs-cookie', 'usercentrics', 'cmp.osano', 'cookiebot', 'cookielaw.org', 'onetrust', 'consentmanager.net',
        'complianz', 'cookieyes', 'iubenda', 'cookie-script.com', 'cookiefirst',
    ];

    /**
     * @return array{resources: list<string>, divification: bool, consentManager: bool}
     */
    public static function inspect(string $html, string $pageUrl): array
    {
        $report = ['resources' => [], 'divification' => false, 'consentManager' => false];

        if (trim($html) === '') {
            return $report;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The XML declaration makes libxml treat undeclared documents as UTF-8 instead of ISO-8859-1.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $base = $pageUrl;
        $baseElement = $dom->getElementsByTagName('base')->item(0);
        if ($baseElement instanceof DOMElement && $baseElement->getAttribute('href') !== '') {
            $base = Url::resolve($pageUrl, $baseElement->getAttribute('href')) ?? $pageUrl;
        }

        $resources = [];
        foreach (self::RESOURCE_ATTRIBUTES as $tag => $attribute) {
            foreach ($dom->getElementsByTagName($tag) as $element) {
                $value = $element->getAttribute($attribute);

                if ($value === '') {
                    continue;
                }
                if (!$report['consentManager'] && self::containsConsentMarker($value)) {
                    $report['consentManager'] = true;
                }
                if (self::isIgnored($element)) {
                    continue;
                }

                $url = Url::resolve($base, $value);
                if ($url !== null) {
                    $resources[$url] = true;
                }
            }
        }

        $report['resources'] = array_keys($resources);
        $report['divification'] = self::hasDivification($dom);

        return $report;
    }

    private static function isIgnored(DOMElement $element): bool
    {
        $rel = preg_split('/\s+/', strtolower(trim($element->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect($rel, self::IGNORED_REL) !== [];
    }

    private static function containsConsentMarker(string $url): bool
    {
        $url = strtolower($url);

        foreach (self::CONSENT_MARKERS as $marker) {
            if (str_contains($url, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if more than one <div> is nested inside at least three other <div>s
     * (same result as the XPath //div//div//div//div having more than one match, without its cost).
     */
    private static function hasDivification(DOMDocument $dom): bool
    {
        $deepDivs = 0;

        foreach ($dom->getElementsByTagName('div') as $div) {
            $ancestors = 0;

            for ($node = $div->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
                if ($node->nodeName === 'div' && ++$ancestors === 3) {
                    if (++$deepDivs > 1) {
                        return true;
                    }
                    break;
                }
            }
        }

        return false;
    }
}
