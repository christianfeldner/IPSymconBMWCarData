# IP-Symcon Modul: BMW CarData (Elektrofahrzeuge)

Ein IP-Symcon Modul zum Abruf der Fahrzeugdaten eines **elektrischen BMW** über die
offizielle [BMW CarData API](https://bmw-cardata.bmwgroup.com/customer/public/api-specification).

Es werden gezielt die für ein E-Fahrzeug relevanten Werte ausgelesen:

| Variable | Telematik-Schlüssel |
|---|---|
| Ladezustand (SoC) | `vehicle.powertrain.electric.battery.stateOfCharge.displayed` |
| Ziel-Ladezustand | `vehicle.powertrain.electric.battery.stateOfCharge.target` |
| Elektrische Reichweite | `vehicle.drivetrain.electricEngine.kombiRemainingElectricRange` |
| Ladestatus | `vehicle.drivetrain.electricEngine.charging.status` |
| Hochvolt-Status | `vehicle.drivetrain.electricEngine.charging.hvStatus` |
| Restladezeit | `vehicle.drivetrain.electricEngine.charging.timeRemaining` |
| Ladeanschluss | `vehicle.body.chargingPort.status` |
| Vorklimatisierung | `vehicle.cabin.hvac.preconditioning.status.comfortState` |
| Kilometerstand | `vehicle.travelledDistance` |

---

## Wichtige Rahmenbedingungen der BMW CarData API

- **Rate-Limit:** max. **50 API-Abrufe pro 24 Stunden**. Deshalb ist das Standardintervall
  auf 60 Minuten gesetzt (≈ 24 Abrufe/Tag). Mindestens 30 Minuten wählen.
- **Authentifizierung:** OAuth 2.0 **Device Code Flow** mit PKCE. Der Access-Token ist
  1 Stunde gültig, der Refresh-Token 2 Wochen. Das Modul erneuert den Access-Token
  automatisch. Nach 2 Wochen Inaktivität ist eine erneute Anmeldung nötig.
- **Container:** Die API liefert nur Werte für Telematik-Schlüssel, die vorher in einem
  „Container" registriert wurden. Das Modul legt diesen Container per Knopfdruck an.

---

## Installation

1. Ordner `BMWCarData` in das IP-Symcon Modul-Verzeichnis kopieren, **oder** als
   privates Modul über die Modul-Verwaltung per Git-URL hinzufügen.
2. In IP-Symcon eine neue Instanz **„BMWCarData"** anlegen.

## Einrichtung

### 1. Client-ID erzeugen
Im [BMW CarData Kundenportal](https://bmw-cardata.bmwgroup.com/) anmelden, unter dem
API-/Streaming-Bereich einen **CarData Client** erstellen und diesem die Scopes
`cardata:api:read` und `cardata:streaming:read` zuweisen. Die erzeugte **Client-ID** kopieren.

### 2. Instanz konfigurieren
- **Client-ID** eintragen
- **VIN** (Fahrgestellnummer) des Fahrzeugs eintragen
- Auf **„Übernehmen"** klicken

### 3. Anmelden
- **„1. Login starten"** klicken → es erscheint eine URL und ein User-Code
- URL im Browser öffnen, mit den **BMW-ID Zugangsdaten** anmelden, Code bestätigen
- Zurück in IP-Symcon **„2. Login abschließen"** klicken

### 4. Container einrichten
- Einmalig **„Container einrichten"** klicken. Damit werden alle oben genannten
  E-Fahrzeug-Werte registriert und ab jetzt automatisch abgerufen.

Fertig – die Variablen werden im gewählten Intervall aktualisiert.

---

## Öffentliche Funktionen (für Skripte/Ablaufpläne)

```php
BMWCD_UpdateData(int $InstanzID);        // Daten sofort abrufen
BMWCD_RefreshToken(int $InstanzID);      // Access-Token manuell erneuern
BMWCD_RequestDeviceCode(int $InstanzID); // Login-Flow starten
BMWCD_CompleteLogin(int $InstanzID);     // Login abschließen
BMWCD_SetupContainer(int $InstanzID);    // Datencontainer (neu) anlegen
```

---

## Hinweise / mögliche Anpassungen

Die offizielle API-Spezifikation ist eine passwortgeschützte Swagger-Oberfläche und
konnte nicht vollständig automatisiert ausgelesen werden. Folgende Details basieren auf
der öffentlichen Dokumentation und Community-Implementierungen und sollten ggf. gegen die
Live-Swagger geprüft werden, falls ein Aufruf fehlschlägt (siehe IP-Symcon Debug-Fenster
der Instanz):

- Feldname des Container-Bodys (`technicalDescriptors`)
- Genaue Verschachtelung der `telematicData`-Antwort → der Parser im Modul ist bewusst
  tolerant und sucht die Schlüssel rekursiv, egal ob als Objekt oder Liste.
- Der Header `x-version: v1`

Weitere Telematik-Schlüssel lassen sich einfach in der Methode `GetDataMap()` in
`BMWCarData/module.php` ergänzen (danach „Container einrichten" erneut ausführen).

Die vollständige Liste der Schlüssel steht im
**BMW CarData Telematics Data Catalogue** (PDF, im Kundenportal verlinkt).
