<?php

namespace Padosoft\MigrateCloudflareRules\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Exception;
use Illuminate\Support\Facades\Log;

use function Padosoft\MigrateCloudflareRules\array_getEx;

class MigrateCloudflareRules extends Command
{
    // Command name, arguments and options
    protected $signature = 'cloudflare:migrate
                        {type : The type of rules to migrate: "waf", "ipaccessrulesaccount", "ipaccessruleszone", "useragent", "ratelimit", "customlists", "pagerules"}
                        {mode : The migration mode: "bulk" to migrate everything in one go, "individual" to migrate rule by rule}
                        {--source-url= : (Page Rules only) URL, or part of the URL, of the source zone}
                        {--destination-url= : (Page Rules only) URL, or part of the URL, of the destination zone that will replace the source one}
                        {--exclude=* : (Optional) Rules to exclude from the individual migration}
                        {--only_rules_id=* : (Optional) When given, migrate only the rules with these IDs (rule IDs of the source account)}
                        {--dryrun : (Optional) Simulate the migration without creating anything on Cloudflare; prints the HTTP request that would have been sent}
                        {--debug : (Optional) Print a detailed log, including the HTTP requests/responses exchanged with Cloudflare}';

    protected $description = 'Migrate Cloudflare security rules (WAF custom rules, IP Access Rules, User Agent Blocking rules, Rate Limiting rules, Custom Lists, Page Rules) from a source zone/account to a destination zone/account.';

    protected $help = <<<'HELP'
# Cloudflare Security Rules Migration

Migrates Cloudflare security rules between two accounts, or between two zones of the same account, according to the selected rule type.

The command can transfer several kinds of security rules - WAF (Web Application Firewall) rules, IP Access Rules (account and zone), User Agent Blocking rules, Rate Limiting rules, Custom Lists and Page Rules - between two Cloudflare accounts.
Rules can be migrated in bulk or one by one; in individual mode you can exclude specific rules or process only a given subset.

Credentials (API tokens, account IDs and zone IDs) are read from config/migrate-cloudflare-rules.php, which in turn reads the CLOUDFLARE_MIGRATE_* variables from your .env file.

The command accepts 2 mandatory arguments and several optional options:

## Mandatory arguments:

1. {type} - The type of rules to migrate:
    - "waf": migrates the WAF (Web Application Firewall) rules.
    - "ipaccessrulesaccount": migrates the account-level IP Access Rules.
    - "ipaccessruleszone": migrates the zone-level IP Access Rules.
    - "useragent": migrates the User Agent Blocking rules.
    - "ratelimit": migrates the Rate Limiting rules.
    - "customlists": migrates the account Custom Lists.
    - "pagerules": migrates the zone Page Rules.

2. {mode} - The migration mode:
    - "bulk": migrates all the rules in one go.
    - "individual": migrates the rules one by one, with the option of excluding some of them.

## Optional options:

3. --exclude (optional) - Rules to exclude from the individual migration. You can pass one or more rule IDs. Only meaningful when mode is "individual".

4. --only_rules_id (optional) - When given, migrates only the rules with these IDs (rule IDs of the SOURCE account). You can pass one or more IDs among the rules present in the source account.

5. --dryrun (optional) - Simulates the migration without creating anything on Cloudflare; prints the HTTP request that would have been sent.

6. --debug (optional) - Prints a detailed log, including the HTTP requests/responses sent to / returned by Cloudflare.

7. --source-url (mandatory for Page Rules) - URL, or part of the URL, of the source zone used when migrating Page Rules.

8. --destination-url (mandatory for Page Rules) - URL, or part of the URL, of the destination zone that will replace the source one when migrating Page Rules.

## Usage examples:

1. Migrate all WAF rules in one go:
    ```
    php artisan cloudflare:migrate waf bulk
    ```
    Migrates every WAF rule from the source account to the destination account.

2. Migrate WAF rules individually, excluding specific rules:
    ```
    php artisan cloudflare:migrate waf individual --exclude=rule_id_1 --exclude=rule_id_2
    ```
    Migrates the WAF rules one at a time, skipping the rules with IDs `rule_id_1` and `rule_id_2`.

3. Migrate all account IP Access Rules in one go:
    ```
    php artisan cloudflare:migrate ipaccessrulesaccount bulk
    ```
    Migrates every account-level IP Access Rule from the source account to the destination account.

4. Migrate account IP Access Rules individually, excluding some rules:
    ```
    php artisan cloudflare:migrate ipaccessrulesaccount individual --exclude=ip_rule_id_1 --exclude=ip_rule_id_2
    ```
    Migrates the IP Access Rules one at a time, skipping the rules with IDs `ip_rule_id_1` and `ip_rule_id_2`.

5. Migrate all User Agent Blocking rules in one go:
    ```
    php artisan cloudflare:migrate useragent bulk
    ```
    Migrates every User Agent Blocking rule from the source account to the destination account.

6. Migrate User Agent Blocking rules individually, excluding some rules:
    ```
    php artisan cloudflare:migrate useragent individual --exclude=ua_rule_id_1 --exclude=ua_rule_id_2
    ```
    Migrates the User Agent Blocking rules one at a time, skipping the rules with IDs `ua_rule_id_1` and `ua_rule_id_2`.

7. Migrate all Rate Limiting rules in one go:
    ```
    php artisan cloudflare:migrate ratelimit bulk
    ```
    Migrates every Rate Limiting rule from the source account to the destination account.

8. Migrate Rate Limiting rules individually, excluding some rules:
    ```
    php artisan cloudflare:migrate ratelimit individual --exclude=rate_limit_id_1 --exclude=rate_limit_id_2
    ```
    Migrates the Rate Limiting rules one at a time, skipping the rules with IDs `rate_limit_id_1` and `rate_limit_id_2`.

9. Migrate Custom Lists individually:
    ```
    php artisan cloudflare:migrate customlists individual
    ```
    Migrates the Custom Lists one at a time.

10. Migrate only the given WAF rules, individually:
    ```
    php artisan cloudflare:migrate waf individual --only_rules_id=rule_id_1 --only_rules_id=rule_id_2
    ```
    Migrates, one at a time, only the WAF rules whose IDs are passed with --only_rules_id.

11. Migrate Page Rules individually:
    ```
    php artisan cloudflare:migrate pagerules individual --source-url=example.com --destination-url=example2.it
    ```
    Migrates the Page Rules, replacing the source zone domain example.com with the destination zone domain example2.it wherever it appears in the rules.

### Other details:

- Before migrating, the command loads all the existing rules of the destination account and checks whether each rule already exists, to avoid duplicates.
- Rule limits depend on the plan of the destination Cloudflare account. When the maximum limit is reached the operation is aborted with an error message.
- API errors and exceptions are handled and written to the Laravel log for detailed analysis.
HELP;

    // Rules already present in the destination account/zone
    protected $destinationRules = [];

    // Custom lists already present in the destination account
    protected $destinationCustomLists = [];

