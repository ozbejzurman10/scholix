<?php
session_start();
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spletna učilnica</title>
    <meta name="description" content="Spletna učilnica za učitelje in dijake.">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main>
        <section class="hero">
            <div class="hero-inner">
                <div class="role-badge">
                    <span class="school-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M3 10.5 12 5l9 5.5-9 5-9-5Z"/>
                            <path d="M6.5 12.5V17c3 2.2 8 2.2 11 0v-4.5"/>
                            <path d="M21 10.5V16"/>
                        </svg>
                    </span>
                    Za učitelje in dijake
                </div>

                <h1>Spletna učilnica</h1>
                <p class="hero-text">
                    Urnik, predmeti, domače naloge, ocene in izostanki – vse na enem mestu,
                    pregledno za vsak razred in vsakega dijaka.
                </p>

                <div class="hero-actions">
                    <a class="btn btn-primary" href="login.php">Prijava</a>
                    <a class="btn btn-outline" href="register.php">Registracija učitelja</a>
                </div>
            </div>
        </section>

        <section class="modules-section">
            <div class="modules-container">
                <h2>Moduli aplikacije</h2>

                <div class="module-grid">
                    <?php
                    $modules = [
                        ['title' => 'Urnik', 'text' => 'Pregled ur po dnevih za vsako učilnico in dijaka.', 'icon' => 'calendar'],
                        ['title' => 'Predmeti', 'text' => 'Predmeti, povezani z učilnicami in urnikom.', 'icon' => 'book'],
                        ['title' => 'Naloge', 'text' => 'Učitelj objavi nalogo, dijak jo odda v aplikaciji.', 'icon' => 'clipboard'],
                        ['title' => 'Ocene', 'text' => 'Vpis ocen in samodejno izračunano povprečje.', 'icon' => 'cap'],
                        ['title' => 'Izostanki', 'text' => 'Evidenca odsotnosti z opravičili in zamudami.', 'icon' => 'person'],
                        ['title' => 'Učilnice', 'text' => 'Skupine dijakov, ki jih ustvari in ureja učitelj.', 'icon' => 'school']
                    ];

                    foreach ($modules as $module):
                    ?>
                        <article class="module-card" tabindex="0" data-module="<?= htmlspecialchars($module['title']) ?>">
                            <div class="module-icon">
                                <?php include __DIR__ . '/icons/' . $module['icon'] . '.svg'; ?>
                            </div>
                            <h3><?= htmlspecialchars($module['title']) ?></h3>
                            <p><?= htmlspecialchars($module['text']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </main>

    <div class="toast" id="toast" role="status" aria-live="polite"></div>
    <script src="script.js"></script>
</body>
</html>
