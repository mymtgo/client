<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

it('renders the Inertia Error page for HTTP exceptions producing a 500', function () {
    Route::get('/__test__/http-500', function () {
        abort(500, 'http-exception-message');
    });

    $response = $this->get('/__test__/http-500');

    $response->assertStatus(500);
    $response->assertInertia(fn ($assert) => $assert
        ->component('Error')
        ->where('status', 500)
        ->where('message', 'http-exception-message')
    );
});

it('renders the Inertia Error page for generic throwables that bubble to a 500', function () {
    Route::get('/__test__/generic-500', function () {
        throw new RuntimeException('raw-database-error');
    });

    $response = $this->get('/__test__/generic-500');

    $response->assertStatus(500);
    $response->assertInertia(fn ($assert) => $assert
        ->component('Error')
        ->where('status', 500)
        // Raw exception message must NOT leak to users.
        ->where('message', fn ($message) => $message !== 'raw-database-error')
    );
});

it('renders the Inertia Error page for 404 http exceptions', function () {
    Route::get('/__test__/http-404', function () {
        throw new NotFoundHttpException('missing');
    });

    $response = $this->get('/__test__/http-404');

    $response->assertNotFound();
    $response->assertInertia(fn ($assert) => $assert->component('Error')->where('status', 404));
});

it('renders the Inertia Error page for 503 http exceptions', function () {
    Route::get('/__test__/http-503', function () {
        throw new ServiceUnavailableHttpException;
    });

    $response = $this->get('/__test__/http-503');

    $response->assertStatus(503);
    $response->assertInertia(fn ($assert) => $assert->component('Error')->where('status', 503));
});

it('leaves unrelated status codes untouched', function () {
    Route::get('/__test__/ok', fn () => response('ok', 200));

    $this->get('/__test__/ok')->assertSuccessful();
});
