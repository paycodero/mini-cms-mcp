<?php
// Imaginile: singurele fișiere pe care AI-ul le pune în dosarul public (media/).
// Tipul se află din conținut, nu din numele trimis: extensia o alegem noi (jpg/png/gif/webp),
// deci „shell.php" cu conținut de imagine ajunge „shell-a1b2c3d4.png". SVG nu se acceptă (poate conține script).
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const IMAGINE_MAX_OCTETI = 5 * 1024 * 1024;
const IMAGINE_TIPURI = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
const IMAGINE_NUME = '/^[a-z0-9-]+\.(jpg|png|gif|webp)$/';

function dir_media(): string
{
    $d = (string) config('media');
    if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) throw new RuntimeException('nu pot crea dosarul media');
    return $d;
}

function urca_imagine(string $nume, string $base64): array
{
    $base64 = (string) preg_replace('/^data:[^,]*,/', '', trim($base64));
    $date = base64_decode($base64, true);
    if ($date === false || $date === '') throw new EroareCms('conținutul nu e base64 valid');
    if (strlen($date) > IMAGINE_MAX_OCTETI) throw new EroareCms('imaginea depășește 5 MB');
    $info = @getimagesizefromstring($date);
    if (!$info || !isset(IMAGINE_TIPURI[$info[2]])) {
        throw new EroareCms('nu e o imagine acceptată: doar JPEG, PNG, GIF sau WebP (SVG nu, poate conține cod)');
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > 10000 || $info[1] > 10000) throw new EroareCms('dimensiuni de imagine nepermise');
    if (preg_match('/<\?php|<script/i', $date)) throw new EroareCms('fișierul conține cod ascuns în imagine — refuzat');

    $baza = substr(slug_din_text((string) pathinfo($nume, PATHINFO_FILENAME)), 0, 60) ?: 'imagine';
    $fisier = rtrim($baza, '-') . '-' . substr(hash('sha256', $date), 0, 8) . '.' . IMAGINE_TIPURI[$info[2]];
    $tinta = dir_media() . '/' . $fisier;
    return cu_blocare(function () use ($tinta, $date, $fisier, $info) {
        $exista = is_file($tinta);
        if (!$exista && !scrie_atomic($tinta, $date)) throw new EroareCms('scrierea imaginii a eșuat');
        return ['operatie' => $exista ? 'exista deja (același conținut)' : 'urcată', 'url' => '/media/' . $fisier,
                'url_absolut' => url_absolut('/media/' . $fisier), 'latime' => $info[0], 'inaltime' => $info[1],
                'octeti' => strlen($date), 'amprenta' => hash('sha256', $date)];
    });
}

function listeaza_imagini(): array
{
    $rez = [];
    foreach (scandir(dir_media()) ?: [] as $f) {
        if (!preg_match(IMAGINE_NUME, $f)) continue;
        $cale = dir_media() . '/' . $f;
        $rez[] = ['nume' => $f, 'url' => '/media/' . $f, 'octeti' => filesize($cale), 'urcata_la' => date('c', (int) filemtime($cale))];
    }
    usort($rez, fn($a, $b) => strcmp($b['urcata_la'], $a['urcata_la']));
    return $rez;
}

// Unde e folosită o imagine: în coperta articolelor sau în conținutul paginilor/articolelor.
function imagine_folosita_in(string $fisier): array
{
    $unde = [];
    foreach (array_keys(TIPURI) as $tip) {
        foreach (listeaza_elemente($tip) as $e) {
            if (($e['imagine'] ?? '') === '/media/' . $fisier || strpos((string) ($e['continut_html'] ?? ''), '/media/' . $fisier) !== false) {
                $unde[] = $tip . '/' . $e['slug'];
            }
        }
    }
    return $unde;
}

function sterge_imagine(string $fisier, bool $forteaza): array
{
    if (!preg_match(IMAGINE_NUME, $fisier)) throw new EroareCms('nume de imagine invalid (ex. "coperta-a1b2c3d4.png", din listeaza_imagini)');
    return cu_blocare(function () use ($fisier, $forteaza) {
        $sursa = dir_media() . '/' . $fisier;
        if (!is_file($sursa)) throw new EroareCms("imaginea $fisier nu există");
        $unde = imagine_folosita_in($fisier);
        if ($unde && !$forteaza) {
            throw new EroareCms('imaginea e folosită în: ' . implode(', ', $unde) . ' — scoate-o de acolo sau cere ștergerea cu forteaza=true');
        }
        $dest = dir_date('versiuni/media') . '/' . $fisier . '.' . date('Ymd-His');
        if (!@rename($sursa, $dest) && !(@copy($sursa, $dest) && @unlink($sursa))) throw new EroareCms('mutarea imaginii a eșuat');
        return ['operatie' => 'ștearsă (mutată între versiuni)', 'nume' => $fisier, 'era_folosita_in' => $unde];
    });
}
