<?php
/* Konfiguration des Obsidian Vault Viewers.
   Diese Datei gibt ein Array zurueck und enthaelt keine Geheimnisse
   (Zugangsschutz laeuft ueber .htaccess). */

return [
    // Titel in Kopfzeile und Browser-Tab
    'SITE_TITLE'   => 'Vault',

    // Pfad zum Vault-Ordner (Ziel des naechtlichen Uploads).
    // Relativ zum Viewer-Ordner (z.B. 'vault') ODER absoluter Pfad (z.B. '/var/www/vault').
    'VAULT_DIR'    => 'vault',

    // Startseite: vault-relativer Pfad zur Landing-Notiz,
    // z.B. 'Startseite.md' oder '🏡 Home.md'.
    // Leer lassen ('') = automatisch erkennen (Home/INDEX/README/… oder erste Notiz).
    'HOME'         => '',

    // true  = saubere URLs via mod_rewrite  (…/Ordner/Notiz.md)
    // false = Fallback ueber Query-Parameter (…/index.php?p=Ordner/Notiz.md)
    //         nutzen, falls mod_rewrite auf dem Server nicht verfuegbar ist
    'REWRITE_URLS' => true,
];
