<?php

declare(strict_types=1);

/**
 * IP-Symcon Modul für BMW CarData (Elektrofahrzeuge)
 *
 * Authentifizierung: OAuth 2.0 Device Code Flow mit PKCE (S256)
 * Daten:             REST-API von BMW CarData (api-cardata.bmwgroup.com)
 *
 * Wichtig: Die BMW CarData REST-API erlaubt nur max. 50 Aufrufe pro 24 Stunden.
 * Das Aktualisierungsintervall ist deshalb standardmäßig konservativ (60 Minuten).
 */
class BMWCarData extends IPSModule
{
    // OAuth / GCDM Endpunkte
    private const OAUTH_DEVICE_CODE = 'https://customer.bmwgroup.com/gcdm/oauth/device/code';
    private const OAUTH_TOKEN       = 'https://customer.bmwgroup.com/gcdm/oauth/token';

    // REST-API Basis
    private const API_BASE = 'https://api-cardata.bmwgroup.com';

    // Angeforderte Scopes
    private const SCOPE = 'authenticate_user openid cardata:streaming:read cardata:api:read';

    // Version-Header der BMW-API
    private const API_VERSION = 'v1';

    /**
     * Telematik-Schlüssel, die für ein Elektrofahrzeug relevant sind.
     * Aufbau je Eintrag: Variablen-Ident => [Typ, Telematik-Key, Profil, Name]
     * Typen: 0 = Boolean, 1 = Integer, 2 = Float, 3 = String
     */
    private function GetDataMap(): array
    {
        return [
            'SoC'                => [2, 'vehicle.powertrain.electric.battery.stateOfCharge.displayed', 'BMWCD.SoC',   'Ladezustand'],
            'TargetSoC'          => [2, 'vehicle.powertrain.electric.battery.stateOfCharge.target',    'BMWCD.SoC',   'Ziel-Ladezustand'],
            'Range'              => [2, 'vehicle.drivetrain.electricEngine.kombiRemainingElectricRange', 'BMWCD.Range', 'Elektrische Reichweite'],
            'ChargingStatus'     => [3, 'vehicle.drivetrain.electricEngine.charging.status',           '',            'Ladestatus'],
            'ChargingHVStatus'   => [3, 'vehicle.drivetrain.electricEngine.charging.hvStatus',         '',            'Hochvolt-Status'],
            'ChargeTimeRemaining'=> [1, 'vehicle.drivetrain.electricEngine.charging.timeRemaining',    'BMWCD.Time',  'Restladezeit'],
            'ChargingPort'       => [3, 'vehicle.body.chargingPort.status',                            '',            'Ladeanschluss'],
            'Preconditioning'    => [3, 'vehicle.cabin.hvac.preconditioning.status.comfortState',      '',            'Vorklimatisierung'],
            'Mileage'            => [2, 'vehicle.travelledDistance',                                    'BMWCD.Range', 'Kilometerstand'],
        ];
    }

