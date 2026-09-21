<?php
// Urcă imagini de pe calculator pe site, fără să treacă prin conversația cu AI-ul (fără base64 în chat):
//
//   php unelte/urca-imagine.php https://site.ro poza.jpg [alta.png …] [--latime=1600] [--original]
//
// Cu GD (PHP-ul de imagini), fiecare poză e întoarsă după orientarea din telefon, micșorată la cel mult 1600 px pe latura
// mare și rescrisă: JPEG-ul pierde datele EXIF, deci și locația GPS. --latime=N schimbă latura (0 = n-o micșora),
// --original o urcă exact cum e. Fără GD, pozele pleacă neatinse (cel mult 5 MB). La final, adresele /media/... de folosit.
// Folosește cheia de scriere din chei-<nume>.json; opțiunile --nume, --dosar și --local sunt cele comune uneltelor.
declare(strict_types=1);

// Pe Windows, PHP-ul vine de obicei cu GD alături, dar oprit în php.ini: îl pornim doar pentru comanda asta, fără să
// schimbăm php.ini.
if (!function_exists('imagecreatefromstring') && getenv('MINICMS_URCA_RELANSAT') === false && DIRECTORY_SEPARATOR === '\\') {
    $ext = dirname(PHP_BINARY) . '\\ext';
    if (is_file("$ext\\php_gd.dll")) {
        putenv('MINICMS_URCA_RELANSAT=1');
        $p = proc_open(array_merge([PHP_BINARY, '-d', "extension_dir=$ext", '-d', 'extension=gd', __FILE__], array_slice($argv, 1)),
            [0 => STDIN, 1 => STDOUT, 2 => STDERR], $tevi);
        exit(is_resource($p) ? proc_close($p) : 1);
    }
}

require __DIR__ . '/comun.php';

// Adresa site-ului e primul argument care nu e opțiune; restul sunt fișierele.
$fisiere = [];
$argumente = [$argv[0]];
$are_site = false;
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--', 2) === 0) $argumente[] = $a;
    elseif (!$are_site) { $argumente[] = $a; $are_site = true; }
    else $fisiere[] = $a;
}
['opt' => $opt, 'site' => $site, 'fisier_chei' => $fisier_chei] = porneste($argumente, ['latime', 'original'],
    'php unelte/urca-imagine.php https://site.ro poza.jpg [alta.png …] [--latime=1600] [--original]');
if (!$fisiere) opreste('spune ce imagini urc: php unelte/urca-imagine.php ' . $site . ' poza.jpg');
$latime = isset($opt['latime']) ? max(0, (int) $opt['latime']) : 1600;
$original = !empty($opt['original']);
$gd = function_exists('imagecreatefromstring');

$chei = cheile($fisier_chei, true);
$cheie = (string) ($chei['scriere']['cheie'] ?? '');
if ($cheie === '') opreste("în $fisier_chei nu e cheia de scriere.");
$antet = antet_pentru($site, $cheie);
if ($antet === null) opreste("serverul nu primește cheia de scriere din $fisier_chei.");

// Orientarea din EXIF (1 = normal; 3, 6, 8 = rotită, cum o salvează telefoanele), citită direct din fișier: fără extensia exif.
function orientare_jpeg(string $d): int
{
    $i = 2;
    $n = strlen($d);
    while ($i + 4 < $n && $d[$i] === "\xFF") {
        $marcaj = ord($d[$i + 1]);
        $lung = unpack('n', substr($d, $i + 2, 2))[1];
        if ($marcaj === 0xE1 && substr($d, $i + 4, 6) === "Exif\0\0") {
            $t = substr($d, $i + 10, $lung - 8);
            $le = substr($t, 0, 2) === 'II';
            $u16 = fn(int $o) => strlen($t) >= $o + 2 ? unpack($le ? 'v' : 'n', substr($t, $o, 2))[1] : 0;
            $ifd = strlen($t) >= 8 ? unpack($le ? 'V' : 'N', substr($t, 4, 4))[1] : 0;
            for ($k = 0, $cate = $u16($ifd); $k < $cate && $k < 200; $k++) {
                if ($u16($ifd + 2 + 12 * $k) === 0x0112) return $u16($ifd + 2 + 12 * $k + 8) ?: 1;
            }
            return 1;
        }
        if ($marcaj === 0xDA) break;
        $i += 2 + $lung;
    }
    return 1;
}

