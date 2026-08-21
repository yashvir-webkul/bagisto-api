<?php

namespace Webkul\BagistoApi\Tests\Feature;

use GraphQL\Language\Parser;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\DocumentValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Webkul\BagistoApi\Tests\BagistoApiTest;

class SchemaExportTest extends BagistoApiTest
{
    private const STOREFRONT_KEY_PREFIX = 'pk_storefront_';

    private static string $exportPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$exportPath !== '') {
            return;
        }

        self::$exportPath = sys_get_temp_dir().'/bagisto-schema-export-'.getmypid();

        File::deleteDirectory(self::$exportPath);

        Artisan::call('bagisto-api-platform:export-schema', ['--path' => self::$exportPath]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$exportPath !== '') {
            File::deleteDirectory(self::$exportPath);

            self::$exportPath = '';
        }

        parent::tearDownAfterClass();
    }

    public function test_export_writes_both_surfaces_for_both_transports(): void
    {
        $expected = [
            'openapi-shop.json',
            'openapi-admin.json',
            'shop.graphql',
            'admin.graphql',
            'graphql-operations-shop.json',
            'graphql-operations-admin.json',
        ];

        foreach ($expected as $file) {
            $this->assertFileExists(self::$exportPath.'/'.$file);
        }
    }

    public function test_storefront_spec_carries_no_admin_definition(): void
    {
        $spec = $this->spec('shop');

        $schemas = array_filter(
            array_keys($spec['components']['schemas']),
            fn ($name) => str_starts_with($name, 'Admin'),
        );

        $tags = array_filter(
            array_column($spec['tags'], 'name'),
            fn ($name) => str_starts_with($name, 'Admin'),
        );

        expect($schemas)->toBe([]);
        expect($tags)->toBe([]);
    }

    public function test_every_schema_reference_resolves(): void
    {
        foreach (['shop', 'admin'] as $surface) {
            $spec = $this->spec($surface);

            $missing = array_diff(
                $this->reachable($spec),
                array_keys($spec['components']['schemas']),
            );

            expect($missing)->toBe([], "openapi-{$surface}.json has unresolved schema references");
        }
    }

    public function test_no_schema_is_orphaned(): void
    {
        foreach (['shop', 'admin'] as $surface) {
            $spec = $this->spec($surface);

            $orphans = array_diff(
                array_keys($spec['components']['schemas']),
                $this->reachable($spec),
            );

            expect(array_values($orphans))->toBe([], "openapi-{$surface}.json carries schemas no path uses");
        }
    }

    public function test_spec_is_import_ready(): void
    {
        foreach (['shop' => '/api/shop', 'admin' => '/api/admin'] as $surface => $prefix) {
            $spec = $this->spec($surface);

            expect($spec['info']['title'])->not->toBe('');
            expect($spec['servers'][0]['url'])->toBe('{url}'.$prefix);
            expect($spec['servers'][0]['variables']['url']['default'])->not->toBe('');
        }
    }

    public function test_top_level_tags_cover_every_operation_tag(): void
    {
        foreach (['shop', 'admin'] as $surface) {
            $spec = $this->spec($surface);

            $declared = array_column($spec['tags'], 'name');

            expect($declared)->not->toBe([], "openapi-{$surface}.json declares no tags");

            $used = [];

            foreach ($spec['paths'] as $operations) {
                foreach ($operations as $operation) {
                    if (is_array($operation)) {
                        $used = array_merge($used, $operation['tags'] ?? []);
                    }
                }
            }

            expect(array_values(array_diff(array_unique($used), $declared)))->toBe([]);
            expect(array_values(array_diff($declared, array_unique($used))))->toBe([]);
        }
    }

    public function test_no_exported_artifact_contains_a_storefront_key(): void
    {
        $files = array_merge(
            glob(self::$exportPath.'/*') ?: [],
            glob(dirname(__DIR__, 2).'/schema/generated/*') ?: [],
            glob(dirname(__DIR__, 2).'/schema/collections/*') ?: [],
            glob(dirname(__DIR__, 2).'/schema/environments/*') ?: [],
        );

        foreach ($files as $file) {
            expect(str_contains((string) file_get_contents($file), self::STOREFRONT_KEY_PREFIX))
                ->toBeFalse(basename($file).' contains a storefront key');
        }
    }

    public function test_graphql_schemas_do_not_overlap(): void
    {
        $shop = (string) file_get_contents(self::$exportPath.'/shop.graphql');
        $admin = (string) file_get_contents(self::$exportPath.'/admin.graphql');

        expect(preg_match('/^type Admin\w+/m', $shop))->toBe(0);

        expect(array_values(array_intersect(
            $this->rootFields($shop, 'Mutation'),
            $this->rootFields($admin, 'Mutation'),
        )))->toBe([]);

        expect(array_values(array_diff(
            array_intersect($this->rootFields($shop, 'Query'), $this->rootFields($admin, 'Query')),
            ['node'],
        )))->toBe([]);
    }

    public function test_collection_graphql_requests_validate_against_the_schema(): void
    {
        foreach (['shop' => 'Bagisto-Shop-API', 'admin' => 'Bagisto-Admin-API'] as $surface => $name) {
            $schema = BuildSchema::build((string) file_get_contents(dirname(__DIR__, 2)."/schema/generated/{$surface}.graphql"));

            $requests = $this->graphQlRequests($name);

            expect(count($requests))->toBeGreaterThan(100, "{$name} exposes too few GraphQL requests to be full coverage");

            foreach ($requests as $request) {
                $errors = DocumentValidator::validate($schema, Parser::parse($request['query']));

                expect(array_map(fn ($error) => $error->getMessage(), $errors))
                    ->toBe([], "{$name} / {$request['name']} does not validate");
            }
        }
    }

    public function test_graphql_folder_covers_every_root_operation(): void
    {
        $pairs = [
            'shop' => 'Bagisto-Shop-API',
            'admin' => 'Bagisto-Admin-API',
        ];

        foreach ($pairs as $surface => $collection) {
            $map = json_decode((string) file_get_contents(dirname(__DIR__, 2)."/schema/generated/graphql-operations-{$surface}.json"), true);

            $shipped = array_column($this->graphQlRequests($collection), 'name');

            expect(array_values(array_diff(array_keys($map), $shipped)))
                ->toBe([], "{$collection} is missing GraphQL operations the schema exposes");
        }
    }

    public function test_graphql_folders_mirror_the_rest_folders(): void
    {
        foreach (['Bagisto-Shop-API', 'Bagisto-Admin-API'] as $collection) {
            $body = json_decode((string) file_get_contents(dirname(__DIR__, 2)."/schema/collections/{$collection}.postman_collection.json"), true);

            $folders = [];

            foreach ($body['item'] as $folder) {
                $folders[$folder['name']] = array_column($folder['item'], 'name');
            }

            expect($folders['GraphQL'])->not->toBe([]);

            expect(array_values(array_diff($folders['GraphQL'], $folders['REST'])))
                ->toBe([], "{$collection} has GraphQL folders with no REST counterpart");
        }
    }

    public function test_every_request_url_is_well_formed(): void
    {
        foreach (['Bagisto-Shop-API', 'Bagisto-Admin-API'] as $collection) {
            $body = json_decode((string) file_get_contents(dirname(__DIR__, 2)."/schema/collections/{$collection}.postman_collection.json"), true);

            $requests = [];

            $this->collectAllRequests($body, $requests);

            expect(count($requests))->toBeGreaterThan(250, "{$collection} holds fewer requests than expected");

            foreach ($requests as $request) {
                $url = $request['request']['url'];

                expect($url['raw'] ?? '')->toStartWith('{{', "{$request['name']} has no variable-rooted URL");
                expect($url['host'] ?? [])->not->toBe([], "{$request['name']} has no host");

                foreach ($url['host'] as $segment) {
                    expect(str_contains((string) $segment, '/'))
                        ->toBeFalse("{$request['name']} crams a path into its host segment");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $requests
     */
    private function collectAllRequests(array $item, array &$requests): void
    {
        if (isset($item['request'])) {
            $requests[] = $item;

            return;
        }

        foreach ($item['item'] ?? [] as $child) {
            $this->collectAllRequests($child, $requests);
        }
    }

    public function test_collections_only_reference_declared_environment_variables(): void
    {
        $pairs = [
            'Bagisto-Shop-API' => 'Bagisto-Shop-Local',
            'Bagisto-Admin-API' => 'Bagisto-Admin-Local',
        ];

        foreach ($pairs as $collection => $environment) {
            $body = (string) file_get_contents(dirname(__DIR__, 2)."/schema/collections/{$collection}.postman_collection.json");

            $declared = array_column(
                json_decode((string) file_get_contents(dirname(__DIR__, 2)."/schema/environments/{$environment}.postman_environment.json"), true)['values'],
                'key',
            );

            preg_match_all('/\{\{(\w+)\}\}/', $body, $matches);

            $undeclared = array_diff(array_unique($matches[1]), array_merge($declared, ['baseUrl']));

            expect(array_values($undeclared))->toBe([], "{$collection} uses variables the environment does not declare");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(string $surface): array
    {
        return json_decode((string) file_get_contents(self::$exportPath."/openapi-{$surface}.json"), true);
    }

    /**
     * Transitive closure of the schema names referenced from the paths.
     *
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    private function reachable(array $spec): array
    {
        $schemas = $spec['components']['schemas'] ?? [];

        $pending = $this->refs($spec['paths'] ?? []);
        $seen = [];

        while ($pending) {
            $name = array_pop($pending);

            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;

            if (isset($schemas[$name])) {
                $pending = array_merge($pending, $this->refs($schemas[$name]));
            }
        }

        return array_keys($seen);
    }

    /**
     * @return list<string>
     */
    private function refs(mixed $node, array &$found = []): array
    {
        if (! is_array($node)) {
            return array_keys($found);
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/components/schemas/')) {
                $found[substr($value, strlen('#/components/schemas/'))] = true;

                continue;
            }

            $this->refs($value, $found);
        }

        return array_keys($found);
    }

    /**
     * @return list<string>
     */
    private function rootFields(string $sdl, string $type): array
    {
        if (! preg_match('/^type '.$type.' \{(.*?)^\}/ms', $sdl, $block)) {
            return [];
        }

        preg_match_all('/^  (\w+)[(:]/m', $block[1], $fields);

        return $fields[1];
    }

    /**
     * @return list<array{name: string, query: string}>
     */
    private function graphQlRequests(string $collection): array
    {
        $body = json_decode((string) file_get_contents(dirname(__DIR__, 2)."/schema/collections/{$collection}.postman_collection.json"), true);

        $requests = [];

        foreach ($body['item'] as $folder) {
            if ($folder['name'] === 'GraphQL') {
                $this->collectRequests($folder, $requests);
            }
        }

        return $requests;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array{name: string, query: string}>  $requests
     */
    private function collectRequests(array $item, array &$requests): void
    {
        if (isset($item['request'])) {
            $requests[] = ['name' => $item['name'], 'query' => $item['request']['body']['graphql']['query']];

            return;
        }

        foreach ($item['item'] ?? [] as $child) {
            $this->collectRequests($child, $requests);
        }
    }
}
