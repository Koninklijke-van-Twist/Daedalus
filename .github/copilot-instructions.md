# Copilot-instructies voor Daedalus

## Scope en architectuur
- Deze applicatie draait volledig vanuit de map `/web/` en wordt gepubliceerd onder `https://sleutels.kvt.nl/daedalus`.
- Houd links daarom relatief (`index.php`, `odata.php?...`) of vanaf `/daedalus/...` als absolute paden nodig zijn.
- De app is mobile-first. Nieuwe UI-wijzigingen moeten eerst op telefoonscherm goed leesbaar zijn.

## Niet wijzigen
- Bestand `web/logincheck.php` niet aanpassen.
- Bestand `web/odata.php` niet aanpassen.
- Bestand `web/auth.php` alleen aanpassen na expliciete gebruikersvraag.

## Data en logica
- Hoofdflow staat in `web/index.php`.
- Helpers staan in `web/functions.php`.
- De werkorderlijst is gebruiker-afhankelijk via `$_SESSION['user']['email']`.
- Gebruikerskoppeling loopt via `AppResource` (`E_Mail`) met fallback via `AppUserSetup` (`Email` -> `User_ID` -> `AppResource.KVT_User_ID`).
- Werkorders komen uit `AppWerkorders`.
- Detailregels komen uit `LVS_JobPlanningLinesSub` (functioneel: ProjectPlanningsRegels).

## UI-regels
- Geen desktop-first redesign toevoegen.
- Geen zware frameworks introduceren zonder expliciet verzoek.
- Houd cards, typografie en spacing compact en touch-vriendelijk.
- Gebruik bestaande favicon/manifest-bestanden op elke nieuwe HTML-pagina.
- Gebruik op de hoofdpagina altijd `logo-website.png`.

## Materiaalstatus
- Materiaalstatus op regelniveau wordt bepaald op basis van o.a.:
  - `KVT_Completely_Picked`, `KVT_Qty_Picked`, `Bin_Code`
  - `LVS_Purchase_Order_No`, `KVT_Expected_Receipt_Date`, `LVS_Outstanding_Qty_Base`
  - fallback `KVT_Status_Material`
- Als businessregels veranderen: pas alleen de statusfunctie aan, niet de complete detailweergave.

## Veiligheid en kwaliteit
- Houd OData-filters veilig door waarden te escapen (`odata_quote_string`).
- Vang OData-fouten af en toon een korte gebruikersvriendelijke melding.
- Gebruik cache-widget via `injectTimerHtml(...)` uit `odata.php`; endpoint-acties blijven:
  - `odata.php?action=cache_status`
  - `odata.php?action=cache_delete`
  - `odata.php?action=cache_clear`

## Bij toekomstige uitbreidingen
- Extra velden eerst verifiëren in `BC Webservices.txt`.
- Alleen benodigde kolommen opvragen via `$select` voor performance.
- Sorteer werkorders standaard op `Start_Date` oplopend.
- Gebruik `KVT_Extended_Text` als beschrijvingstekst in planningregels; `Description` blijft de naam.

## Code-structuur en refactorregels (PHP en JS)
- Pas bij refactors in PHP/JS altijd dezelfde sectievolgorde toe, en alleen als de sectie inhoud heeft:
  - `Includes/requires` (of vergelijkbare naam zoals `Imports`)
  - `Constants`
  - `Variabelen`
  - `Functies`
  - `Page load` (alle top-level uitvoerbare code die niet in functies staat)
- Gebruik voor secties een duidelijke blokcomment-stijl, bijvoorbeeld:
  - `/**` + `* Functies` + `*/`
- Voeg geen lege secties toe. Een ontbrekende sectie betekent: niet opnemen.
- Functioneel gedrag mag niet wijzigen door de refactor:
  - geen wijziging in logica, filters, output, routes, sessiegedrag of side-effects
  - alleen herordenen/annoteren en waar nodig veilig opsplitsen zonder gedragswijziging
- Houd top-level uitvoerbare code geconcentreerd in de `Page load`-sectie.
- Classes moeten altijd in een eigen bestand staan:
  - maximaal 1 class per bestand
  - bestandsnaam sluit aan op classnaam
  - geen class-definities tussen page-load code in gecombineerde scriptbestanden
- Respecteer altijd bestaande uitzonderingen uit deze instructies:
  - `web/logincheck.php` niet aanpassen
  - `web/odata.php` niet aanpassen
  - `web/auth.php` alleen aanpassen na expliciete gebruikersvraag
