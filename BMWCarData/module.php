<?php

declare(strict_types=1);

/**
 * IP-Symcon Modul für BMW CarData (Elektrofahrzeug) - MQTT-Streaming-Variante
 *
 * Authentifizierung: OAuth 2.0 Device Code Flow mit PKCE (S256)
 * Daten:             BMW CarData Stream (MQTT, Echtzeit-Push, KEIN 50/Tag-Limit)
 *
 * Architektur:
 *   Dieses Modul ist ein KIND eines IP-Symcon "MQTT Client" (I/O).
 *   - Der MQTT Client hält die persistente TLS-Verbindung zu BMW.
 *   - Dieses Modul verwaltet die Tokens (Device Flow + Refresh) und schiebt das
 *     stündlich neue id_token automatisch als Passwort in den MQTT Client.
 *   - Eingehende MQTT-Nachrichten werden in ReceiveData() geparst.
 *
 * MQTT-Datenfluss-GUIDs: RX (Parent->Modul) {7F7632D9-FA40-4F38-8DEA-C83CD4325A32},
 *                        TX (Modul->Parent) {043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}
 */
class BMWCarData extends IPSModule
{
    // OAuth / GCDM Endpunkte
    private const OAUTH_DEVICE_CODE = 'https://customer.bmwgroup.com/gcdm/oauth/device/code';
    private const OAUTH_TOKEN       = 'https://customer.bmwgroup.com/gcdm/oauth/token';

    // Streaming (MQTT) Zugangsdaten
    private const STREAM_HOST = 'customer.streaming-cardata.bmwgroup.com';
    private const STREAM_PORT = 9000;

    // Für das Streaming benötigter Scope
    private const SCOPE = 'authenticate_user openid cardata:streaming:read';

    // IP-Symcon MQTT Client Modul-GUID (als übergeordnetes Gateway)
    private const MQTT_CLIENT_GUID = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';