// [conținut, ce s-a făcut]. Tipul se află din conținut, nu din numele fișierului.
function pregateste(string $date, int $latime, bool $original, bool $gd): array
{
    $info = @getimagesizefromstring($date);
    if (!$info) throw new RuntimeException('nu e o imagine pe care o recunosc');
    $tip = $info[2];
    if ($original || !$gd || $tip === IMAGETYPE_GIF) return [$date, $tip === IMAGETYPE_GIF ? 'GIF, urcat cum e (poate fi animat)' : 'urcată cum e'];
    $orientare = $tip === IMAGETYPE_JPEG ? orientare_jpeg($date) : 1;
    $mare = $latime > 0 && max($info[0], $info[1]) > $latime;
    if ($tip !== IMAGETYPE_JPEG && !$mare) return [$date, 'urcată cum e'];
    $im = @imagecreatefromstring($date);
    if (!$im) return [$date, 'urcată cum e (GD n-a putut-o citi)'];
    $ce = [];
    if ($orientare !== 1) {
        if (in_array($orientare, [2, 5, 7], true)) imageflip($im, IMG_FLIP_HORIZONTAL);
        if ($orientare === 4) imageflip($im, IMG_FLIP_VERTICAL);
        $unghi = [3 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90][$orientare] ?? 0;
        if ($unghi) $im = imagerotate($im, $unghi, 0);
        $ce[] = 'întoarsă după orientarea din telefon';
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if ($latime > 0 && max($w, $h) > $latime) {
        $k = $latime / max($w, $h);
        if ($tip !== IMAGETYPE_JPEG) { imagealphablending($im, false); imagesavealpha($im, true); }
        $im = imagescale($im, (int) round($w * $k), (int) round($h * $k), IMG_BICUBIC);
        $ce[] = "micșorată de la {$w}×{$h}";
    }
    ob_start();
    if ($tip === IMAGETYPE_PNG) { imagesavealpha($im, true); imagepng($im, null, 9); }
    elseif ($tip === IMAGETYPE_WEBP && function_exists('imagewebp')) { imagesavealpha($im, true); imagewebp($im, null, 82); }
    else { imageinterlace($im, true); imagejpeg($im, null, 84); if ($tip === IMAGETYPE_JPEG) $ce[] = 'fără EXIF (și fără locație)'; }
    $nou = (string) ob_get_clean();
    return [$nou !== '' ? $nou : $date, $ce ? implode(', ', $ce) : 'rescrisă'];
}

echo culoare("mini-cms-mcp $VERSIUNE — urc imagini pe $site", '1'), "\n";
if (!$gd && !$original) atentie('PHP-ul de aici n-are GD: pozele pleacă neatinse (nemicșorate, cu datele EXIF). Merg până la 5 MB fiecare.');
titlu('Imaginile');
$adrese = [];
$greseli = 0;
foreach ($fisiere as $f) {
    $nume = basename($f);
    if (!is_file($f)) { gresit("$f: nu există"); $greseli++; continue; }
    try {
        $inainte = (int) filesize($f);
        [$date, $ce] = pregateste((string) file_get_contents($f), $latime, $original, $gd);
        if (strlen($date) > 5 * 1024 * 1024) throw new RuntimeException('are ' . round(strlen($date) / 1048576, 1) . ' MB, peste 5 MB (fără --original, cu GD, s-ar fi micșorat)');
        $r = apel_mcp($site, $cheie, $antet, 'urca_imagine', ['nume' => $nume, 'continut_base64' => base64_encode($date)]);
        ok(sprintf('%s → %s  (%d×%d, %s KB din %s KB; %s)', $nume, $r['url'] ?? '?', $r['latime'] ?? 0, $r['inaltime'] ?? 0,
            round(strlen($date) / 1024), round($inainte / 1024), $ce));
        $adrese[] = (string) ($r['url'] ?? '');
    } catch (Throwable $e) {
        gresit("$nume: " . $e->getMessage());
        $greseli++;
    }
}
if ($adrese) {
    titlu('Adresele, de folosit în conținut sau drept copertă');
    foreach ($adrese as $a) echo "  $a\n";
}
exit($greseli ? 1 : 0);
