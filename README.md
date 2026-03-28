# Module SyncOdoo

Version du module : 0.1.0

Version principale en français sur cette page.
Nederlandse versie verderop op deze pagina.
English version further below on this page.

---

## Français

### Présentation

SyncOdoo est un module Dolibarr de synpeux tu mettre lle ne couvre pas encore tous les cas fonctionnels possibles. Certaines actions doivent encore être confirmées manuellement et certains rapprochements complexes peuvent nécessiter une vérification avant validation.

### Fonctions principales

- Synchronisation Dolibarr vers Odoo et Odoo vers Dolibarr
- Analyse des divergences entre les deux systèmes
- Détection des tiers présents d'un seul côté ou modifiés différemment
- Détection des factures présentes d'un seul côté ou avec écarts de montants
- Journalisation des opérations et des erreurs
- Lancement manuel depuis l'interface Dolibarr
- Exécution automatisable via cron
- Test détaillé de connexion Odoo depuis la configuration
- Utilisation automatique de la clé API Dolibarr de l'utilisateur connecté quand elle existe
- Validation assistée des taux TVA détectés par pays
- Proposition automatique de taux TVA proches avec présélection dans certains cas
- Choix manuel de référence pour les factures fournisseurs divergentes
- Import optionnel du fichier de facture Odoo (PDF en priorité) lors de la création dans Dolibarr

### Ce que le module sait faire aujourd'hui

#### Configuration

- Enregistrer l'URL Odoo, la base, l'utilisateur et le secret Odoo
- Tester la connexion Odoo avec retour détaillé des erreurs de paramètres, accès HTTP et authentification
- Détecter automatiquement la clé API Dolibarr de l'utilisateur courant
- Utiliser une clé API Dolibarr de secours si aucune clé utilisateur n'est disponible
- Mémoriser localement plusieurs champs non sensibles dans l'interface de configuration
- Afficher ou masquer les champs sensibles pour faciliter la saisie

#### Divergences tiers

- Identifier les partenaires présents seulement dans Odoo
- Identifier les partenaires présents seulement dans Dolibarr
- Identifier les fiches partenaires avec différences de données
- Permettre des actions de synchronisation ou d'archivage selon le cas

#### Divergences factures clients

- Lister les factures clients présentes seulement dans Odoo
- Lister les factures clients présentes seulement dans Dolibarr
- Détecter les différences de montants HTVA, TVA et TVAC
- Permettre de conserver la version Odoo ou Dolibarr selon le besoin

#### Divergences factures fournisseurs

- Lister les factures fournisseurs présentes seulement dans Odoo
- Lister les factures fournisseurs présentes seulement dans Dolibarr
- Détecter les divergences de montants entre les deux systèmes
- Afficher le fournisseur et le sujet de la vente pour faciliter la décision
- Laisser l'utilisateur choisir de conserver Odoo ou Dolibarr
- Vérifier la cohérence TVA avant écrasement des montants

#### Vérification TVA

- Détecter les nouveaux taux TVA observés par pays
- Demander une confirmation manuelle quand un taux n'est pas encore connu
- Proposer automatiquement des approximations utiles comme 20.0, 20.5 ou 21.0
- Présélectionner un taux proposé quand la valeur observée est suffisamment proche

### Utilisation

#### Installation

1. Copier le dossier du module dans :

```text
/var/www/html/dolibarr/htdocs/custom/syncodoo/
```

2. Dans Dolibarr, activer le module :
   - Accueil > Configuration > Modules/Applications
   - Catégorie Other
   - Activer SyncOdoo

3. Ouvrir la page de configuration :
   - Outils > SyncOdoo > Configuration

#### Paramètres à renseigner

- URL Odoo
- Base Odoo
- Utilisateur Odoo
- Mot de passe Odoo ou clé API Odoo
- Clé API Dolibarr uniquement en secours si la clé utilisateur n'est pas détectée automatiquement
- Option d'import du fichier de facture Odoo vers les documents Dolibarr (facultatif)

#### Cas Odoo Online

Pour Odoo Online, il faut utiliser une clé API Odoo à la place du mot de passe du compte.

Valeurs conseillées :

- URL Odoo : https://votrebase.odoo.com/
- Base Odoo : votrebase
- Utilisateur Odoo : login exact du compte
- Mot de passe Odoo : clé API Odoo

#### Lancement manuel

- Tableau de bord : vue générale du module
- Divergences : traitement manuel des écarts
- Journal : lecture des logs de synchronisation
- Configuration : paramètres et test de connexion
- À propos : informations de version et rappel du statut du module

