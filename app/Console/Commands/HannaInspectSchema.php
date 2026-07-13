<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HannaCloudService;
use Illuminate\Console\Command;

class HannaInspectSchema extends Command
{
    protected $signature = 'hanna:inspect-schema';
    protected $description = 'Inspects Hanna Cloud GraphQL schema for messageToDevice and other fields';

    public function handle(HannaCloudService $hanna): int
    {
        $email = config('services.hanna.email');
        $password = config('services.hanna.password');

        if (empty($email) || empty($password)) {
            $this->error('HANNA_CLOUD_EMAIL and HANNA_CLOUD_PASSWORD must be set in .env');
            return self::FAILURE;
        }

        try {
            $hanna->authenticate($email, $password);
            $this->info('Authenticated successfully.');
        } catch (\Throwable $e) {
            $this->error('Authentication failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Inspecting Query type...');
        $this->inspectType($hanna, 'Query');

        $this->info("\n" . str_repeat('-', 40) . "\n");

        $this->info('Inspecting Mutation type...');
        $this->inspectType($hanna, 'Mutation');

        return self::SUCCESS;
    }

    private function inspectType(HannaCloudService $hanna, string $typeName): void
    {
        $query = <<<'GQL'
        query InspectType($name: String!) {
          __type(name: $name) {
            name
            fields {
              name
              type {
                name
                kind
                ofType {
                  name
                  kind
                }
              }
              args {
                name
                type {
                  name
                  kind
                  ofType {
                    name
                    kind
                  }
                }
              }
            }
          }
        }
        GQL;

        try {
            // We need a helper method in HannaCloudService or bypass it using post
            // Let's use reflection to call the private graphql method, or just call post directly.
            // Since post is private too, we can add a public method or inspect it via reflection.
            $reflector = new \ReflectionClass(HannaCloudService::class);
            $graphqlMethod = $reflector->getMethod('graphql');
            $graphqlMethod->setAccessible(true);

            $result = $graphqlMethod->invoke($hanna, 'InspectType', ['name' => $typeName], $query);
            $fields = $result['__type']['fields'] ?? [];

            if (empty($fields)) {
                $this->warn("No fields found on type {$typeName}");
                return;
            }

            foreach ($fields as $field) {
                $name = $field['name'];
                
                // Get type description
                $typeStr = $this->formatType($field['type']);

                // Get arguments description
                $argsArr = [];
                foreach ($field['args'] ?? [] as $arg) {
                    $argsArr[] = $arg['name'] . ': ' . $this->formatType($arg['type']);
                }
                $argsStr = implode(', ', $argsArr);

                $this->line("- {$name}({$argsStr}): {$typeStr}");
            }
        } catch (\Throwable $e) {
            $this->error("Failed to inspect type {$typeName}: " . $e->getMessage());
        }
    }

    private function formatType(array $type): string
    {
        $name = $type['name'] ?? null;
        $kind = $type['kind'] ?? '';
        
        if ($kind === 'NON_NULL' && isset($type['ofType'])) {
            return $this->formatType($type['ofType']) . '!';
        }
        if ($kind === 'LIST' && isset($type['ofType'])) {
            return '[' . $this->formatType($type['ofType']) . ']';
        }
        
        return $name ?: 'unknown';
    }
}
