<?php
// Filtrul de HTML: tot ce scrie AI-ul trece printr-o listă de etichete și atribute PERMISE.
// Ce nu e pe listă se scoate (script, style, formulare, SVG, evenimente on*, javascript: în linkuri).
// Filtrul rulează și la scriere, și la afișare — un JSON pus pe server pe altă cale nu ocolește regula.
// Motivul: la cinesunt.info HTML-ul articolelor se afișa nefiltrat; cine avea cheia putea injecta JS.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

// scoase cu tot cu conținut
const HTML_ELIMINATE = ['script', 'style', 'noscript', 'template', 'object', 'embed', 'applet', 'form', 'input',
    'button', 'select', 'textarea', 'option', 'svg', 'math', 'link', 'meta', 'base', 'frame', 'frameset', 'head',
    'title', 'audio', 'video', 'source', 'track', 'canvas', 'dialog', 'portal', 'xmp', 'plaintext'];

// eticheta => atributele ei permise (pe lângă cele globale)
const HTML_PERMISE = [
    'p' => [], 'br' => [], 'hr' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
    'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'mark' => [], 'small' => [],
    'sub' => [], 'sup' => [], 'code' => [], 'kbd' => [], 'pre' => [], 'abbr' => [], 'cite' => [],
    'blockquote' => ['cite'], 'q' => ['cite'], 'time' => ['datetime'],
    'ul' => [], 'ol' => ['start', 'reversed', 'type'], 'li' => ['value'], 'dl' => [], 'dt' => [], 'dd' => [],
    'a' => ['href', 'target', 'rel'],
    'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
    'figure' => [], 'figcaption' => [],
    'table' => [], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
    'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
    'div' => [], 'span' => [], 'section' => [], 'aside' => [], 'details' => ['open'], 'summary' => [],
    'iframe' => ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy'],
];
const HTML_GLOBALE = ['class', 'id', 'title', 'lang', 'dir'];

// Doar videoclipuri încorporate de pe YouTube și Vimeo.
const IFRAME_SURSE = '#^https://(www\.)?(youtube-nocookie\.com|youtube\.com)/embed/[A-Za-z0-9_-]{6,20}([?][A-Za-z0-9_=&;.-]*)?$'
    . '|^https://player\.vimeo\.com/video/[0-9]{3,12}([?][A-Za-z0-9_=&;.-]*)?$#';

// Linkuri: http(s), mailto, tel sau relative. Fără javascript:, data:, vbscript:, fără // (alt domeniu fără schemă).
function url_sigur(string $url, bool $relativ, array $scheme): bool
{
    $u = (string) preg_replace('/[\x00-\x20\x7F]+/', '', $url);   // browserele ignoră aceste caractere
    if ($u === '' || preg_match('#^[/\\\\]{2}#', $u) || strpos($u, '\\') !== false) return false;
    if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $u, $m)) return in_array(strtolower($m[1]), $scheme, true);
    return $relativ;
}

function atribut_permis(string $el, string $atr, string $v): bool
{
    if (!in_array($atr, HTML_GLOBALE, true) && !in_array($atr, HTML_PERMISE[$el] ?? [], true)) return false;
    switch ($atr) {
        case 'class': return preg_match('/^[A-Za-z0-9_\- ]{1,120}$/', $v) === 1;
        case 'id': return preg_match('/^[A-Za-z][A-Za-z0-9_\-]{0,63}$/', $v) === 1;
        case 'title': return strlen($v) <= 300;
        case 'lang': return preg_match('/^[A-Za-z\-]{2,12}$/', $v) === 1;
        case 'dir': return in_array($v, ['ltr', 'rtl', 'auto'], true);
        case 'href': return url_sigur($v, true, ['http', 'https', 'mailto', 'tel']);
        case 'cite': return url_sigur($v, false, ['http', 'https']);
        case 'src': return $el === 'iframe' ? preg_match(IFRAME_SURSE, $v) === 1 : url_sigur($v, true, ['https']);
        case 'width': case 'height': return preg_match('/^[0-9]{1,4}%?$/', $v) === 1;
        case 'colspan': case 'rowspan': case 'start': case 'value': return preg_match('/^[0-9]{1,3}$/', $v) === 1;
        case 'scope': return in_array($v, ['row', 'col', 'rowgroup', 'colgroup'], true);
        case 'type': return in_array($v, ['1', 'a', 'A', 'i', 'I'], true);
        case 'target': return $v === '_blank';
        case 'rel': return preg_match('/^((nofollow|noopener|noreferrer|sponsored|ugc)( |$))+$/', $v) === 1;
        case 'loading': return in_array($v, ['lazy', 'eager'], true);
        case 'decoding': return in_array($v, ['async', 'sync', 'auto'], true);
        case 'datetime': return preg_match('/^[0-9T:\-+ Z.]{4,40}$/', $v) === 1;
        case 'allow': return preg_match('/^[a-z\-; ]{0,200}$/', $v) === 1;
        case 'referrerpolicy': return preg_match('/^[a-z\-]{0,40}$/', $v) === 1;
        case 'open': case 'reversed': case 'allowfullscreen': return true;
    }
    return false;
}

