<?php

namespace Padosoft\MigrateCloudflareRules\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Padosoft\MigrateCloudflareRules\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MigrateCloudflareRulesCommandTest extends TestCase
{
    #[Test]
    public function the_command_is_registered_and_the_config_is_merged(): void
    {
        $this->assertArrayHasKey('cloudflare:migrate', Artisan::all());

        $this->assertIsArray(config('migrate-cloudflare-rules.source'));
        $this->assertIsArray(config('migrate-cloudflare-rules.destination'));
        foreach (['api_token', 'account_id', 'zone_id'] as $key) {
            $this->assertArrayHasKey($key, config('migrate-cloudflare-rules.source'));
            $this->assertArrayHasKey($key, config('migrate-cloudflare-rules.destination'));
        }
    }

    #[Test]
    public function it_fails_with_a_clear_message_when_credentials_are_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $exitCode = Artisan::call('cloudflare:migrate', ['type' => 'waf', 'mode' => 'bulk']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Missing Cloudflare configuration', $output);
        foreach (['source.api_token', 'destination.api_token', 'source.zone_id', 'destination.zone_id'] as $key) {
            $this->assertStringContainsString("migrate-cloudflare-rules.{$key}", $output);
        }
        $this->assertStringContainsString('CLOUDFLARE_MIGRATE_*', $output);

        Http::assertNothingSent();
    }

