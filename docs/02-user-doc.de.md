# Hilfe zu Model Context Protocol

Das eLeDia MCP-Plugin verbindet freigegebene MCP-Clients und KI-Agenten mit
Moodle ueber einen kuratierten Tool-Katalog. Jede Anfrage laeuft als der
authentifizierte Moodle-Nutzer und respektiert die normalen Moodle-Rechte,
Einschreibungen und Sichtbarkeiten.

## Was Administratoren konfigurieren

Administratoren waehlen auf der MCP-Konfigurationsseite aus, welche externen
Dienste MCP-Tokens ausstellen duerfen. Dort werden ausserdem Token- und
CORS-Regeln, Anfragegrenzen und die Verfuegbarkeit von Premium-MCP-Tools ueber
das optionale Premium-Add-on gesteuert.

## Tokens

MCP-Clients authentifizieren sich mit Moodle-Tokens. Behandeln Sie jeden Token
wie ein Passwort: Erstellen Sie einen eigenen Token pro Client, widerrufen Sie
nicht mehr benoetigte Tokens und verwenden Sie bevorzugt den
`Authorization: Bearer`-Header statt Token-Werten in URLs.

## Claude Desktop

Die MCP-Seite zeigt nach der Token-Erstellung eine sofort nutzbare
Claude-Desktop-Konfiguration. Kopieren Sie den Token direkt; Moodle zeigt den
Token-Wert nur einmal an.

## Free- und Premium-Tools

Ohne Premium-Add-on stellt MCP die freien Basis-Tools fuer Identitaet,
Kursuebersicht, Kursinhalte, Ressourcen, Ankuendigungen, Kalender, Aufgaben,
Bewertungen, Fortschritt, Quiz-Informationen, Kurssuche, Inhaltssuche und
Nutzererstellung bereit, sofern der Moodle-Nutzer die noetigen Rechte besitzt.

Mit dem Premium-Feature `mcp_tools` wird der vollstaendige MCP-Katalog
freigeschaltet, einschliesslich weiterer Kommunikations-, Forum-, Abgabe-,
Kurserstellungs- und roher Moodle-Webservice-Tools, sofern die Richtlinie dies
zulaesst. Premium-Schreibtools umfassen Kurserstellung, Kursaktualisierung und
manuelle Kurseinschreibung. Sie nutzen eine Vorschau und veraendern Moodle erst
beim zweiten Aufruf mit `confirm=true`.
