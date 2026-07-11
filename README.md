# IP-Symcon Modul: BMW CarData (Elektrofahrzeug) – MQTT-Streaming

Ein IP-Symcon Modul zum Abruf der Fahrzeugdaten eines **elektrischen BMW** über den
**BMW CarData Stream** (MQTT, Echtzeit-Push). Gegenüber der REST-API hat das Streaming
zwei Vorteile: **Echtzeit-Aktualisierung** und **kein 50-Abrufe-pro-Tag-Limit**.

Ausgelesene E-Fahrzeug-Werte (Telematik-Schlüssel):

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

## Architektur

Das Modul ist ein **Kind eines IP-Symcon „MQTT Client"** (I/O):

- Der **MQTT Client** hält die persistente TLS-Verbindung zu BMW
  (`customer.streaming-cardata.bmwgroup.com:9000`).
- Dieses **Modul** verwaltet die OAuth-Tokens (Device Code Flow + Refresh) und schiebt
  das stündlich neue **`id_token` automatisch als Passwort** in den MQTT Client.
- Eingehende MQTT-Nachrichten (`{"vin":…,"data":{…}}`) werden geparst und in Variablen
  geschrieben.

---

## Einrichtung

### 1. BMW-Portal
- CarData-Client anlegen, **Client-ID** notieren
- Client für **„CarData Stream"** (`cardata:streaming:read`) abonnieren – **vor** dem Login
- Unter „Configure data stream" die gewünschten Attribute (mind. Ladezustand etc.) auswählen

### 2. IP-Symcon
1. Neue Instanz **„BMWCarData"** anlegen. IP-Symcon fragt nach einem übergeordneten
   **MQTT Client** – diesen anlegen lassen.
2. In der Modul-Instanz **Client-ID** und **VIN** eintragen, **Übernehmen**.
3. **„1. Login starten"** → URL + Code im Browser mit BMW-ID bestätigen.
4. **„2. Login abschließen"** → Tokens werden gespeichert, der MQTT Client automatisch
   mit Host/Port/TLS/Username/Passwort konfiguriert.
5. **„Verbindungsdaten anzeigen"** klicken und das angezeigte **Topic-Abo**
   (`GCID/VIN`) im **MQTT Client** unter *Subscriptions* eintragen.

Sobald das Fahrzeug neue Daten sendet (oder du in der My-BMW-App eine Aktion auslöst),
erscheinen die Werte in den Variablen.

---

## Öffentliche Funktionen

```php
BMWCD_RequestDeviceCode(int $InstanzID);  // Login-Flow starten
BMWCD_CompleteLogin(int $InstanzID);      // Login abschließen + MQTT-Client konfigurieren
BMWCD_RefreshToken(int $InstanzID);       // id_token erneuern + an MQTT-Client übergeben
BMWCD_ConfigureMQTT(int $InstanzID);      // MQTT-Client neu konfigurieren
BMWCD_ShowConnectionInfo(int $InstanzID); // Host/Port/Username/Topic anzeigen
```

---

## Status / Hinweise

- **Getestet:** Client-Anlage, Device-Code-Flow und PKCE (S256, gegen RFC-7636 verifiziert)
  funktionieren. Die **Token-Ausstellung bei BMW** war zum Entwicklungszeitpunkt durch eine
  **BMW-seitige Störung (HTTP 500 am Token-Endpoint)** blockiert, weshalb der Live-Datenfluss
  per MQTT noch nicht end-to-end gegen echte Fahrzeugdaten getestet werden konnte.
- Falls die automatische MQTT-Konfiguration in deiner Symcon-Version nicht greift
  (abweichende Property-Namen), die Verbindungsdaten per **„Verbindungsdaten anzeigen"**
  ablesen und im MQTT Client manuell eintragen. Das Passwort (`id_token`) wird dennoch
  automatisch aktualisiert.
- Weitere Telematik-Schlüssel lassen sich in `GetDataMap()` (in `BMWCarData/module.php`)
  ergänzen; die vollständige Liste steht im **BMW Telematics Data Catalogue** im Portal.
