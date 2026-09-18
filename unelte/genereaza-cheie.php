<?php
// Generează o pereche nouă de chei (citire + scriere) și amprentele lor.
// Cheile se scriu într-un fișier local, NU pe ecran: nu trebuie să ajungă în istoricul terminalului sau în chat.
// Pe server, în app/config.php, se pun doar amprentele.
//
//   php unelte/genereaza-cheie.php <fisier-local-pentru-chei>
$tinta = $argv[1] ?? '';
if ($tinta === '') {
    fwrite(STDERR, "Folosire: php unelte/genereaza-cheie.php <fisier-local-pentru-chei>\n");
    exit(1);
}
if (file_exists($tinta)) {
    fwrite(STDERR, "Fișierul $tinta există deja — nu îl suprascriu (versionează-l întâi).\n");
    exit(1);
}
$rez = [];
foreach (['citire' => 'c', 'scriere' => 's'] as $rol => $p) {
    $cheie = 'mcms_' . $p . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $rez[$rol] = ['cheie' => $cheie, 'amprenta' => hash('sha256', $cheie)];
}
file_put_contents($tinta, json_encode($rez, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Cheile sunt în $tinta.\nAmprentele, de pus în app/config.php:\n";
foreach ($rez as $rol => $r) echo "  '$rol' => '{$r['amprenta']}',\n";
