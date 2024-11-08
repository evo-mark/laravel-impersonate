<p align="center">
    <a href="https://evomark.co.uk" target="_blank" alt="Link to evoMark's website">
        <picture>
          <source media="(prefers-color-scheme: dark)" srcset="https://evomark.co.uk/wp-content/uploads/static/evomark-logo--dark.svg">
          <source media="(prefers-color-scheme: light)" srcset="https://evomark.co.uk/wp-content/uploads/static/evomark-logo--light.svg">
          <img alt="evoMark company logo" src="https://evomark.co.uk/wp-content/uploads/static/evomark-logo--light.svg" width="500">
        </picture>
    </a>
</p>

<p align="center">
    <a href="https://packagist.org/packages/evo-mark/laravel-impersonate">
        <img src="https://img.shields.io/packagist/v/evo-mark/laravel-impersonate?logo=packagist&logoColor=white" alt="Build status" />
    </a>
    <a href="https://packagist.org/packages/evo-mark/laravel-impersonate">
        <img src="https://img.shields.io/packagist/dt/evo-mark/laravel-impersonate" alt="Total Downloads">
    </a>
    <a href="https://packagist.org/packages/evo-mark/laravel-impersonate">
        <img src="https://img.shields.io/packagist/l/evo-mark/laravel-impersonate" alt="License">
    </a>
</p>

# Laravel Impersonate

**Laravel Impersonate** makes it easy to **authenticate as your users**. Add a simple **trait** to your **user model** and impersonate as one of your users in one click.
 
- [Requirements](#requirements)
- [Installation](#installation)
- [Simple usage](#simple-usage)
    - [Using the built-in controller](#using-the-built-in-controller)
- [Advanced Usage](#advanced-usage)
    - [Defining impersonation authorization](#defining-impersonation-authorization)
    - [Using your own strategy](#using-your-own-strategy)
    - [Middleware](#middleware)
    - [Events](#events)
- [Configuration](#configuration)
- [Blade](#blade)
- [Tests](#tests)
- [Contributors](#contributors)
- [Why Not Just Use loginAsId()?](#rationale)

## Requirements

- Laravel 10.x to 11.x
- PHP >= 8.2

### Laravel support

| Version       | Release       |
|:-------------:|:-------------:|
| 10.x to 11.x  | 1.8           |

## Migrating to evo-mark/impersonate

1. Remove the original package with `composer remove lab404/laravel-impersonate`
2. Install the forked version using the instructions below
3. Rename any uses of the package's classes in your app from `Lab404\Impersonate` to `EvoMark\Impersonate`

## Installation

- Require it with Composer:
```bash
composer require evo-mark/laravel-impersonate
```

- Add the service provider at the end of your `config/app.php`:
```php
'providers' => [
    // ...
    EvoMark\Impersonate\ImpersonateServiceProvider::class,
],
```

- Add the trait `EvoMark\Impersonate\Models\Impersonate` to your **User** model.

## Simple usage

Impersonate a user:
```php
Auth::user()->impersonate($other_user);
// You're now logged as the $other_user
```

Leave impersonation:
```php
Auth::user()->leaveImpersonation();
// You're now logged as your original user.
```

### Using the built-in controller

In your routes file, under web middleware, you must call the `impersonate` route macro. 

```php
Route::impersonate();
```

Alternatively, you can execute this macro with your `RouteServiceProvider`.

```php
namespace App\Providers;

class RouteServiceProvider extends ServiceProvider
{
    public function map() {
        Route::middleware('web')->group(function (Router $router) {
            $router->impersonate();
        });
    }
}
```

```php
// Where $id is the ID of the user you want impersonate
route('impersonate', $id)

// Or in case of multi guards, you should also add `guardName` (defaults to `web`)
route('impersonate', ['id' => $id, 'guardName' => 'admin'])

// Generate an URL to leave current impersonation
route('impersonate.leave')
```

## Advanced Usage

### Defining impersonation authorization

By default all users can **impersonate** an user.  
You need to add the method `canImpersonate()` to your user model:

```php
    /**
     * @return bool
     */
    public function canImpersonate()
    {
        // For example
        return $this->is_admin == 1;
    }
```

By default all users can **be impersonated**.  
You need to add the method `canBeImpersonated()` to your user model to extend this behavior:

```php
    /**
     * @return bool
     */
    public function canBeImpersonated()
    {
        // For example
        return $this->can_be_impersonated == 1;
    }
```

### Using your own strategy

- Getting the manager:
```php
// With the app helper
app('impersonate')
// Dependency Injection
public function impersonate(ImpersonateManager $manager, $user_id) { /* ... */ }
```

- Working with the manager:
```php
$manager = app('impersonate');

// Find a user by their ID
$manager->findUserById($id);

// TRUE if your are impersonating an user.
$manager->isImpersonating();

// Impersonate an user. Pass the original user and the user you want to impersonate
$manager->take($from, $to);

// Leave current impersonation
$manager->leave();

// Get the impersonator ID
$manager->getImpersonatorId();
```

### Middleware

**Protect From Impersonation**

You can use the middleware `impersonate.protect` to protect your routes against user impersonation.  
This middleware can be useful when you want to protect specific pages like users subscriptions, users credit cards, ... 

```php
Router::get('/my-credit-card', function() {
    echo "Can't be accessed by an impersonator";
})->middleware('impersonate.protect');
```

### Events

There are two events available that can be used to improve your workflow:
- `TakeImpersonation` is fired when an impersonation is taken.
- `LeaveImpersonation` is fired when an impersonation is leaved.

Each events returns two properties `$event->impersonator` and `$event->impersonated` containing User model instance.

## Configuration

The package comes with a configuration file.  

Publish it with the following command:
```bash
php artisan vendor:publish --tag=impersonate
```

Available options:
```php
    // The session key used to store the original user id.
    'session_key' => 'impersonated_by',
    // Where to redirect after taking an impersonation.
    // Only used in the built-in controller.
    // You can use: an URI, the keyword back (to redirect back) or a route name
    'take_redirect_to' => '/',
    // Where to redirect after leaving an impersonation.
    // Only used in the built-in controller.
    // You can use: an URI, the keyword back (to redirect back) or a route name
    'leave_redirect_to' => '/'
```

## Blade

There are three Blade directives available.

### When the user can impersonate

```blade
@canImpersonate($guard = null)
    <a href="{{ route('impersonate', $user->id) }}">Impersonate this user</a>
@endCanImpersonate
```

### When the user can be impersonated

This comes in handy when you have a user list and want to show an "Impersonate" button next to all the users.
But you don\'t want that button next to the current authenticated user neither to that users which should not be able to impersonated according your implementation of `canBeImpersonated()` . 

```blade
@canBeImpersonated($user, $guard = null)
    <a href="{{ route('impersonate', $user->id) }}">Impersonate this user</a>
@endCanBeImpersonated
```

### When the user is impersonated

```blade
@impersonating($guard = null)
    <a href="{{ route('impersonate.leave') }}">Leave impersonation</a>
@endImpersonating
```

## Tests

```bash
vendor/bin/phpunit
```

## Contributors

- This package was forked from [Lab404](https://github.com/404labfr/laravel-impersonate) and is maintained by evoMark.

## Rationale

### Why not just use `loginAsId()`?

This package adds broader functionality, including Blade directives to allow you to override analytics and other tracking events when impersonating, fire events based on impersonation status, and more. Brief discussion at [issues/5](https://github.com/404labfr/laravel-impersonate/issues/5)

### Why did you fork this package

The original package seems to be in maintenance now, only receiving minor updates without addressing bugs or features. We wanted a few of those features.

## Licence

MIT