#### Automatisation via cron

Exemple de crontab système :

```cron
0 * * * * php /var/www/html/dolibarr/htdocs/custom/syncodoo/cron/sync.php >> /var/log/sync-doli-odoo.log 2>&1
```

### Remarques importantes

- Le module est en version 0.1.0, donc il doit être considéré comme en cours de stabilisation
- La synchronisation automatique ne remplace pas encore un contrôle fonctionnel sur tous les cas métier
- Les rapprochements de références entre Dolibarr et Odoo peuvent demander une vérification, surtout sur les factures fournisseurs
- Les divergences TVA doivent être validées avec attention avant confirmation
- Les montants de facture ne doivent pas être écrasés sans vérifier la cohérence comptable
- Les actions manuelles depuis l'onglet Divergences restent le mode recommandé pour les cas ambigus

### Prérequis techniques

| Élément | Requis |
|---------|--------|
| Dolibarr | >= 11.0 |
| PHP | >= 7.0 |
| Extension PHP curl | Oui |
| API REST Dolibarr | Activée |
| Accès Odoo JSON-RPC | Oui |

Le module utilise JSON-RPC Odoo. L'extension PHP xmlrpc n'est pas nécessaire.

### Structure du module

```text
syncodoo/
├── index.php
├── divergences.php
├── admin/
│   └── config.php
├── core/
│   ├── classes/
│   │   └── SyncOdoo.class.php
│   └── modules/
│       └── modSyncodoo.class.php
├── cron/
│   └── sync.php
├── sql/
│   └── llx_syncodoo_log.sql
└── langs/
    ├── fr_FR/syncodoo.lang
   ├── nl_NL/syncodoo.lang
    └── en_US/syncodoo.lang
```

### Droits utilisateur

| Droit | Description |
|-------|-------------|
| syncodoo.lire | Consulter le journal et les divergences |
| syncodoo.lancer | Exécuter les actions manuelles de synchronisation |
| syncodoo.config | Modifier la configuration |

### Dépannage

#### Authentification Odoo échouée

Vérifier :

- URL Odoo
- base Odoo
- login exact
- mot de passe ou clé API Odoo
- accès réseau depuis le serveur Dolibarr

#### Erreur de résolution DNS

Vérifier la résolution du nom de domaine Odoo depuis le serveur Dolibarr.

#### Erreur API Dolibarr

Vérifier que l'API REST Dolibarr est activée et que la clé API disponible est valide.

#### Où consulter les logs

- Onglet Journal dans le module
- /var/log/sync-doli-odoo.log
- logs Apache ou PHP du serveur

---

## Nederlands

### Overzicht

SyncOdoo is een Dolibarr-module voor bidirectionele synchronisatie tussen Dolibarr en Odoo.
De module behandelt vooral derden, verkoopfacturen en aankoopfacturen, met nadruk op gebruikerscontrole, diagnose en manuele oplossing van verschillen.

Huidige versie : 0.1.0
Status : experimenteel

Deze versie is bruikbaar, maar dekt nog niet alle functionele scenario's. Sommige acties vereisen nog manuele bevestiging en bepaalde complexe matches moeten vooraf worden gecontroleerd.

### Belangrijkste functies

- Synchronisatie van Dolibarr naar Odoo en van Odoo naar Dolibarr
- Analyse van verschillen tussen beide systemen
- Detectie van derden die slechts aan een kant bestaan of anders gewijzigd zijn
- Detectie van facturen die slechts aan een kant bestaan of afwijkende bedragen hebben
- Logging van acties en fouten
- Manuele uitvoering vanuit de Dolibarr-interface
- Automatisering via cron
- Gedetailleerde Odoo-connectietest in de configuratie
- Automatisch gebruik van de Dolibarr API-sleutel van de ingelogde gebruiker indien beschikbaar
- Begeleide validatie van btw-tarieven per land
- Automatische voorstellen van nabije btw-tarieven
- Manuele keuze van de referentie voor afwijkende aankoopfacturen
- Optionele import van het Odoo-factuurbestand (PDF met prioriteit) bij creatie in Dolibarr

### Wat de module momenteel doet

#### Configuratie

- Odoo-URL, database, gebruiker en geheim opslaan
- Odoo-verbinding testen met detailinformatie over parameterfouten, HTTP-toegang en authenticatie
- Automatisch de Dolibarr API-sleutel van de huidige gebruiker detecteren
- Een fallback API-sleutel gebruiken als er geen gebruikerssleutel beschikbaar is
- Niet-gevoelige velden lokaal onthouden in de configuratiepagina
- Gevoelige velden tonen of verbergen voor gemakkelijker invoer

