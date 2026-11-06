<?php

declare(strict_types=1);

use App\Actions\SaveManualOrderAction;
use App\Http\Controllers\ManualOrderController;
use App\Services\IbkrClient;
use App\Services\MarketHours;

/**
 * Manual orders are a record only. The stakeholder decision is that nothing in this feature
 * may reach the broker, so the feature's own files are scanned for anything that could.
 */
function manualOrderFeatureFiles(): array
{
    return [
        app_path('Actions/SaveManualOrderAction.php'),
        app_path('Http/Controllers/ManualOrderController.php'),
        app_path('Http/Requests/StoreManualOrderRequest.php'),
        app_path('Http/Requests/UpdateManualOrderRequest.php'),
        app_path('Models/ManualOrder.php'),
        ...glob(resource_path('views/manual-orders/*.blade.php')),
    ];
}

it('never references the broker client, trading actions or the HTTP client', function (string $needle): void {
    foreach (manualOrderFeatureFiles() as $file) {
        expect(file_get_contents($file))->not->toContain($needle, "{$file} mentions {$needle}");
    }
})->with([
    'IbkrClient',
    'IbkrAuthService',
    'PlaceOrderAction',
    'CancelOrderAction',
    'placeOrder',
    'confirmOrder',
    'cancelOrder',
    'Illuminate\\Support\\Facades\\Http',
    'Http::',
]);

it('exposes no write route to the broker from the manual order routes', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->getName() ?? '', 'manual-orders.'));

    expect($routes->map(fn ($route): string => $route->getActionName())->unique()->all())
        ->each->toContain('ManualOrderController');
    expect($routes->count())->toBe(5);
});

it('does not inject a broker client into anything it constructs', function (): void {
    foreach ([
        SaveManualOrderAction::class,
        ManualOrderController::class,
    ] as $class) {
        $constructor = (new ReflectionClass($class))->getConstructor();
        $types = collect($constructor?->getParameters() ?? [])->map(fn ($p): string => (string) $p->getType());

        expect($types->contains(IbkrClient::class))->toBeFalse()
            ->and($types->contains(MarketHours::class))->toBeFalse();
    }
});