    // API tokens, account IDs and zone IDs. Loaded in handle() from config/migrate-cloudflare-rules.php
    public string $apiKeyAccount1 = '';  // API token of the source account
    public string $apiKeyAccount2 = '';  // API token of the destination account
    public string $zoneIdAccount1 = '';  // Zone ID of the source zone
    public string $zoneIdAccount2 = '';  // Zone ID of the destination zone
    public string $accountId1 = '';  // ID of the source account
    public string $accountId2 = '';  // ID of the destination account
    public string $sourceUrl = '';  // domain URL of the source zone (Page Rules only)
    public string $destinationUrl = '';  // domain URL of the destination zone (Page Rules only)

    public function __construct()
    {
        parent::__construct();
    }

    // Command entry point

    /**
     * @throws Exception
     */
    public function handle(): int
    {
        $type = $this->argument('type');
        $mode = $this->argument('mode');
        $excludeRules = $this->option('exclude');
        $onlyTheseRules = $this->option('only_rules_id');
        $dryrun = $this->option('dryrun');
        $debug = $this->option('debug');
        $this->sourceUrl = $this->option('source-url') ?? '';
        $this->destinationUrl = $this->option('destination-url') ?? '';

        // Load credentials and identifiers from the package config (config/migrate-cloudflare-rules.php)
        $this->loadConfiguration();
        if (!$this->validateConfiguration($type)) {
            return self::FAILURE;
        }

        $this->info("Starting migration of {$type} rules in {$mode} mode...");

        // Load all the destination account rules of the given type, so we can check whether they already exist.
        // Custom lists are not loaded here: they are loaded only when needed, inside migrateCustomLists().
        if ($type !== 'customlists') {
            $destinationRules = $this->loadDestinationRules($type, $debug);
            $this->line('Found ' . count($this->destinationRules) . " {$type} rules in the destination account.");
        }

        try {
            switch ($type) {
                case 'waf':
                    $this->migrateWAFRules($mode, $excludeRules, $onlyTheseRules, $dryrun, $debug);
                    break;
                case 'ipaccessruleszone':
                case 'ipaccessrulesaccount':
                    $this->migrateIPAccessRules($type, $mode, $excludeRules, $onlyTheseRules, $dryrun, $debug);
                    break;
                case 'useragent':
                    $this->migrateUserAgentRules($mode, $excludeRules, $onlyTheseRules, $dryrun, $debug);
                    break;
                case 'ratelimit':
                    $this->migrateRateLimitRules($mode, $excludeRules, $onlyTheseRules, $dryrun, $debug);
                    break;
                case 'customlists':
                    $this->migrateCustomLists($mode, $excludeRules, $onlyTheseRules, $dryrun, $debug);
                    break;
                case 'pagerules':
                    $this->migratePageRules($mode, $excludeRules, $onlyTheseRules, $dryrun, $debug);
                    break;
                default:
                    $this->error('Invalid rule type ' . $type . '. Use one of: "waf", "ipaccessrulesaccount", "ipaccessruleszone", "useragent", "ratelimit", "customlists", "pagerules".');
            }
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error('Error while migrating rules: ' . $e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * Load API tokens, account IDs and zone IDs from the package configuration.
     */
    protected function loadConfiguration(): void
    {
        $this->apiKeyAccount1 = (string) config('migrate-cloudflare-rules.source.api_token', '');
        $this->accountId1 = (string) config('migrate-cloudflare-rules.source.account_id', '');
        $this->zoneIdAccount1 = (string) config('migrate-cloudflare-rules.source.zone_id', '');
        $this->apiKeyAccount2 = (string) config('migrate-cloudflare-rules.destination.api_token', '');
        $this->accountId2 = (string) config('migrate-cloudflare-rules.destination.account_id', '');
        $this->zoneIdAccount2 = (string) config('migrate-cloudflare-rules.destination.zone_id', '');
    }

    /**
     * Make sure the credentials required by the given rule type are configured.
     * API tokens are always required; account IDs are needed for account-level resources
     * ("ipaccessrulesaccount", "customlists"), zone IDs for zone-level resources (everything else).
     */
    protected function validateConfiguration(string $type): bool
    {
        $required = [
            'source.api_token' => $this->apiKeyAccount1,
            'destination.api_token' => $this->apiKeyAccount2,
        ];

        if (in_array($type, ['ipaccessrulesaccount', 'customlists'], true)) {
            $required['source.account_id'] = $this->accountId1;
            $required['destination.account_id'] = $this->accountId2;
        } else {
            $required['source.zone_id'] = $this->zoneIdAccount1;
            $required['destination.zone_id'] = $this->zoneIdAccount2;
        }

        $missing = array_keys(array_filter($required, static fn (string $value): bool => trim($value) === ''));

        if ($missing === []) {
            return true;
        }

        $this->error('Missing Cloudflare configuration: ' . implode(', ', array_map(static fn (string $key): string => "migrate-cloudflare-rules.{$key}", $missing)) . '.');
        $this->line('Set the corresponding CLOUDFLARE_MIGRATE_* variables in your .env file (or publish and edit config/migrate-cloudflare-rules.php).');

        return false;
    }

    public function migrateCustomLists(string $mode, array $excludeLists = [], array $onlyTheseRules = [], bool $dryrun = false, bool $debug = false): void
    {
        $lists = $this->getCustomLists($this->apiKeyAccount1, $this->accountId1, $debug);
        if (count($lists) < 1) {
            $this->warn('There are no Custom Lists in the source account, exiting.');
        }

        // Load the lists already existing in the destination account
        $destinationCustomList = $this->loadDestinationCustomList($this->apiKeyAccount2, $this->accountId2, $debug);

        $i = 0;
        foreach ($lists as $list) {
            $i++;
            $this->line("Migrating list {$list['name']} ({$i} of " . count($lists) . ')...');

            // Check whether the list already exists in the destination account
            if ($this->customListExistsInDestination($list['name'])) {
                $this->line("List {$list['name']} already exists in the destination account, skipped.");
                continue;
            }

            // When "$onlyTheseRules" is given, make sure the list is included in it, otherwise discard it.
            if ($onlyTheseRules && !in_array($list['name'], $onlyTheseRules)) {
                $this->warn("List {$list['name']} is not included in the given list, skipped.");
                continue;
            }

            // Check whether the list is excluded from the migration
            if (in_array($list['id'], $excludeLists)) {
                $this->warn("List {$list['name']} is excluded.");
                continue;
            }

            $newList = $this->createCustomList($this->apiKeyAccount2, $this->accountId2, $list, $dryrun, $debug);
            if (!$newList) {
                $this->error("Error while creating list {$list['name']} in the destination account. Skipping this list.");
                continue;
            }

            $items = $this->getCustomListItems($this->apiKeyAccount1, $this->accountId1, $list['id'], $debug);

            // If the list has no items, do not try to add an empty set of items: the API would return an error
            if (!$items) {
                $this->info("List {$list['name']} (with no items) migrated successfully.");
                continue;
            }
            $this->addItemsToCustomList($this->apiKeyAccount2, $this->accountId2, $newList['id'], $items, $dryrun, $debug);
            $this->info("List {$list['name']} migrated successfully.");
        }
    }

    public function loadDestinationCustomList(string $apiKey, string $accountId, bool $debug = false): array
    {
        $allLists = [];  // Collects all the lists
        $page = 1;
        $perPage = 50;  // Maximum per_page supported by the Cloudflare API
        $totalPages = 1;

        $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/rules/lists";
        try {
            while ($page <= $totalPages) {
                $this->line("Fetching destination Custom Lists page {$page} of {$totalPages} ...");
                $response = $this->getResponse($apiKey, $url, 'GET', null, $page, $perPage, $debug);

                if (!$response->successful()) {
                    $this->handleErrorResponse($response, 'Error while fetching the destination Custom Lists for page ' . $page);
                    exit(1);
                }
                $data = $response->json();
                $allLists = array_merge($allLists, $data['result']);
                $totalPages = $data['result_info']['total_pages'] ?? 1;
                $page++;
            }

            $this->destinationCustomLists = $allLists;  // Store all the destination lists in the class property
            Log::debug('Custom Lists already existing in the destination: ' . json_encode($this->destinationCustomLists));

            return $allLists;
        } catch (Exception $e) {
            Log::error('Error while calling the Cloudflare API (destination Custom Lists): ' . $e->getMessage());
            $this->error("Error while fetching the destination Custom Lists: {$e->getMessage()}");
        }

        return [];
    }

    public function customListExistsInDestination(string $listName): bool
    {
        foreach ($this->destinationCustomLists as $existingList) {
            if ($existingList['name'] === $listName) {
                return true;  // The list already exists
            }
        }

        return false;  // The list does not exist
    }

    // Load the destination rules for the given type

    /**
     * @throws Exception
     */
    public function loadDestinationRules(string $type, bool $debug = false): array
    {
        $this->destinationRules = [];  // Reset the destination rules

        $allLists = [];  // Collects all the rules
        $page = 1;
        $perPage = 50;  // Maximum per_page supported by the Cloudflare API
        $totalPages = 1;

        $endpoint = $this->getEndpointForType($type);
        $url = $type === 'ipaccessrulesaccount'
            ? "https://api.cloudflare.com/client/v4/accounts/{$this->accountId2}/{$endpoint}"
            : "https://api.cloudflare.com/client/v4/zones/{$this->zoneIdAccount2}/{$endpoint}";

        try {
            while ($page <= $totalPages) {
                $this->line("Fetching destination rules page {$page} of {$totalPages} ...");
                $response = $this->getResponse($this->apiKeyAccount2, $url, 'GET', null, $page, $perPage, $debug);
                if (!$response->successful()) {
                    $this->handleErrorResponse($response, "Error while fetching the destination {$type} rules for page " . $page);
                    exit(1);
                }
                $data = $response->json();
                $allLists = array_merge($allLists, $data['result']);
                $totalPages = $data['result_info']['total_pages'] ?? 1;
                $page++;
            }

            $this->destinationRules = $allLists;  // Store all the destination rules in the class property
            $this->line('Loaded all ' . count($allLists) . " {$type} rules from the destination account.");
            if ($debug) {
                $this->line("{$type} rules already existing in the destination: " . json_encode($this->destinationRules));
            }
            Log::debug("{$type} rules already existing in the destination: " . json_encode($this->destinationRules));

            return $allLists;
        } catch (Exception $e) {
            Log::error("Error while calling the Cloudflare API to load the destination rules ({$type}): " . $e->getMessage());
            $this->error("Error while fetching the destination {$type} rules: {$e->getMessage()}");
        }

        return [];
    }

    // 1. WAF Rules migration

    /**
     * @throws Exception
     */
    public function migrateWAFRules(string $mode, array $excludeRules, array $onlyTheseRules, bool $dryrun = false, bool $debug = false): void
    {
        // Get the WAF rules from the source account
        $rules = $this->getWAFRules($this->apiKeyAccount1, $this->zoneIdAccount1, $debug);
        if (!$rules) {
            $this->error('Error while fetching the WAF rules from the source account');

            return;
        }

        $this->line('Found ' . count($rules) . ' WAF rules to migrate.');
        $i = 0;
        foreach ($rules as $rule) {
            $i++;
            $this->line('Migrating WAF rule ' . $i . ' of ' . count($rules) . ' with "' . array_getEx($rule, 'description', '') . '" ruleid=' . $rule['id'] . '...');

            // When "$onlyTheseRules" is given, make sure the rule is included in it, otherwise discard it.
            if ($onlyTheseRules && !in_array($rule['id'], $onlyTheseRules)) {
                $this->warn("Rule {$rule['id']} is not included in the given list, skipped.");
                continue;
            }

            if (in_array($rule['id'], $excludeRules)) {
                $this->warn("Rule {$rule['id']} excluded from the migration.");
                continue;
            }

            if ($this->ruleExistsInDestination($rule, 'waf')) {
                $this->line("Rule {$rule['id']} already exists, skipped.");
                continue;
            }

            $this->migrateCustomWAFRule($this->apiKeyAccount2, $this->zoneIdAccount2, $rule, $dryrun, $debug);
        }
    }

    public function migrateCustomWAFRule(string $apiKey, string $zoneId, array $rule, bool $dryrun = false, bool $debug = false): void
    {
        // Get the ID of the existing ruleset, or create a new one
        $rulesetId = $this->getCustomRulesetId($apiKey, $zoneId, $debug);

        if ($rulesetId) {
            // Add the rule to the existing ruleset
            $this->createCustomRule($apiKey, $zoneId, $rulesetId, $rule, $dryrun, $debug);

            return;
        }
        // Create a new ruleset with the first rule
        $this->createCustomRuleset($apiKey, $zoneId, $rule, $dryrun, $debug);
    }

    public function createCustomRuleset(string $apiKey, string $zoneId, array $rule, bool $dryrun = false, bool $debug = false): void
    {
        $this->line('Creating a new ruleset for the custom WAF rules with "' . array_getEx($rule, 'description', '') . '" ruleid=' . $rule['id'] . '...');
        try {
            $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/rulesets";

            // Take the original action from the rule
            $action = $rule['action'] ?? 'block';  // Use 'block' only when no action is present
            // The "allow" action is not supported, convert it to "skip"
            if ($action === 'allow') {
                $action = 'skip';
            }

            $paused = $rule['paused'] ?? false;  // Use false when no paused flag is present

            // When the action is "skip", set the proper action parameters
            $actionParameters = [];
            if ($action === 'skip') {
                // For the 'skip' action include all three kinds of action_parameters
                $actionParameters = [
                    'ruleset' => 'current',  // Skip the remaining rules of the current ruleset
                    'phases' => ['http_ratelimit', 'http_request_sbfm', 'http_request_firewall_managed'],  // Skip these phases
                    'products' => ['zoneLockdown', 'uaBlock', 'bic', 'hot', 'securityLevel', 'rateLimit', 'waf'],  // Skip these products
                ];
            }

            // $payloadRule adds the single rule to the existing ruleset
            if ($actionParameters === []) {
                // Without actionParameters do not send the key at all: an empty array makes the API fail
                $payloadRule = [
                    'action' => $action,
                    'expression' => $rule['filter']['expression'] ?? '',  // The expression of the source rule
                    'description' => $rule['description'] ?? 'Rule created by migration',
                    'enabled' => !$paused,
                ];
            } else {
                $payloadRule = [
                    'action' => $action,
                    'action_parameters' => $actionParameters,  // Parameters for the "skip" action, none for the other actions
                    'expression' => $rule['filter']['expression'] ?? '',  // The expression of the source rule
                    'description' => $rule['description'] ?? 'Rule created by migration via API',
                    'enabled' => !$paused,
                ];
            }

            // Build the payload used to create the new ruleset
            $payload = [
                'name' => 'Custom WAF Ruleset',  // Name of the new ruleset
                'kind' => 'zone',  // Zone-level ruleset
                'phase' => 'http_request_firewall_custom',  // WAF custom rules phase
                'rules' => [$payloadRule],  // Add the first rule to the ruleset
            ];

            // Check whether `dryrun` is enabled
            if ($dryrun) {
                $this->warn('Dry run enabled: the HTTP request will NOT be sent to Cloudflare, only printed.');
                // Print the full HTTP request
                $this->printHttpRequest($apiKey, $url, 'POST', $payload);

                return;
            }

            // Call the API to create the ruleset
            $response = $this->getResponse($apiKey, $url, 'POST', $payload, 0, 0, $debug);

            if (!$response->successful()) {
                Log::error('Rule:');
                Log::error(json_encode($rule));
                Log::error('Payload:');
                Log::error(json_encode($payload));
                Log::error('--------------------------------------------' . PHP_EOL);
                $this->handleErrorResponse($response, 'Error while creating the ruleset for "' . array_getEx($rule, 'description', '') . '" ruleid=' . $rule['id'] . '.');
            }

            $this->info('Rule added successfully.');
        } catch (Exception $e) {
            Log::error('Error while creating the ruleset for "' . array_getEx($rule, 'description', '') . '" ruleid=' . $rule['id'] . ': ' . $e->getMessage());
            $this->error('Error while creating the ruleset for "' . array_getEx($rule, 'description', '') . '" ruleid=' . $rule['id'] . ': ' . $e->getMessage());
            Log::error('Rule:');
            Log::error(json_encode($rule));
            Log::error('Payload:');
            Log::error(json_encode($payload));
            Log::error('--------------------------------------------' . PHP_EOL);
        }
    }

    public function createCustomRule(string $apiKey, string $zoneId, string $rulesetId, array $rule, bool $dryrun = false, bool $debug = false): void
    {
        $this->line('Adding a new custom rule to the existing rulesetid=' . $rulesetId . '...');
        try {
            $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/rulesets/{$rulesetId}/rules";

            // Take the original action from the rule
            $action = $rule['action'] ?? 'block';  // Use 'block' only when no action is present
            // The "allow" action is not supported, convert it to "skip"
            if ($action === 'allow') {
                $action = 'skip';
            }

            $paused = $rule['paused'] ?? false;  // Use false when no paused flag is present

            // When the action is "skip", set the proper action parameters
            $actionParameters = [];
            if ($action === 'skip') {
                // For the 'skip' action include all three kinds of action_parameters
                $actionParameters = [
                    'ruleset' => 'current',  // Skip the remaining rules of the current ruleset
                    'phases' => ['http_ratelimit', 'http_request_sbfm', 'http_request_firewall_managed'],  // Skip these phases
                    'products' => ['zoneLockdown', 'uaBlock', 'bic', 'hot', 'securityLevel', 'rateLimit', 'waf'],  // Skip these products
                ];
            }

            // Payload used to add the single rule to the existing ruleset
            if ($actionParameters === []) {
                // Without actionParameters do not send the key at all: an empty array makes the API fail
                $payload = [
                    'action' => $action,
                    'expression' => $rule['filter']['expression'] ?? '',  // The expression of the source rule
                    'description' => $rule['description'] ?? 'Rule created by migration',
                    'enabled' => !$paused,
                ];
            } else {
                $payload = [
                    'action' => $action,
                    'action_parameters' => $actionParameters,  // Parameters for the "skip" action, none for the other actions
                    'expression' => $rule['filter']['expression'] ?? '',  // The expression of the source rule
                    'description' => $rule['description'] ?? 'Rule created by migration via API',
                    'enabled' => !$paused,
                ];
            }

            // Check whether `dryrun` is enabled
            if ($dryrun) {
                $this->warn('Dry run enabled: the HTTP request will NOT be sent to Cloudflare, only printed.');
                // Print the full HTTP request
                $this->printHttpRequest($apiKey, $url, 'POST', $payload);

                return;
            }

            // Call the API to add the rule to the existing ruleset
            $response = $this->getResponse($apiKey, $url, 'POST', $payload, 0, 0, $debug);

            if (!$response->successful()) {
                Log::error('Rule:');
                Log::error(json_encode($rule));
                Log::error('Payload:');
                Log::error(json_encode($payload));
                Log::error('--------------------------------------------' . PHP_EOL);
                $this->handleErrorResponse($response, 'Error while adding a custom rule to rulesetid=' . $rulesetId . '.');
            }

            $this->info('Rule added successfully.');
        } catch (Exception $e) {
            Log::error('Error while adding a custom rule to rulesetid=' . $rulesetId . ': ' . $e->getMessage());
            $this->error('Error while adding a custom rule to rulesetid=' . $rulesetId . ': ' . $e->getMessage());
            Log::error('Rule:');
            Log::error(json_encode($rule));
            Log::error('Payload:');
            Log::error(json_encode($payload));
            Log::error('--------------------------------------------' . PHP_EOL);
        }
    }

    public function getCustomRulesetId(string $apiKey, string $zoneId, bool $debug = false): ?string
    {
        $this->line('Fetching the existing ruleset...');
        $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/rulesets";
        try {
            $response = $this->getResponse($apiKey, $url, 'GET', null, 0, 0, $debug);

            if (!$response->successful()) {
                $this->handleErrorResponse($response, 'Error while fetching the zone rulesets.');

                return null;
            }

            $rulesets = $response->json()['result'];

            // Look for the ruleset of the 'http_request_firewall_custom' phase
            foreach ($rulesets as $ruleset) {
                if ($ruleset['phase'] === 'http_request_firewall_custom' && $ruleset['kind'] === 'zone') {
                    $this->line('Existing ruleset found id=' . $ruleset['id']);

                    return $ruleset['id']; // Return the ID of the existing ruleset
                }
            }
        } catch (Exception $e) {
            Log::error('Error while calling the Cloudflare API (Rulesets): ' . $e->getMessage());
            $this->error("Error while fetching the zone rulesets: {$e->getMessage()}");

            return null;
        }
        $this->line('No existing ruleset found.');

        return null;
    }


    // 2. IP Access Rules migration

    /**
     * @throws Exception
     */
    public function migrateIPAccessRules(string $type, string $mode, array $excludeRules, array $onlyTheseRules, bool $dryrun = false, bool $debug = false): void
    {
        $rules = $this->getIPAccessRules($type, $this->apiKeyAccount1, $debug);
        $this->line('Found ' . count($rules) . " {$type} rules in the source account.");
        if (count($rules) < 1) {
            $this->warn("There are no {$type} rules in the source account, exiting.");
        }

        if ($mode === 'bulk') {
            $this->bulkMigrateRules($rules, $type, $dryrun, $debug);

            return;
        }

        $this->individualMigrateRules($rules, $type, $excludeRules, $onlyTheseRules, $dryrun, $debug);
    }

    // 3. User Agent Blocking Rules migration

    /**
     * @throws Exception
     */
    public function migrateUserAgentRules(string $mode, array $excludeRules = [], array $onlyTheseRules = [], bool $dryrun = false, bool $debug = false): void
    {
        $rules = $this->getUserAgentRules($this->apiKeyAccount1, $this->zoneIdAccount1, $debug);
        if (count($rules) < 1) {
            $this->warn('There are no useragent rules in the source account, exiting.');
        }

        if ($mode === 'bulk') {
            $this->bulkMigrateRules($rules, 'useragent', $dryrun, $debug);

            return;
        }

        $this->individualMigrateRules($rules, 'useragent', $excludeRules, $onlyTheseRules, $dryrun, $debug);
    }

    // 4. Rate Limiting Rules migration

    /**
     * @throws Exception
     */
    public function migrateRateLimitRules(string $mode, array $excludeRules = [], array $onlyTheseRules = [], bool $dryrun = false, bool $debug = false): void
    {
        $rules = $this->getRateLimitRules($this->apiKeyAccount1, $this->zoneIdAccount1, $debug);
        if (count($rules) < 1) {
            $this->warn('There are no ratelimit rules in the source account, exiting.');
        }

        if ($mode === 'bulk') {
            $this->bulkMigrateRules($rules, 'ratelimit', $dryrun, $debug);

            return;
        }

        $this->individualMigrateRules($rules, 'ratelimit', $excludeRules, $onlyTheseRules, $dryrun, $debug);
    }

    // Migrate all the rules in one go

    /**
     * @throws Exception
     */
    public function bulkMigrateRules($rules, string $type, bool $dryrun = false, bool $debug = false): void
    {
        if (!$rules) {
            $this->error("No {$type} rules found to migrate.");

            return;
        }
        $this->line("Starting migration of all {$type} rules...");
        $this->createRules($this->apiKeyAccount2, $this->zoneIdAccount2, $rules, $type, $dryrun, $debug);
        $this->info("All {$type} rules have been migrated successfully.");
    }

    // Migrate the rules one by one, with the option of excluding some of them

    /**
     * @throws Exception
     */
    public function individualMigrateRules($rules, string $type, array $excludeRules, array $onlyTheseRules, bool $dryrun = false, bool $debug = false): void
    {
        if (!$rules) {
            $this->warn("No {$type} rules found to migrate.");

            return;
        }

        $i = 0;
        foreach ($rules as $rule) {
            $i++;
            $descr = array_getEx($rule, 'description', '') ?: array_getEx($rule, 'notes', '');
            $this->line("Migrating {$type} rule {$i} of " . count($rules) . ' with "' . $descr . '" ruleid=' . $rule['id'] . '...');

            // When "$onlyTheseRules" is given, make sure the rule is included in it, otherwise discard it.
            if ($onlyTheseRules && !in_array($rule['id'], $onlyTheseRules)) {
                $this->warn("Rule {$rule['id']} is not included in the given list, skipped.");
                continue;
            }

            if (in_array($rule['id'], $excludeRules)) {
                $this->warn("Rule {$rule['id']} excluded from the migration.");
                continue;
            }

            if ($this->ruleExistsInDestination($rule, $type)) {
                $this->line("Rule {$rule['id']} already exists, skipped.");
                continue;
            }

            $result = $this->createRule($this->apiKeyAccount2, $this->zoneIdAccount2, $rule, $type, $dryrun, $debug);
            if (!$result) {
                $this->error("Error while migrating rule {$rule['id']}. Rule NOT created.");
                continue;
            }
            $this->info("Rule {$rule['id']} migrated successfully.");
        }
    }

    // Functions that fetch the rules from the source account
    public function getWAFRules(string $apiKey, string $zoneId, bool $debug = false)
    {
        $allRules = [];  // Collects all the rules
        $page = 1;
        $perPage = 50;  // Maximum per_page supported by the Cloudflare API
        $totalPages = 1;

        $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/firewall/rules";
        try {
            while ($page <= $totalPages) {
                $this->line("Fetching WAF rules page {$page} of {$totalPages} ...");
                $response = $this->getResponse($apiKey, $url, 'GET', null, $page, $perPage, $debug);

                if (!$response->successful()) {
                    $this->handleErrorResponse($response, 'Error while fetching the WAF rules for page ' . $page);
                    break;
                }

                $data = $response->json();
                $allRules = array_merge($allRules, $data['result']);
                $totalPages = $data['result_info']['total_pages'] ?? 1;  // Get the total number of pages
                $page++;
            }

            return $allRules;  // Return all the collected rules
        } catch (Exception $e) {
            Log::error('Error while calling the Cloudflare API (getWAFRules): ' . $e->getMessage());
            $this->error("Error while fetching the WAF rules from the source account: {$e->getMessage()}");
        }

        return null;
    }

    public function getIPAccessRules(string $type, string $apiKey, bool $debug = false): ?array
    {
        $allRules = [];  // Collects all the rules
        $page = 1;
        $perPage = 50;  // Maximum per_page supported by the Cloudflare API
        $totalPages = 1;

        $url = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId1}/firewall/access_rules/rules";
        if ($type === 'ipaccessruleszone') {
            $url = "https://api.cloudflare.com/client/v4/zones/{$this->zoneIdAccount1}/firewall/access_rules/rules";
        }
        try {
            while ($page <= $totalPages) {
                $this->line("Fetching {$type} page {$page} of {$totalPages} ...");
                $response = $this->getResponse($apiKey, $url, 'GET', null, $page, $perPage, $debug);

                if (!$response->successful()) {
                    $this->handleErrorResponse($response, "Error while fetching the {$type} for page " . $page);
                    break;
                }

                $data = $response->json();
                $allRules = array_merge($allRules, $data['result']);
                $totalPages = $data['result_info']['total_pages'] ?? 1;  // Get the total number of pages
                $page++;
            }

            $this->line('Loaded all ' . count($allRules) . " {$type} rules from the source account.");
            if ($debug) {
                $this->line("{$type} rules existing in the source account: " . json_encode($allRules));
            }
            Log::debug("{$type} rules existing in the source account: " . json_encode($allRules));

            return $allRules;  // Return all the collected rules
        } catch (Exception $e) {
            Log::error("Error while calling the Cloudflare API (getIPAccessRules) with type:{$type} - Error: : " . $e->getMessage());
            $this->error("Error while fetching the {$type} from the source account: {$e->getMessage()}");
        }

        return null;
    }

    public function getUserAgentRules(string $apiKey, string $zoneId, bool $debug = false)
    {
        $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/firewall/ua_rules";
        try {
            $response = $this->getResponse($apiKey, $url, 'GET', null, 0, 0, $debug);

            if ($response->successful()) {
                return $response->json()['result'];
            }

            $this->handleErrorResponse($response, 'Error while fetching the User Agent Blocking rules from the source account.');
        } catch (Exception $e) {
            Log::error('Error while calling the Cloudflare API (getUserAgentRules): ' . $e->getMessage());
            $this->error("Error while fetching the User Agent Blocking rules from the source account: {$e->getMessage()}");
        }

        return null;
    }

    public function getRateLimitRules(string $apiKey, string $zoneId, bool $debug = false)
    {
        $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/rate_limits";
        try {
            $response = $this->getResponse($apiKey, $url, 'GET', null, 0, 0, $debug);

            if ($response->successful()) {
                return $response->json()['result'];
            }

            $this->handleErrorResponse($response, 'Error while fetching the Rate Limiting rules from the source account.');
        } catch (Exception $e) {
            Log::error('Error while calling the Cloudflare API (getRateLimitRules): ' . $e->getMessage());
            $this->error("Error while fetching the Rate Limiting rules from the source account: {$e->getMessage()}");
        }

        return null;
    }

    // Check whether a rule already exists in the destination account

    /**
     * @throws Exception
     */
    public function ruleExistsInDestination($rule, string $type): bool
    {
        Log::debug('Checking whether the rule already exists in the destination account...');
        Log::debug('New rule:' . json_encode($rule));

        foreach ($this->destinationRules as $existingRule) {
            if ($this->compareRules($existingRule, $rule, $type)) {
                Log::warning('Rule id ' . $rule['id'] . ' already exists in the destination account with rule id ' . $existingRule['id']);
                $this->warn('Rule id ' . $rule['id'] . ' already exists in the destination account with rule id ' . $existingRule['id']);

                return true;
            }
        }

        return false;
    }

    // Create several rules in the destination account

    /**
     * @throws Exception
     */
    public function createRules(string $apiKey, string $zoneId, array $rules, string $type, bool $dryrun, bool $debug = false): void
    {
        $endpoint = $this->getEndpointForType($type);

        $url = $type === 'ipaccessrulesaccount'
            ? "https://api.cloudflare.com/client/v4/user/{$endpoint}"
            : "https://api.cloudflare.com/client/v4/zones/{$zoneId}/{$endpoint}";

        foreach ($rules as $rule) {
            $result = $this->createRule($apiKey, $zoneId, $rule, $type, $dryrun, $debug);
            if (!$result) {
                $this->error("Error while migrating rule {$rule['id']}. Rule NOT created.");
                continue;
            }
            $this->info("Rule {$rule['id']} migrated successfully.");
        }
    }

    // Create a single rule in the destination account

    /**
     * @throws Exception
     */
    public function createRule($apiKey, $zoneId, array $rule, string $type, bool $dryrun = false, bool $debug = false): bool
    {
        $endpoint = $this->getEndpointForType($type);

        $url = $type === 'ipaccessrulesaccount'
            ? "https://api.cloudflare.com/client/v4/accounts/{$this->accountId2}/{$endpoint}"
            : "https://api.cloudflare.com/client/v4/zones/{$zoneId}/{$endpoint}";

        // Keep the ID of the source rule so it can be printed in case of error
        $ruleIdOriginal = $rule['id'];

        // Build the payload used to create the rule
        $payload = $this->getPayload($rule, $type);

        // Check whether `dryrun` is enabled
        if ($dryrun) {
            $this->warn('Dry run enabled: the HTTP request will NOT be sent to Cloudflare, only printed.');
            // Print the full HTTP request
            $this->printHttpRequest($apiKey, $url, 'POST', $payload);

            return true;
        }

        try {
            $response = $this->getResponse($apiKey, $url, 'POST', $payload, 0, 0, $debug);

            if ($response->successful()) {
                $this->info('Rule id: ' . $ruleIdOriginal . ' created successfully.');

                return true;
            }
            $this->handleErrorResponse($response, 'Error while creating a rule (createRule) original rule id: ' . $ruleIdOriginal . '.');

            return false;
        } catch (Exception $e) {
            Log::error('Error while calling the Cloudflare API (createRule) original rule id: ' . $ruleIdOriginal . ':' . $e->getMessage());
            $this->error('Error while calling the Cloudflare API (createRule) original rule id: ' . $ruleIdOriginal . ':' . $e->getMessage());
        }

        return false;
    }

    // Error handling

    /**
     * @throws \JsonException
     */
    public function handleErrorResponse($response, string $customMessage): void
    {
        $statusCode = $response->status();
        $errorBody = $response->json();

        $this->error("Cloudflare API error: {$customMessage}. Status Code: {$statusCode}, Response: " . json_encode($errorBody, JSON_THROW_ON_ERROR));
        Log::error("Cloudflare API error: {$customMessage}. Status Code: {$statusCode}, Response: " . json_encode($errorBody, JSON_THROW_ON_ERROR));

        if ($statusCode == 429 || (isset($errorBody['errors']) && $this->containsRuleLimitError($errorBody['errors']))) {
            $this->error('Maximum number of rules reached for the account. You cannot add more rules: buy additional rules on Cloudflare or upgrade your subscription. Operation aborted.');
            Log::error('Maximum number of rules reached for the account. You cannot add more rules: buy additional rules on Cloudflare or upgrade your subscription. Operation aborted.');
            exit(1);
        }

        $this->error("Error while migrating the rules: {$customMessage}");
        Log::error("Error while migrating the rules: {$customMessage}");
    }

    // Check whether the error message contains a rule-limit warning
    public function containsRuleLimitError($errors): bool
    {
        foreach ($errors as $error) {
            if (str_contains($error['message'], 'exceeded the maximum number')) {
                return true;
            }
        }

        return false;
    }

    // Get the API endpoint for the given rule type

    /**
     * @throws Exception
     */
    public function getEndpointForType(string $type): string
    {
        return match ($type) {
            'waf' => 'firewall/rules',
            'ipaccessrulesaccount', 'ipaccessruleszone' => 'firewall/access_rules/rules',
            'useragent' => 'firewall/ua_rules',
            'ratelimit' => 'rate_limits',
            'pagerules' => 'pagerules',
            default => throw new Exception('Invalid rule type. type=' . $type),
        };
    }

    // Compare an existing rule with the rule to migrate
    public function compareRules($existingRule, $newRule, string $type): bool
    {
        return match ($type) {
            'waf' => (($existingRule['filter']['expression'] === $newRule['filter']['expression']) || ($existingRule['description'] === $newRule['description'])),
            'ipaccessrulesaccount', 'ipaccessruleszone' => $existingRule['configuration']['value'] === $newRule['configuration']['value'] &&
                $existingRule['configuration']['target'] === $newRule['configuration']['target'],
            'useragent' => $existingRule['description'] === $newRule['description'] &&
                $existingRule['mode'] === $newRule['mode'],
            'ratelimit' => $existingRule['match']['request']['url'] === $newRule['match']['request']['url'] &&
                $existingRule['action'] === $newRule['action'],
            'pagerules' => false,//TODO: implement the comparison of page rules; there seems to be no description, the only way looks like looping over the actions and targets arrays
            default => false,
        };
    }

    public function getCustomLists(string $apiKey, string $accountId, bool $debug = false)
    {
        $allLists = [];  // Collects all the lists
        $page = 1;
        $perPage = 50;  // Maximum per_page supported by the Cloudflare API
        $totalPages = 1;

        $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/rules/lists";
        try {
            while ($page <= $totalPages) {
                $this->line("Fetching Custom Lists page {$page} of {$totalPages} ...");
                $response = $this->getResponse($apiKey, $url, 'GET', null, $page, $perPage, $debug);

                if (!$response->successful()) {
                    $this->handleErrorResponse($response, 'Error while fetching the Custom Lists for page ' . $page);
                    break;
                }
                $data = $response->json();
                $allLists = array_merge($allLists, $data['result']);
                $totalPages = $data['result_info']['total_pages'] ?? 1;
                $page++;
            }

            return $allLists;  // Return all the collected lists
        } catch (Exception $e) {
            Log::error('Error while calling the Cloudflare API (Custom Lists): ' . $e->getMessage());
            $this->error("Error while fetching the Custom Lists: {$e->getMessage()}");

            return null;
        }
    }

    public function getCustomListItems(string $apiKey, string $accountId, string $listId, bool $debug = false)
    {
        $allItems = [];
        $page = 1;
        $perPage = 50;
        $totalPages = 1;

        $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/rules/lists/{$listId}/items";
        try {
            while ($page <= $totalPages) {
                $this->line("Fetching Custom List items page {$page} of {$totalPages} ...");
                $response = $this->getResponse($apiKey, $url, 'GET', null, $page, $perPage, $debug);

                if (!$response->successful()) {
                    $this->handleErrorResponse($response, "Error while fetching the items of list {$listId} for page {$page}");
                    break;
                }
                $data = $response->json();
                $allItems = array_merge($allItems, $data['result']);
                $totalPages = $data['result_info']['total_pages'] ?? 1;
                $page++;
            }

            return $allItems;
        } catch (Exception $e) {
            Log::error('Error while calling the Cloudflare API (Custom List Items): ' . $e->getMessage());
            $this->error("Error while fetching the items of list {$listId}: {$e->getMessage()}");

            return null;
        }
    }

    public function createCustomList(string $apiKey, string $accountId, array $list, bool $dryrun = false, bool $debug = false)
    {
        try {
            $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/rules/lists";

            $payload = [
                'name' => $list['name'],
                'kind' => $list['kind'],
                'description' => $list['description'] ?? 'List created by migration',
            ];

            // Check whether `dryrun` is enabled
            if ($dryrun) {
                $this->warn('Dry run enabled: the HTTP request will NOT be sent to Cloudflare, only printed.');
                // Print the full HTTP request
                $this->printHttpRequest($apiKey, $url, 'POST', $payload);

                return null;
            }

            $response = $this->getResponse($apiKey, $url, 'POST', $payload, 0, 0, $debug);

            if ($response->successful()) {
                $this->info('Custom List created successfully.');

                return $response->json()['result'];
            }
            $this->handleErrorResponse($response, 'Error while creating the Custom List ' . $list['name']);

            return null;
        } catch (Exception $e) {
            Log::error('Error while creating the Custom List ' . $list['name'] . ': ' . $e->getMessage());
            $this->error('Error while creating the Custom List ' . $list['name'] . ': ' . $e->getMessage());

            return null;
        }
    }

    public function addItemsToCustomList(string $apiKey, string $accountId, string $listId, array $items, bool $dryrun = false, bool $debug = false): void
    {
        try {
            $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/rules/lists/{$listId}/items";

            // Build the array of items to send
            // The payload must be an array of objects
            $payload = [];

            foreach ($items as $item) {
                $payload[] = [
                    'ip' => $item['ip'],
                    'comment' => $item['comment'] ?? null,
                ];
            }

            // Check whether `dryrun` is enabled
            if ($dryrun) {
                $this->warn('Dry run enabled: the HTTP request will NOT be sent to Cloudflare, only printed.');
                // Print the full HTTP request
                $this->printHttpRequest($apiKey, $url, 'POST', $payload);

                return;
            }

            $response = $this->getResponse($apiKey, $url, 'POST', $payload, 0, 0, $debug);

            if ($response->successful()) {
                $this->info('Items added successfully to the Custom List ' . $listId);

                return;
            }
            $this->handleErrorResponse($response, 'Error while adding the items to the Custom List ' . $listId);
        } catch (Exception $e) {
            Log::error("Error while adding items to the Custom List {$listId}: " . $e->getMessage());
            $this->error("Error while adding items to the Custom List {$listId}: " . $e->getMessage());
        }
    }

    public function getResponse(string $apiKey, string $url, string $method = 'GET', ?array $payload = null, int $page = 0, int $perPage = 0, bool $debug = false)
    {
        // Set the headers
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ];

        // Set the payload
        $payload = $payload ?? null;

        // When pagination is set, it takes precedence over the payload
        if ($page > 0 && $perPage > 0) {
            $payload = [
                'page' => $page,
                'per_page' => $perPage,
            ];
        }

        // Print the full HTTP request
        if ($debug) {
            $this->printHttpRequest($apiKey, $url, $method, $payload);
        }

        $response = Http::withHeaders($headers);

        if ($method === 'POST') {
            $result = $response->post($url, $payload);
            if ($debug) {
                $this->printHttpResponse($result);
            }

            return $result;
        }

        $result = $response->get($url, $payload);
        if ($debug) {
            $this->printHttpResponse($result);
        }

        return $result;
    }

    public function printHttpRequest(string $apiKey, string $url, string $method, ?array $payload): void
    {
        $this->warn('=== HTTP REQUEST ===');
        $this->line("Method: $method");
        $this->line("URL: $url");
        $this->line('Headers:');
        $this->line('  Authorization: Bearer ' . $this->maskSecret($apiKey));
        $this->line('  Content-Type: application/json');
        $this->line('Body:');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT));
        $this->warn('====================');
    }

    /**
     * Mask a secret so it can be safely printed on screen / in logs (only the last 4 characters are shown).
     */
    protected function maskSecret(string $secret): string
    {
        if (strlen($secret) <= 4) {
            return str_repeat('*', strlen($secret));
        }

        return str_repeat('*', strlen($secret) - 4) . substr($secret, -4);
    }

    public function printHttpResponse($response): void
    {
        $this->warn('=== HTTP RESPONSE ===');

        // Print whether the request succeeded or not
        if ($response->successful()) {
            $this->info('Status: Success');
        } else {
            $this->error('Status: Error');
        }

        // Print the HTTP status
        $this->line('HTTP Status: ' . $response->status());

        // Print the headers
        $this->line('Headers:');
        foreach ($response->headers() as $key => $value) {
            $this->line("  $key: " . implode(', ', $value));
        }

        // Handle the response body
        $this->line('Body:');
        try {
            // Try to decode the body as JSON
            $body = $response->json();
            if ($body) {
                $this->line(json_encode($body, JSON_PRETTY_PRINT));
            } else {
                // When the JSON is invalid or empty, print the raw body
                $this->line($response->body());
            }
        } catch (Exception $e) {
            // An exception occurred while decoding the JSON
            $this->error('Error while decoding the JSON: ' . $e->getMessage());
            $this->line('Raw body:');
            $this->line($response->body());
        }

        $this->warn('=====================');
    }

    public function getPayload(array $rule, string $type): array
    {
        // Remove the rule ID to avoid conflicts: rule IDs differ from account to account
        unset($rule['id']);

        if ($type === 'waf') {
            $payload = [
                'action' => $rule['action'] ?? 'block',  // Default action when not present
                'filter' => [
                    'expression' => $rule['filter']['expression'] ?? '',
                    'paused' => $rule['filter']['paused'] ?? false,
                ],
                'description' => $rule['description'] ?? 'WAF rule created by migration',
            ];

            return $payload;
        }

        if ($type === 'ipaccessrulesaccount' || $type === 'ipaccessruleszone') {
            $payload = [
                'configuration' => [
                    'target' => $rule['configuration']['target'],
                    'value' => $rule['configuration']['value'],
                ],
                'mode' => $rule['mode'] ?? 'block',
                'notes' => $rule['notes'] ?? 'IP Access rule created by migration',
            ];

            return $payload;
        }

        // For the other rule types, try to use the rule returned by the GET directly as payload
        $payload = $rule;

        return $payload;
    }

    public function migratePageRules(string $mode, array $excludeRules, array $onlyTheseRules, bool $dryrun, bool $debug)
    {
        // Get the rules from the source account
        $rules = $this->getPageRules($this->apiKeyAccount1, $this->zoneIdAccount1, $debug);
        if (!$rules) {
            $this->error('Error while fetching the Page Rules from the source account');

            return;
        }

        $this->line('Found ' . count($rules) . ' Page Rules to migrate.');
        $i = 0;
        foreach ($rules as $rule) {
            $i++;
            $this->line('Migrating Page Rule ' . $i . ' of ' . count($rules) . ' with "' . array_getEx($rule, 'description', '') . '" ruleid=' . $rule['id'] . '...');

            // When "$onlyTheseRules" is given, make sure the rule is included in it, otherwise discard it.
            if ($onlyTheseRules && !in_array($rule['id'], $onlyTheseRules)) {
                $this->warn("Rule {$rule['id']} is not included in the given list, skipped.");
                continue;
            }

            if (in_array($rule['id'], $excludeRules)) {
                $this->warn("Rule {$rule['id']} excluded from the migration.");
                continue;
            }

            if ($this->ruleExistsInDestination($rule, 'pagerules')) {
                $this->line("Rule {$rule['id']} already exists, skipped.");
                continue;
            }

            $this->migratePageRule($this->apiKeyAccount2, $this->zoneIdAccount2, $rule, $dryrun, $debug);
        }
    }

    public function migratePageRule(string $apiKeyAccount2, string $zoneIdAccount2, $rule, bool $dryrun, bool $debug): void
    {
        $this->createPageRule($apiKeyAccount2, $zoneIdAccount2, $rule, $dryrun, $debug);
    }

    public function createPageRule(string $apiKey, string $zoneId, array $rule, bool $dryrun = false, bool $debug = false): void
    {
        $this->line('Adding a new Page Rule...');
        try {
            $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/pagerules";

            // Remove the rule ID to avoid conflicts: rule IDs differ from account to account
            unset($rule['id']);

            // Replace the source URL with the destination one
            $rule = $this->replaceUrlInPageRule($rule, $this->sourceUrl, $this->destinationUrl);

            $payload = $rule;

            // Check whether `dryrun` is enabled
            if ($dryrun) {
                $this->warn('Dry run enabled: the HTTP request will NOT be sent to Cloudflare, only printed.');
                // Print the full HTTP request
                $this->printHttpRequest($apiKey, $url, 'POST', $payload);

                return;
            }

            // Call the API to add the Page Rule
            $response = $this->getResponse($apiKey, $url, 'POST', $payload, 0, 0, $debug);

            if (!$response->successful()) {
                Log::error('Rule:');
                Log::error(json_encode($rule));
                Log::error('Payload:');
                Log::error(json_encode($payload));
                Log::error('--------------------------------------------' . PHP_EOL);
                $this->handleErrorResponse($response, 'Error while adding a Page Rule.');
            }

            $this->info('Rule added successfully.');
        } catch (Exception $e) {
            Log::error('Error while adding a Page Rule: ' . $e->getMessage());
            $this->error('Error while adding a Page Rule: ' . $e->getMessage());
            Log::error('Rule:');
            Log::error(json_encode($rule));
            Log::error('Payload:');
            Log::error(json_encode($payload));
            Log::error('--------------------------------------------' . PHP_EOL);
        }
    }

    public function getPageRules(string $apiKey, string $zoneId, bool $debug = false): ?array
    {
        $allRules = [];  // Collects all the rules
        $page = 1;
        $perPage = 50;  // Maximum per_page supported by the Cloudflare API
        $totalPages = 1;

        $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/pagerules";
        try {
            while ($page <= $totalPages) {
                $this->line("Fetching Page Rules page {$page} of {$totalPages} ...");
                $response = $this->getResponse($apiKey, $url, 'GET', null, $page, $perPage, $debug);

                if (!$response->successful()) {
                    $this->handleErrorResponse($response, 'Error while fetching the Page Rules for page ' . $page);
                    break;
                }

                $data = $response->json();
                $allRules = array_merge($allRules, $data['result']);
                $totalPages = $data['result_info']['total_pages'] ?? 1;  // Get the total number of pages
                $page++;
            }

            return $allRules;  // Return all the collected rules
        } catch (Exception $e) {
            Log::error('Error while calling the Cloudflare API (getPageRules): ' . $e->getMessage());
            $this->error("Error while fetching the Page Rules from the source account: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Page Rules may match URLs containing the domain of the source zone,
     * so the source URL must be replaced with the destination one.
     */
    public function replaceUrlInPageRule(array $rule, string $sourceUrl, string $destinationUrl): array
    {
        // Check that the "targets" key exists and is an array
        if (!isset($rule['targets']) || !is_array($rule['targets'])) {
            return $rule;
        }

        foreach ($rule['targets'] as &$target) {
            // When "constraint" or "value" do not exist, skip to the next target
            if (!isset($target['constraint']['value'])) {
                continue;
            }

            // Perform the string replacement
            $target['constraint']['value'] = str_replace($sourceUrl, $destinationUrl, $target['constraint']['value']);
        }

        return $rule;
    }
}