#### Verschillen bij derden

- Partners tonen die enkel in Odoo bestaan
- Partners tonen die enkel in Dolibarr bestaan
- Partners met verschillende gegevens opsporen
- Synchronisatie- of archiveringsacties toelaten afhankelijk van het geval

#### Verschillen bij verkoopfacturen

- Verkoopfacturen tonen die enkel in Odoo bestaan
- Verkoopfacturen tonen die enkel in Dolibarr bestaan
- Verschillen in netto, btw en totaalbedrag detecteren
- Toelaten om Odoo of Dolibarr als referentie te behouden

#### Verschillen bij aankoopfacturen

- Aankoopfacturen tonen die enkel in Odoo bestaan
- Aankoopfacturen tonen die enkel in Dolibarr bestaan
- Bedragsverschillen tussen beide systemen detecteren
- Leverancier en onderwerp tonen om de keuze te vergemakkelijken
- De gebruiker laten kiezen tussen Odoo of Dolibarr
- Btw-coherentie controleren voor bedragen worden overschreven

#### Btw-validatie

- Nieuwe waargenomen btw-tarieven per land detecteren
- Manuele bevestiging vragen als een tarief nog onbekend is
- Nuttige benaderingen voorstellen zoals 20.0, 20.5 of 21.0
- Een voorstel automatisch vooraf selecteren wanneer het waargenomen tarief dicht genoeg ligt

### Gebruik

#### Installatie

1. Kopieer de modulemap naar :

```text
/var/www/html/dolibarr/htdocs/custom/syncodoo/
```

2. Activeer de module in Dolibarr :
   - Start > Configuratie > Modules/Applicaties
   - Categorie Other
   - Activeer SyncOdoo

3. Open de configuratiepagina :
   - Tools > SyncOdoo > Configuratie

#### In te vullen parameters

- Odoo-URL
- Odoo-database
- Odoo-gebruiker
- Odoo-wachtwoord of Odoo API-sleutel
- Dolibarr API-sleutel alleen als fallback wanneer geen gebruikerssleutel automatisch wordt gevonden
- Optie om het Odoo-factuurbestand te importeren naar Dolibarr-documenten (optioneel)

#### Odoo Online

Voor Odoo Online moet een Odoo API-sleutel worden gebruikt in plaats van het accountwachtwoord.

#### Manueel gebruik

- Dashboard : algemeen overzicht
- Divergences : manuele verwerking van verschillen
- Journal : logboek van synchronisaties
- Configuration : parameters en connectietest
- About : versie-informatie en status van de module

#### Cron

Voorbeeld :

```cron
0 * * * * php /var/www/html/dolibarr/htdocs/custom/syncodoo/cron/sync.php >> /var/log/sync-doli-odoo.log 2>&1
```

### Belangrijke opmerkingen

- Versie 0.1.0 moet nog als niet volledig gestabiliseerd worden beschouwd
- Automatische synchronisatie vervangt nog geen functionele controle in alle scenario's
- Referentiematching tussen Dolibarr en Odoo kan extra controle vereisen, vooral voor aankoopfacturen
- Btw-verschillen moeten zorgvuldig worden gevalideerd
- Factuurbedragen mogen niet blind worden overschreven zonder boekhoudkundige controle

### Technische vereisten

| Element | Vereist |
|---------|---------|
| Dolibarr | >= 11.0 |
| PHP | >= 7.0 |
| PHP curl-extensie | Ja |
| Dolibarr REST API | Actief |
| Odoo JSON-RPC toegang | Ja |

### Rechten

| Recht | Beschrijving |
|-------|--------------|
| syncodoo.lire | Logboek en verschillen raadplegen |
| syncodoo.lancer | Manuele synchronisatieacties uitvoeren |
| syncodoo.config | Configuratie wijzigen |

### Probleemoplossing

#### Odoo-authenticatie mislukt

Controleer : URL, database, login, Odoo API-sleutel of wachtwoord, en netwerktoegang vanaf de Dolibarr-server.

#### DNS of hostfout

Controleer de naamresolutie van de Odoo-host op de server.

#### Dolibarr API-fout

Controleer of de Dolibarr REST API actief is en of de beschikbare API-sleutel geldig is.

---

## English

### Overview

