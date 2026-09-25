<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section class="jurnal">
  <h1>Jurnalul site-ului</h1>
<?php if ($intrari === null): ?>
  <p>Introdu cheia de citire sau de scriere. Cheia nu se păstrează nicăieri: pagina se deschide o singură dată, la trimitere.</p>
<?php if ($mesaj !== ''): ?><p class="alerta"><?= esc($mesaj) ?></p><?php endif; ?>
  <form method="post" action="/jurnal.php" class="formular">
    <label for="cheie">Cheia</label>
    <input id="cheie" name="cheie" type="password" autocomplete="current-password" required minlength="20">
    <label class="bifa"><input type="checkbox" name="doar_probleme" value="1"> doar problemele (încercări eșuate, refuzuri, erori, blocări)</label>
    <button class="buton" type="submit">Deschide jurnalul</button>
  </form>
<?php else: ?>
  <p class="lant <?= $lant['intact'] ? 'bun' : 'rau' ?>">
    <?= $lant['intact'] ? '✔ Lanțul e intact' : '✘ Lanțul e rupt la ' . esc($lant['unde'] ?? '?') ?> · <?= (int) $lant['intrari'] ?> intrări în total · se văd ultimele <?= count($intrari) ?>
  </p>
  <div class="tabel">
  <table>
    <thead><tr><th>Când</th><th>Rezultat</th><th>Cerere</th><th>Țintă</th><th>Cine</th><th>IP</th><th>Detalii</th></tr></thead>
    <tbody>
<?php foreach ($intrari as $i): $r = (string) ($i['rezultat'] ?? ''); ?>
      <tr>
        <td class="nowrap"><?= esc(str_replace('T', ' ', substr((string) ($i['t'] ?? ''), 0, 19))) ?></td>
        <td><span class="stare stare-<?= esc(preg_replace('/[^a-z_]/', '', $r)) ?>"><?= esc($r) ?></span></td>
        <td><?= esc(trim(($i['punct'] ?? '') . ' ' . ($i['cerere'] ?? '') . ' ' . ($i['unealta'] ?? ''))) ?></td>
        <td><?= esc($i['tinta'] ?? '') ?></td>
        <td><?= esc($i['cine'] ?? $i['cheie'] ?? '') ?><?= isset($i['cine'], $i['cheie']) && $i['cine'] !== $i['cheie'] ? '<br><small>cheie ' . esc($i['cheie']) . '</small>' : '' ?><?= isset($i['conexiune']) ? '<br><small>' . esc($i['conexiune']) . '</small>' : '' ?></td>
        <td class="nowrap"><?= esc($i['ip'] ?? '') ?></td>
        <td class="detalii"><?= esc(isset($i['detalii']) ? json_text($i['detalii']) : '') ?><?= isset($i['amprenta']) ? '<br><small>amprentă ' . esc(substr((string) $i['amprenta'], 0, 16)) . '…</small>' : '' ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
