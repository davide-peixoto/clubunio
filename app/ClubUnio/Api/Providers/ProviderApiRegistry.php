<?php

namespace App\ClubUnio\Api\Providers;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Models\Account;

class ProviderApiRegistry
{
    public function definitions(): array
    {
        return app(BexioApiActions::class)->definitions()
            + app(SfvApiActions::class)->definitions()
            + app(TrainingApiActions::class)->definitions();
    }

    private function adapter(string $operation): object
    {
        abort_unless(isset($this->definitions()[$operation]), 404);

        return app(match (explode('.', $operation, 2)[0]) {
            'bexio' => BexioApiActions::class,
            'sfv' => SfvApiActions::class,
            'training' => TrainingApiActions::class,
            default => abort(404),
        });
    }

    public function validate(ApiContext $context, string $operation, int $id, array $input): array
    {
        return $this->adapter($operation)->validate($context, $operation, $id, $input);
    }

    public function authorize(ApiContext $context, string $operation): void
    {
        $definition = $this->definitions()[$operation] ?? abort(404);
        foreach ($definition['scopes'] as $scope => [$module, $function]) {
            $context->authorize($scope, $module, $function);
        }
    }

    public function execute(ApiContext $context, string $operation, int $id, array $input, ?Account $executor = null): array
    {
        return $this->adapter($operation)->execute($context, $operation, $id, $input, $executor);
    }
}
