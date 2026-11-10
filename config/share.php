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
    ],

    // Optionaler Gate-Name, z. B. 'share-files' – sonst nur Login erforderlich
    'gate' => null,

    'route_prefix' => 'nfshare',
];
