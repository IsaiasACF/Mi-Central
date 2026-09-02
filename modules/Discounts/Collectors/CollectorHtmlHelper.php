<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use DOMDocument;
use DOMXPath;

final class CollectorHtmlHelper
{
    public static function xpath(string $html): DOMXPath
    {
        if (!class_exists(DOMDocument::class)) {
            throw new CollectorParseException('DOMDocument no esta disponible.');
        }

        if (@preg_match('//u', $html) !== 1) {
            throw new CollectorParseException('HTML externo no es UTF-8 valido.');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new CollectorParseException('No se pudo parsear HTML externo.');
        }

        return new DOMXPath($document);
    }
}
