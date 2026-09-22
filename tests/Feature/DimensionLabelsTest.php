<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function labelsUrl(string $key, array $values): string
{
    return '/telemetry-ui/api/v2/dimensions/labels?'.http_build_query(['key' => $key, 'values' => $values]);
}

it('resolves a batch of ids to names with a closure, only for ids it knows', function (): void {
    $calls = 0;
    TelemetryUi::dimension('hubhus.customer_id', label: 'Customer', resolve: function (array $ids) use (&$calls): array {
        $calls++;

        return ['8655' => 'Acme ApS', '999' => 'Not asked for'];
    });

    $this->getJson(labelsUrl('hubhus.customer_id', ['8655', '1']))
        ->assertOk()
        ->assertExactJson(['labels' => ['8655' => 'Acme ApS']]);

    // Cached per value, misses included: no second lookup.
    $this->getJson(labelsUrl('hubhus.customer_id', ['8655', '1']))->assertExactJson(['labels' => ['8655' => 'Acme ApS']]);
    expect($calls)->toBe(1);

    // A new value only looks up that one.
    $this->getJson(labelsUrl('hubhus.customer_id', ['8655', '2']));
    expect($calls)->toBe(2);
});

it('flags resolvable dimensions in the bootstrap, keeping built-ins built-in', function (): void {
    TelemetryUi::resolve('user.id', fn (array $ids): array => array_fill_keys($ids, 'Someone'));

    $user = collect($this->getJson('/telemetry-ui/api/v2/bootstrap')->json('dimensions'))->firstWhere('key', 'user.id');

    expect($user)->toMatchArray(['resolvable' => true, 'builtin' => true, 'label' => 'User']);
});

it('resolves ids through an Eloquent model and attribute', function (): void {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
    Schema::create('people', function ($table): void {
        $table->id();
        $table->string('name');
    });
    DB::table('people')->insert([['id' => 17, 'name' => 'Jane Doe'], ['id' => 20, 'name' => 'Sam Roe']]);

    $model = new class extends Model
    {
        protected $table = 'people';
    };

    TelemetryUi::resolve('user.id', $model::class, 'name');

    $this->getJson(labelsUrl('user.id', ['17', '20', '404']))
        ->assertOk()
        ->assertExactJson(['labels' => ['17' => 'Jane Doe', '20' => 'Sam Roe']]);
});

it('fails open when the resolver throws, and rejects unknown dimensions', function (): void {
    TelemetryUi::dimension('tenant.id', resolve: fn (array $ids): array => throw new RuntimeException('db down'));

    $this->getJson(labelsUrl('tenant.id', ['a']))->assertOk()->assertExactJson(['labels' => []]);
    $this->getJson(labelsUrl('nope.nothing', ['a']))->assertNotFound();
});

it('refuses a model class that is not an Eloquent model', function (): void {
    TelemetryUi::resolve('user.id', stdClass::class);
})->throws(InvalidArgumentException::class);
