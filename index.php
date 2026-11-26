<?php
$scenarios = [
    'sunny' => ['label' => 'Ensoleille', 'icon' => 'sun'],
    'cloudy' => ['label' => 'Nuageux', 'icon' => 'cloud'],
    'static' => ['label' => 'Faible activite', 'icon' => 'wind'],
    'night' => ['label' => 'Nuit', 'icon' => 'moon'],
];

$scenario = $_GET['scenario'] ?? 'sunny';
if (!array_key_exists($scenario, $scenarios)) {
    $scenario = 'sunny';
}

$json = @file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $scenario . '.json');
$data = $json !== false ? json_decode($json, true) : null;

$values = [];
if (is_array($data) && isset($data['dataList']) && is_array($data['dataList'])) {
    foreach ($data['dataList'] as $item) {
        $values[$item['key']] = $item;
    }
}

$number = static function (array $values, string $key): float {
    $value = isset($values[$key]['value']) ? str_replace(',', '.', (string) $values[$key]['value']) : '0';
    return is_numeric($value) ? (float) $value : 0.0;
};

$text = static function (array $values, string $key, string $fallback = '-') : string {
    $value = $values[$key]['value'] ?? $fallback;
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$solar = $number($values, 'DPi_t1');
$grid = $number($values, 'PG_Pt1');
$consumption = $number($values, 'E_Puse_t1');
$status = $text($values, 'INV_ST1', 'Indisponible');
$systemTime = $text($values, 'SYSTIM1');
$serial = $text($values, 'SN1');
$gridText = abs($grid) >= 1000 ? number_format(abs($grid) / 1000, 2, ',', ' ') . ' kW' : number_format(abs($grid), 0, ',', ' ') . ' W';
$gridDirection = $grid >= 0 ? 'Injection au reseau' : 'Import du reseau';
$gridClass = $grid >= 0 ? 'positive' : 'negative';
$error = $data === null;
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ondulateur virtuel</title>
    <style>
        :root {
            --navy: #0b1b35;
            --blue: #184a82;
            --cyan: #35b6a7;
            --orange: #f59e0b;
            --red: #dc2626;
            --green: #16a34a;
            --surface: #ffffff;
            --background: #edf2f7;
            --muted: #64748b;
            --line: #dbe5ef;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, "Segoe UI", Arial, sans-serif;
            color: var(--navy);
            background: var(--background);
        }
        header {
            background: var(--navy);
            color: white;
            padding: 18px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand-mark {
            width: 38px; height: 38px; border: 2px solid #77d8cf;
            border-radius: 50%; display: grid; place-items: center;
            color: #77d8cf; font-weight: 800;
        }
        .brand h1 { margin: 0; font-size: 18px; }
        .live { font-size: 13px; color: #bcd1e8; }
        main { max-width: 1180px; margin: 0 auto; padding: 28px 24px 40px; }
        .toolbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; margin-bottom: 20px;
        }
        .toolbar h2 { margin: 0 0 4px; font-size: 25px; }
        .toolbar p { margin: 0; color: var(--muted); }
        .scenario-select { display: flex; gap: 8px; flex-wrap: wrap; }
        .scenario-select a {
            color: var(--navy); text-decoration: none; background: white;
            border: 1px solid var(--line); border-radius: 8px;
            padding: 10px 12px; font-size: 13px; font-weight: 700;
        }
        .scenario-select a.active { background: var(--blue); color: white; border-color: var(--blue); }
        .cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
        .card {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: 12px; padding: 22px;
            box-shadow: 0 5px 18px rgba(15, 41, 74, 0.06);
        }
        .card-label { color: var(--muted); font-size: 13px; font-weight: 700; text-transform: uppercase; }
        .card-value { margin-top: 10px; font-size: 34px; font-weight: 800; }
        .card-value.positive { color: var(--green); }
        .card-value.negative { color: var(--red); }
        .card-foot { margin-top: 8px; color: var(--muted); font-size: 13px; }
        .panel {
            margin-top: 18px; background: white; border: 1px solid var(--line);
            border-radius: 12px; padding: 22px;
        }
        .panel h3 { margin: 0 0 18px; }
        .flow { display: grid; grid-template-columns: 1fr 90px 1fr 90px 1fr; align-items: center; }
        .node {
            border: 1px solid var(--line); border-radius: 10px;
            min-height: 125px; padding: 18px; text-align: center;
            display: grid; align-content: center; gap: 8px;
        }
        .node strong { font-size: 20px; }
        .node.solar { background: #fff8e8; }
        .node.house { background: #effaf8; }
        .node.grid { background: #eff4fb; }
        .arrow { text-align: center; color: #94a3b8; font-size: 28px; }
        .details { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 18px; }
        .detail { padding: 12px 14px; background: #f8fafc; border-radius: 8px; }
        .detail span { display: block; color: var(--muted); font-size: 12px; margin-bottom: 4px; }
        .detail strong { font-size: 14px; }
        .error { padding: 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; }
        @media (max-width: 760px) {
            header { padding: 14px 18px; }
            .live { display: none; }
            main { padding: 20px 14px; }
            .toolbar { align-items: flex-start; flex-direction: column; }
            .cards { grid-template-columns: 1fr; }
            .flow { grid-template-columns: 1fr; gap: 8px; }
            .arrow { transform: rotate(90deg); height: 38px; }
            .details { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<header>
    <div class="brand">
        <div class="brand-mark">OV</div>
        <h1>Ondulateur virtuel</h1>
    </div>
    <div class="live">Surveillance energetique &bull; Mode simulation</div>
</header>
<main>
    <section class="toolbar">
        <div>
            <h2>Tableau de bord</h2>
            <p><?= htmlspecialchars($scenarios[$scenario]['label']) ?> - <?= $systemTime ?></p>
        </div>
        <nav class="scenario-select" aria-label="Choisir un scenario">
            <?php foreach ($scenarios as $key => $scenarioInfo): ?>
                <a class="<?= $key === $scenario ? 'active' : '' ?>" href="?scenario=<?= urlencode($key) ?>">
                    <?= htmlspecialchars($scenarioInfo['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </section>

    <?php if ($error): ?>
        <div class="error">Impossible de charger les donnees de simulation.</div>
    <?php else: ?>
        <section class="cards">
            <article class="card">
                <div class="card-label">Production solaire</div>
                <div class="card-value"><?= number_format($solar / 1000, 2, ',', ' ') ?> kW</div>
                <div class="card-foot">Puissance DC entree</div>
            </article>
            <article class="card">
                <div class="card-label">Puissance du reseau</div>
                <div class="card-value <?= $gridClass ?>"><?= $gridText ?></div>
                <div class="card-foot"><?= $gridDirection ?></div>
            </article>
            <article class="card">
                <div class="card-label">Consommation</div>
                <div class="card-value"><?= number_format($consumption / 1000, 2, ',', ' ') ?> kW</div>
                <div class="card-foot">Charge actuelle du foyer</div>
            </article>
        </section>

        <section class="panel">
            <h3>Flux energetiques</h3>
            <div class="flow">
                <div class="node solar">
                    <span>Panneaux solaires</span>
                    <strong><?= number_format($solar / 1000, 2, ',', ' ') ?> kW</strong>
                </div>
                <div class="arrow">&rarr;</div>
                <div class="node house">
                    <span>Consommation</span>
                    <strong><?= number_format($consumption / 1000, 2, ',', ' ') ?> kW</strong>
                </div>
                <div class="arrow">&harr;</div>
                <div class="node grid">
                    <span>Reseau</span>
                    <strong><?= $gridText ?></strong>
                </div>
            </div>
            <div class="details">
                <div class="detail"><span>Etat de l'ondulateur</span><strong><?= $status ?></strong></div>
                <div class="detail"><span>Numero de serie</span><strong><?= $serial ?></strong></div>
                <div class="detail"><span>Heure du releve</span><strong><?= $systemTime ?></strong></div>
            </div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
