<?php

/**
 * Povezava z bazo (PDO). Nastavitve se preberejo iz spremenljivk okolja
 * (glej .env.example / compose.yaml). Znotraj Dockerja je gostitelj ime storitve "baza".
 */

function scholixEnv($ime, $privzeto)
{
    $v = getenv($ime);
    return ($v === false || $v === "") ? $privzeto : $v;
}

$host = scholixEnv("DB_HOST", "baza");
$port = scholixEnv("DB_PORT", "3306");
$dbname = scholixEnv("DB_NAME", "sola");
$username = scholixEnv("DB_USER", "root");
$password = scholixEnv("DB_PASSWORD", "");

try {
    $conn = null;
    $zadnjaNapaka = null;

    // MySQL je lahko tik po zagonu še nedosegljiv – nekajkrat poskusi znova.
    for ($poskus = 1; $poskus <= 10; $poskus++) {
        try {
            $conn = new PDO(
                "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5,
                ]
            );
            break;
        } catch (PDOException $ex) {
            $zadnjaNapaka = $ex;
            sleep(1);
        }
    }

    if (!$conn) {
        throw $zadnjaNapaka;
    }

    // Tabele ustvari inicializacijska storitev (Baza_Scholix.sql); spodaj so le varne, ponovljive dopolnitve.
    $conn->exec("
        CREATE TABLE IF NOT EXISTS Pisno_Ocenjevanje (
            id_ocenjevanje INT AUTO_INCREMENT PRIMARY KEY,
            datum DATE NOT NULL,
            ura INT NOT NULL,
            predmet_id INT NOT NULL,
            ucitelj_id INT NOT NULL,
            oddelek_id INT NOT NULL,
            naslov VARCHAR(150) NOT NULL,
            opis VARCHAR(255) NULL,
            ustvarjeno DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ocenjevanje_datum (datum),
            FOREIGN KEY (predmet_id) REFERENCES Predmet(id_predmet) ON DELETE CASCADE,
            FOREIGN KEY (ucitelj_id) REFERENCES Uporabnik(id_uporabnik) ON DELETE CASCADE,
            FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Zagotovi stolpca vrsta_ocenjevanja in ocenjevanje_id v tabeli Ocena
    $checkVrstaOcene = $conn->query("SHOW COLUMNS FROM Ocena LIKE 'vrsta_ocenjevanja'")->fetch();
    if (!$checkVrstaOcene) {
        $conn->exec("ALTER TABLE Ocena ADD COLUMN vrsta_ocenjevanja VARCHAR(50) NOT NULL DEFAULT 'Pisno ocenjevanje' AFTER ocena");
        $conn->exec("ALTER TABLE Ocena ADD COLUMN ocenjevanje_id INT NULL AFTER opis");
    }

    // Razredna ura ni ocenjevani predmet – odstrani morebitne ocene, pisna ocenjevanja in vpise dijakov za Razredno uro
    $razrednaUraIds = $conn->query("SELECT id_predmet FROM Predmet WHERE LOWER(naziv) = 'razredna ura'")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($razrednaUraIds)) {
        $ruPlaceholders = implode(",", array_map("intval", $razrednaUraIds));
        $conn->exec("DELETE FROM Ocena WHERE predmet_id IN ($ruPlaceholders)");
        $conn->exec("DELETE FROM Pisno_Ocenjevanje WHERE predmet_id IN ($ruPlaceholders)");
        $conn->exec("DELETE FROM Dijak_Predmet WHERE predmet_id IN ($ruPlaceholders)");
        $conn->exec("DELETE FROM Ucitelj_Predmet WHERE predmet_id IN ($ruPlaceholders)");
    }

    // Če je v bazi zelo malo ocen, napolni začetne ocene za dijake pri njihovih vpisanih predmetih
    $stOcenBaza = (int) $conn->query("SELECT COUNT(*) FROM Ocena")->fetchColumn();
    if ($stOcenBaza < 20) {
        $vsiDijakiPredmeti = $conn->query("
            SELECT dp.dijak_id, dp.predmet_id, u.oddelek_id, p.naziv AS predmet_naziv
            FROM Dijak_Predmet dp
            JOIN Uporabnik u ON u.id_uporabnik = dp.dijak_id
            JOIN Predmet p ON p.id_predmet = dp.predmet_id
            WHERE u.vloga = 'dijak' AND LOWER(p.naziv) <> 'razredna ura'
            ORDER BY dp.dijak_id, dp.predmet_id
        ")->fetchAll();

        $uciteljiPoPredmetuOddeleku = [];
        foreach ($conn->query("SELECT predmet_id, oddelek_id, ucitelj_id FROM Urnik WHERE oddelek_id IS NOT NULL")->fetchAll() as $urRow) {
            $uciteljiPoPredmetuOddeleku[(int) $urRow["predmet_id"] . "_" . (int) $urRow["oddelek_id"]] = (int) $urRow["ucitelj_id"];
        }
        $uciteljiPoPredmetu = [];
        foreach ($conn->query("SELECT predmet_id, ucitelj_id FROM Ucitelj_Predmet")->fetchAll() as $upRow) {
            if (!isset($uciteljiPoPredmetu[(int) $upRow["predmet_id"]])) {
                $uciteljiPoPredmetu[(int) $upRow["predmet_id"]] = (int) $upRow["ucitelj_id"];
            }
        }

        $stmtCheckOcenaObstaja = $conn->prepare("SELECT COUNT(*) FROM Ocena WHERE dijak_id = ? AND predmet_id = ?");
        $stmtInsertOcenaSeed = $conn->prepare("
            INSERT INTO Ocena (dijak_id, predmet_id, ucitelj_id, ocena, vrsta_ocenjevanja, naslov_ocene, opis, datum_ocenitve)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $komentarjiPoOceni = [
            5 => "Odlično znanje in brezhibno reševanje nalog.",
            4 => "Zelo dobro razumevanje snovi z manjšimi napakami.",
            3 => "Solidno poznavanje osnovnih pojmov in postopkov.",
            2 => "Doseženi minimalni standardi znanja, priporočena vaja."
        ];

        $perDijakCount = [];
        foreach ($vsiDijakiPredmeti as $dpRow) {
            $dId = (int) $dpRow["dijak_id"];
            $pId = (int) $dpRow["predmet_id"];
            $oId = (int) ($dpRow["oddelek_id"] ?? 0);

            $perDijakCount[$dId] = ($perDijakCount[$dId] ?? 0) + 1;
            if ($perDijakCount[$dId] > 5) {
                continue;
            }

            $stmtCheckOcenaObstaja->execute([$dId, $pId]);
            if ((int) $stmtCheckOcenaObstaja->fetchColumn() > 0) {
                continue;
            }

            $uId = $uciteljiPoPredmetuOddeleku[$pId . "_" . $oId] ?? ($uciteljiPoPredmetu[$pId] ?? 0);
            if ($uId <= 0) {
                continue;
            }

            $ocena1 = (($dId + $pId) % 4) + 2; // 2..5
            $datum1 = date("Y-m-d", strtotime("-" . (10 + (($dId + $pId) % 25)) . " days"));
            $stmtInsertOcenaSeed->execute([
                $dId,
                $pId,
                $uId,
                $ocena1,
                "Pisno ocenjevanje",
                "1. pisno ocenjevanje znanja",
                $komentarjiPoOceni[$ocena1],
                $datum1
            ]);

            if (($dId + $pId) % 2 === 0) {
                $ocena2 = (($dId * 2 + $pId) % 3) + 3; // 3..5
                $datum2 = date("Y-m-d", strtotime("-" . (2 + (($dId + $pId) % 8)) . " days"));
                $stmtInsertOcenaSeed->execute([
                    $dId,
                    $pId,
                    $uId,
                    $ocena2,
                    "Ustno ocenjevanje",
                    "Ustni odgovor in zagovor vaje",
                    $komentarjiPoOceni[$ocena2],
                    $datum2
                ]);
            }
        }
    }

    // Zagotovi stolpce za kabinet, telefon in govorilne ure pri profesorjih (SŠ KER)
    $checkKabinet = $conn->query("SHOW COLUMNS FROM Uporabnik LIKE 'kabinet'")->fetch();
    if (!$checkKabinet) {
        $conn->exec("ALTER TABLE Uporabnik ADD COLUMN kabinet VARCHAR(50) NULL");
        $conn->exec("ALTER TABLE Uporabnik ADD COLUMN telefon VARCHAR(50) NULL");
        $conn->exec("ALTER TABLE Uporabnik ADD COLUMN govorilne_ure VARCHAR(150) NULL");
    }

    $missingKabinetCount = (int) $conn->query("SELECT COUNT(*) FROM Uporabnik WHERE vloga = 'ucitelj' AND kabinet IS NULL")->fetchColumn();
    if ($missingKabinetCount > 0) {
        $kerData = [
            ["Laznik", "Anita", "E13A", "03 428 58 55", "po dogovoru"],
            ["Jančič", "Jernej", "BE01", "03 428 58 40", "po dogovoru"],
            ["Verdev", "Tjaša", "D02", "03 428 58 51", "torek, od 8.00 do 8.45"],
            ["Besednjak", "Nataša", "E12", "03 428 58 58", "sreda, od 10.30 do 11.15"],
            ["Boh", "Eva", "E12", "03 428 58 58", "ponedeljek, od 8.50 do 9.35"],
            ["Božič", "Sara", "A32B", "03 428 58 77", "po dogovoru"],
            ["Breznik", "Rosana", "E12", "03 428 58 58", "sreda, od 12.10 do 12.55"],
            ["Dolinar", "Taja", "A32B", "03 428 58 77", "po dogovoru"],
            ["Drame", "Matjaž", "A29A", "03 428 58 78", "petek, od 9.40 do 10.25"],
            ["Drofenik", "Irena", "A29A", "03 428 58 78", "petek, od 10.30 do 11.15"],
            ["Ferenc", "Aleksandra", "A29A", "03 428 58 78", "torek, od 10.30 do 11.15"],
            ["Fidler", "Boštjan", "AE05", "03 428 58 79", "petek, od 11.20 do 12.00"],
            ["Gobec", "Igor", "ŠVZE", "03 428 58 18", "sreda, od 8.50 do 9.30"],
            ["Grilc", "Andrej", "BE08", "03 428 58 57", "ponedeljek, od 10.30 do 11.15"],
            ["Herman", "Bojan", "A12", "03 428 58 09", "četrtek, od 9.40 do 10.25"],
            ["Holobar", "Matic", "AE06", "03 428 58 74", "sreda, od 8.00 do 8.45"],
            ["Hrastnik", "Valentina", "D02", "03 428 58 51", "torek, od 9.40 do 10.25"],
            ["Jelenko", "Tanja", "E11", "03 428 58 36", "petek, od 9.40 do 10.25"],
            ["Jug", "Mihela", "A29A", "03 428 58 78", "torek, od 9.40 do 10.25"],
            ["Kališek", "Matej", "AE05", "03 428 58 79", "torek, od 12.10 do 12.55"],
            ["Klepej Viher", "Helenca", "E12", "03 428 58 58", "sreda, od 9.40 do 10.25"],
            ["Klovar", "Sebastian", "A29A", "03 428 58 78", "ponedeljek, od 8.50 do 9.35"],
            ["Kolar", "Katja", "E11", "03 428 58 36", "ponedeljek, od 9.40 do 10.25"],
            ["Koren", "Jaka", "BE08", "03 428 58 57", "ponedeljek, od 9.40 do 10.25"],
            ["Koštomaj Gašperšič", "Marjetica", "D07", "03 428 58 04", "torek, od 9.40 do 10.25"],
            ["Kramer", "Gregor", "VSŠA1", "03 428 57 56", "sreda, od 8.50 do 9.35"],
            ["Križan", "Jerneja", "B15", "03 428 58 23", "torek, od 10.30 do 11.15"],
            ["Lilija", "Boštjan", "E11", "03 428 58 36", "sreda, od 8.50 do 9.35"],
            ["Lubej", "Boštjan", "AE05", "03 428 58 79", "sreda, od 10.30 do 11.15"],
            ["Lukner", "Mitja", "ŠVZE", "03 428 58 18", "ponedeljek, od 10.30 do 11.15"],
            ["Omerzel", "Martina", "E12", "03 428 58 58", "torek, od 8.50 do 9.35"],
            ["Padarič", "Sara", "ŠVZE", "03 428 58 18", "sreda, od 8.50 do 9.35"],
            ["Parežnik", "Polona", "D02", "03 428 58 51", "petek, od 13.00 do 13.45"],
            ["Plantev", "Marija", "E11", "03 428 58 36", "ponedeljek, od 8.50 do 9.35"],
            ["Povše", "Darja", "B07", "03 428 58 21", "torek, od 9.40 do 10.25"],
            ["Preložnik", "David", "C31", "03 428 58 42", "torek, od 8.50 do 9.35"],
            ["Pušelc", "Žiga", "BE08", "03 428 58 57", "ponedeljek, od 9.40 do 10.25"],
            ["Pušnik", "Andraž", "AE06", "03 428 58 74", "sreda, od 8.50 do 9.35"],
            ["Ravnak", "Stane", "BE08", "03 428 58 57", "ponedeljek, od 9.40 do 10.25"],
            ["Remic", "Tajda", "E11", "03 428 58 36", "po dogovoru"],
            ["Resinovič", "Boštjan", "BE08", "03 428 58 57", "sreda, od 11.20 do 12.05"],
            ["Rozman", "Aljaž", "AE06", "03 428 58 74", "torek, od 9.40 do 10.25"],
            ["Selič Turnšek", "Meta", "D02", "03 428 58 51", "četrtek, od 11.20 do 12.05"],
            ["Sirk", "Tilen", "BE08", "03 428 58 57", "sreda, od 10.30 do 11.15"],
            ["Slemenšek", "Borut", "B04", "03 428 58 19", "četrtek, od 9.40 do 10.25"],
            ["Špur Jereb", "Klavdija", "E12", "03 428 58 58", "ponedeljek, od 11.20 do 12.05"],
            ["Štefane", "Jure", "A32B", "03 428 58 77", "po dogovoru"],
            ["Stropnik", "Aljaž", "E11", "03 428 58 36", "po dogovoru"],
            ["Tkalec", "Andreja", "D02", "03 428 58 51", "sreda, od 9.40 do 10.25"],
            ["Trunkl", "Metod", "BE08", "03 428 58 57", "ponedeljek, od 10.25 do 11.15"],
            ["Vrečko", "Marko", "AE05", "03 428 58 79", "četrtek, od 12.10 do 12.55"],
            ["Zupanc", "Davor", "AE05", "03 428 58 79", "torek, od 9.40 do 10.25"],
            ["Žveglič", "Oskar", "BE08", "03 428 58 57", "ponedeljek, od 10.30 do 11.15"]
        ];
        $stmtUpdKer = $conn->prepare("
            UPDATE Uporabnik
            SET kabinet = ?, telefon = ?, govorilne_ure = ?
            WHERE vloga = 'ucitelj' AND priimek = ? AND ime = ? AND kabinet IS NULL
        ");
        foreach ($kerData as [$priimek, $ime, $kabinet, $telefon, $govorilne]) {
            $stmtUpdKer->execute([$kabinet, $telefon, $govorilne, $priimek, $ime]);
        }
    }

} catch (PDOException $e) {
    error_log("Scholix: napaka pri povezavi z bazo: " . $e->getMessage());
    http_response_code(500);
    die("Napaka pri povezavi z bazo. Poskusite znova čez nekaj trenutkov.");
}

?>