    public function Create()
    {
        parent::Create();

        // Konfigurations-Eigenschaften
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyString('ContainerID', '');
        $this->RegisterPropertyInteger('UpdateInterval', 60); // Minuten

        // Persistente Speicher (überleben Neustart, werden nicht im Formular gezeigt)
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpiry', 0);
        $this->RegisterAttributeString('DeviceCode', '');
        $this->RegisterAttributeString('CodeVerifier', '');
        $this->RegisterAttributeString('AutoContainerID', '');
        $this->RegisterAttributeString('GCID', '');
        $this->RegisterAttributeString('Scope', '');

        $this->RegisterTimer('UpdateData', 0, 'BMWCD_UpdateData($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetupProfiles();
        $this->SetupVariables();

        // Timer nur aktivieren, wenn wir angemeldet sind
        $connected = $this->ReadAttributeString('RefreshToken') !== '';
        $interval  = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateData', $connected ? max(30, $interval) * 60 * 1000 : 0);

        $this->UpdateStatus();
    }

    // =====================================================================
    //  Profile & Variablen
    // =====================================================================

    private function SetupProfiles(): void
    {
        if (!IPS_VariableProfileExists('BMWCD.SoC')) {
            IPS_CreateVariableProfile('BMWCD.SoC', 2); // Float
            IPS_SetVariableProfileValues('BMWCD.SoC', 0, 100, 1);
            IPS_SetVariableProfileText('BMWCD.SoC', '', ' %');
            IPS_SetVariableProfileIcon('BMWCD.SoC', 'Battery');
            IPS_SetVariableProfileDigits('BMWCD.SoC', 0);
        }

        if (!IPS_VariableProfileExists('BMWCD.Range')) {
            IPS_CreateVariableProfile('BMWCD.Range', 2); // Float
            IPS_SetVariableProfileValues('BMWCD.Range', 0, 0, 1);
            IPS_SetVariableProfileText('BMWCD.Range', '', ' km');
            IPS_SetVariableProfileIcon('BMWCD.Range', 'Distance');
            IPS_SetVariableProfileDigits('BMWCD.Range', 0);
        }

        if (!IPS_VariableProfileExists('BMWCD.Time')) {
            IPS_CreateVariableProfile('BMWCD.Time', 1); // Integer
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
                case 0:
                    $this->RegisterVariableBoolean($ident, $this->Translate($name), $profile, $pos);
                    break;
                case 1:
                    $this->RegisterVariableInteger($ident, $this->Translate($name), $profile, $pos);
                    break;
                case 2:
                    $this->RegisterVariableFloat($ident, $this->Translate($name), $profile, $pos);
                    break;
                default:
                    $this->RegisterVariableString($ident, $this->Translate($name), $profile, $pos);
                    break;
            }
            $pos++;
        }

        // Zeitstempel der letzten erfolgreichen Aktualisierung
        $this->RegisterVariableInteger('LastUpdate', $this->Translate('Letzte Aktualisierung'), '~UnixTimestamp', $pos);
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

        // PKCE: code_verifier + code_challenge (S256) erzeugen
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

        $res = $this->HttpForm('POST', self::OAUTH_DEVICE_CODE, [], $params);
        if ($res['code'] !== 200 || !is_array($res['json'])) {
            $this->LogMessage('Device-Code Anforderung fehlgeschlagen: HTTP ' . $res['code'] . ' ' . $res['body'], KL_ERROR);
            echo $this->Translate('Fehler bei der Anmeldung') . ' (HTTP ' . $res['code'] . "):\n" . $res['body'];
            return '';
        }

        $this->WriteAttributeString('DeviceCode', (string) ($res['json']['device_code'] ?? ''));

        $uri      = (string) ($res['json']['verification_uri_complete'] ?? ($res['json']['verification_uri'] ?? ''));
        $userCode = (string) ($res['json']['user_code'] ?? '');
        $expires  = (int) ($res['json']['expires_in'] ?? 300);

        $this->SetStatus(203); // Login läuft

        $msg = $this->Translate('Bitte diese Adresse im Browser öffnen und mit den BMW-ID Zugangsdaten anmelden:') . "\n\n"
             . $uri . "\n\n"
             . $this->Translate('User-Code') . ': ' . $userCode . "\n\n"
             . sprintf($this->Translate('Der Code ist %d Sekunden gültig. Danach auf "2. Login abschließen" klicken.'), $expires);

        echo $msg;
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

        $res = $this->HttpForm('POST', self::OAUTH_TOKEN, [], $params);

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

        // Aufräumen
        $this->WriteAttributeString('DeviceCode', '');
        $this->WriteAttributeString('CodeVerifier', '');

        // Timer starten
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateData', max(30, $interval) * 60 * 1000);

        $this->UpdateStatus();

        $scope = $this->ReadAttributeString('Scope');
        $msg = $this->Translate('Anmeldung erfolgreich!') . "\n\n"
             . $this->Translate('Erteilte Berechtigungen (Scopes)') . ":\n" . $scope . "\n\n";

        if (strpos($scope, 'cardata:api:read') === false) {
            $msg .= $this->Translate('ACHTUNG: Der Scope "cardata:api:read" fehlt! Das Token ist NICHT für die API autorisiert. '
                . 'Bitte im BMW CarData Portal dem Client die Berechtigung "CarData API" zuweisen, 2-3 Minuten warten '
                . 'und den Login (Schritt 1 + 2) danach erneut ausführen.');
        } else {
            $msg .= $this->Translate('Der Scope "cardata:api:read" ist vorhanden. Als Nächstes "Container einrichten" klicken.');
        }

        echo $msg;
        return true;
    }

    private function StoreTokens(array $token): void
    {
        if (isset($token['access_token'])) {
            $this->WriteAttributeString('AccessToken', (string) $token['access_token']);
        }
        if (isset($token['refresh_token'])) {
            $this->WriteAttributeString('RefreshToken', (string) $token['refresh_token']);
        }
        if (isset($token['gcid'])) {
            $this->WriteAttributeString('GCID', (string) $token['gcid']);
        }
        if (isset($token['scope'])) {
            $this->WriteAttributeString('Scope', (string) $token['scope']);
        }
        $expiresIn = (int) ($token['expires_in'] ?? 3600);
        // 60 Sekunden Sicherheitspuffer
        $this->WriteAttributeInteger('TokenExpiry', time() + $expiresIn - 60);
    }

