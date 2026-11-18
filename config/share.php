<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verbindung zum Portal
    |--------------------------------------------------------------------------
    | url:    Adresse, unter der die interne Anwendung die API erreicht. Im Test
    |         die öffentliche Adresse, später ggf. die WireGuard-Adresse des
    |         vServers. Die Download-Links liefert das Portal selbst.
    | token/secret: aus `php artisan portal:client:create` auf dem Portal.
    | verify: TLS-Prüfung; true, false (nur Test!) oder Pfad zu einer CA-Datei.
    */
    'url' => rtrim((string) env('NFSHARE_URL', ''), '/'),
    'token' => env('NFSHARE_TOKEN'),
    'secret' => env('NFSHARE_SECRET'),
    'verify' => env('NFSHARE_VERIFY', true),
    'timeout' => (int) env('NFSHARE_TIMEOUT', 30),

    // Zeitlimit für den Upload einer Datei in Sekunden
    'upload_timeout' => (int) env('NFSHARE_UPLOAD_TIMEOUT', 600),

    /*
    |--------------------------------------------------------------------------
    | Vorgaben im Modal
    |--------------------------------------------------------------------------
    | Das Portal begrenzt die Frist auf höchstens 90 Tage (PORTAL_MAX_EXPIRES_DAYS).
    */
    'defaults' => [
        'expires_days' => (int) env('NFSHARE_EXPIRES_DAYS', 14),
        'max_downloads' => (int) env('NFSHARE_MAX_DOWNLOADS', 5),
        'password' => (bool) env('NFSHARE_PASSWORD', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Freigegebene Dateien
    |--------------------------------------------------------------------------
    | Das Modal wird vom Browser geöffnet; Dateien kommen als disk/path. Deshalb
    | nur aus diesen Disks und Verzeichnissen (Schutz vor Pfad-Manipulation).
    | allowed_prefixes: Liste für alle Disks oder [disk => Liste]; Disk ohne
    | Eintrag = ganze Disk. Einträge mit * sind Muster (wie im Mailer).
    */
    'files' => [
        'allowed_disks' => ['local', 'data'],
        'allowed_prefixes' => [
            'local' => ['akten/', 'rechnungen/', '*akv/files/*', '*akv/mails/*'],
        ],
        'max_files' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Link und Passwort speichern
    |--------------------------------------------------------------------------
    | true: Link und Passwort je Empfänger werden verschlüsselt (APP_KEY) in
    | share_recipients gespeichert und sind in der Übersicht später abrufbar,
    | z. B. für eine erneute Mitteilung. false: nur direkt nach der Freigabe sichtbar.
    */
    'store_secrets' => (bool) env('NFSHARE_STORE_SECRETS', true),

    /*
    |--------------------------------------------------------------------------
    | Abgleich mit dem Portal (php artisan share:sync)
    |--------------------------------------------------------------------------
    | schedule: Das Package trägt den Abgleich selbst in den Scheduler ein
    |           (alle fünf Minuten). false = in routes/console.php selbst eintragen.
    | notify_on_download: Absender per E-Mail benachrichtigen, wenn ein Empfänger
    |           zum ersten Mal eine Datei abruft.
    */
    'sync' => [
        'schedule' => (bool) env('NFSHARE_SCHEDULE', true),
        'notify_on_download' => (bool) env('NFSHARE_NOTIFY_ON_DOWNLOAD', true),
        'notify_mailer' => env('NFSHARE_NOTIFY_MAILER'),  // null = Standard-Mailer
    ],

    /*
    |--------------------------------------------------------------------------
    | Text für die E-Mail an den Empfänger
    |--------------------------------------------------------------------------
    | Wird nach der Freigabe in das Mailer-Modal übernommen (falls installiert)
    | oder zum Kopieren angezeigt. Platzhalter: :name, :subject, :files, :url,
    | :expires, :password_hint. Das Passwort selbst steht nie in der E-Mail.
    */
    'mail' => [
        'subject' => 'Dokumente zum Abruf: :subject',
        'body' => "Guten Tag:name,\n\nüber unser Dokumentenportal stelle ich Ihnen folgende Unterlagen bereit:\n\n:files\n\nAbruf: :url\nAbrufbar bis: :expires\n:password_hint\nDer Download startet erst, wenn Sie auf der Seite auf „Herunterladen“ klicken.",
        'password_hint' => "\nDas Passwort zum Abruf erhalten Sie gesondert.\n",
        // Wird angehängt, wenn zusätzlich Unterlagen angefordert werden (:upload_url, :upload_subject, :upload_expires)
        'upload' => "\nBitte laden Sie außerdem folgende Unterlagen hoch – :upload_subject:\n:upload_url\n(möglich bis :upload_expires)\n",
        // Nur Anforderung, ohne bereitgestellte Dateien
        'request_subject' => 'Bitte um Unterlagen: :subject',
        'request_body' => "Guten Tag:name,\n\nfür die weitere Bearbeitung benötige ich von Ihnen folgende Unterlagen – :upload_subject.\n\nBitte laden Sie diese über unser Dokumentenportal hoch:\n:upload_url\n\nDas Hochladen ist bis :upload_expires möglich. Sie erhalten eine Eingangsbestätigung per E-Mail.",
    ],

    /*
    |--------------------------------------------------------------------------
    | Rückkanal: Unterlagen anfordern und abholen (php artisan share:fetch-uploads)
    |--------------------------------------------------------------------------
    | key_path: Schlüsselpaar für die Versiegelung (php artisan share:keygen).
    |           Der private Schlüssel bleibt hier; das Portal kennt nur den
    |           öffentlichen. Geht die Datei verloren, sind noch nicht abgeholte
    |           Uploads unlesbar – also mitsichern.
    | disk/directory: Ablage der entschlüsselten Dateien ({Y}, {m}, {d} werden ersetzt).
    | files: Mit nfgarching/files werden Uploads stattdessen als Dateien im
    |        Posteingang angelegt (File::storeUpload + Ereignis NewFiles).
    | scan_command: Virenprüfung vor der Ablage, z. B. "clamdscan --no-summary --fdpass {file}".
    |        Exit 0 = sauber, 1 = Fund (Quarantäne), sonst Fehler (später erneut).
    | password_default: Upload-Links mit Passwort vorbelegen. Ein Upload-Link gibt
    |        nichts preis außer Betreff und Hinweis; daher standardmäßig ohne.
    */
    'uploads' => [
        'key_path' => env('NFSHARE_KEY_PATH', storage_path('app/nfshare/upload.key')),
        'disk' => env('NFSHARE_UPLOAD_DISK', 'local'),
        'directory' => env('NFSHARE_UPLOAD_DIRECTORY', 'nfshare/eingang/{Y}/{m}'),
        'quarantine_directory' => 'nfshare/quarantaene',
        'files' => (bool) env('NFSHARE_UPLOADS_TO_FILES', true),
        'scan_command' => env('NFSHARE_SCAN_COMMAND'),
        'expires_days' => (int) env('NFSHARE_UPLOAD_REQUEST_DAYS', 14),
        'max_files' => 10,
        'password_default' => (bool) env('NFSHARE_UPLOAD_PASSWORD', false),
        // Benachrichtigung über Eingänge ohne anfordernden Benutzer (freie Uploads aus dem Konto)
        'notify_address' => env('NFSHARE_UPLOAD_NOTIFY'),
    ],

    // Optionaler Gate-Name, z. B. 'share-files' – sonst nur Login erforderlich
    'gate' => null,

    'route_prefix' => 'nfshare',
];