    #[Test]
    public function account_level_types_require_account_ids_but_not_zone_ids(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        config()->set('migrate-cloudflare-rules.source.api_token', 'a');
        config()->set('migrate-cloudflare-rules.destination.api_token', 'b');
        config()->set('migrate-cloudflare-rules.source.zone_id', 'z1');
        config()->set('migrate-cloudflare-rules.destination.zone_id', 'z2');

        $exitCode = Artisan::call('cloudflare:migrate', ['type' => 'customlists', 'mode' => 'individual']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('migrate-cloudflare-rules.source.account_id', $output);
        $this->assertStringContainsString('migrate-cloudflare-rules.destination.account_id', $output);
        $this->assertStringNotContainsString('zone_id', $output);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_migrates_zone_ip_access_rules_in_bulk(): void
    {
        $this->configureCredentials();
        $this->fakeCloudflare([
            'GET zones/dst-zone/firewall/access_rules/rules' => $this->cfList([]),
            'GET zones/src-zone/firewall/access_rules/rules' => $this->cfList([
                [
                    'id' => 'rule-1',
                    'mode' => 'block',
                    'notes' => 'Bad actor',
                    'configuration' => ['target' => 'ip', 'value' => '1.2.3.4'],
                ],
            ]),
            'POST zones/dst-zone/firewall/access_rules/rules' => $this->cfResult(['id' => 'new-rule-1']),
        ]);

        $this->artisan('cloudflare:migrate', ['type' => 'ipaccessruleszone', 'mode' => 'bulk'])
            ->expectsOutputToContain('Starting migration of ipaccessruleszone rules in bulk mode')
            ->expectsOutputToContain('Rule rule-1 migrated successfully.')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'zones/src-zone/firewall/access_rules/rules')
                && $request->hasHeader('Authorization', 'Bearer '.self::SOURCE_TOKEN);
        });

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'zones/dst-zone/firewall/access_rules/rules')
                && $request->hasHeader('Authorization', 'Bearer '.self::DESTINATION_TOKEN)
                && $request->data() === [
                    'configuration' => ['target' => 'ip', 'value' => '1.2.3.4'],
                    'mode' => 'block',
                    'notes' => 'Bad actor',
                ];
        });
    }

    #[Test]
    public function it_skips_ip_access_rules_that_already_exist_in_the_destination(): void
    {
        $this->configureCredentials();
        $this->fakeCloudflare([
            'GET zones/dst-zone/firewall/access_rules/rules' => $this->cfList([
                [
                    'id' => 'existing-99',
                    'mode' => 'block',
                    'notes' => 'Already there',
                    'configuration' => ['target' => 'ip', 'value' => '1.2.3.4'],
                ],
            ]),
            'GET zones/src-zone/firewall/access_rules/rules' => $this->cfList([
                [
                    'id' => 'rule-1',
                    'mode' => 'block',
                    'notes' => 'Bad actor',
                    'configuration' => ['target' => 'ip', 'value' => '1.2.3.4'],
                ],
            ]),
        ]);

        $this->artisan('cloudflare:migrate', ['type' => 'ipaccessruleszone', 'mode' => 'individual'])
            ->expectsOutputToContain('Rule id rule-1 already exists in the destination account with rule id existing-99')
            ->expectsOutputToContain('Rule rule-1 already exists, skipped.')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    #[Test]
    public function individual_mode_honours_exclude_and_only_rules_id(): void
    {
        $this->configureCredentials();

        $sourceRules = $this->cfList([
            ['id' => 'rule-1', 'mode' => 'block', 'notes' => 'one', 'configuration' => ['target' => 'ip', 'value' => '1.1.1.1']],
            ['id' => 'rule-2', 'mode' => 'block', 'notes' => 'two', 'configuration' => ['target' => 'ip', 'value' => '2.2.2.2']],
            ['id' => 'rule-3', 'mode' => 'block', 'notes' => 'three', 'configuration' => ['target' => 'ip', 'value' => '3.3.3.3']],
        ]);

        // --exclude
        $this->fakeCloudflare([
            'GET accounts/dst-account/firewall/access_rules/rules' => $this->cfList([]),
            'GET accounts/src-account/firewall/access_rules/rules' => $sourceRules,
            'POST accounts/dst-account/firewall/access_rules/rules' => $this->cfResult(['id' => 'x']),
        ]);

        $this->artisan('cloudflare:migrate', [
            'type' => 'ipaccessrulesaccount',
            'mode' => 'individual',
            '--exclude' => ['rule-2'],
        ])
            ->expectsOutputToContain('Rule rule-2 excluded from the migration.')
            ->assertSuccessful();

        $posted = collect(Http::recorded(fn (Request $r) => $r->method() === 'POST'))
            ->map(fn (array $pair) => $pair[0]->data()['configuration']['value'])
            ->values()
            ->all();
        $this->assertSame(['1.1.1.1', '3.3.3.3'], $posted);

        // --only_rules_id
        $this->fakeCloudflare([
            'GET accounts/dst-account/firewall/access_rules/rules' => $this->cfList([]),
            'GET accounts/src-account/firewall/access_rules/rules' => $sourceRules,
            'POST accounts/dst-account/firewall/access_rules/rules' => $this->cfResult(['id' => 'x']),
        ]);

        $this->artisan('cloudflare:migrate', [
            'type' => 'ipaccessrulesaccount',
            'mode' => 'individual',
            '--only_rules_id' => ['rule-2'],
        ])
            ->expectsOutputToContain('Rule rule-1 is not included in the given list, skipped.')
            ->assertSuccessful();

        $posted = collect(Http::recorded(fn (Request $r) => $r->method() === 'POST'))
            ->map(fn (array $pair) => $pair[0]->data()['configuration']['value'])
            ->values()
            ->all();
        $this->assertSame(['2.2.2.2'], $posted);
    }

    #[Test]
    public function dry_run_prints_the_request_without_sending_it_and_masks_the_token(): void
    {
        $this->configureCredentials();
        $this->fakeCloudflare([
            'GET zones/dst-zone/firewall/access_rules/rules' => $this->cfList([]),
            'GET zones/src-zone/firewall/access_rules/rules' => $this->cfList([
                ['id' => 'rule-1', 'mode' => 'block', 'notes' => 'Bad actor', 'configuration' => ['target' => 'ip', 'value' => '1.2.3.4']],
            ]),
        ]);

        $this->artisan('cloudflare:migrate', ['type' => 'ipaccessruleszone', 'mode' => 'bulk', '--dryrun' => true])
            ->expectsOutputToContain('Dry run enabled')
            ->expectsOutputToContain('=== HTTP REQUEST ===')
            ->expectsOutputToContain('URL: https://api.cloudflare.com/client/v4/zones/dst-zone/firewall/access_rules/rules')
            ->expectsOutputToContain('Authorization: Bearer ******************5678')
            ->doesntExpectOutputToContain(self::DESTINATION_TOKEN)
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    #[Test]
    public function it_adds_waf_rules_to_the_existing_custom_ruleset_converting_allow_into_skip(): void
    {
        $this->configureCredentials();
        $this->fakeCloudflare([
            'GET zones/dst-zone/firewall/rules' => $this->cfList([]),
            'GET zones/src-zone/firewall/rules' => $this->cfList([
                [
                    'id' => 'waf-1',
                    'action' => 'allow',
                    'paused' => false,
                    'description' => 'Allow office',
                    'filter' => ['id' => 'f1', 'expression' => 'ip.src eq 1.2.3.4', 'paused' => false],
                ],
            ]),
            'GET zones/dst-zone/rulesets' => $this->cfList([
                ['id' => 'rs-managed', 'phase' => 'http_request_firewall_managed', 'kind' => 'zone'],
                ['id' => 'rs-custom', 'phase' => 'http_request_firewall_custom', 'kind' => 'zone'],
            ]),
            'POST zones/dst-zone/rulesets/rs-custom/rules' => $this->cfResult(['id' => 'rs-custom']),
        ]);

        $this->artisan('cloudflare:migrate', ['type' => 'waf', 'mode' => 'individual'])
            ->expectsOutputToContain('Found 1 WAF rules to migrate.')
            ->expectsOutputToContain('Existing ruleset found id=rs-custom')
            ->expectsOutputToContain('Rule added successfully.')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), 'rulesets/rs-custom/rules')) {
                return false;
            }
            $data = $request->data();

            return $data['action'] === 'skip'
                && $data['action_parameters']['ruleset'] === 'current'
                && $data['expression'] === 'ip.src eq 1.2.3.4'
                && $data['description'] === 'Allow office'
                && $data['enabled'] === true;
        });
    }

    #[Test]
    public function it_creates_the_custom_ruleset_when_the_destination_zone_has_none(): void
    {
        $this->configureCredentials();
        $this->fakeCloudflare([
            'GET zones/dst-zone/firewall/rules' => $this->cfList([]),
            'GET zones/src-zone/firewall/rules' => $this->cfList([
                [
                    'id' => 'waf-1',
                    'action' => 'block',
                    'paused' => true,
                    'description' => 'Block bots',
                    'filter' => ['id' => 'f1', 'expression' => 'cf.client.bot', 'paused' => false],
                ],
            ]),
            'GET zones/dst-zone/rulesets' => $this->cfList([]),
            'POST zones/dst-zone/rulesets' => $this->cfResult(['id' => 'rs-new']),
        ]);

        $this->artisan('cloudflare:migrate', ['type' => 'waf', 'mode' => 'individual'])
            ->expectsOutputToContain('No existing ruleset found.')
            ->expectsOutputToContain('Creating a new ruleset for the custom WAF rules')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST' || ! str_ends_with(strtok($request->url(), '?'), 'zones/dst-zone/rulesets')) {
                return false;
            }
            $data = $request->data();

            return $data['name'] === 'Custom WAF Ruleset'
                && $data['kind'] === 'zone'
                && $data['phase'] === 'http_request_firewall_custom'
                && $data['rules'][0]['action'] === 'block'
                && ! array_key_exists('action_parameters', $data['rules'][0])
                && $data['rules'][0]['expression'] === 'cf.client.bot'
                && $data['rules'][0]['enabled'] === false;
        });
    }

    #[Test]
    public function it_skips_waf_rules_with_the_same_expression_in_the_destination(): void
    {
        $this->configureCredentials();
        $this->fakeCloudflare([
            'GET zones/dst-zone/firewall/rules' => $this->cfList([
                ['id' => 'dst-1', 'action' => 'block', 'description' => 'Something else', 'filter' => ['expression' => 'ip.src eq 1.2.3.4']],
            ]),
            'GET zones/src-zone/firewall/rules' => $this->cfList([
                ['id' => 'waf-1', 'action' => 'block', 'description' => 'Block one IP', 'filter' => ['expression' => 'ip.src eq 1.2.3.4']],
            ]),
        ]);

        $this->artisan('cloudflare:migrate', ['type' => 'waf', 'mode' => 'individual'])
            ->expectsOutputToContain('Rule waf-1 already exists, skipped.')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    #[Test]
    public function it_migrates_user_agent_rules_using_the_source_rule_as_payload(): void
    {
        $this->configureCredentials();
        $this->fakeCloudflare([
            'GET zones/dst-zone/firewall/ua_rules' => $this->cfList([]),
            'GET zones/src-zone/firewall/ua_rules' => $this->cfList([
                [
                    'id' => 'ua-1',
                    'mode' => 'block',
                    'paused' => false,
                    'description' => 'Block BadBot',
                    'configuration' => ['target' => 'ua', 'value' => 'BadBot/1.0'],
                ],
            ]),
            'POST zones/dst-zone/firewall/ua_rules' => $this->cfResult(['id' => 'ua-new']),
        ]);

        $this->artisan('cloudflare:migrate', ['type' => 'useragent', 'mode' => 'bulk'])
            ->expectsOutputToContain('All useragent rules have been migrated successfully.')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'zones/dst-zone/firewall/ua_rules')
                && $request->data() === [
                    'mode' => 'block',
                    'paused' => false,
                    'description' => 'Block BadBot',
                    'configuration' => ['target' => 'ua', 'value' => 'BadBot/1.0'],
                ];
        });
    }

    #[Test]
    public function it_migrates_rate_limiting_rules(): void
    {
        $this->configureCredentials();
        $rule = [
            'id' => 'rl-1',
            'disabled' => false,
            'description' => 'Login throttle',
            'match' => ['request' => ['url' => '*example.com/login', 'methods' => ['POST']]],
            'threshold' => 10,
            'period' => 60,
            'action' => ['mode' => 'ban', 'timeout' => 600],
        ];
        $this->fakeCloudflare([
            'GET zones/dst-zone/rate_limits' => $this->cfList([]),
            'GET zones/src-zone/rate_limits' => $this->cfList([$rule]),
            'POST zones/dst-zone/rate_limits' => $this->cfResult(['id' => 'rl-new']),
        ]);

        $this->artisan('cloudflare:migrate', ['type' => 'ratelimit', 'mode' => 'individual'])
            ->expectsOutputToContain('Rule rl-1 migrated successfully.')
            ->assertSuccessful();

        $expected = $rule;
        unset($expected['id']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), 'zones/dst-zone/rate_limits')
            && $request->data() === $expected);
    }

    #[Test]
    public function it_migrates_page_rules_rewriting_the_source_domain(): void
    {
        $this->configureCredentials();
        $this->fakeCloudflare([
            'GET zones/dst-zone/pagerules' => $this->cfList([]),
            'GET zones/src-zone/pagerules' => $this->cfList([
                [
                    'id' => 'pr-1',
                    'status' => 'active',
                    'priority' => 1,
                    'targets' => [
                        ['target' => 'url', 'constraint' => ['operator' => 'matches', 'value' => '*example.com/images/*']],
                    ],
                    'actions' => [
                        ['id' => 'cache_level', 'value' => 'cache_everything'],
                    ],
                ],
            ]),
            'POST zones/dst-zone/pagerules' => $this->cfResult(['id' => 'pr-new']),
        ]);

        $this->artisan('cloudflare:migrate', [
            'type' => 'pagerules',
            'mode' => 'individual',
            '--source-url' => 'example.com',
            '--destination-url' => 'example.org',
        ])
            ->expectsOutputToContain('Found 1 Page Rules to migrate.')
            ->expectsOutputToContain('Rule added successfully.')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), 'zones/dst-zone/pagerules')) {
                return false;
            }
            $data = $request->data();

            return ! array_key_exists('id', $data)
                && $data['targets'][0]['constraint']['value'] === '*example.org/images/*'
                && $data['actions'][0]['id'] === 'cache_level'
                && $data['status'] === 'active';
        });
    }

    #[Test]
    public function it_migrates_custom_lists_together_with_their_items(): void
    {
        $this->configureCredentials();
        $this->fakeCloudflare([
            'GET accounts/src-account/rules/lists' => $this->cfList([
                ['id' => 'list-1', 'name' => 'blocklist', 'kind' => 'ip', 'description' => 'Bad IPs'],
            ]),
            'GET accounts/dst-account/rules/lists' => $this->cfList([]),
            'POST accounts/dst-account/rules/lists' => $this->cfResult(['id' => 'new-list', 'name' => 'blocklist', 'kind' => 'ip']),
            'GET accounts/src-account/rules/lists/list-1/items' => $this->cfList([
                ['id' => 'i1', 'ip' => '1.1.1.1', 'comment' => 'first'],
                ['id' => 'i2', 'ip' => '2.2.2.2'],
            ]),
            'POST accounts/dst-account/rules/lists/new-list/items' => $this->cfResult(['operation_id' => 'op-1']),
        ]);

        $this->artisan('cloudflare:migrate', ['type' => 'customlists', 'mode' => 'individual'])
            ->expectsOutputToContain('Custom List created successfully.')
            ->expectsOutputToContain('Items added successfully to the Custom List new-list')
            ->expectsOutputToContain('List blocklist migrated successfully.')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with(strtok($request->url(), '?'), 'accounts/dst-account/rules/lists')
            && $request->data() === ['name' => 'blocklist', 'kind' => 'ip', 'description' => 'Bad IPs']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), 'rules/lists/new-list/items')
            && $request->data() === [
                ['ip' => '1.1.1.1', 'comment' => 'first'],
                ['ip' => '2.2.2.2', 'comment' => null],
            ]);
    }

    #[Test]
    public function it_skips_custom_lists_that_already_exist_or_are_excluded(): void
    {
        $this->configureCredentials();
        $this->fakeCloudflare([
            'GET accounts/src-account/rules/lists' => $this->cfList([
                ['id' => 'list-1', 'name' => 'blocklist', 'kind' => 'ip'],
                ['id' => 'list-2', 'name' => 'allowlist', 'kind' => 'ip'],
            ]),
            'GET accounts/dst-account/rules/lists' => $this->cfList([
                ['id' => 'dst-list', 'name' => 'blocklist', 'kind' => 'ip'],
            ]),
        ]);

        $this->artisan('cloudflare:migrate', [
            'type' => 'customlists',
            'mode' => 'individual',
            '--exclude' => ['list-2'],
        ])
            ->expectsOutputToContain('List blocklist already exists in the destination account, skipped.')
            ->expectsOutputToContain('List allowlist is excluded.')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }
}