    // =====================================================================
    //  Token-Erneuerung
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

        $res = $this->HttpForm('POST', self::OAUTH_TOKEN, [], $params);
        if ($res['code'] !== 200 || !is_array($res['json'])) {
            $this->LogMessage('Token-Erneuerung fehlgeschlagen: HTTP ' . $res['code'] . ' ' . $res['body'], KL_ERROR);
            // Refresh-Token evtl. abgelaufen (2 Wochen) -> Neuanmeldung nötig
            if ($res['code'] === 400 || $res['code'] === 401) {
                $this->WriteAttributeString('RefreshToken', '');
                $this->SetTimerInterval('UpdateData', 0);
                $this->UpdateStatus();
            }
            return false;
        }

        $this->StoreTokens($res['json']);
        return true;
    }

    /**
     * Liefert ein gültiges Access-Token (erneuert bei Bedarf).
     */
    private function EnsureAccessToken(): string
    {
        if ($this->ReadAttributeString('RefreshToken') === '') {
            return '';
        }
        if (time() >= $this->ReadAttributeInteger('TokenExpiry')) {
            if (!$this->RefreshToken()) {
                return '';
            }
        }
        return $this->ReadAttributeString('AccessToken');
    }

    // =====================================================================
    //  Container einrichten (definiert die abzurufenden Telematik-Keys)
    // =====================================================================

    public function SetupContainer(): string
    {
        $token = $this->EnsureAccessToken();
        if ($token === '') {
            echo $this->Translate('Nicht angemeldet. Bitte zuerst den Login durchführen.');
            return '';
        }

        $descriptors = [];
        foreach ($this->GetDataMap() as $def) {
            $descriptors[] = $def[1];
        }

        $body = [
            'name'                 => 'IP-Symcon EV #' . $this->InstanceID,
            'purpose'              => 'IP-Symcon Elektrofahrzeug Integration',
            'technicalDescriptors' => array_values(array_unique($descriptors)),
        ];

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'x-version: ' . self::API_VERSION,
        ];

        $res = $this->HttpRaw('POST', self::API_BASE . '/customers/containers/', $headers, json_encode($body));
        if (($res['code'] === 200 || $res['code'] === 201) && is_array($res['json'])) {
            $containerId = (string) ($res['json']['containerId'] ?? ($res['json']['id'] ?? ''));
            if ($containerId !== '') {
                $this->WriteAttributeString('AutoContainerID', $containerId);
                echo $this->Translate('Container erfolgreich erstellt.') . "\n\n"
                   . $this->Translate('Container-ID') . ': ' . $containerId . "\n\n"
                   . $this->Translate('Diese ID wurde automatisch gespeichert. Ein manuelles Eintragen ist nicht nötig.');
                return $containerId;
            }
        }

        $this->LogMessage('Container-Erstellung fehlgeschlagen: HTTP ' . $res['code'] . ' ' . $res['body'], KL_ERROR);
        echo $this->Translate('Container-Erstellung fehlgeschlagen') . ' (HTTP ' . $res['code'] . "):\n" . $res['body']
           . $this->TokenErrorHint($res);
        return '';
    }

    /**
     * Liefert bei einem Token-/Autorisierungsfehler einen erklärenden Hinweistext.
     */
    private function TokenErrorHint(array $res): string
    {
        $body = strtolower($res['body'] ?? '');
        $isTokenError = $res['code'] === 401
            || strpos($body, 'invalid_access_token') !== false
            || strpos($body, 'cu-103') !== false
            || strpos($body, 'not authorized') !== false;

        if (!$isTokenError) {
            return '';
        }

        return "\n\n" . $this->Translate('HINWEIS: Das Token ist nicht für die CarData-API autorisiert. Mögliche Ursachen:') . "\n"
            . $this->Translate('1. Dem Client fehlt im BMW-Portal der Scope "cardata:api:read" (Bereich "CarData API" abonnieren).') . "\n"
            . $this->Translate('2. Die Berechtigung wurde erst NACH dem Login erteilt - dann Login (Schritt 1 + 2) erneut ausführen.') . "\n"
            . $this->Translate('3. BMW braucht einige Minuten, bis neue Berechtigungen wirken - 2-3 Minuten warten und erneut versuchen.');
    }

    private function GetContainerID(): string
    {
        $manual = trim($this->ReadPropertyString('ContainerID'));
        if ($manual !== '') {
            return $manual;
        }
        return $this->ReadAttributeString('AutoContainerID');
    }

    // =====================================================================
    //  Datenabruf
    // =====================================================================

    public function UpdateData(): bool
    {
        $token = $this->EnsureAccessToken();
        if ($token === '') {
            $this->UpdateStatus();
            return false;
        }

        $vin = trim($this->ReadPropertyString('VIN'));
        if ($vin === '') {
            $this->SetStatus(204); // VIN fehlt
            return false;
        }

        $containerId = $this->GetContainerID();
        if ($containerId === '') {
            $this->SetStatus(205); // Container fehlt
            return false;
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'x-version: ' . self::API_VERSION,
        ];

        $url = self::API_BASE . '/customers/vehicles/' . rawurlencode($vin) . '/telematicData?containerId=' . rawurlencode($containerId);

        $res = $this->HttpRaw('GET', $url, $headers);

        if ($res['code'] === 429) {
            $this->LogMessage('BMW CarData Rate-Limit erreicht (50 Aufrufe / 24h). Bitte Intervall vergrößern.', KL_WARNING);
            return false;
        }

        if ($res['code'] !== 200 || !is_array($res['json'])) {
            $this->LogMessage('Datenabruf fehlgeschlagen: HTTP ' . $res['code'] . ' ' . $res['body'], KL_ERROR);
            return false;
        }

        $count = 0;
        foreach ($this->GetDataMap() as $ident => $def) {
            [$type, $key] = $def;
            $value = $this->ExtractTelematic($res['json'], $key);
            if ($value === null) {
                continue;
            }
            $this->ApplyValue($ident, $type, $value);
            $count++;
        }

        $this->SetValue('LastUpdate', time());
        $this->SetStatus(102); // aktiv
        $this->SendDebug('UpdateData', $count . ' Werte aktualisiert', 0);
        return true;
    }

    private function ApplyValue(string $ident, int $type, $value): void
    {
        switch ($type) {
            case 0:
                $v = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                $this->SetValue($ident, (bool) $v);
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

    /**
     * Sucht einen Telematik-Wert in der (evtl. verschachtelten) API-Antwort.
     * Unterstützt sowohl { key: {value: ...} } als auch Listen mit {name:..., value:...}.
     */
    private function ExtractTelematic($data, string $key)
    {
        if (!is_array($data)) {
            return null;
        }

        // Direkter Schlüssel
        if (array_key_exists($key, $data)) {
            return $this->NormalizeEntry($data[$key]);
        }

        // Rekursiv durchsuchen (inkl. Listen mit "name"-Feld)
        foreach ($data as $v) {
            if (is_array($v)) {
                if (isset($v['name']) && $v['name'] === $key) {
                    return $this->NormalizeEntry($v);
                }
                $found = $this->ExtractTelematic($v, $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function NormalizeEntry($entry)
    {
        if (is_array($entry)) {
            return $entry['value'] ?? null;
        }
        return $entry;
    }

    // =====================================================================
    //  Status / Hilfsfunktionen
    // =====================================================================

    private function UpdateStatus(): void
    {
        if (trim($this->ReadPropertyString('ClientID')) === '') {
            $this->SetStatus(201); // Client-ID fehlt
            return;
        }
        if ($this->ReadAttributeString('RefreshToken') === '') {
            $this->SetStatus(202); // nicht angemeldet
            return;
        }
        if (trim($this->ReadPropertyString('VIN')) === '') {
            $this->SetStatus(204); // VIN fehlt
            return;
        }
        if ($this->GetContainerID() === '') {
            $this->SetStatus(205); // Container fehlt
            return;
        }
        $this->SetStatus(102); // aktiv
    }

    private function Base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // =====================================================================
    //  HTTP-Helfer
    // =====================================================================

    private function HttpForm(string $method, string $url, array $headers, array $params): array
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Accept: application/json';
        return $this->HttpRaw($method, $url, $headers, http_build_query($params));
    }

    private function HttpRaw(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->SendDebug('HTTP', $method . ' ' . $url . ' -> cURL Fehler: ' . $error, 0);
            return ['code' => 0, 'body' => $error, 'json' => null];
        }

        $this->SendDebug('HTTP', $method . ' ' . $url . ' -> ' . $code, 0);
        $json = json_decode((string) $response, true);

        return [
            'code' => $code,
            'body' => (string) $response,
            'json' => (json_last_error() === JSON_ERROR_NONE) ? $json : null,
        ];
    }
}