SyncOdoo is a Dolibarr module for bidirectional synchronization between Dolibarr and Odoo.
It mainly handles third parties, customer invoices, and supplier invoices, with a workflow focused on user control, diagnostics, and manual divergence resolution.

Current version: 0.1.0
Status: experimental

This version is usable, but it does not yet cover every functional case. Some actions still require manual confirmation and some complex matches should be checked before validation.

### Main features

- Synchronization from Dolibarr to Odoo and from Odoo to Dolibarr
- Divergence analysis between both systems
- Detection of third parties existing on only one side or having different data
- Detection of invoices existing on only one side or having amount differences
- Logging of actions and errors
- Manual execution from the Dolibarr interface
- Automation through cron
- Detailed Odoo connection test from the configuration page
- Automatic use of the logged-in user's Dolibarr API key when available
- Assisted VAT rate validation by country
- Automatic suggestion of nearby VAT rates
- Manual reference choice for divergent supplier invoices
- Optional import of the Odoo invoice file (PDF first) when creating the invoice in Dolibarr

### What the module currently does

#### Configuration

- Store Odoo URL, database, user, and secret
- Test the Odoo connection with detailed information about parameter errors, HTTP access, and authentication
- Automatically detect the current user's Dolibarr API key
- Use a fallback API key when no user API key is available
- Remember non-sensitive fields locally in the configuration page
- Show or hide sensitive fields for easier input

#### Third-party divergences

- Identify partners present only in Odoo
- Identify partners present only in Dolibarr
- Identify partner records with data differences
- Allow synchronization or archive actions depending on the case

#### Customer invoice divergences

- List customer invoices present only in Odoo
- List customer invoices present only in Dolibarr
- Detect differences in net amount, VAT amount, and total amount
- Allow the user to keep Odoo or Dolibarr as the reference side

#### Supplier invoice divergences

- List supplier invoices present only in Odoo
- List supplier invoices present only in Dolibarr
- Detect amount differences between both systems
- Display the supplier and sales subject to help decision making
- Let the user choose whether to keep Odoo or Dolibarr
- Check VAT consistency before overwriting amounts

#### VAT validation

- Detect newly observed VAT rates by country
- Ask for manual confirmation when a rate is not yet known
- Suggest useful approximations such as 20.0, 20.5, or 21.0
- Automatically preselect a suggested rate when the observed value is close enough

### Usage

#### Installation

1. Copy the module folder to:

```text
/var/www/html/dolibarr/htdocs/custom/syncodoo/
```

2. Enable the module in Dolibarr:
   - Home > Setup > Modules/Applications
   - Category Other
   - Enable SyncOdoo

3. Open the configuration page:
   - Tools > SyncOdoo > Configuration

#### Required settings

- Odoo URL
- Odoo database
- Odoo user
- Odoo password or Odoo API key
- Dolibarr API key only as a fallback if no user API key is automatically detected
- Option to import the Odoo invoice file into Dolibarr documents (optional)

#### Odoo Online

For Odoo Online, use an Odoo API key instead of the account password.

#### Manual usage

- Dashboard: general module overview
- Divergences: manual handling of differences
- Journal: synchronization logs
- Configuration: parameters and connection test
- About: version information and module status reminder

#### Cron

Example:

```cron
0 * * * * php /var/www/html/dolibarr/htdocs/custom/syncodoo/cron/sync.php >> /var/log/sync-doli-odoo.log 2>&1
```

### Important notes

- Version 0.1.0 should still be considered not fully stabilized
- Automatic synchronization does not yet replace business validation in every scenario
- Reference matching between Dolibarr and Odoo may require extra review, especially for supplier invoices
- VAT divergences should be checked carefully before confirmation
- Invoice amounts should not be overwritten blindly without accounting validation

### Technical requirements

| Item | Required |
|------|----------|
| Dolibarr | >= 11.0 |
| PHP | >= 7.0 |
| PHP curl extension | Yes |
| Dolibarr REST API | Enabled |
| Odoo JSON-RPC access | Yes |

### Permissions

| Permission | Description |
|------------|-------------|
| syncodoo.lire | View logs and divergences |
| syncodoo.lancer | Run manual synchronization actions |
| syncodoo.config | Modify configuration |

### Troubleshooting

#### Odoo authentication failed

Check the URL, database, login, Odoo API key or password, and network access from the Dolibarr server.

#### DNS or host error

Check host name resolution from the server.

#### Dolibarr API error

Check that the Dolibarr REST API is enabled and that the available API key is valid.
