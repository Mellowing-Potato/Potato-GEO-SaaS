<?php

namespace App\Services\Content;

use DOMElement;

/** Visibility rules shared by every server-side HTML reader. */
final class HtmlVisibility
{
    public static function isHidden(DOMElement $node): bool
    {
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            $style = preg_replace('/\s+/', '', strtolower($current->getAttribute('style'))) ?? '';
            if ($current->hasAttribute('hidden')
                || strtolower(trim($current->getAttribute('aria-hidden'))) === 'true'
                || str_contains($style, 'display:none')
                || str_contains($style, 'visibility:hidden')) {
                return true;
            }
        }

        return false;
    }
}
