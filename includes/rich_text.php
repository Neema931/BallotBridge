<?php

function sanitize_rich_text(string $html): string
{
    if (!class_exists(DOMDocument::class)) {
        throw new RuntimeException('The PHP DOM extension is required to sanitize election instructions.');
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previousErrorMode = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="rich-root">' . $html . '</div>',
        LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrorMode);

    $root = $loaded ? $document->getElementById('rich-root') : null;
    if (!$root instanceof DOMElement) {
        throw new RuntimeException('Unable to parse the election instructions.');
    }

    $allowedTags = ['a', 'b', 'blockquote', 'br', 'em', 'h2', 'h3', 'h4', 'i', 'li', 'ol', 'p', 'strong', 'u', 'ul'];
    $removeEntirely = ['iframe', 'object', 'script', 'style', 'svg', 'video', 'audio'];
    $sanitizeChildren = static function (DOMNode $parent) use (
        &$sanitizeChildren,
        $allowedTags,
        $removeEntirely,
        $document
    ): void {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            $sanitizeChildren($child);
            if (!in_array($tag, $allowedTags, true)) {
                if (in_array($tag, $removeEntirely, true)) {
                    $parent->removeChild($child);
                    continue;
                }

                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            $attributes = [];
            foreach ($child->attributes as $attribute) {
                $attributes[] = $attribute->name;
            }
            foreach ($attributes as $attributeName) {
                if ($tag === 'a' && in_array($attributeName, ['href', 'title'], true)) {
                    continue;
                }
                $child->removeAttribute($attributeName);
            }

            if ($tag === 'a' && $child->hasAttribute('href')) {
                $href = trim($child->getAttribute('href'));
                $scheme = parse_url($href, PHP_URL_SCHEME);
                $isSafeRelative = $href !== ''
                    && !str_starts_with($href, '//')
                    && ($scheme === null || $scheme === '');
                $isSafeAbsolute = is_string($scheme)
                    && in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);

                if (!$isSafeRelative && !$isSafeAbsolute) {
                    $child->removeAttribute('href');
                } else {
                    $child->setAttribute('href', $href);
                }
            }
        }
    };

    $sanitizeChildren($root);

    $sanitized = '';
    foreach ($root->childNodes as $child) {
        $serialized = $document->saveHTML($child);
        if (!is_string($serialized)) {
            throw new RuntimeException('Unable to serialize election instructions.');
        }
        $sanitized .= $serialized;
    }

    return $sanitized;
}