// Curăță un fragment HTML. $raport primește ce s-a scos, ca AI-ul să afle și să se corecteze.
function curata_html(string $html, ?array &$raport = null): string
{
    $raport = [];
    if (trim($html) === '') return '';
    if (!class_exists('DOMDocument')) {   // fără extensia dom nu riscăm: tot conținutul devine text
        $raport['extensia PHP dom lipsește — HTML-ul a fost transformat în text'] = 1;
        return '<p>' . nl2br(esc(strip_tags($html))) . '</p>';
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    $anterior = libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
        . '</head><body>' . $html . '</body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);

    $corp = $doc->getElementsByTagName('body')->item(0);
    if (!$corp) return '';
    curata_copii($corp, $raport);
    $iesire = '';
    foreach (iterator_to_array($corp->childNodes) as $copil) $iesire .= $doc->saveHTML($copil);
    return trim($iesire);
}

function raport_adauga(array &$raport, string $ce): void
{
    $raport[$ce] = ($raport[$ce] ?? 0) + 1;
}

function curata_copii(DOMNode $parinte, array &$raport): void
{
    foreach (iterator_to_array($parinte->childNodes) as $nod) {
        if ($nod instanceof DOMElement) {
            curata_element($nod, $raport);
        } elseif ($nod instanceof DOMText && !($nod instanceof DOMCdataSection)) {
            continue;
        } else {   // comentarii, cod PHP trimis ca text (devine instrucțiune de procesare sau comentariu), CDATA
            raport_adauga($raport, $nod instanceof DOMComment ? 'comentariu HTML scos' : 'nod ' . $nod->nodeName . ' scos');
            $parinte->removeChild($nod);
        }
    }
}

function curata_element(DOMElement $el, array &$raport): void
{
    $nume = strtolower($el->nodeName);
    $parinte = $el->parentNode;
    if (in_array($nume, HTML_ELIMINATE, true)) {
        raport_adauga($raport, "<$nume> scos cu tot conținutul");
        $parinte->removeChild($el);
        return;
    }
    if ($nume === 'h1') {   // titlul paginii e singurul h1
        $nou = $el->ownerDocument->createElement('h2');
        while ($el->firstChild) $nou->appendChild($el->firstChild);
        foreach (iterator_to_array($el->attributes) as $a) $nou->setAttribute($a->name, $a->value);
        $parinte->replaceChild($nou, $el);
        $el = $nou;
        $nume = 'h2';
        raport_adauga($raport, '<h1> transformat în <h2> (h1 e titlul paginii)');
    }
    if (!isset(HTML_PERMISE[$nume])) {   // etichetă necunoscută: păstrăm textul, scoatem eticheta
        curata_copii($el, $raport);
        while ($el->firstChild) $parinte->insertBefore($el->firstChild, $el);
        $parinte->removeChild($el);
        raport_adauga($raport, "<$nume> despachetat (s-a păstrat doar conținutul)");
        return;
    }
    foreach (iterator_to_array($el->attributes) as $a) {
        $n = strtolower($a->name);
        if (!atribut_permis($nume, $n, (string) $a->value)) {
            $el->removeAttribute($a->name);
            raport_adauga($raport, "atributul $n scos de pe <$nume>");
        }
    }
    if (($nume === 'iframe' || $nume === 'img') && !$el->hasAttribute('src')) {
        $parinte->removeChild($el);
        raport_adauga($raport, "<$nume> fără sursă permisă scos");
        return;
    }
    if ($nume === 'a' && $el->getAttribute('target') === '_blank') $el->setAttribute('rel', 'noopener noreferrer');
    if ($nume === 'iframe') {
        $el->setAttribute('loading', 'lazy');
        $el->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    }
    curata_copii($el, $raport);
}
