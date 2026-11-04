# Persoenlicher Mitgliederausweis (04.11.2026)

Die originale `MemberIdCardScreen` wird weiterverwendet: frische Mitgliederdaten,
grafische Karte und ausdruecklicher PNG-Export ueber den nativen Teilen-Dialog.
Kein neuer Walletpass, kein Portrait und kein amtlicher oder kryptografisch
verifizierbarer Ausweis werden erzeugt. Das Original benutzt fuer diese Karte
keinen Portraitdownload. Fehlende Status-/Artlabels erscheinen als fehlend und
werden nicht durch erfundene Mitgliedschaften ersetzt.

`GET /api/mobile/v1/clubs/{tenant}/members/{member}/id-card` liefert nur ID,
Mandanten-ID/-Name, Vor-/Nachname, Geburt, echte Status-/Artlabels, Ehren-/
Freimitglied und SHA256-SourceVersion. Keine Kontakte, AHV, Eltern-, Pass-,
Rechnungs-, Portrait- oder Anhangdaten. `Cache-Control: private, no-store`.
Serverrechte: bestehender persoenlicher Mandantenzutritt, echter Dossierscope
und eigene/aktuelle Elternvertretung oder `members.view`. Trainer-Listenrecht
allein genuegt nicht; Erwachsenenstatus, widerrufene Elternlinks und Sessions
werden erneut geprueft. SourceVersion bindet zusaetzlich die echten Lookuplabels
und den Mandantennamen an den Mitgliederstand.

Readiness: `native_member_id_card_enabled` / environment
`CLUBUNIO_MOBILE_NATIVE_MEMBER_ID_CARD_ENABLED`, standardmaessig false.
Der Dossierlink benoetigt zusaetzlich `member-id-card-v1` als reine
Clientkompatibilitaet. Dieser Header ersetzt keine Serverrechte. Keine Migration,
neuen Abhaengigkeiten oder Providerkosten. Root integriert und deployt separat.

Die Karte zeigt den echten Mandantennamen, eine neutrale Ausweisgrafik und die
konfigurierte App-Primaerfarbe (ClubUnio standardmaessig Blau). Es wird kein
ClubUnio-App-Logo als Vereinslogo ausgegeben und kein fixes SVS-Logo genutzt.
Eine echte konfigurierbare Vereinsmarke ist in diesem Vertragsumfang nicht
vorhanden; deshalb der klare neutrale Fallback.

Vor PNG-Export werden Rechte und Daten frisch geladen; nach dem Rendern wird
der Quellstand erneut bestaetigt. Fehler, Kontextwechsel oder neue SourceVersion
verhindern die Weitergabe. Private Altstaende und initialMember-Ersatzdaten
werden bei persoenlichen Ladefehlern verworfen. Die vorhandene Originalmaske
bleibt auch fuer alte Clients kompatibel.

Nachweis: sechs synthetische, zurueckgerollte PostgreSQL-/HTTP-Dossierfaelle mit
125 Assertions, darunter zwei neue Ausweisfaelle; keine Datenbankauffrischung,
DDL oder echten Mitgliederaktionen. Flutter prueft den tatsaechlichen SDK-Pfad,
Mandanten-/Mitgliederbindung, die Originalkarte und Widerruf vor Export.
Freigabegate `deploy/check-mobile-member-id-card.php --foundation` bzw.
`--enabled` liest keine privaten Mitgliederdaten. Native Gesamt-/Shareabnahme
auf einem echten Geraet bleibt ein eigener Abnahmeschritt.