    /**
     * Telematik-Schlüssel für ein Elektrofahrzeug.
     * Variablen-Ident => [Typ, Telematik-Key (Descriptor), Profil, Name]
     * Typen: 0 = Boolean, 1 = Integer, 2 = Float, 3 = String
     */
    private function GetDataMap(): array
    {
        return [
            'SoC'                 => [2, 'vehicle.powertrain.electric.battery.stateOfCharge.displayed', 'BMWCD.SoC',   'Ladezustand'],
            'TargetSoC'           => [2, 'vehicle.powertrain.electric.battery.stateOfCharge.target',    'BMWCD.SoC',   'Ziel-Ladezustand'],
            'Range'               => [2, 'vehicle.drivetrain.electricEngine.kombiRemainingElectricRange', 'BMWCD.Range', 'Elektrische Reichweite'],
            'ChargingStatus'      => [3, 'vehicle.drivetrain.electricEngine.charging.status',           '',            'Ladestatus'],
            'ChargingHVStatus'    => [3, 'vehicle.drivetrain.electricEngine.charging.hvStatus',         '',            'Hochvolt-Status'],
            'ChargeTimeRemaining' => [1, 'vehicle.drivetrain.electricEngine.charging.timeRemaining',    'BMWCD.Time',  'Restladezeit'],
            'ChargingPort'        => [3, 'vehicle.body.chargingPort.status',                            '',            'Ladeanschluss'],
            'Preconditioning'     => [3, 'vehicle.cabin.hvac.preconditioning.status.comfortState',      '',            'Vorklimatisierung'],
            'Mileage'             => [2, 'vehicle.travelledDistance',                                    'BMWCD.Range', 'Kilometerstand'],
        ];
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyInteger('RefreshInterval', 50); // Minuten (Token < 60 min gültig)

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('IDToken', '');
        $this->RegisterAttributeInteger('TokenExpiry', 0);
        $this->RegisterAttributeString('DeviceCode', '');
        $this->RegisterAttributeString('CodeVerifier', '');
        $this->RegisterAttributeString('GCID', '');
        $this->RegisterAttributeString('Scope', '');

        $this->RegisterTimer('RefreshToken', 0, 'BMWCD_RefreshToken($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetupProfiles();
        $this->SetupVariables();

        // Nur MQTT-Nachrichten für unsere VIN durchlassen (Filter auf das gesamte Datenpaket)
        $vin = trim($this->ReadPropertyString('VIN'));
        if ($vin !== '') {
            $this->SetReceiveDataFilter('.*' . preg_quote($vin) . '.*');
        } else {
            $this->SetReceiveDataFilter('.*\x00NOMATCH\x00.*');
        }

        // Token-Refresh-Timer
        $connected = $this->ReadAttributeString('RefreshToken') !== '';
        $interval  = $this->ReadPropertyInteger('RefreshInterval');
        $this->SetTimerInterval('RefreshToken', $connected ? max(5, min(59, $interval)) * 60 * 1000 : 0);

        $this->UpdateStatus();
    }

    /**
     * Bietet den IP-Symcon MQTT Client als übergeordnetes Gateway an.
     */
    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type'      => 'connect',
            'moduleIDs' => [self::MQTT_CLIENT_GUID],
        ]);
    }

    // =====================================================================
    //  Profile & Variablen
    // =====================================================================

    private function SetupProfiles(): void
    {
        if (!IPS_VariableProfileExists('BMWCD.SoC')) {
            IPS_CreateVariableProfile('BMWCD.SoC', 2);
            IPS_SetVariableProfileValues('BMWCD.SoC', 0, 100, 1);
            IPS_SetVariableProfileText('BMWCD.SoC', '', ' %');
            IPS_SetVariableProfileIcon('BMWCD.SoC', 'Battery');
            IPS_SetVariableProfileDigits('BMWCD.SoC', 0);
        }
        if (!IPS_VariableProfileExists('BMWCD.Range')) {
            IPS_CreateVariableProfile('BMWCD.Range', 2);
            IPS_SetVariableProfileValues('BMWCD.Range', 0, 0, 1);
            IPS_SetVariableProfileText('BMWCD.Range', '', ' km');
            IPS_SetVariableProfileIcon('BMWCD.Range', 'Distance');
            IPS_SetVariableProfileDigits('BMWCD.Range', 0);
        }
        if (!IPS_VariableProfileExists('BMWCD.Time')) {
            IPS_CreateVariableProfile('BMWCD.Time', 1);
            IPS_SetVariableProfileText('BMWCD.Time', '', ' min');
            IPS_SetVariableProfileIcon('BMWCD.Time', 'Clock');
        }
    }

    private function SetupVariables(): void
    {
        $pos = 0;
        foreach ($this->GetDataMap() as $ident => $def) {
            [$type, , $profile, $name] = $def;
            switch ($type) {
                case 0: $this->RegisterVariableBoolean($ident, $this->Translate($name), $profile, $pos); break;
                case 1: $this->RegisterVariableInteger($ident, $this->Translate($name), $profile, $pos); break;
                case 2: $this->RegisterVariableFloat($ident, $this->Translate($name), $profile, $pos); break;
                default: $this->RegisterVariableString($ident, $this->Translate($name), $profile, $pos); break;
            }
            $pos++;
        }
        $this->RegisterVariableInteger('LastUpdate', $this->Translate('Letzte Aktualisierung'), '~UnixTimestamp', $pos);
    }

    // =====================================================================
    //  MQTT-Empfang
    // =====================================================================

    /**
     * Wird vom übergeordneten MQTT Client bei eingehenden Nachrichten aufgerufen.
     */
    public function ReceiveData($JSONString)
    {
        $data = json_decode((string) $JSONString);
        if (!is_object($data) || !isset($data->Payload)) {
            return '';
        }

        // IP-Symcon überträgt die MQTT-Payload HEX-kodiert
        $payloadRaw = hex2bin((string) $data->Payload);
        if ($payloadRaw === false) {
            // Fallback: manche Konstellationen liefern die Payload direkt
            $payloadRaw = (string) $data->Payload;
        }
        $msg = json_decode($payloadRaw, true);
        if (!is_array($msg)) {
            $this->SendDebug('ReceiveData', 'Payload nicht als JSON lesbar: ' . substr($payloadRaw, 0, 120), 0);
            return '';
        }

        $descriptors = $msg['data'] ?? [];
        if (!is_array($descriptors) || $descriptors === []) {
            return '';
        }

        $count = 0;
        foreach ($this->GetDataMap() as $ident => $def) {
            [$type, $key] = $def;
            if (!isset($descriptors[$key])) {
                continue;
            }
            $entry = $descriptors[$key];
            $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;
            if ($value === null) {
                continue;
            }
            $this->ApplyValue($ident, $type, $value);
            $count++;
        }

        if ($count > 0) {
            $this->SetValue('LastUpdate', time());
            $this->SendDebug('ReceiveData', $count . ' Werte aktualisiert', 0);
        }
        return '';
    }

    private function ApplyValue(string $ident, int $type, $value): void
    {
        // BMW-Boolean-Notation normalisieren
        if (is_string($value)) {
            $low = strtolower($value);
            if ($low === 'asn_istrue') { $value = true; }
            elseif ($low === 'asn_isfalse') { $value = false; }
            elseif ($low === 'asn_isunknown') { return; }
        }

        switch ($type) {
            case 0:
                $this->SetValue($ident, filter_var($value, FILTER_VALIDATE_BOOLEAN));
                break;
            case 1:
                $this->SetValue($ident, (int) round((float) $value));
                break;
            case 2:
                $this->SetValue($ident, (float) $value);
                break;
            default:
                $this->SetValue($ident, (string) $value);
                break;
        }
    }

    // =====================================================================
    //  Schritt 1: Login starten (Device Code anfordern)
    // =====================================================================

    public function RequestDeviceCode(): string
    {
        $clientID = trim($this->ReadPropertyString('ClientID'));
        if ($clientID === '') {
            echo $this->Translate('Bitte zuerst die Client-ID eintragen und auf "Übernehmen" klicken.');
            return '';
        }

        $verifier  = $this->Base64UrlEncode(random_bytes(48));
        $challenge = $this->Base64UrlEncode(hash('sha256', $verifier, true));
        $this->WriteAttributeString('CodeVerifier', $verifier);

        $params = [
            'client_id'             => $clientID,
            'response_type'         => 'device_code',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'scope'                 => self::SCOPE,
        ];

        $res = $this->HttpForm(self::OAUTH_DEVICE_CODE, $params);
        if ($res['code'] !== 200 || !is_array($res['json'])) {
            $this->LogMessage('Device-Code Anforderung fehlgeschlagen: HTTP ' . $res['code'] . ' ' . $res['body'], KL_ERROR);
            echo $this->Translate('Fehler bei der Anmeldung') . ' (HTTP ' . $res['code'] . "):\n" . $res['body'];
            return '';
        }

        $this->WriteAttributeString('DeviceCode', (string) ($res['json']['device_code'] ?? ''));

        $uri      = (string) ($res['json']['verification_uri_complete'] ?? ($res['json']['verification_uri'] ?? ''));
        $userCode = (string) ($res['json']['user_code'] ?? '');
        $expires  = (int) ($res['json']['expires_in'] ?? 300);

        $this->SetStatus(203);

        echo $this->Translate('Bitte diese Adresse im Browser öffnen und mit den BMW-ID Zugangsdaten anmelden:') . "\n\n"
           . $uri . "\n\n"
           . $this->Translate('User-Code') . ': ' . $userCode . "\n\n"
           . sprintf($this->Translate('Der Code ist %d Sekunden gültig. Danach auf "2. Login abschließen" klicken.'), $expires);
        return $uri;
    }

    // =====================================================================
    //  Schritt 2: Login abschließen (Token abholen)
    // =====================================================================

    public function CompleteLogin(): bool
    {
        $clientID   = trim($this->ReadPropertyString('ClientID'));
        $deviceCode = $this->ReadAttributeString('DeviceCode');
        $verifier   = $this->ReadAttributeString('CodeVerifier');

        if ($clientID === '' || $deviceCode === '' || $verifier === '') {
            echo $this->Translate('Bitte zuerst "1. Login starten" ausführen.');
            return false;
        }

        $params = [
            'client_id'     => $clientID,
            'device_code'   => $deviceCode,
            'grant_type'    => 'urn:ietf:params:oauth:grant-type:device_code',
            'code_verifier' => $verifier,
        ];

        $res = $this->HttpForm(self::OAUTH_TOKEN, $params);
        if ($res['code'] !== 200 || !is_array($res['json'])) {
            $err = is_array($res['json']) ? (string) ($res['json']['error'] ?? '') : '';
            if ($err === 'authorization_pending') {
                echo $this->Translate('Die Anmeldung im Browser ist noch nicht abgeschlossen. Bitte erst im Browser anmelden und dann erneut auf "2. Login abschließen" klicken.');
            } else {
                $this->LogMessage('Token-Abholung fehlgeschlagen: HTTP ' . $res['code'] . ' ' . $res['body'], KL_ERROR);
                echo $this->Translate('Login fehlgeschlagen') . ' (HTTP ' . $res['code'] . "):\n" . $res['body'];
            }
            return false;
        }

        $this->StoreTokens($res['json']);
        $this->WriteAttributeString('DeviceCode', '');
        $this->WriteAttributeString('CodeVerifier', '');

        $interval = $this->ReadPropertyInteger('RefreshInterval');
        $this->SetTimerInterval('RefreshToken', max(5, min(59, $interval)) * 60 * 1000);

        // MQTT-Client vollständig konfigurieren und verbinden
        $this->ConfigureParent(true);

        $this->UpdateStatus();

        $scope = $this->ReadAttributeString('Scope');
        $msg = $this->Translate('Anmeldung erfolgreich!') . "\n\n"
             . $this->Translate('Erteilte Berechtigungen (Scopes)') . ":\n" . $scope . "\n\n";
        if (strpos($scope, 'cardata:streaming:read') === false) {
            $msg .= $this->Translate('ACHTUNG: Der Scope "cardata:streaming:read" fehlt! Bitte im BMW-Portal den Client für "CarData Stream" abonnieren und den Login erneut ausführen.');
        } else {
            $msg .= $this->Translate('Der MQTT-Client wurde konfiguriert. Prüfe die Verbindung im übergeordneten "MQTT Client" und dass dort das Topic abonniert ist (siehe "Verbindungsdaten anzeigen").');
        }
        echo $msg;
        return true;
    }

    private function StoreTokens(array $token): void
    {
        if (isset($token['access_token']))  { $this->WriteAttributeString('AccessToken', (string) $token['access_token']); }
        if (isset($token['refresh_token'])) { $this->WriteAttributeString('RefreshToken', (string) $token['refresh_token']); }
        if (isset($token['id_token']))      { $this->WriteAttributeString('IDToken', (string) $token['id_token']); }
        if (isset($token['gcid']))          { $this->WriteAttributeString('GCID', (string) $token['gcid']); }
        if (isset($token['scope']))         { $this->WriteAttributeString('Scope', (string) $token['scope']); }
        $expiresIn = (int) ($token['expires_in'] ?? 3600);
        $this->WriteAttributeInteger('TokenExpiry', time() + $expiresIn - 60);
    }

    // =====================================================================
    //  Token-Erneuerung (aktualisiert das id_token im MQTT-Client)
    // =====================================================================

    public function RefreshToken(): bool
    {
        $clientID = trim($this->ReadPropertyString('ClientID'));
        $refresh  = $this->ReadAttributeString('RefreshToken');
        if ($clientID === '' || $refresh === '') {
            return false;
        }

        $params = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id'     => $clientID,
        ];

        $res = $this->HttpForm(self::OAUTH_TOKEN, $params);
        if ($res['code'] !== 200 || !is_array($res['json'])) {
            $this->LogMessage('Token-Erneuerung fehlgeschlagen: HTTP ' . $res['code'] . ' ' . $res['body'], KL_ERROR);
            if ($res['code'] === 400 || $res['code'] === 401) {
                $this->WriteAttributeString('RefreshToken', '');
                $this->SetTimerInterval('RefreshToken', 0);
                $this->UpdateStatus();
            }
            return false;
        }

        $this->StoreTokens($res['json']);
        // Neues id_token als Passwort in den MQTT-Client schieben + neu verbinden
        $this->ConfigureParent(false);
        $this->SendDebug('RefreshToken', 'id_token erneuert und an MQTT-Client übergeben', 0);
        return true;
    }

    // =====================================================================
    //  MQTT-Client (Parent) konfigurieren
    // =====================================================================

    private function GetParentID(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return (int) $instance['ConnectionID'];
    }

    /**
     * Schreibt die Zugangsdaten in den übergeordneten MQTT-Client.
     * $applyConnection = true: komplette Verbindung (Host/Port/TLS/User/Passwort),
     * sonst nur das Passwort (id_token) aktualisieren.
     *
     * Die Property-Namen des MQTT-Clients werden zur Laufzeit erkannt, um
     * versionsunabhängig zu funktionieren.
     */
    private function ConfigureParent(bool $applyConnection): bool
    {
        $parentId = $this->GetParentID();
        if ($parentId === 0) {
            $this->LogMessage('Kein MQTT-Client als übergeordnete Instanz gefunden.', KL_WARNING);
            return false;
        }

        $config = json_decode(IPS_GetConfiguration($parentId), true);
        if (!is_array($config)) {
            return false;
        }

        $setFirst = function (array $candidates, $value) use ($parentId, $config): bool {
            foreach ($candidates as $key) {
                if (array_key_exists($key, $config)) {
                    IPS_SetProperty($parentId, $key, $value);
                    return true;
                }
            }
            return false;
        };

        // Passwort = aktuelles id_token (immer aktualisieren)
        $setFirst(['Password', 'Passwort'], $this->ReadAttributeString('IDToken'));

        if ($applyConnection) {
            $setFirst(['Host', 'Server', 'URL', 'Address'], self::STREAM_HOST);
            $setFirst(['Port'], self::STREAM_PORT);
            $setFirst(['UseSSL', 'UseTLS', 'TLS', 'Encryption', 'UseEncryption'], true);
            $setFirst(['UserName', 'Username', 'User'], $this->ReadAttributeString('GCID'));
        }

        if (IPS_HasChanges($parentId)) {
            IPS_ApplyChanges($parentId);
        }
        return true;
    }

    // =====================================================================
    //  Verbindungsdaten anzeigen (für manuelle Einrichtung des MQTT-Clients)
    // =====================================================================

    public function ShowConnectionInfo(): void
    {
        $gcid = $this->ReadAttributeString('GCID');
        $vin  = trim($this->ReadPropertyString('VIN'));
        $topic = ($gcid !== '' ? $gcid : 'GCID') . '/' . ($vin !== '' ? $vin : 'VIN');

        echo $this->Translate('MQTT-Verbindungsdaten für den übergeordneten "MQTT Client":') . "\n\n"
           . "Host:       " . self::STREAM_HOST . "\n"
           . "Port:       " . self::STREAM_PORT . " (TLS/SSL)\n"
           . "Username:   " . ($gcid !== '' ? $gcid : $this->Translate('(nach Login verfügbar)')) . "\n"
           . "Passwort:   " . $this->Translate('id_token (wird vom Modul automatisch gesetzt/erneuert)') . "\n"
           . "Topic-Abo:  " . $topic . "\n\n"
           . $this->Translate('Trage das Topic-Abo im MQTT-Client unter "Subscriptions" ein. Host/Port/TLS/Username/Passwort werden beim Login automatisch gesetzt.');
    }

    public function ConfigureMQTT(): bool
    {
        if ($this->ReadAttributeString('IDToken') === '') {
            echo $this->Translate('Noch kein Token vorhanden. Bitte zuerst den Login (Schritt 1 + 2) durchführen.');
            return false;
        }
        $ok = $this->ConfigureParent(true);
        echo $ok
            ? $this->Translate('MQTT-Client wurde mit Host, Port, TLS, Username und id_token konfiguriert.')
            : $this->Translate('Konnte den MQTT-Client nicht konfigurieren. Ist eine "MQTT Client"-Instanz als übergeordnetes Gateway verbunden?');
        return $ok;
    }

    // =====================================================================
    //  Status / Hilfsfunktionen
    // =====================================================================

    private function UpdateStatus(): void
    {
        if (trim($this->ReadPropertyString('ClientID')) === '') { $this->SetStatus(201); return; }
        if ($this->ReadAttributeString('RefreshToken') === '')  { $this->SetStatus(202); return; }
        if (trim($this->ReadPropertyString('VIN')) === '')      { $this->SetStatus(204); return; }
        $this->SetStatus(102);
    }

    private function Base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function HttpForm(string $url, array $params): array
    {
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->SendDebug('HTTP', $url . ' -> cURL Fehler: ' . $error, 0);
            return ['code' => 0, 'body' => $error, 'json' => null];
        }
        $this->SendDebug('HTTP', $url . ' -> ' . $code, 0);
        $json = json_decode((string) $response, true);
        return [
            'code' => $code,
            'body' => (string) $response,
            'json' => (json_last_error() === JSON_ERROR_NONE) ? $json : null,
        ];
    }
}
